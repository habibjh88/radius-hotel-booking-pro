<?php
/**
 * Revenue breakdowns (M10 T6b, 10.16–10.17).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Reports
 */

namespace RadiusTheme\RadiusHotelBookingPro\Reports;

use RadiusTheme\RadiusHotelBooking\Services\Reports\ReportRange;
use RadiusTheme\RadiusHotelBooking\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * The `revenue` report of the free `rtbp_report_definitions` registry: the
 * period's revenue by room type, by rate plan and by the staff member who
 * took the booking.
 *
 * *Revenue* is the free Sales report's own figure — the frozen `total` of the
 * period's sold lines (not cancelled, declined or no-show, nor on a booking
 * whose money was all refunded), by arrival date or by booking date — so the
 * three breakdowns each add up to the Sales tab's *Sales with tax included*.
 * Nothing is re-priced.
 *
 * - Room type — its current name (a deleted type keeps its name);
 * - Rate plan — the plan name frozen on the line when it was booked;
 * - Staff — `bookings.created_by`; bookings without one are *Website* (source
 *   `web`), *Imported* (an import or a channel calendar), or *Unknown staff*
 *   (a desk booking with no creator — e.g. made by a since-removed process).
 *
 * One grouped statement per breakdown.
 */
class RevenueReport {

	/**
	 * Build the report.
	 *
	 * @param ReportRange $range Period and date mode.
	 * @return array `{ totals, room_types, rate_plans, staff }`.
	 */
	public function build( ReportRange $range ): array {
		$types = $this->group( $range, 'br.room_type_id', "COALESCE( MAX( t.name ), '' )" );
		$plans = $this->group( $range, 'br.rate_plan_id', 'MAX( br.rate_plan_name )' );
		$staff = $this->group( $range, "IF( b.created_by IS NULL OR b.created_by = 0, CASE b.source WHEN 'web' THEN 'web' WHEN 'desk' THEN 'unknown' ELSE 'imported' END, b.created_by )", "''" );

		$total = array_sum( array_column( $types, 'revenue' ) );
		foreach ( $staff as &$row ) {
			$row['label'] = self::staffName( (string) $row['key'] );
		}
		unset( $row );

		$finish = static function ( array $rows ) use ( $total ): array {
			usort( $rows, static fn( $a, $b ) => array( $b['revenue'], $a['label'] ) <=> array( $a['revenue'], $b['label'] ) );
			return array_map(
				static fn( $row ) => array(
					'key'      => (string) $row['key'],
					'label'    => '' !== $row['label'] ? $row['label'] : __( 'Unnamed', 'radius-hotel-booking-pro' ),
					'bookings' => $row['bookings'],
					'rooms'    => $row['rooms'],
					'revenue'  => Money::round( $row['revenue'] ),
					'share'    => $total > 0 ? round( 100 * $row['revenue'] / $total, 1 ) : 0.0,
				),
				$rows
			);
		};

		return array(
			'totals'     => array(
				'revenue' => Money::round( $total ),
				'rooms'   => array_sum( array_column( $types, 'rooms' ) ),
			),
			'room_types' => $finish( $types ),
			'rate_plans' => $finish( $plans ),
			'staff'      => $finish( $staff ),
		);
	}

	/**
	 * The report as export tables.
	 *
	 * @param ReportRange $range Period and date mode.
	 * @return array[] Tables.
	 */
	public function tables( ReportRange $range ): array {
		$report  = $this->build( $range );
		$columns = static fn( $first ) => array( $first, __( 'Bookings', 'radius-hotel-booking-pro' ), __( 'Rooms', 'radius-hotel-booking-pro' ), __( 'Revenue', 'radius-hotel-booking-pro' ), __( 'Share (%)', 'radius-hotel-booking-pro' ) );
		$rows    = static fn( $list ) => array_map( static fn( $row ) => array( $row['label'], $row['bookings'], $row['rooms'], $row['revenue'], $row['share'] ), $list );
		$mode    = 'created' === $range->mode ? __( 'by booking date', 'radius-hotel-booking-pro' ) : __( 'by arrival date', 'radius-hotel-booking-pro' );
		return array(
			array(
				/* translators: 1: first day, 2: last day, 3: "by arrival date" or "by booking date". */
				'title'   => sprintf( __( 'Revenue by room type, %1$s to %2$s, %3$s', 'radius-hotel-booking-pro' ), $range->from, $range->to, $mode ),
				'columns' => $columns( __( 'Room type', 'radius-hotel-booking-pro' ) ),
				'rows'    => $rows( $report['room_types'] ),
			),
			array(
				'title'   => __( 'Revenue by rate plan', 'radius-hotel-booking-pro' ),
				'columns' => $columns( __( 'Rate plan', 'radius-hotel-booking-pro' ) ),
				'rows'    => $rows( $report['rate_plans'] ),
			),
			array(
				'title'   => __( 'Revenue by staff member', 'radius-hotel-booking-pro' ),
				'columns' => $columns( __( 'Taken by', 'radius-hotel-booking-pro' ) ),
				'rows'    => $rows( $report['staff'] ),
			),
		);
	}

	/**
	 * Sold lines of the period grouped by one key.
	 *
	 * @param ReportRange $range Period.
	 * @param string      $key   SQL expression grouped on (a fixed fragment).
	 * @param string      $label SQL aggregate giving the group's name (a fixed fragment).
	 * @return array[] `{ key, label, bookings, rooms, revenue }`.
	 */
	private function group( ReportRange $range, string $key, string $label ): array {
		global $wpdb;
		$where = 'created' === $range->mode ? 'b.created_at_gmt >= %s AND b.created_at_gmt < %s' : 'br.start_at_gmt >= %s AND br.start_at_gmt < %s';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $key, $label and $where are fixed fragments of this class (two %s in $where); the free registry caches the result.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$key} AS group_key, {$label} AS label,
					COUNT( DISTINCT b.id ) AS bookings, COUNT(*) AS rooms, SUM( br.total ) AS revenue
				FROM %i br
				JOIN %i b ON b.id = br.booking_id AND b.deleted_at IS NULL
				LEFT JOIN %i t ON t.id = br.room_type_id
				WHERE br.status NOT IN ( 'cancelled', 'declined', 'no_show' ) AND b.payment_status <> 'refunded'
					AND {$where}
				GROUP BY group_key",
				rtbp_table( 'booking_rooms' ),
				rtbp_table( 'bookings' ),
				rtbp_table( 'room_types' ),
				$range->from_gmt,
				$range->to_gmt
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map(
			static fn( $row ) => array(
				'key'      => (string) $row['group_key'],
				'label'    => (string) $row['label'],
				'bookings' => (int) $row['bookings'],
				'rooms'    => (int) $row['rooms'],
				'revenue'  => (float) $row['revenue'],
			),
			(array) $rows
		);
	}

	/**
	 * Who took the bookings of a staff group.
	 *
	 * @param string $key User id, `web`, `imported` or `unknown`.
	 * @return string
	 */
	private static function staffName( string $key ): string {
		if ( 'web' === $key ) {
			return __( 'Website (guests)', 'radius-hotel-booking-pro' );
		}
		if ( 'imported' === $key ) {
			return __( 'Imported or channel calendars', 'radius-hotel-booking-pro' );
		}
		if ( 'unknown' === $key ) {
			return __( 'Unknown staff', 'radius-hotel-booking-pro' );
		}
		$user = get_userdata( (int) $key );
		/* translators: %d: user id of a deleted account. */
		return $user ? (string) $user->display_name : sprintf( __( 'Deleted user #%d', 'radius-hotel-booking-pro' ), (int) $key );
	}
}
