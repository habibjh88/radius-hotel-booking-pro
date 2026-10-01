<?php
/**
 * Occupancy rate (M10 T6a, 10.15).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Reports
 */

namespace RadiusTheme\RadiusHotelBookingPro\Reports;

use RadiusTheme\RadiusHotelBooking\Repositories\AvailabilityRepository;
use RadiusTheme\RadiusHotelBooking\Services\Availability\Overlap;
use RadiusTheme\RadiusHotelBooking\Services\Reports\ReportRange;
use RadiusTheme\RadiusHotelBooking\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The `occupancy` report of the free `rtbp_report_definitions` registry.
 *
 * **Occupancy % for a day** = occupied room-hours ÷ sellable room-hours × 100
 * (the module's definition):
 *
 * - *Sellable* — every room in the `available` state of a live, **active**
 *   room type (a type hidden from sale is not counted), for
 *   the whole day, less the hours a block covers it. Room states keep no
 *   history: a past period is measured against today's rooms (said on screen).
 * - *Occupied* — the hours a `confirmed`, `checked_in` or `checked_out` stay is
 *   in the room (`start_at_gmt ≤ t < occupied_until_gmt` — the end, or the
 *   moment of an early check-out; overlap, so a multi-night
 *   stay counts on every night), never inside a block, one stay at a time.
 *
 * Days are the hotel's calendar days (a daylight-saving day keeps its real
 * length). Also per day: the **peak** number of rooms occupied at once; per
 * room type: its occupancy over the period. Three reads: the rooms, their
 * stays, and the engine's busy read for the blocks.
 */
class OccupancyReport {

	/**
	 * Stay statuses that occupy a room.
	 */
	public const STATUSES = array( 'confirmed', 'checked_in', 'checked_out' );

