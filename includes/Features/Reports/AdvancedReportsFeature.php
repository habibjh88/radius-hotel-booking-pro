<?php
/**
 * Advanced reports (M10 T6a–T7).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Reports
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Reports;

use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;
use RadiusTheme\RadiusHotelBookingPro\Reports\OccupancyReport;
use RadiusTheme\RadiusHotelBookingPro\Reports\RevenueReport;
use RadiusTheme\RadiusHotelBookingPro\Reports\XlsxWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `advanced_reports`: reports added to the free plugin's registry
 * (`rtbp_report_definitions`) and shown as tabs of its Reports screen
 * (`rtbp.reports.tabs`, `src/admin/index.js`).
 *
 * - **Occupancy** (10.15) — `OccupancyReport`, behind the Rooms report's key.
 * - **Revenue breakdown** (10.16, 10.17) — `RevenueReport`, behind the Sales
 *   report's key: by room type, rate plan and staff member.
 * - **Excel export** (10.14) — the `xlsx` format of `rtbp_export_formats`
 *   (`XlsxWriter`), for every report, when PHP's zip extension is there.
 *
 * The free endpoint enforces each report's access key and caches its result
 * until bookings, payments or blocks change.
 */
final class AdvancedReportsFeature extends AbstractFeature {

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_table',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Reports\\ReportService',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Reports\\ReportRange',
			'RadiusTheme\\RadiusHotelBooking\\Repositories\\AvailabilityRepository',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Availability\\Overlap',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Dates',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Money',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Export\\ExportWriter',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'rtbp_report_definitions', array( $this, 'definitions' ) );
		if ( XlsxWriter::available() ) {
			add_filter( 'rtbp_export_formats', array( $this, 'formats' ) );
		}
	}

	/**
	 * `rtbp_export_formats`: Excel.
	 *
	 * @param array $formats Key => format.
	 * @return array
	 */
	public function formats( $formats ): array {
		$formats         = (array) $formats;
		$formats['xlsx'] = array(
			'label'     => __( 'Excel (XLSX)', 'radius-hotel-booking-pro' ),
			'extension' => 'xlsx',
			'mime'      => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'write'     => array( XlsxWriter::class, 'write' ),
		);
		return $formats;
	}

	/**
	 * `rtbp_report_definitions`: the Pro reports.
	 *
	 * @param array $definitions Name => definition.
	 * @return array
	 */
	public function definitions( $definitions ): array {
		$definitions              = (array) $definitions;
		$definitions['occupancy'] = array(
			'label'  => __( 'Occupancy', 'radius-hotel-booking-pro' ),
			'access' => 'page.reports_rooms',
			// About stays: the arrival / booking-date mode does not apply.
			'modes'  => false,
			'build'  => static fn( $range ) => ( new OccupancyReport() )->build( $range ),
			'export' => static fn( $range ) => ( new OccupancyReport() )->tables( $range ),
		);
		$definitions['revenue']   = array(
			'label'  => __( 'Revenue breakdown', 'radius-hotel-booking-pro' ),
			'access' => 'page.reports_sales',
			'build'  => static fn( $range ) => ( new RevenueReport() )->build( $range ),
			'export' => static fn( $range ) => ( new RevenueReport() )->tables( $range ),
		);
		return $definitions;
	}
}
