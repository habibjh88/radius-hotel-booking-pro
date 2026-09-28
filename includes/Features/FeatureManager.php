<?php
/**
 * Decides which Pro features run, and boots them.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features;

use RadiusTheme\RadiusHotelBookingPro\Settings\FeatureSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves each feature's state and boots the enabled ones.
 *
 * States:
 * - `active`        enabled, built, requirements met — booted
 * - `disabled`      switched off in Settings → Pro features
 * - `blocked`       enabled, but a feature it requires is off
 * - `needs_free`    enabled, but the free plugin lacks a symbol it needs
 * - `in_development` its module has not been built yet (no class)
 */
final class FeatureManager {

	/**
	 * Booted feature instances, by key.
	 *
	 * @var array<string,AbstractFeature>
	 */
	private array $booted = array();

	/**
	 * Boot every feature whose state resolves to `active`.
	 *
	 * @return void
	 */
	public function boot(): void {
		foreach ( array_keys( FeatureRegistry::definitions() ) as $key ) {
			if ( 'active' !== $this->state( $key ) ) {
				continue;
			}

			$class = FeatureRegistry::definitions()[ $key ]['class'];
			$this->booted[ $key ] = new $class();
			$this->booted[ $key ]->boot();
		}

		add_action( 'rtbp_pro_features_changed', array( $this, 'handle_disabled' ), 10, 2 );
	}

	/**
	 * Resolve one feature's state.
	 *
	 * @param string $key Feature key.
	 * @return string
	 */
	public function state( string $key ): string {
		$features = FeatureRegistry::definitions();
		if ( ! isset( $features[ $key ] ) ) {
			return 'disabled';
		}

		if ( ! FeatureRegistry::is_built( $key ) ) {
			return 'in_development';
		}

		if ( ! self::is_enabled( $key ) ) {
			return 'disabled';
		}

		foreach ( $features[ $key ]['requires'] as $required ) {
			if ( ! self::is_enabled( $required ) || ! FeatureRegistry::is_built( $required ) ) {
				return 'blocked';
			}
		}

		$class = $features[ $key ]['class'];
		if ( ! ( new $class() )->free_symbols_present() ) {
			return 'needs_free';
		}

		return 'active';
	}

	/**
	 * Whether the admin has the feature switched on (ignores build state).
	 *
	 * @param string $key Feature key.
	 * @return bool
	 */
	public static function is_enabled( string $key ): bool {
		$values = FeatureSettings::values();
		return ! empty( $values[ $key ] );
	}

	/**
	 * Whether a feature is actually running in this request.
	 *
	 * @param string $key Feature key.
	 * @return bool
	 */
	public function is_active( string $key ): bool {
		return isset( $this->booted[ $key ] );
	}

	/**
	 * Every feature with its state, for the settings screen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function describe(): array {
		$out = array();
		foreach ( FeatureRegistry::all() as $key => $feature ) {
			$out[] = array(
				'key'         => $key,
				'label'       => $feature['label'],
				'description' => $feature['description'],
				'group'       => $feature['group'],
				'module'      => $feature['module'],
				'requires'    => array_values( $feature['requires'] ),
				'state'       => $this->state( $key ),
			);
		}
		return $out;
	}

	/**
	 * Let features that were just switched off clean up (cron, transients).
	 * They were booted in this request, so their instances exist.
	 *
	 * @param array $before Previous on/off values.
	 * @param array $after  New on/off values.
	 * @return void
	 */
	public function handle_disabled( array $before, array $after ): void {
		foreach ( $this->booted as $key => $feature ) {
			if ( ! empty( $before[ $key ] ) && empty( $after[ $key ] ) ) {
				$feature->on_disable();
			}
		}
	}
}
