<?php
/**
 * Stores activity events: append-only, hash-chained.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * The Pro side of the activity log (M14, ADR-011). Subscribed to the free
 * `rtbp_activity` action; the free plugin emits, this class stores.
 *
 * - Append-only. There is no update or delete here except the grouping
 *   counter below; the only removal is the verified purge after an archive
 *   (T4, its own class).
 * - HMAC chain: `hash = hmac_sha256( key, prev_hash | canonical row )` with a
 *   key kept outside the database (LogKey), so the chain cannot be recomputed
 *   by someone who can only edit tables. Rows are written one at a time in a
 *   transaction of their own (after the caller's transaction commits, when
 *   there is one — see record()) that locks the head record (`pro_activity_head`, `SELECT …
 *   FOR UPDATE`): the chain never forks, ids commit in order, and the head
 *   (last id, last hash, live rows, anchor; itself sealed with the key) lets
 *   `log:verify` catch deleted recent rows or an emptied table.
 * - Grouping (14.10): a page view by the same user of the same page within
 *   the window increments `repeat_count` / `last_seen_gmt` on that row.
 *   Those two columns are outside the hash; data changes are never grouped.
 * - The raw user agent is kept only for sign-in and security events; other
 *   rows keep the parsed device (legacy rule).
 */
final class ActivityLogger {

	/**
	 * How often a write retries when the head lock times out or deadlocks.
	 */
	const RETRIES = 5;

	/**
	 * Whether the head row is known to exist (this request).
	 *
	 * @var bool
	 */
	private static bool $head_ready = false;

