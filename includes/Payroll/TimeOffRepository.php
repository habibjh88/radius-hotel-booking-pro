<?php
/**
 * Time off data access (M15 T8a).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_time_off` (rows as arrays: no model needed).
 */
class TimeOffRepository extends BaseRepository {

	/**
	 * Model class (the base repository wants one; reads here are plain rows).
	 *
	 * @var string
	 */
	protected string $model = PayrollRun::class;

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_time_off';
	}

	/**
	 * Lock an employee (Pro file) for the rest of the transaction: one decision on their quota at a time.
	 *
	 * @param int $employee_id Employee.
	 * @return bool
	 */
	public function lockEmployee( int $employee_id ): bool {
		return $this->lockRow( 'pro_employees', $employee_id );
	}

	/**
	 * One request, or null.
	 *
	 * @param int  $id     Id.
	 * @param bool $lock   Lock it (in a transaction).
	 * @return array|null
	 */
	public function one( int $id, bool $lock = false ): ?array {
		global $wpdb;
		if ( $lock ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- locked for the transaction.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d FOR UPDATE', self::table(), $id ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read live.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		}
		return $row ? $row : null;
	}

	/**
	 * Requests, pending first, then newest start.
	 *
	 * @param array $filters `{ status?, employee_id?, year? }`.
	 * @param int   $limit   Rows.
	 * @return array[]
	 */
	public function search( array $filters, int $limit = 200 ): array {
		global $wpdb;
		$status   = (string) ( $filters['status'] ?? '' );
		$employee = (int) ( $filters['employee_id'] ?? 0 );
		$year     = (int) ( $filters['year'] ?? 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a filtered list, read live.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE ( %s = '' OR status = %s ) AND ( %d = 0 OR employee_id = %d ) AND ( %d = 0 OR YEAR( start_date ) = %d ) ORDER BY status = 'pending' DESC, start_date DESC, id DESC LIMIT %d", self::table(), $status, $status, $employee, $employee, $year, $year, $limit ), ARRAY_A );
	}

	/**
	 * Approved days per type for an employee in a year (by the request's start).
	 *
	 * @param int $employee_id Employee.
	 * @param int $year        Year.
	 * @return array<string, int>
	 */
	public function approvedByType( int $employee_id, int $year ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a sum, read live.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT type, SUM( days ) AS days FROM %i WHERE employee_id = %d AND status = 'approved' AND YEAR( start_date ) = %d GROUP BY type", self::table(), $employee_id, $year ), ARRAY_A );
		return array_map( 'intval', array_column( $rows, 'days', 'type' ) );
	}

	/**
	 * A pending or approved request of the employee that overlaps the dates (another than one).
	 *
	 * @param int    $employee_id Employee.
	 * @param string $start       Y-m-d.
	 * @param string $end         Y-m-d.
	 * @param int    $except      Request to leave out.
	 * @return array|null
	 */
	public function overlapping( int $employee_id, string $start, string $end, int $except = 0 ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read under the employee lock.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE employee_id = %d AND status IN ( 'pending', 'approved' ) AND id <> %d AND start_date <= %s AND end_date >= %s LIMIT 1", self::table(), $employee_id, $except, $end, $start ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Write a request as it was (the legacy import: history, no checks).
	 *
	 * @param array    $row Columns.
	 * @param int|null $id  Replace this request (an import update); null = insert.
	 * @return int Id.
	 */
	public function put( array $row, ?int $id = null ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- plain writes of imported rows.
		if ( $id ) {
			$wpdb->update( self::table(), $row + array( 'updated_at' => $now ), array( 'id' => $id ) );
			// phpcs:enable
			return $id;
		}
		$wpdb->insert(
			self::table(),
			$row + array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		// phpcs:enable
		return (int) $wpdb->insert_id;
	}
}
