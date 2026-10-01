<?php
/**
 * Base class for a Pro feature.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features;

defined( 'ABSPATH' ) || exit;

/**
 * A switchable Pro feature.
 *
 * `boot()` runs only when the feature is enabled in Settings → Pro features and
 * everything it requires is enabled too. A disabled feature registers nothing —
 * no hooks, routes, screens or cron events — so the free plugin behaves exactly
 * as it does without Pro. Its data is kept, and comes back when it is switched
 * on again.
 *
 * To add a feature: create the class named in FeatureRegistry, extend this
 * class, and hook the feature's code in boot(). Tables go through the
 * `rtbp_migration_classes` filter so they exist even while the feature is off.
 */
abstract class AbstractFeature {

	/**
	 * Register the feature's hooks. Called once, on `rtbp_loaded`.
	 *
	 * @return void
	 */
	abstract public function boot(): void;

	/**
	 * Free-plugin symbols the feature needs (functions or classes). If any is
	 * missing, the feature does not boot and is reported as needing a newer
	 * free plugin, rather than fataling.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array();
	}

	/**
	 * Called when the feature is switched off, e.g. to unschedule its cron
	 * events. Must not delete data.
	 *
	 * @return void
	 */
	public function on_disable(): void {}

	/**
	 * Whether every free-plugin symbol the feature needs is present.
	 *
	 * @return bool
	 */
	final public function free_symbols_present(): bool {
		foreach ( $this->requires_free() as $symbol ) {
			// Functions, classes and interfaces (an add-on may implement a free interface).
			if ( ! function_exists( $symbol ) && ! class_exists( $symbol ) && ! interface_exists( $symbol ) ) {
				return false;
			}
		}
		return true;
	}
}