	/**
	 * The chained columns, in hash order.
	 */
	const HASHED = array(
		'user_id',
		'user_name',
		'user_role',
		'action',
		'kind',
		'subject_type',
		'subject_id',
		'subject_label',
		'description',
		'changes',
		'meta',
		'ip',
		'user_agent',
		'device',
		'created_at',
		'created_at_gmt',
	);

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_activity_log';
	}

	/**
	 * `rtbp_activity` listener.
	 *
	 * @param array $event The free plugin's event.
	 * @return void
	 */
	public static function record( $event ): void {
		if ( ! is_array( $event ) || empty( $event['action'] ) ) {
			return;
		}
		if ( 'view' === ( $event['kind'] ?? '' ) && ! ActivitySettings::get( 'logPageViews', true ) ) {
			return;
		}

		$row   = self::row( $event );
		$group = 'page.view' === $row['action'];

		// Inside the free plugin's transaction (a booking, a hold, a guest…) the
		// row waits for its commit: the logger's own START TRANSACTION would
		// commit the caller's work early and release its row locks (the room
		// locks of the booking engine). If the caller rolls back, the entry goes
		// with it — the action never happened.
		$tx = '\\RadiusTheme\\RadiusHotelBooking\\Core\\Database\\Transaction';
		if ( class_exists( $tx ) && method_exists( $tx, 'active' ) && method_exists( $tx, 'afterCommit' ) && $tx::active() ) {
			$tx::afterCommit( static fn() => self::insert( $row, $group ) );
			return;
		}

		// Page views are grouped inside the same lock as the insert, so two
		// parallel requests of one screen cannot both miss the row and add two.
		self::insert( $row, $group );
	}

	/**
	 * The row for an event (without hashes).
	 *
	 * @param array $event Event.
	 * @return array
	 */
	public static function row( array $event ): array {
		$actor   = (array) ( $event['actor'] ?? array() );
		$subject = (array) ( $event['subject'] ?? array() );
		$agent   = (string) ( $event['user_agent'] ?? '' );
		$kind    = (string) ( $event['kind'] ?? 'data' );
		$meta    = (array) ( $event['meta'] ?? array() );

		return array(
			'user_id'        => (int) ( $actor['id'] ?? 0 ),
			'user_name'      => mb_substr( (string) ( $actor['name'] ?? '' ), 0, 100 ),
			'user_role'      => mb_substr( (string) ( ( (array) ( $actor['roles'] ?? array() ) )[0] ?? '' ), 0, 60 ),
			'action'         => mb_substr( (string) $event['action'], 0, 64 ),
			'kind'           => mb_substr( $kind, 0, 16 ),
			'subject_type'   => mb_substr( (string) ( $subject['type'] ?? '' ), 0, 40 ),
			'subject_id'     => mb_substr( (string) ( $subject['id'] ?? '' ), 0, 64 ),
			'subject_label'  => mb_substr( (string) ( $subject['label'] ?? '' ), 0, 191 ),
			'description'    => (string) ( $event['description'] ?? '' ),
			'changes'        => empty( $event['changes'] ) ? '' : (string) wp_json_encode( $event['changes'] ),
			'meta'           => $meta ? (string) wp_json_encode( $meta ) : '',
			'ip'             => mb_substr( (string) ( $event['ip'] ?? '' ), 0, 45 ),
			'user_agent'     => in_array( $kind, array( 'auth', 'security' ), true ) ? mb_substr( $agent, 0, 255 ) : '',
			'device'         => mb_substr( DeviceParser::describe( $agent ), 0, 100 ),
			'created_at'     => (string) ( $event['time'] ?? current_time( 'mysql' ) ),
			'created_at_gmt' => (string) ( $event['time_gmt'] ?? current_time( 'mysql', true ) ),
		);
	}

	/**
	 * A row's hash.
	 *
	 * @param array  $row       Row (the HASHED columns).
	 * @param string $prev_hash The previous row's hash ('' for the first).
	 * @return string
	 */
	public static function hash( array $row, string $prev_hash ): string {
		$canonical = array();
		foreach ( self::HASHED as $column ) {
			$canonical[] = (string) ( $row[ $column ] ?? '' );
		}
		return hash_hmac( 'sha256', $prev_hash . '|' . wp_json_encode( $canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), LogKey::get() );
	}

	/**
	 * Insert a row at the end of the chain (or group it, for page views),
	 * inside a transaction holding the head lock.
	 *
	 * @param array $row   Row.
	 * @param bool  $group Try grouping into a recent row first (page views).
	 * @return int The new id (0 when grouped or on failure; failures are logged).
	 */
	private static function insert( array $row, bool $group = false ): int {
		global $wpdb;
		$table = self::table();

		for ( $attempt = 1; $attempt <= self::RETRIES; $attempt++ ) {
			$head = self::lock_head();
			if ( null === $head ) {
				self::rollback();
				usleep( wp_rand( 20000, 120000 ) * $attempt ); // Lock wait timeout or deadlock: back off, try again.
				continue;
			}

			if ( $group && self::group( $row ) ) {
				self::commit();
				return 0;
			}

			$prev = '' !== $head['last_hash'] ? $head['last_hash'] : $head['anchor'];

			$row['prev_hash']     = $prev;
			$row['hash']          = self::hash( $row, $prev );
			$row['repeat_count']  = 1;
			$row['last_seen_gmt'] = $row['created_at_gmt'];

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- append-only log table.
			if ( ! $wpdb->insert( $table, $row ) ) {
				$error = $wpdb->last_error;
				self::rollback();
				self::fail( 'insert', $error, $row );
				return 0;
			}
			$id = (int) $wpdb->insert_id;

			$head['last_id']   = $id;
			$head['last_hash'] = $row['hash'];
			$head['row_count'] = (int) $head['row_count'] + 1;
			if ( ! self::write_head( $head ) ) {
				$error = $wpdb->last_error;
				self::rollback();
				self::fail( 'head', $error, $row );
				return 0;
			}

			self::commit();
			return $id;
		}

		self::fail( 'lock', 'The log head stayed locked.', $row );
		return 0;
	}

	/**
	 * Start a transaction and lock the head record (created if missing).
	 *
	 * @return array|null The head, or null when the lock could not be taken.
	 */
	public static function lock_head(): ?array {
		global $wpdb;
		$table = self::head_table();

		self::ensure_head();

		// A plain exclusive lock on an existing row: writers queue up instead
		// of deadlocking (an INSERT IGNORE inside the transaction would take a
		// shared lock first and deadlock on the upgrade).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control and the head row lock.
		$wpdb->query( 'START TRANSACTION' );
		$head = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = 1 FOR UPDATE', $table ), ARRAY_A );
		if ( ! is_array( $head ) && ! $wpdb->last_error ) {
			// The head row is gone: recreate it (log:verify will still report
			// the rows it no longer accounts for) and lock it.
			$wpdb->query( 'ROLLBACK' );
			self::$head_ready = false;
			self::ensure_head();
			$wpdb->query( 'START TRANSACTION' );
			$head = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = 1 FOR UPDATE', $table ), ARRAY_A );
		}
		// phpcs:enable

		return is_array( $head ) ? $head : null;
	}

	/**
	 * Create the head row once, outside any transaction (autocommit).
	 *
	 * @return void
	 */
	private static function ensure_head(): void {
		if ( self::$head_ready ) {
			return;
		}
		global $wpdb;
		$empty = array(
			'last_id'   => 0,
			'last_hash' => '',
			'row_count' => 0,
			'anchor'    => '',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time creation of the head row.
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (id, last_id, last_hash, row_count, anchor, mac) VALUES (1, 0, %s, 0, %s, %s)', self::head_table(), '', '', self::head_mac( $empty ) ) );
		self::$head_ready = true;
	}

	/**
	 * Write the head record (inside the transaction), sealed with the key.
	 *
	 * @param array $head Head.
	 * @return bool
	 */
	public static function write_head( array $head ): bool {
		global $wpdb;
		$head['mac'] = self::head_mac( $head );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the head row.
		return false !== $wpdb->update(
			self::head_table(),
			array(
				'last_id'   => (int) $head['last_id'],
				'last_hash' => (string) $head['last_hash'],
				'row_count' => (int) $head['row_count'],
				'anchor'    => (string) $head['anchor'],
				'mac'       => $head['mac'],
			),
			array( 'id' => 1 )
		);
	}

	/**
	 * The head's seal.
	 *
	 * @param array $head Head.
	 * @return string
	 */
	public static function head_mac( array $head ): string {
		return hash_hmac( 'sha256', implode( '|', array( (int) $head['last_id'], (string) $head['last_hash'], (int) $head['row_count'], (string) $head['anchor'] ) ), LogKey::get() );
	}

	/**
	 * The head record, read without a lock (for the verifier).
	 *
	 * @return array|null
	 */
	public static function head(): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live read.
		$head = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = 1', self::head_table() ), ARRAY_A );
		return is_array( $head ) ? $head : null;
	}

	/**
	 * The head table name.
	 *
	 * @return string
	 */
	public static function head_table(): string {
		return rtbp_table_prefix() . 'pro_activity_head';
	}

	/**
	 * Commit the transaction.
	 *
	 * @return void
	 */
	public static function commit(): void {
		global $wpdb;
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
	}

	/**
	 * Roll the transaction back.
	 *
	 * @return void
	 */
	public static function rollback(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
	}

	/**
	 * Record that an event could not be stored (never silently lost).
	 *
	 * @param string $stage Where it failed.
	 * @param string $error Database error.
	 * @param array  $row   The row.
	 * @return void
	 */
	private static function fail( string $stage, string $error, array $row ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- an audit event could not be stored.
		error_log( sprintf( '[radius-hotel-booking-pro] Activity event %s not stored (%s): %s', $row['action'] ?? '?', $stage, $error ) );
	}

	/**
	 * Group a page view into the user's last view of the same page within
	 * the window. Only `repeat_count` and `last_seen_gmt` change (outside the
	 * hash). Never into an archived row (its count is already in a file), nor
	 * into a row whose `last_seen_gmt` lies in the future.
	 *
	 * @param array $row Row.
	 * @return bool Whether it was grouped.
	 */
	private static function group( array $row ): bool {
		global $wpdb;
		$window = max( 0, (int) ActivitySettings::get( 'groupWindow', 900 ) );
		if ( ! $window || ! $row['user_id'] ) {
			return false;
		}

		$since = gmdate( 'Y-m-d H:i:s', strtotime( $row['created_at_gmt'] . ' UTC' ) - $window );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live lookup of the last view.
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE id > %d AND user_id = %d AND action = %s AND subject_type = %s AND subject_id = %s AND last_seen_gmt >= %s AND last_seen_gmt <= %s ORDER BY id DESC LIMIT 1',
				self::table(),
				ActivityArchiver::last_archived_id(),
				$row['user_id'],
				$row['action'],
				$row['subject_type'],
				$row['subject_id'],
				$since,
				$row['created_at_gmt']
			)
		);
		if ( ! $id ) {
			return false;
		}

		// The only UPDATE the log ever runs: the grouping counter.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- counter on an existing row.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET repeat_count = repeat_count + 1, last_seen_gmt = %s WHERE id = %d', self::table(), $row['created_at_gmt'], $id ) );
		return true;
	}
}
