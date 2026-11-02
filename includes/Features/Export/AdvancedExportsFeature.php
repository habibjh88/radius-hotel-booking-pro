<?php
/**
 * Advanced exports (M11 T3a, T4).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Export
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Export;

use RadiusTheme\RadiusHotelBookingPro\Export\ScheduleController;
use RadiusTheme\RadiusHotelBookingPro\Export\ScheduleService;
use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;
use RadiusTheme\RadiusHotelBookingPro\Reports\XlsxWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `advanced_exports`: what Pro adds to the free Exports screen.
 *
 * - **Excel** (11.2) — the `xlsx` format of `rtbp_export_formats`, with its
 *   streamed writer (`XlsxStream`) for exports of any size. The same format
 *   as `advanced_reports` registers (one definition, `XlsxWriter::format()`),
 *   so either feature alone brings it.
 * - *Archive and remove* (11.4) was dropped (R1, 2026-11-02): the hotel keeps
 *   every booking; a period file is a normal export. Its activity label stays
 *   so earlier log entries still read.
 * - **Scheduled exports** (11.8) — `ScheduleService` (weekly / monthly, the
 *   previous week or month), run by a 15-minute job; `exports/schedules`
 *   endpoints; the *Scheduled exports* panel with each next run.
 */
final class AdvancedExportsFeature extends AbstractFeature {

	/**
	 * The scheduled-exports job.
	 */
	const HOOK = 'rtbp_pro_scheduled_exports';

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_table',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Export\\ExportService',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Export\\ExportWriter',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Export\\ExportStream',
			'RadiusTheme\\RadiusHotelBooking\\Storage\\ProtectedFiles',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Reports\\ReportRange',
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
		if ( XlsxWriter::available() ) {
			add_filter( 'rtbp_export_formats', array( $this, 'formats' ) );
		}
		add_filter( 'rtbp_activity_actions', array( $this, 'actions' ) );
		add_action( 'rtbp_register_addon_routes', array( ScheduleController::class, 'routes' ) );
		add_filter( 'rtbp_scheduled_events', array( $this, 'events' ) );
	}

	/**
	 * Unschedule the job (the schedules stay, for when the feature returns).
	 *
	 * @return void
	 */
	public function on_disable(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * `rtbp_scheduled_events`: run due scheduled exports every 15 minutes.
	 *
	 * @param array $events Hook => job.
	 * @return array
	 */
	public function events( $events ): array {
		$events               = (array) $events;
		$events[ self::HOOK ] = array(
			'recurrence' => 'rtbp_fifteen_minutes',
			'callback'   => static function () {
				( new ScheduleService() )->runDue();
			},
		);
		return $events;
	}

	/**
	 * `rtbp_activity_actions`: the scheduled-export actions, and the label of
	 * archive-and-remove runs made before it was dropped.
	 *
	 * @param array $actions Key => definition.
	 * @return array
	 */
	public function actions( $actions ): array {
		$actions                           = (array) $actions;
		$actions['exports.archive_remove'] = array(
			'kind'  => 'data',
			'label' => __( 'Archived and removed bookings', 'radius-hotel-booking-pro' ),
		);
		foreach ( array(
			'exports.schedule_create' => __( 'Added a scheduled export', 'radius-hotel-booking-pro' ),
			'exports.schedule_update' => __( 'Changed a scheduled export', 'radius-hotel-booking-pro' ),
			'exports.schedule_delete' => __( 'Removed a scheduled export', 'radius-hotel-booking-pro' ),
		) as $key => $label ) {
			$actions[ $key ] = array(
				'kind'  => 'system',
				'label' => $label,
			);
		}
		return $actions;
	}

	/**
	 * `rtbp_export_formats`: Excel.
	 *
	 * @param array $formats Key => format.
	 * @return array
	 */
	public function formats( $formats ): array {
		$formats         = (array) $formats;
		$formats['xlsx'] = XlsxWriter::format();
		return $formats;
	}
}