	/**
	 * Build the report.
	 *
	 * @param ReportRange $range Period (the date mode does not apply: occupancy is about stays).
	 * @return array `{ totals, chart, days, room_types }`.
	 */
	public function build( ReportRange $range ): array {
		$days  = $this->days( $range );
		$rooms = $this->rooms();
		$start = $days[0]['s'];
		$end   = $days[ count( $days ) - 1 ]['e'];

		$blocks = array();
		if ( $rooms ) {
			$busy = ( new AvailabilityRepository() )->busy( array_keys( $rooms ), gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', $end ) );
			foreach ( $busy as $room_id => $intervals ) {
				foreach ( $intervals as $interval ) {
					if ( Overlap::BLOCK === $interval['kind'] ) {
						$blocks[ $room_id ][] = array( strtotime( $interval['s'] . ' UTC' ), strtotime( $interval['e'] . ' UTC' ) );
					}
				}
			}
		}
		$stays = $this->stays( array_keys( $rooms ), $start, $end );

		$count = count( $days );
		$sell  = array_fill( 0, $count, 0 );
		$occ   = array_fill( 0, $count, 0 );
		$edges = array_fill( 0, $count, array() );
		$types = array();
		foreach ( $rooms as $room_id => $room ) {
			$blocked  = self::union( $blocks[ $room_id ] ?? array(), $start, $end );
			$occupied = self::subtract( self::union( $stays[ $room_id ] ?? array(), $start, $end ), $blocked );
			$type     = $room['room_type_id'];
			if ( ! isset( $types[ $type ] ) ) {
				$types[ $type ] = array(
					'name'     => $room['room_type'],
					'rooms'    => 0,
					'sellable' => 0,
					'occupied' => 0,
				);
			}
			++$types[ $type ]['rooms'];
			$types[ $type ]['sellable'] += ( $end - $start ) - self::within( $blocked, $start, $end );
			foreach ( $days as $index => $day ) {
				$sell[ $index ] += $day['e'] - $day['s'];
			}
			// Each interval only visits the days it touches.
			self::spread(
				$blocked,
				$days,
				static function ( $index, $s, $e ) use ( &$sell ) {
					$sell[ $index ] -= $e - $s;
				}
			);
			self::spread(
				$occupied,
				$days,
				static function ( $index, $s, $e ) use ( &$occ, &$edges, &$types, $type ) {
					$occ[ $index ]              += $e - $s;
					$types[ $type ]['occupied'] += $e - $s;
					$edges[ $index ][]           = array( $s, 1 );
					$edges[ $index ][]           = array( $e, -1 );
				}
			);
		}

		$out_days = array();
		$peak_max = 0;
		foreach ( $days as $index => $day ) {
			$peak      = self::peak( $edges[ $index ] );
			$peak_max  = max( $peak_max, $peak );
			$out_days[] = array(
				'day'            => $day['day'],
				'occupancy'      => self::percent( $occ[ $index ], $sell[ $index ] ),
				'peak'           => $peak,
				'occupied_hours' => round( $occ[ $index ] / HOUR_IN_SECONDS, 1 ),
				'sellable_hours' => round( $sell[ $index ] / HOUR_IN_SECONDS, 1 ),
			);
		}

		return array(
			'totals'     => array(
				'rooms'          => count( $rooms ),
				'occupancy'      => self::percent( array_sum( $occ ), array_sum( $sell ) ),
				'peak'           => $peak_max,
				'occupied_hours' => round( array_sum( $occ ) / HOUR_IN_SECONDS, 1 ),
				'sellable_hours' => round( array_sum( $sell ) / HOUR_IN_SECONDS, 1 ),
				// Rooms occupied on average over the period (room-hours ÷ hours).
				'average_rooms'  => round( array_sum( $occ ) / max( 1, $end - $start ), 1 ),
			),
			'chart'      => $this->chart( $range, $out_days ),
			'days'       => $out_days,
			'room_types' => array_values(
				array_map(
					static fn( $type ) => array(
						'name'      => $type['name'],
						'rooms'     => $type['rooms'],
						'occupancy' => self::percent( $type['occupied'], $type['sellable'] ),
					),
					$types
				)
			),
		);
	}

	/**
	 * The report as export tables.
	 *
	 * @param ReportRange $range Period.
	 * @return array[] Tables.
	 */
	public function tables( ReportRange $range ): array {
		$report = $this->build( $range );
		$totals = $report['totals'];
		return array(
			array(
				/* translators: 1: first day, 2: last day. */
				'title'   => sprintf( __( 'Occupancy, %1$s to %2$s', 'radius-hotel-booking-pro' ), $range->from, $range->to ),
				'columns' => array( __( 'Figure', 'radius-hotel-booking-pro' ), __( 'Value', 'radius-hotel-booking-pro' ) ),
				'rows'    => array(
					array( __( 'Occupancy (%)', 'radius-hotel-booking-pro' ), $totals['occupancy'] ),
					array( __( 'Sellable rooms', 'radius-hotel-booking-pro' ), $totals['rooms'] ),
					array( __( 'Most rooms occupied at once', 'radius-hotel-booking-pro' ), $totals['peak'] ),
					array( __( 'Rooms occupied on average', 'radius-hotel-booking-pro' ), $totals['average_rooms'] ),
					array( __( 'Occupied room-hours', 'radius-hotel-booking-pro' ), $totals['occupied_hours'] ),
					array( __( 'Sellable room-hours', 'radius-hotel-booking-pro' ), $totals['sellable_hours'] ),
				),
			),
			array(
				'title'   => __( 'By day', 'radius-hotel-booking-pro' ),
				'columns' => array( __( 'Day', 'radius-hotel-booking-pro' ), __( 'Occupancy (%)', 'radius-hotel-booking-pro' ), __( 'Most rooms occupied at once', 'radius-hotel-booking-pro' ), __( 'Occupied room-hours', 'radius-hotel-booking-pro' ), __( 'Sellable room-hours', 'radius-hotel-booking-pro' ) ),
				'rows'    => array_map( static fn( $day ) => array( $day['day'], $day['occupancy'], $day['peak'], $day['occupied_hours'], $day['sellable_hours'] ), $report['days'] ),
			),
			array(
				'title'   => __( 'By room type', 'radius-hotel-booking-pro' ),
				'columns' => array( __( 'Room type', 'radius-hotel-booking-pro' ), __( 'Rooms', 'radius-hotel-booking-pro' ), __( 'Occupancy (%)', 'radius-hotel-booking-pro' ) ),
				'rows'    => array_map( static fn( $type ) => array( $type['name'], $type['rooms'], $type['occupancy'] ), $report['room_types'] ),
			),
		);
	}

	/**
	 * The chart: occupancy per day (≤ 62 days) or per month.
	 *
	 * @param ReportRange $range Period.
	 * @param array[]     $days  Day rows.
	 * @return array `{ mode, series, points }` (the free `ReportChart` shape).
	 */
	private function chart( ReportRange $range, array $days ): array {
		$monthly = 'month' === $range->chartMode();
		$buckets = array();
		foreach ( $days as $day ) {
			$key = $monthly ? substr( $day['day'], 0, 7 ) : $day['day'];
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array( 0.0, 0.0 );
			}
			$buckets[ $key ][0] += $day['occupied_hours'];
			$buckets[ $key ][1] += $day['sellable_hours'];
		}
		$points = array();
		foreach ( $buckets as $key => $hours ) {
			$points[] = array(
				'key'    => (string) $key,
				'values' => array( 'occupancy' => self::percent( $hours[0], $hours[1] ) ),
			);
		}
		return array(
			'mode'   => $monthly ? 'month' : 'day',
			'series' => array(
				array(
					'key'   => 'occupancy',
					'label' => __( 'Occupancy (%)', 'radius-hotel-booking-pro' ),
				),
			),
			'points' => $points,
		);
	}

