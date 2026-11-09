<?php
/**
 * Cash register data access (R3-T5).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\CashRegister
 */

namespace RadiusTheme\RadiusHotelBookingPro\CashRegister;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_cash_sessions` (rows as arrays) and the takings read from
 * the free payments table.
 */
final class CashSessionRepository {

	/**
	 * The sessions table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_cash_sessions';
	}

	/**
	 * One session, or null.
	 *
	 * @param int  $id     Id.
	 * @param bool $locked Lock the row until the transaction ends.
	 * @return array|null
	 */
	public function find( int $id, bool $locked = false ): ?array {
		global $wpdb;
		$sql = 'SELECT * FROM %i WHERE id = %d' . ( $locked ? ' FOR UPDATE' : '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- one row; $sql holds placeholders only.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, self::table(), $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * A user's open session, or null; locked until the transaction ends when asked
	 * (two Opens at once must not both pass).
	 *
	 * @param int  $user_id User.
	 * @param bool $locked  Lock.
	 * @return array|null
	 */
	public function openFor( int $user_id, bool $locked = false ): ?array {
		global $wpdb;
		$sql = "SELECT * FROM %i WHERE user_id = %d AND status = 'open' ORDER BY id DESC LIMIT 1" . ( $locked ? ' FOR UPDATE' : '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- one row; $sql holds placeholders only.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, self::table(), $user_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Insert a session.
	 *
	 * @param array $data Columns.
	 * @return int Id.
	 */
	public function insert( array $data ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- a write.
		$wpdb->insert( self::table(), $data + array( 'created_at' => $now, 'updated_at' => $now ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Change a session.
	 *
	 * @param int   $id   Id.
	 * @param array $data Columns.
	 * @return void
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a write.
		$wpdb->update( self::table(), $data + array( 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
	}

	/**
	 * A page of sessions, newest first.
	 *
	 * @param array $filters `{ user_id?, status?, from?, to? }` (dates Y-m-d, on the opening day).
	 * @param int   $page    Page.
	 * @param int   $per     Per page.
	 * @return array{items: array[], total: int}
	 */
	public function page( array $filters, int $page, int $per ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['user_id'] ) ) {
			$where[] = 's.user_id = %d';
			$args[]  = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 's.status = %s';
			$args[]  = (string) $filters['status'];
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[] = 's.opened_at >= %s';
			$args[]  = $filters['from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[] = 's.opened_at <= %s';
			$args[]  = $filters['to'] . ' 23:59:59';
		}
		$where = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where holds fixed conditions with placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i s WHERE $where", array_merge( array( self::table() ), $args ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, u.display_name FROM %i s LEFT JOIN %i u ON u.ID = s.user_id WHERE $where ORDER BY s.opened_at DESC, s.id DESC LIMIT %d OFFSET %d",
				array_merge( array( self::table(), $wpdb->users ), $args, array( $per, ( max( 1, $page ) - 1 ) * $per ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'items' => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * What a user took in, per method, between two moments (site time): the
	 * payments, refunds and voids they recorded, signed as stored (a refund
	 * or a void is negative), so the sum is the money that moved.
	 *
	 * @param int         $user_id User.
	 * @param string      $from    Y-m-d H:i:s, included.
	 * @param string|null $to      Y-m-d H:i:s, excluded; null = now.
	 * @return array<string, array{amount: float, count: int}> Method => totals.
	 */
	public function takings( int $user_id, string $from, ?string $to ): array {
		global $wpdb;
		$to = $to ? $to : current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live totals of an open register.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT method, SUM( amount ) AS amount, SUM( type = 'payment' ) AS count FROM %i
				WHERE recorded_by = %d AND type IN ( 'payment', 'refund', 'void' ) AND created_at >= %s AND created_at <= %s
				GROUP BY method",
				rtbp_table( 'payments' ),
				$user_id,
				$from,
				$to
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['method'] ] = array(
				'amount' => round( (float) $row['amount'], 2 ),
				'count'  => (int) $row['count'],
			);
		}
		return $out;
	}
}
