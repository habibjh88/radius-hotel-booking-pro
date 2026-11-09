<?php
/**
 * Settings → Staff (Pro): the departments and job titles staff files pick from.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * The `pro_staff` section (option `rtbp_pro_staff_settings`): two lists of
 * names. The staff file, the contracts and *Add an employee* offer them; the
 * staff list filters and sorts by them. Files keep the name as text, so a
 * name used before it was listed (an import) still shows and can be added.
 */
final class StaffSettings {

	const SECTION = 'pro_staff';

	/**
	 * Most names in a list.
	 */
	const MAX = 100;

	/**
	 * Register the section on the free schema.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rtbp_settings_schema_init', array( self::class, 'schema' ) );
	}

	/**
	 * `rtbp_settings_schema_init` listener.
	 *
	 * @return void
	 */
	public static function schema(): void {
		if ( ! class_exists( SettingsSchema::class ) ) {
			return;
		}
		SettingsSchema::register(
			self::SECTION,
			array(
				'departments' => array(
					'type'     => 'array',
					'default'  => array(),
					'sanitize' => array( self::class, 'names' ),
				),
				'jobTitles'   => array(
					'type'     => 'array',
					'default'  => array(),
					'sanitize' => array( self::class, 'names' ),
				),
			)
		);
	}

	/**
	 * Clean a list: trimmed names of at most 100 characters, each once
	 * (case-insensitive), in the order given.
	 *
	 * @param mixed $value Raw list.
	 * @return array|\WP_Error
	 */
	public static function names( $value ) {
		$out  = array();
		$seen = array();
		foreach ( (array) $value as $name ) {
			$name = trim( sanitize_text_field( (string) $name ) );
			$key  = mb_strtolower( $name );
			if ( '' === $name || isset( $seen[ $key ] ) ) {
				continue;
			}
			if ( mb_strlen( $name ) > 100 ) {
				/* translators: %s: the name. */
				return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( '"%s" is longer than 100 characters.', 'radius-hotel-booking-pro' ), $name ) );
			}
			$seen[ $key ] = true;
			$out[]        = $name;
		}
		if ( count( $out ) > self::MAX ) {
			/* translators: %d: most names. */
			return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( 'List at most %d names.', 'radius-hotel-booking-pro' ), self::MAX ) );
		}
		return $out;
	}

	/**
	 * A list.
	 *
	 * @param string $key departments|jobTitles.
	 * @return string[]
	 */
	public static function get( string $key ): array {
		$list = function_exists( 'rtbp_setting' ) ? rtbp_setting( self::SECTION, $key, array() ) : array();
		return array_values( array_filter( array_map( 'strval', is_array( $list ) ? $list : array() ) ) );
	}

	/**
	 * The names already on staff files and contracts, per list, sorted.
	 *
	 * @return array{departments: string[], jobTitles: string[]}
	 */
	public static function inUse(): array {
		global $wpdb;
		$out = array();
		foreach ( array(
			'departments' => 'department',
			'jobTitles'   => 'job_title',
		) as $key => $column ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a short list for Settings.
			$names       = (array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT TRIM( %i ) FROM %i WHERE TRIM( %i ) <> %s UNION SELECT DISTINCT TRIM( %i ) FROM %i WHERE TRIM( %i ) <> %s',
					$column,
					rtbp_table_prefix() . 'pro_employees',
					$column,
					'',
					$column,
					rtbp_table_prefix() . 'pro_employee_contracts',
					$column,
					''
				)
			);
			$names       = array_values( array_unique( array_map( 'strval', $names ) ) );
			natcasesort( $names );
			$out[ $key ] = array_values( $names );
		}
		return $out;
	}
}
