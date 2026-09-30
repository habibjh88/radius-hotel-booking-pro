<?php
/**
 * The iCal calendar sync (M08).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Ical
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Ical;

use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;
use RadiusTheme\RadiusHotelBookingPro\Ical\IcalController;
use RadiusTheme\RadiusHotelBookingPro\Ical\IcalImportService;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `ical_sync`: each room publishes its calendar at a secret link
 * (T6a); channel calendars are imported as blocks (T6b). Switched off, the
 * routes are gone, so published links answer 404; the feeds are kept and
 * work again when it is switched back on.
 */
final class IcalSyncFeature extends AbstractFeature {

	/**
	 * The sync job.
	 */
	const HOOK = 'rtbp_pro_ical_sync';

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_activity',
			'rtbp_table',
			'rtbp_table_prefix',
			'rtbp_setting',
			'RadiusTheme\\RadiusHotelBooking\\Repositories\\BlockRepository',
			'RadiusTheme\\RadiusHotelBooking\\Repositories\\AvailabilityRepository',
			'RadiusTheme\\RadiusHotelBooking\\Core\\Container\\Container',
			'RadiusTheme\\RadiusHotelBooking\\Core\\Database\\Transaction',
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
		add_action( 'rtbp_register_addon_routes', array( IcalController::class, 'routes' ) );
		add_filter( 'rtbp_activity_actions', array( $this, 'actions' ) );
		add_filter( 'rtbp_block_sources', array( $this, 'source' ) );
		add_filter( 'rtbp_scheduled_events', array( $this, 'events' ) );
	}

	/**
	 * Unschedule the sync (the free Scheduler also drops jobs no longer
	 * registered); the feeds and their blocks stay.
	 *
	 * @return void
	 */
	public function on_disable(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * `rtbp_block_sources`: imported blocks are read-only in the Blocked
	 * dates screen; the next sync would undo an edit.
	 *
	 * @param array $sources Key => { label, editable }.
	 * @return array
	 */
	public function source( $sources ): array {
		$sources         = (array) $sources;
		$sources['ical'] = array(
			'label'    => __( 'Calendar sync', 'radius-hotel-booking-pro' ),
			'editable' => false,
		);
		return $sources;
	}

	/**
	 * `rtbp_scheduled_events`: read the due channel calendars every 15 minutes.
	 *
	 * @param array $events Hook => job.
	 * @return array
	 */
	public function events( $events ): array {
		$events               = (array) $events;
		$events[ self::HOOK ] = array(
			'recurrence' => 'rtbp_fifteen_minutes',
			'callback'   => static fn() => ( new IcalImportService() )->syncDue(),
		);
		return $events;
	}

	/**
	 * `rtbp_activity_actions`: the iCal actions.
	 *
	 * @param array $actions Key => definition.
	 * @return array
	 */
	public function actions( $actions ): array {
		$actions = (array) $actions;
		$labels  = array(
			'ical.feed_create'     => __( 'Published a room calendar', 'radius-hotel-booking-pro' ),
			'ical.feed_regenerate' => __( 'Gave a room calendar a new link', 'radius-hotel-booking-pro' ),
			'ical.feed_delete'     => __( 'Stopped publishing a room calendar', 'radius-hotel-booking-pro' ),
			'ical.import_create'   => __( 'Added a channel calendar', 'radius-hotel-booking-pro' ),
			'ical.import_update'   => __( 'Changed a channel calendar', 'radius-hotel-booking-pro' ),
			'ical.import_delete'   => __( 'Removed a channel calendar', 'radius-hotel-booking-pro' ),
			'ical.sync'            => __( 'Read a channel calendar', 'radius-hotel-booking-pro' ),
		);
		foreach ( $labels as $key => $label ) {
			$actions[ $key ] = array(
				'kind'  => 'data',
				'label' => $label,
			);
		}
		return $actions;
	}
}
