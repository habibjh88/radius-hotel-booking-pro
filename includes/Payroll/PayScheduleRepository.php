<?php
/**
 * Pay schedule data access (M15 T3a).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_pay_schedules`.
 */
class PayScheduleRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = PaySchedule::class;

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_pay_schedules';
	}

	/**
	 * Every schedule, by name.
	 *
	 * @return PaySchedule[]
	 */
	public function byName(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a handful of rows, read live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name, id', self::table() ), ARRAY_A );
		return array_map( array( PaySchedule::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * Whether another schedule has this name (the column's collation ignores case).
	 *
	 * @param string $name   Name.
	 * @param int    $except Schedule being edited (0 for none).
	 * @return bool
	 */
	public function nameTaken( string $name, int $except = 0 ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- checked right before the write.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE name = %s AND id <> %d LIMIT 1', self::table(), $name, $except ) );
	}
}
