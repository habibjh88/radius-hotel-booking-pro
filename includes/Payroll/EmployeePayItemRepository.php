<?php
/**
 * Employee additional pay data access (M15 T4c).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_employee_pay_items` (rows are plain: type + JSON settings).
 */
class EmployeePayItemRepository {

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_employee_pay_items';
	}

	/**
	 * An employee's additional pay types: type => settings.
	 *
	 * @param int $employee_id Pro employee id.
	 * @return array<string, array>
	 */
	public function forEmployee( int $employee_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a few rows, read live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT type, settings FROM %i WHERE employee_id = %d ORDER BY id', self::table(), $employee_id ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$settings              = json_decode( (string) $row['settings'], true );
			$out[ $row['type'] ] = is_array( $settings ) ? $settings : array();
		}
		return $out;
	}

	/**
	 * Replace an employee's additional pay types.
	 *
	 * @param int                  $employee_id Pro employee id.
	 * @param array<string, array> $items       Type => settings.
	 * @return void
	 */
	public function replace( int $employee_id, array $items ): void {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the employee's own rows, rewritten in one transaction.
		$wpdb->delete( self::table(), array( 'employee_id' => $employee_id ), array( '%d' ) );
		foreach ( $items as $type => $settings ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plain insert.
			$wpdb->insert(
				self::table(),
				array(
					'employee_id' => $employee_id,
					'type'        => (string) $type,
					'settings'    => wp_json_encode( $settings ),
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}
}
