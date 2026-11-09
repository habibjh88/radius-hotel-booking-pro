<?php
/**
 * Settings → Cash register (Pro, R3-T7).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\CashRegister
 */

namespace RadiusTheme\RadiusHotelBookingPro\CashRegister;

use RadiusTheme\RadiusHotelBooking\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * The `pro_cash_register` section (option `rtbp_pro_cash_register_settings`).
 */
final class CashRegisterSettings {

	const SECTION = 'pro_cash_register';

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
				// Staff must open their register before they record a payment or refund (Q6).
				'requireOpen' => array(
					'type'    => 'bool',
					'default' => false,
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
