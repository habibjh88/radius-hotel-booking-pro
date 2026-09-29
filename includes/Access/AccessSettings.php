<?php
/**
 * Settings → Passcodes (Pro): passcode validity, scope, locked message, fallback
 * PIN switch, profile-field defaults.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * The `pro_access` settings section (option `rtbp_pro_access_settings`),
 * registered on the free plugin's schema (M13 features 13.7–13.10, 17.4).
 *
 * The fallback PIN's hash is not part of the section: it lives in its own
 * option (FALLBACK_OPTION), so it never travels through the settings API.
 * It is set with `POST access/fallback-pin`.
 */
final class AccessSettings {

	const SECTION = 'pro_access';

	const FALLBACK_OPTION = 'rtbp_pro_access_fallback_hash';

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
				// Minutes a correct PIN stays valid.
				'passcodeValidity' => array(
					'type'    => 'int',
					'default' => 5,
					'min'     => 1,
					'max'     => 240,
				),
				// `global`: one PIN covers everything until it expires; `per_key`: each page or action asks again.
				'passcodeScope'    => array(
					'type'    => 'enum',
					'default' => 'global',
					'options' => array( 'global', 'per_key' ),
				),
				// Shown when something is locked; empty = the built-in message.
				'lockedMessage'    => array(
					'type'      => 'text',
					'default'   => '',
					'maxLength' => 300,
				),
				// Staff without a personal PIN (and administrators) may use the fallback PIN.
				'fallbackEnabled'  => array(
					'type'    => 'bool',
					'default' => true,
				),
				// Profile fields staff may change themselves: field => allow | restrict (partial; code defaults fill the rest). M16 enforces it.
				'profileFields'    => array(
					'type'     => 'array',
					'default'  => array(),
					'sanitize' => array( ProfileFields::class, 'sanitize_setting' ),
				),
			)
		);
	}

	/**
	 * One setting, with its default applied.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Used when the free helper is missing.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		return function_exists( 'rtbp_setting' ) ? rtbp_setting( self::SECTION, $key, $default ) : $default;
	}

	/**
	 * Passcode validity in seconds.
	 *
	 * @return int
	 */
	public static function validity_seconds(): int {
		return max( 1, (int) self::get( 'passcodeValidity', 5 ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * Whether one unlock covers every key.
	 *
	 * @return bool
	 */
	public static function global_scope(): bool {
		return 'per_key' !== self::get( 'passcodeScope', 'global' );
	}
}
