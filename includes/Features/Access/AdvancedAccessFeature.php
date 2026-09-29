<?php
/**
 * Advanced staff access (M13).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Access;

use RadiusTheme\RadiusHotelBookingPro\Access\AccessRoleService;
use RadiusTheme\RadiusHotelBookingPro\Access\AccessRolesController;
use RadiusTheme\RadiusHotelBookingPro\Access\AccessSettings;
use RadiusTheme\RadiusHotelBookingPro\Access\PasscodeController;
use RadiusTheme\RadiusHotelBookingPro\Access\PasscodeService;
use RadiusTheme\RadiusHotelBookingPro\Access\UserAccessController;
use RadiusTheme\RadiusHotelBookingPro\Access\UserAccessService;
use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;
use RadiusTheme\RadiusHotelBookingPro\Settings\FeatureSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `advanced_access`: the passcode level on top of the free open/locked
 * access map (T4a: PINs, fallback PIN, unlock, grants, settings), custom
 * access roles and per-person overrides (T5a). Profile fields come in T6.
 */
final class AdvancedAccessFeature extends AbstractFeature {

	/**
	 * Keys the plan protects with a passcode by default (M13 "Access key
	 * registry"). The free plugin defaults them to `open`.
	 */
	const PASSCODE_DEFAULTS = array(
		'page.reports_sales',
		'bookings.cancel',
		'bookings.line_edit',
		'bookings.line_remove',
		'bookings.note_remove',
		'payments.change_status',
		'invoices.regenerate',
		'guests.ban',
		'guests.note_remove',
		'reports.export',
	);

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'RadiusTheme\\RadiusHotelBooking\\Access\\Access',
			'RadiusTheme\\RadiusHotelBooking\\Access\\AccessRegistry',
			'RadiusTheme\\RadiusHotelBooking\\Core\\Api\\Middleware\\AccessMiddleware',
			'RadiusTheme\\RadiusHotelBooking\\Settings\\SettingsSchema',
			'RadiusTheme\\RadiusHotelBooking\\Abstracts\\BaseRepository',
			'RadiusTheme\\RadiusHotelBooking\\Exceptions\\DomainException',
			'rtbp_setting',
		);
	}

	/**
	 * Hook in (only while the feature is switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		AccessSettings::register();

		add_filter( 'rtbp_access_keys', array( $this, 'keys' ) );
		add_filter( 'rtbp_access_passcode_check', array( PasscodeService::class, 'check' ), 10, 3 );
		add_filter( 'rtbp_access_locked_message', array( $this, 'locked_message' ) );
		add_action( 'rtbp_register_addon_routes', array( PasscodeController::class, 'routes' ) );
		add_action( 'rtbp_register_addon_routes', array( AccessRolesController::class, 'routes' ) );
		add_action( 'rtbp_register_addon_routes', array( UserAccessController::class, 'routes' ) );

		// Custom access roles, then per-person overrides (T5a).
		add_filter( 'rtbp_access_level', array( UserAccessService::class, 'filter_level' ), 10, 3 );

		// The free permission matrix may store passcode too (T5b).
		add_filter( 'rtbp_access_role_levels', array( $this, 'role_levels' ) );

		// Passcode settings and the Pro feature switches change permissions:
		// guard them with access.manage and audit them (review finding 1).
		add_filter( 'rtbp_settings_access_key', array( $this, 'settings_key' ), 10, 2 );
		add_filter( 'rtbp_access_role_defaults', array( $this, 'manager_defaults' ) );
		add_action( 'rtbp_settings_updated', array( $this, 'audit_settings' ), 10, 3 );
	}

	/**
	 * Raise the plan's passcode defaults and add the reset key.
	 *
	 * @param array $keys Key => definition.
	 * @return array
	 */
	public function keys( $keys ): array {
		$keys = (array) $keys;
		foreach ( self::PASSCODE_DEFAULTS as $key ) {
			if ( isset( $keys[ $key ] ) && is_array( $keys[ $key ] ) ) {
				$keys[ $key ]['default'] = 'passcode';
			}
		}
		$keys['access.reset_overrides'] = array(
			'default' => 'passcode',
			'group'   => 'settings',
			'label'   => __( 'Reset personal permission overrides', 'radius-hotel-booking-pro' ),
		);
		return $keys;
	}

	/**
	 * `rtbp_access_role_defaults`: the built-in Manager role (all open in the
	 * free plugin) confirms permission changes and voids with a PIN, like the
	 * Manager template.
	 *
	 * @param array $defaults Role => key => level.
	 * @return array
	 */
	public function manager_defaults( $defaults ): array {
		$defaults = (array) $defaults;
		foreach ( array( 'access.manage', 'access.reset_overrides', 'payments.void' ) as $key ) {
			$defaults['rtbp_manager'][ $key ] = 'passcode';
		}
		return $defaults;
	}

	/**
	 * `rtbp_settings_access_key`: the security sections need `access.manage`.
	 *
	 * @param string $key     Access key.
	 * @param string $section Section key.
	 * @return string
	 */
	public function settings_key( $key, $section ): string {
		return in_array( $section, array( AccessSettings::SECTION, FeatureSettings::SECTION ), true ) ? 'access.manage' : (string) $key;
	}

	/**
	 * `rtbp_settings_updated`: a change to the passcode settings is a
	 * permission change (`permission.changed`, scope `passcode`).
	 *
	 * @param string $section Section key.
	 * @param mixed  $before  Values before.
	 * @param mixed  $after   Values after.
	 * @return void
	 */
	public function audit_settings( $section, $before, $after ): void {
		if ( AccessSettings::SECTION !== $section ) {
			return;
		}
		$diff = AccessRoleService::diff( self::flat( (array) $before ), self::flat( (array) $after ) );
		if ( ! $diff['before'] ) {
			return;
		}

		/** This action is documented in the free plugin's includes/Access/AccessAudit.php */
		do_action( 'rtbp_access_changed', 'passcode', $diff, get_current_user_id() );

		if ( function_exists( 'rtbp_activity' ) ) {
			rtbp_activity(
				'permission.changed',
				null,
				$diff + array( 'description' => __( 'Changed the passcode settings', 'radius-hotel-booking-pro' ) )
			);
		}
	}

	/**
	 * One level of a settings array, nested maps as `key.sub`.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	private static function flat( array $values ): array {
		$out = array();
		foreach ( $values as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $sub => $inner ) {
					$out[ $key . '.' . $sub ] = is_scalar( $inner ) ? $inner : wp_json_encode( $inner );
				}
			} else {
				$out[ $key ] = is_bool( $value ) ? ( $value ? 'on' : 'off' ) : $value;
			}
		}
		return $out;
	}

	/**
	 * `rtbp_access_role_levels`: allow passcode in the free matrix.
	 *
	 * @param array $levels Levels.
	 * @return array
	 */
	public function role_levels( $levels ): array {
		return array_values( array_unique( array_merge( (array) $levels, array( 'passcode' ) ) ) );
	}

	/**
	 * Settings → Access: the custom locked message, when set.
	 *
	 * @param string $message Built-in message.
	 * @return string
	 */
	public function locked_message( $message ): string {
		$custom = trim( (string) AccessSettings::get( 'lockedMessage', '' ) );
		return '' !== $custom ? $custom : (string) $message;
	}
}