	/**
	 * The period's calendar days, as timestamps.
	 *
	 * @param ReportRange $range Period.
	 * @return array[] `[ { day, s, e } ]`.
	 */
	private function days( ReportRange $range ): array {
		$out = array();
		foreach ( $range->buckets( 'day' ) as $day ) {
			$out[] = array(
				'day' => $day,
				's'   => Dates::start_of_day( $day )->getTimestamp(),
				'e'   => Dates::end_of_day( $day )->getTimestamp(),
			);
		}
		return $out;
	}

	/**
	 * Sellable rooms: `available`, of a live and active room type.
	 *
	 * @return array<int, array> Room id => `{ room_type_id, room_type }`.
	 */
	private function rooms(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one read per report; the free registry caches the result.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.room_type_id, t.name FROM %i r JOIN %i t ON t.id = r.room_type_id AND t.deleted_at IS NULL
				WHERE r.deleted_at IS NULL AND r.state = 'available' AND t.is_active = 1 ORDER BY t.sort_order, t.name, r.number_sort",
				rtbp_table( 'rooms' ),
				rtbp_table( 'room_types' )
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['id'] ] = array(
				'room_type_id' => (int) $row['room_type_id'],
				'room_type'    => (string) $row['name'],
			);
		}
		return $out;
	}

	/**
	 * The occupying stays of some rooms that touch `[start, end)`.
	 *
	 * @param int[] $room_ids Rooms.
	 * @param int   $start    Period start (timestamp).
	 * @param int   $end      Period end, excluded.
	 * @return array<int, array> Room id => `[ [ start, end ] ]` (timestamps).
	 */
	private function stays( array $room_ids, int $start, int $end ): array {
		global $wpdb;
		if ( ! $room_ids ) {
			return array();
		}
		$ids      = implode( ',', array_map( 'intval', $room_ids ) );
		$statuses = "'" . implode( "','", self::STATUSES ) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $ids are integers, $statuses constant; the free registry caches the result.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT br.room_id, br.start_at_gmt, br.occupied_until_gmt FROM %i br
				JOIN %i b ON b.id = br.booking_id AND b.deleted_at IS NULL
				WHERE br.room_id IN ( {$ids} ) AND br.status IN ( {$statuses} )
					AND br.start_at_gmt < %s AND br.occupied_until_gmt > %s",
				rtbp_table( 'booking_rooms' ),
				rtbp_table( 'bookings' ),
				gmdate( 'Y-m-d H:i:s', $end ),
				gmdate( 'Y-m-d H:i:s', $start )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['room_id'] ][] = array( strtotime( $row['start_at_gmt'] . ' UTC' ), strtotime( $row['occupied_until_gmt'] . ' UTC' ) );
		}
		return $out;
	}

	/**
	 * Cut intervals at day boundaries: `$visit( day index, start, end )` for
	 * each piece. The first day is found by binary search, then days are
	 * walked only while the interval lasts.
	 *
	 * @param array[]  $intervals Disjoint, inside the period.
	 * @param array[]  $days      `[ { s, e } ]`, contiguous, in order.
	 * @param callable $visit     Called per piece.
	 * @return void
	 */
	public static function spread( array $intervals, array $days, callable $visit ): void {
		$count = count( $days );
		foreach ( $intervals as $interval ) {
			$low  = 0;
			$high = $count - 1;
			while ( $low < $high ) {
				$mid = intdiv( $low + $high + 1, 2 );
				if ( $days[ $mid ]['s'] <= $interval[0] ) {
					$low = $mid;
				} else {
					$high = $mid - 1;
				}
			}
			for ( $index = $low; $index < $count && $days[ $index ]['s'] < $interval[1]; $index++ ) {
				$s = max( $interval[0], $days[ $index ]['s'] );
				$e = min( $interval[1], $days[ $index ]['e'] );
				if ( $s < $e ) {
					$visit( $index, $s, $e );
				}
			}
		}
	}

	/**
	 * Intervals merged and clipped to `[start, end)`.
	 *
	 * @param array[] $intervals `[ [ s, e ] ]`.
	 * @param int     $start     Clip start.
	 * @param int     $end       Clip end.
	 * @return array[] Sorted, disjoint.
	 */
	public static function union( array $intervals, int $start, int $end ): array {
		$clipped = array();
		foreach ( $intervals as $interval ) {
			$s = max( (int) $interval[0], $start );
			$e = min( (int) $interval[1], $end );
			if ( $s < $e ) {
				$clipped[] = array( $s, $e );
			}
		}
		usort( $clipped, static fn( $a, $b ) => $a[0] <=> $b[0] );
		$out = array();
		foreach ( $clipped as $interval ) {
			$last = count( $out ) - 1;
			if ( $last >= 0 && $interval[0] <= $out[ $last ][1] ) {
				$out[ $last ][1] = max( $out[ $last ][1], $interval[1] );
			} else {
				$out[] = $interval;
			}
		}
		return $out;
	}

	/**
	 * Disjoint intervals minus disjoint intervals.
	 *
	 * @param array[] $from   Sorted, disjoint.
	 * @param array[] $remove Sorted, disjoint.
	 * @return array[]
	 */
	public static function subtract( array $from, array $remove ): array {
		$out = array();
		foreach ( $from as $interval ) {
			$pieces = array( $interval );
			foreach ( $remove as $cut ) {
				$next = array();
				foreach ( $pieces as $piece ) {
					if ( $cut[1] <= $piece[0] || $cut[0] >= $piece[1] ) {
						$next[] = $piece;
						continue;
					}
					if ( $cut[0] > $piece[0] ) {
						$next[] = array( $piece[0], $cut[0] );
					}
					if ( $cut[1] < $piece[1] ) {
						$next[] = array( $cut[1], $piece[1] );
					}
				}
				$pieces = $next;
			}
			$out = array_merge( $out, $pieces );
		}
		return $out;
	}

	/**
	 * Seconds of disjoint intervals inside `[start, end)`.
	 *
	 * @param array[] $intervals Disjoint.
	 * @param int     $start     Start.
	 * @param int     $end       End.
	 * @return int
	 */
	public static function within( array $intervals, int $start, int $end ): int {
		$sum = 0;
		foreach ( $intervals as $interval ) {
			$sum += max( 0, min( $interval[1], $end ) - max( $interval[0], $start ) );
		}
		return $sum;
	}

	/**
	 * Most intervals open at once (an end at t comes before a start at t).
	 *
	 * @param array[] $edges `[ [ t, +1|-1 ] ]`.
	 * @return int
	 */
	public static function peak( array $edges ): int {
		usort( $edges, static fn( $a, $b ) => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );
		$open = 0;
		$max  = 0;
		foreach ( $edges as $edge ) {
			$open += $edge[1];
			$max   = max( $max, $open );
		}
		return $max;
	}

	/**
	 * A share as a percentage, one decimal; 0 when nothing could be sold.
	 *
	 * @param float $part  Part.
	 * @param float $whole Whole.
	 * @return float
	 */
	public static function percent( $part, $whole ): float {
		return $whole > 0 ? round( min( 100, 100 * $part / $whole ), 1 ) : 0.0;
	}
}
