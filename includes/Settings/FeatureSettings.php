<?php
/**
 * The "Pro features" settings section.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Settings
 */

namespace RadiusTheme\RadiusHotelBookingPro\Settings;

use RadiusTheme\RadiusHotelBookingPro\Features\FeatureRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the on/off switch of every Pro feature through the free plugin's
 * settings system, so the switches are managed from the free plugin's Settings
 * screen like any other section:
 *
 * - The section `pro_features` is added with the `rtbp_settings` filter, which
 *   gives it defaults and the free endpoints `GET/PUT settings/pro_features`
 *   (capability `rtbp_manage_settings`).
 * - The value is stored in `rtbp_pro_features_settings`, and sanitised here
 *   whatever writes it: unknown keys are dropped, values become booleans, and
 *   a feature whose requirement is off is switched off too.
 * - Every change fires `rtbp_pro_features_changed` and, when the free plugin
 *   has it, an `rtbp_activity` event with before/after values.
 */
final class FeatureSettings {

	/**
	 * Settings section key (free plugin convention: option `rtbp_<key>_settings`).
	 */
	const SECTION = 'pro_features';

	/**
	 * Option name.
	 */
	const OPTION = 'rtbp_pro_features_settings';

	/**
	 * Hook the section into the free plugin.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'rtbp_settings', array( $this, 'add_section' ) );
		add_filter( 'pre_update_option_' . self::OPTION, array( $this, 'sanitize' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION, array( $this, 'on_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, array( $this, 'on_updated' ), 10, 2 );
	}

	/**
	 * Add the section and its defaults to the free plugin's settings.
	 *
	 * @param array $sections Section key => defaults.
	 * @return array
	 */
	public function add_section( $sections ): array {
		$sections                  = (array) $sections;
		$sections[ self::SECTION ] = FeatureRegistry::defaults();
		return $sections;
	}

	/**
	 * Current on/off values: saved values over defaults, known keys only.
	 *
	 * @return array<string,bool>
	 */
	public static function values(): array {
		$saved = get_option( self::OPTION, array() );
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Sanitise whatever is about to be saved.
	 *
	 * @param mixed $value     New value.
	 * @param mixed $old_value Old value.
	 * @return array<string,bool>
	 */
	public function sanitize( $value, $old_value ): array {
		unset( $old_value );
		return self::clean( is_array( $value ) ? $value : array() );
	}

	/**
	 * Normalise a raw value: defaults for missing keys, booleans, known keys
	 * only, and requirements enforced (repeated until stable, so chains work).
	 *
	 * @param array $raw Raw values.
	 * @return array<string,bool>
	 */
	private static function clean( array $raw ): array {
		$features = FeatureRegistry::definitions();
		$values   = array();

		foreach ( FeatureRegistry::defaults() as $key => $default ) {
			$values[ $key ] = array_key_exists( $key, $raw )
				? filter_var( $raw[ $key ], FILTER_VALIDATE_BOOLEAN )
				: $default;
		}

		do {
			$changed = false;
			foreach ( $values as $key => $enabled ) {
				if ( ! $enabled ) {
					continue;
				}
				foreach ( $features[ $key ]['requires'] ?? array() as $required ) {
					if ( empty( $values[ $required ] ) ) {
						$values[ $key ] = false;
						$changed        = true;
						break;
					}
				}
			}
		} while ( $changed );

		return $values;
	}

	/**
	 * First save of the option.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Saved value.
	 * @return void
	 */
	public function on_added( $option, $value ): void {
		unset( $option );
		$this->changed( self::clean( array() ), (array) $value );
	}

	/**
	 * Later saves of the option.
	 *
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     Saved value.
	 * @return void
	 */
	public function on_updated( $old_value, $value ): void {
		$this->changed( self::clean( (array) $old_value ), (array) $value );
	}

	/**
	 * Announce a change.
	 *
	 * @param array $before Previous values.
	 * @param array $after  New values.
	 * @return void
	 */
	private function changed( array $before, array $after ): void {
		$diff_before = array();
		$diff_after  = array();
		foreach ( $after as $key => $enabled ) {
			if ( ( $before[ $key ] ?? null ) !== $enabled ) {
				$diff_before[ $key ] = $before[ $key ] ?? null;
				$diff_after[ $key ]  = $enabled;
			}
		}

		if ( ! $diff_after ) {
			return;
		}

		/**
		 * Fires when Pro features are switched on or off.
		 *
		 * @param array $before Full previous on/off map.
		 * @param array $after  Full new on/off map.
		 */
		do_action( 'rtbp_pro_features_changed', $before, $after );

		// The free plugin's activity emitter arrives in M14 (free); guard it.
		if ( function_exists( 'rtbp_activity' ) ) {
			rtbp_activity(
				'settings.pro_features',
				null,
				array(
					'before'      => $diff_before,
					'after'       => $diff_after,
					'description' => __( 'Changed the Pro features that are switched on.', 'radius-hotel-booking-pro' ),
				)
			);
		}
	}
}
