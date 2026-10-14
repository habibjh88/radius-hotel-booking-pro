<?php
/**
 * Employee pay profile data access (M15 T4b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_employee_pay`.
 */
class EmployeePayRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = EmployeePay::class;

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_employee_pay';
	}

	/**
	 * An employee's profile, or null.
	 *
	 * @param int $employee_id Pro employee id.
	 * @return EmployeePay|null
	 */
	public function forEmployee( int $employee_id ): ?EmployeePay {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one row, read live.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE employee_id = %d', self::table(), $employee_id ), ARRAY_A );
		return $row ? EmployeePay::hydrate( $row ) : null;
	}

	/**
	 * The profiles on a pay schedule.
	 *
	 * @param int $schedule_id Schedule.
	 * @return EmployeePay[]
	 */
	public function forSchedule( int $schedule_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE pay_schedule_id = %d ORDER BY employee_id', self::table(), $schedule_id ), ARRAY_A );
		return array_map( array( EmployeePay::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Employees per pay schedule or work shift.
	 *
	 * @param string $column `pay_schedule_id` or `work_shift_id`.
	 * @return array<int, int> Id => employees.
	 */
	public function countsBy( string $column ): array {
		if ( ! in_array( $column, array( 'pay_schedule_id', 'work_shift_id' ), true ) ) {
			return array();
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a grouped count, read live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT %i AS id, COUNT(*) AS n FROM %i WHERE %i IS NOT NULL GROUP BY %i', $column, self::table(), $column, $column ), ARRAY_A );
		return array_map( 'intval', array_column( (array) $rows, 'n', 'id' ) );
	}
}
