<?php
/**
 * Automatic release of unpaid bookings (M05 T8).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Payments
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Payments;

use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;
use RadiusTheme\RadiusHotelBookingPro\Payments\AutoReleaseJob;
use RadiusTheme\RadiusHotelBookingPro\Payments\AutoReleaseSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `auto_release` (off by default, D6): a 15-minute job releases
 * bookings left unpaid past their deadline plus the grace period, and
 * Settings → Automatic release holds that grace period. Switched off, the
 * job is unscheduled and the desk releases by hand (free, M05 T6).
 */
final class AutoReleaseFeature extends AbstractFeature {

	/**
	 * The job.
	 */
	const HOOK = 'rtbp_pro_auto_release';

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_setting',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Booking\\BookingStatusService',
			'RadiusTheme\\RadiusHotelBooking\\Repositories\\BookingRepository',
			'RadiusTheme\\RadiusHotelBooking\\Settings\\SettingsSchema',
			'RadiusTheme\\RadiusHotelBooking\\Exceptions\\DomainException',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Dates',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		AutoReleaseSettings::register();
		add_filter( 'rtbp_scheduled_events', array( $this, 'events' ) );
		// Saved with the payment settings' key, and audited here (free skips sections it does not guard).
		add_filter( 'rtbp_settings_access_key', array( $this, 'settings_key' ), 10, 2 );
		add_action( 'rtbp_settings_updated', array( $this, 'audit_settings' ), 10, 3 );
	}

	/**
	 * Unschedule the job (the free Scheduler also drops jobs no longer registered).
	 *
	 * @return void
	 */
	public function on_disable(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * `rtbp_scheduled_events`: run every 15 minutes.
	 *
	 * @param array $events Hook => job.
	 * @return array
	 */
	public function events( $events ): array {
		$events               = (array) $events;
		$events[ self::HOOK ] = array(
			'recurrence' => 'rtbp_fifteen_minutes',
			'callback'   => static function () {
				AutoReleaseJob::run();
			},
		);
		return $events;
	}

	/**
	 * `rtbp_settings_access_key`: the section is saved with `settings.payments`.
	 *
	 * @param string $key     Access key.
	 * @param string $section Section.
	 * @return string
	 */
	public function settings_key( $key, $section ): string {
		return AutoReleaseSettings::SECTION === $section ? 'settings.payments' : (string) $key;
	}

	/**
	 * `rtbp_settings_updated`: record a change of the grace period.
	 *
	 * @param string $section Section.
	 * @param mixed  $before  Values before.
	 * @param mixed  $after   Values after.
	 * @return void
	 */
	public function audit_settings( $section, $before, $after ): void {
		if ( AutoReleaseSettings::SECTION !== $section || $before == $after || ! function_exists( 'rtbp_activity' ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- same values, any key order.
			return;
		}
		rtbp_activity(
			'settings.updated',
			array(
				'type'  => 'settings',
				'id'    => AutoReleaseSettings::SECTION,
				'label' => __( 'Automatic release', 'radius-hotel-booking-pro' ),
			),
			array(
				'before' => (array) $before,
				'after'  => (array) $after,
			)
		);
	}
}
