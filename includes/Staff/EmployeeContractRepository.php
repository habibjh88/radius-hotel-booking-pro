<?php
/**
 * Employment contract data access.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_employee_contracts`.
 */
class EmployeeContractRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = EmployeeContract::class;

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_employee_contracts';
	}

	/**
	 * An employee's contracts, the newest start first.
	 *
	 * @param int $employee_id Employee id.
	 * @return EmployeeContract[]
	 */
	public function forEmployee( int $employee_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a short history, read live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE employee_id = %d ORDER BY start_date DESC, id DESC', self::table(), $employee_id ), ARRAY_A );
		return array_map( array( EmployeeContract::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * The active contracts of an employee, other than one.
	 *
	 * @param int $employee_id Employee id.
	 * @param int $except      Contract id to leave out (0 for none).
	 * @return EmployeeContract[]
	 */
	public function activeFor( int $employee_id, int $except = 0 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read inside the write transaction.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE employee_id = %d AND status = 'active' AND id <> %d", self::table(), $employee_id, $except ), ARRAY_A );
		return array_map( array( EmployeeContract::class, 'hydrate' ), (array) $rows );
	}
}
