<?php
/**
 * Employee deductions and garnishments data access (M15 T4d).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_employee_deductions`.
 */
class EmployeeDeductionRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = EmployeeDeduction::class;

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_employee_deductions';
	}

	/**
	 * An employee's deductions and garnishments: deductions first, then by id.
	 *
	 * @param int $employee_id Pro employee id.
	 * @return EmployeeDeduction[]
	 */
	public function forEmployee( int $employee_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a few rows, read live.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE employee_id = %d ORDER BY kind = 'garnishment', id", self::table(), $employee_id ), ARRAY_A );
		return array_map( array( EmployeeDeduction::class, 'hydrate' ), (array) $rows );
	}
}
