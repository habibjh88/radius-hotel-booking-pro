<?php
/**
 * Settings → Activity log (Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\ActivityLog;

use RadiusTheme\RadiusHotelBooking\Settings\SettingsSchema;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The `pro_activity` section (option `rtbp_pro_activity_settings`).
 */
final class ActivitySettings {

	const SECTION = 'pro_activity';

	const ARCHIVE_EVERY = array( 0, 1, 2, 3, 6, 12, 24 );

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
				// Months between automatic archives: 1, 2, 3, 6, 12 or 24; 0 = off (14.11).
				'archiveEvery'      => array(
					'type'     => 'int',
					'default'  => 12,
					'min'      => 0,
					'max'      => 24,
					'sanitize' => array( self::class, 'archive_every' ),
				),
				// Remove archived rows from the database after a verified archive (14.12).
				'purgeAfterArchive' => array(
					'type'    => 'bool',
					'default' => false,
				),
				// Record page views (14.4).
				'logPageViews'      => array(
					'type'    => 'bool',
					'default' => true,
				),
				// Seconds within which repeated page views are one row (14.10; legacy 900).
				'groupWindow'       => array(
					'type'    => 'int',
					'default' => 900,
					'min'     => 0,
					'max'     => 86400,
				),
			)
		);
	}

	/**
	 * One setting, with its default.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Used when the free helper is missing.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		return function_exists( 'rtbp_setting' ) ? rtbp_setting( self::SECTION, $key, $default ) : $default;
	}

	/**
	 * Only the allowed archive intervals.
	 *
	 * @param mixed $value Value.
	 * @return int|WP_Error
	 */
	public static function archive_every( $value ) {
		$value = (int) $value;
		return in_array( $value, self::ARCHIVE_EVERY, true )
			? $value
			: new WP_Error( 'rtbp_invalid_setting', __( 'Choose 1, 2, 3, 6, 12 or 24 months, or off.', 'radius-hotel-booking-pro' ) );
	}
}
