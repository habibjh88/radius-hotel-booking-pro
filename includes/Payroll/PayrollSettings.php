<?php
/**
 * Settings → Payroll (M15, 15.15 / 17.3).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\ActivityLog\ChangeDiff;
use RadiusTheme\RadiusHotelBooking\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

/**
 * The `payroll` section (option `rtbp_payroll_settings`): the yearly
 * vacation quota and, for the built-in calculator (RuleCalculator), the
 * hotel's social contributions and income tax brackets. A country add-on
 * that brings its own calculator ignores those two. The business details on
 * payslips are the free Settings → General ones (15.21).
 *
 * Saved with `payroll.tax_settings`, not the generic `settings.payroll`; the
 * free log skips sections with their own key, so a save is logged here.
 */
final class PayrollSettings {

	public const SECTION = 'payroll';

	/**
	 * Most contributions a hotel can list, and most tax brackets.
	 */
	public const MAX_ROWS = 20;

	public const ACCESS_KEY = 'payroll.tax_settings';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rtbp_settings_schema_init', array( self::class, 'schema' ) );
		add_filter( 'rtbp_settings_access_key', array( self::class, 'accessKey' ), 10, 2 );
		add_action( 'rtbp_settings_updated', array( self::class, 'log' ), 10, 3 );
	}

	/**
	 * `rtbp_settings_updated`: log a change of this section (the keys that changed).
	 *
	 * @param string $section Section.
	 * @param mixed  $before  Values before.
	 * @param mixed  $after   Values after.
	 * @return void
	 */
	public static function log( $section, $before, $after ): void {
		if ( self::SECTION !== $section || ! function_exists( 'rtbp_activity' ) || ! class_exists( ChangeDiff::class ) ) {
			return;
		}
		$diff = ChangeDiff::between( (array) $before, (array) $after );
		if ( ! $diff['before'] && ! $diff['after'] ) {
			return;
		}
		rtbp_activity(
			self::ACCESS_KEY,
			array(
				'type'  => 'settings',
				'id'    => self::SECTION,
				'label' => __( 'Payroll settings', 'radius-hotel-booking-pro' ),
			),
			$diff
		);
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
				// Vacation days a year per employee for `day_off` requests; 0 = unlimited (15.20, 17.3).
				'vacationDaysYear' => array(
					'type'    => 'int',
					'default' => 0,
					'min'     => 0,
					'max'     => 366,
				),
				// Built-in calculator: `{ code, label, authority, side (employee|employer), rate (%), cap (monthly, 0 = none) }`.
				'contributions'    => array(
					'type'     => 'array',
					'default'  => array(),
					'sanitize' => array( self::class, 'contributions' ),
				),
				// Built-in calculator: income tax withheld from pay; off = none.
				'incomeTax'        => array(
					'type'    => 'bool',
					'default' => false,
				),
				'incomeTaxLabel'   => array(
					'type'      => 'string',
					'default'   => '',
					'maxLength' => 60,
				),
				// Who the income tax is paid to (also the tax on one-off additions).
				'taxAuthority'     => array(
					'type'      => 'string',
					'default'   => '',
					'maxLength' => 40,
				),
				// `progressive` (each slice at its rate) or `flat` (the bracket's rate on the whole).
				'taxMode'          => array(
					'type'    => 'enum',
					'options' => array( 'progressive', 'flat' ),
					'default' => 'progressive',
				),
				// Whether employee contributions come off before the tax.
				'taxAfterContributions' => array(
					'type'    => 'bool',
					'default' => true,
				),
				// `{ upTo (monthly; empty = and above), rate (%) }`, ascending.
				'taxBrackets'      => array(
					'type'     => 'array',
					'default'  => array(),
					'sanitize' => array( self::class, 'brackets' ),
				),
			)
		);
	}

	/**
	 * Clean the contributions list.
	 *
	 * @param mixed $value Raw list.
	 * @return array|\WP_Error
	 */
	public static function contributions( $value ) {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_ROWS ) {
			/* translators: %d: most rows. */
			return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( 'List at most %d contributions.', 'radius-hotel-booking-pro' ), self::MAX_ROWS ) );
		}
		$clean = array();
		$codes = array();
		foreach ( array_values( $value ) as $index => $row ) {
			$row   = is_array( $row ) ? $row : array();
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			if ( '' === $label || mb_strlen( $label ) > 60 ) {
				/* translators: %d: position in the list. */
				return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( 'Contribution %d needs a name of at most 60 characters.', 'radius-hotel-booking-pro' ), $index + 1 ) );
			}
			$rate = (float) ( $row['rate'] ?? 0 );
			$cap  = (float) ( $row['cap'] ?? 0 );
			if ( $rate < 0 || $rate > 100 || $cap < 0 ) {
				/* translators: %s: contribution name. */
				return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( '"%s": the rate must be 0–100 %% and the ceiling 0 or more.', 'radius-hotel-booking-pro' ), $label ) );
			}
			// The code groups both shares of one contribution in the remittances: made from the name when empty.
			$raw  = '' !== trim( (string) ( $row['code'] ?? '' ) ) ? (string) $row['code'] : remove_accents( $label );
			$code = trim( strtoupper( preg_replace( '/[^A-Za-z0-9_]+/', '_', $raw ) ), '_' );
			$code = '' !== $code ? substr( $code, 0, 30 ) : 'C' . ( $index + 1 );
			$side = 'employer' === ( $row['side'] ?? '' ) ? 'employer' : 'employee';
			if ( isset( $codes[ $code . $side ] ) ) {
				/* translators: %s: contribution name. */
				return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( '"%s" is listed twice for the same side.', 'radius-hotel-booking-pro' ), $label ) );
			}
			$codes[ $code . $side ] = true;
			$clean[]                = array(
				'code'      => $code,
				'label'     => $label,
				'authority' => mb_substr( sanitize_text_field( (string) ( $row['authority'] ?? '' ) ), 0, 40 ),
				'side'      => $side,
				'rate'      => round( $rate, 4 ),
				'cap'       => round( $cap, 2 ),
			);
		}
		return $clean;
	}

	/**
	 * Clean the tax brackets: ascending limits, the last one may be open.
	 *
	 * @param mixed $value Raw list.
	 * @return array|\WP_Error
	 */
	public static function brackets( $value ) {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_ROWS ) {
			/* translators: %d: most rows. */
			return new \WP_Error( 'rtbp_invalid_setting', sprintf( __( 'List at most %d tax brackets.', 'radius-hotel-booking-pro' ), self::MAX_ROWS ) );
		}
		$clean = array();
		$last  = -1.0;
		$rows  = array_values( $value );
		foreach ( $rows as $index => $row ) {
			$row   = is_array( $row ) ? $row : array();
			$up_to = isset( $row['upTo'] ) && '' !== (string) $row['upTo'] ? (float) $row['upTo'] : null;
			$rate  = (float) ( $row['rate'] ?? 0 );
			if ( $rate < 0 || $rate > 100 ) {
				return new \WP_Error( 'rtbp_invalid_setting', __( 'Each tax rate must be between 0 and 100 %.', 'radius-hotel-booking-pro' ) );
			}
			if ( null === $up_to && $index < count( $rows ) - 1 ) {
				return new \WP_Error( 'rtbp_invalid_setting', __( 'Only the last tax bracket can have no upper limit.', 'radius-hotel-booking-pro' ) );
			}
			if ( null !== $up_to && count( $rows ) - 1 === $index ) {
				return new \WP_Error( 'rtbp_invalid_setting', __( 'Leave the last tax bracket without an upper limit, so higher pay is taxed too.', 'radius-hotel-booking-pro' ) );
			}
			if ( null !== $up_to && $up_to <= $last ) {
				return new \WP_Error( 'rtbp_invalid_setting', __( 'Tax brackets must go up: each limit higher than the one before.', 'radius-hotel-booking-pro' ) );
			}
			$last    = null === $up_to ? $last : $up_to;
			$clean[] = array(
				'upTo' => null === $up_to ? '' : round( $up_to, 2 ),
				'rate' => round( $rate, 4 ),
			);
		}
		return $clean;
	}

	/**
	 * `rtbp_settings_access_key`: this section is saved with `payroll.tax_settings`.
	 *
	 * @param string $key     Key.
	 * @param string $section Section.
	 * @return string
	 */
	public static function accessKey( $key, $section ): string {
		return self::SECTION === $section ? self::ACCESS_KEY : (string) $key;
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
