<?php
/**
 * The iCal feed data access.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Ical
 */

namespace RadiusTheme\RadiusHotelBookingPro\Ical;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_ical_feeds`, plus the live rooms they belong to (read from
 * the free plugin's tables).
 */
class IcalFeedRepository {

	/**
	 * The feeds table.
	 *
	 * @return string
	 */
	private function table(): string {
		return rtbp_table_prefix() . 'pro_ical_feeds';
	}

	/**
	 * A room's export feed.
	 *
	 * @param int $room_id Room id.
	 * @return array|null
	 */
	public function exportFor( int $room_id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small read.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE room_id = %d AND direction = 'export' ORDER BY id LIMIT 1", $this->table(), $room_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * The export feed with this token, if its room is still live.
	 *
	 * @param string $token Token.
	 * @return array|null Feed row plus `number` (room number).
	 */
	public function exportByToken( string $token ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the public feed: always fresh.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT f.*, r.number FROM %i f INNER JOIN %i r ON r.id = f.room_id AND r.deleted_at IS NULL
				WHERE f.token = %s AND f.direction = 'export' LIMIT 1",
				$this->table(),
				rtbp_table( 'rooms' ),
				$token
			),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Export feeds by room.
	 *
	 * @return array<int, array> Room id => feed row.
	 */
	public function exports(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one small read.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE direction = 'export' ORDER BY id", $this->table() ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['room_id'] ] = $out[ (int) $row['room_id'] ] ?? $row;
		}
		return $out;
	}

	/**
	 * One feed.
	 *
	 * @param int  $id   Id.
	 * @param bool $lock Lock the row until the transaction ends.
	 * @return array|null
	 */
	public function find( int $id, bool $lock = false ): ?array {
		global $wpdb;
		$sql = $lock ? 'SELECT * FROM %i WHERE id = %d FOR UPDATE' : 'SELECT * FROM %i WHERE id = %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is one of two fixed strings.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $this->table(), $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Import feeds, with how many blocks each holds now.
	 *
	 * @param int $room_id One room only (0 = all).
	 * @return array[] Rows plus `blocks`.
	 */
	public function imports( int $room_id = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.*, ( SELECT COUNT(*) FROM %i b WHERE b.feed_id = f.id AND b.source = 'ical' AND b.end_at_gmt > UTC_TIMESTAMP() ) AS blocks
				FROM %i f WHERE f.direction = 'import' AND ( %d = 0 OR f.room_id = %d ) ORDER BY f.room_id, f.id",
				rtbp_table( 'blocks' ),
				$this->table(),
				$room_id,
				$room_id
			),
			ARRAY_A
		);
		return (array) $rows;
	}

	/**
	 * Import feeds due for a sync, least recently synced first.
	 *
	 * @param string $now_gmt Now, UTC.
	 * @param int    $limit   Most feeds.
	 * @return int[] Feed ids.
	 */
	public function due( string $now_gmt, int $limit ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the cron's work list.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT f.id FROM %i f INNER JOIN %i r ON r.id = f.room_id AND r.deleted_at IS NULL
				WHERE f.direction = 'import'
					AND ( f.last_synced_at_gmt IS NULL OR f.last_synced_at_gmt <= DATE_SUB( %s, INTERVAL f.interval_minutes MINUTE ) )
				ORDER BY f.last_synced_at_gmt IS NOT NULL, f.last_synced_at_gmt, f.id
				LIMIT %d",
				$this->table(),
				rtbp_table( 'rooms' ),
				$now_gmt,
				$limit
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Booked stays that overlap any live block of an import feed (a possible
	 * double booking), counted fresh on every sync.
	 *
	 * @param int    $feed_id  Feed id.
	 * @param string $now_gmt  Now, UTC.
	 * @param array  $statuses Occupying line statuses (the free engine's).
	 * @return int
	 */
	public function overlaps( int $feed_id, string $now_gmt, array $statuses ): int {
		global $wpdb;
		if ( ! $statuses ) {
			return 0;
		}
		$in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $in is only "%s,…". Live count.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( DISTINCT br.id ) FROM %i b
				INNER JOIN %i br ON br.room_id = b.scope_id AND br.status IN ( $in )
					AND br.start_at_gmt < b.end_at_gmt AND br.occupied_until_gmt > b.start_at_gmt
				WHERE b.feed_id = %d AND b.source = 'ical' AND b.scope = 'room' AND b.end_at_gmt > %s",
				array_merge( array( rtbp_table( 'blocks' ), rtbp_table( 'booking_rooms' ) ), $statuses, array( $feed_id, $now_gmt ) )
			)
		);
		// phpcs:enable
		return (int) $count;
	}

	/**
	 * Whether a room already imports this URL.
	 *
	 * @param int    $room_id   Room id.
	 * @param string $url       URL.
	 * @param int    $except_id Feed being changed.
	 * @return bool
	 */
	public function urlTaken( int $room_id, string $url, int $except_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uniqueness check.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE room_id = %d AND direction = 'import' AND url = %s AND id <> %d LIMIT 1", $this->table(), $room_id, $url, $except_id ) );
	}

	/**
	 * Live rooms with their type and floor, in the order the Rooms screen
	 * shows them.
	 *
	 * @param int $room_id One room only (0 = all).
	 * @return array[] `{ id, number, room_type_id, room_type, floor }`.
	 */
	public function rooms( int $room_id = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small read.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.id, r.number, r.room_type_id, COALESCE( t.name, \'\' ) AS room_type, COALESCE( f.name, \'\' ) AS floor
				FROM %i r
				LEFT JOIN %i t ON t.id = r.room_type_id
				LEFT JOIN %i f ON f.id = r.floor_id
				WHERE r.deleted_at IS NULL AND ( %d = 0 OR r.id = %d )
				ORDER BY t.sort_order, t.id, r.number_sort, r.number, r.id',
				rtbp_table( 'rooms' ),
				rtbp_table( 'room_types' ),
				rtbp_table( 'floors' ),
				$room_id,
				$room_id
			),
			ARRAY_A
		);
		return (array) $rows;
	}

	/**
	 * Lock a live room row until the transaction ends.
	 *
	 * @param int $room_id Room id.
	 * @return bool Whether it exists.
	 */
	public function lockRoom( int $room_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- row lock inside a transaction.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d AND deleted_at IS NULL FOR UPDATE', rtbp_table( 'rooms' ), $room_id ) );
	}

	/**
	 * Insert a feed.
	 *
	 * @param array $row Columns.
	 * @return int Id.
	 * @throws \RuntimeException When the insert fails.
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$row = array_merge(
			$row,
			array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plugin table.
		if ( ! $wpdb->insert( $this->table(), $row ) ) {
			throw new \RuntimeException( 'Could not save the feed: ' . esc_html( (string) $wpdb->last_error ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a feed.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Columns.
	 * @param bool  $touch  Also set `updated_at`.
	 * @return void
	 */
	public function update( int $id, array $fields, bool $touch = true ): void {
		global $wpdb;
		if ( $touch ) {
			$fields['updated_at'] = current_time( 'mysql', true );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->update( $this->table(), $fields, array( 'id' => $id ) );
	}

	/**
	 * Delete a feed.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plugin table.
		$wpdb->delete( $this->table(), array( 'id' => $id ) );
	}
}
