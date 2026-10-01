<?php
/**
 * Settings → Automatic release (Pro, M05 T8).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payments
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payments;

use RadiusTheme\RadiusHotelBooking\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * The `pro_auto_release` section (option `rtbp_pro_auto_release_settings`).
 * On/off is the feature switch itself (Settings → Pro features); this holds
 * the grace period only.
 */
final class AutoReleaseSettings {

	const SECTION = 'pro_auto_release';

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
				// Hours after the payment deadline before the room is released (0 = at the deadline).
				'graceHours' => array(
					'type'    => 'int',
					'default' => 0,
					'min'     => 0,
					'max'     => 720,
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
}
