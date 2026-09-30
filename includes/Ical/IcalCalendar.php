<?php
/**
 * The iCalendar text for a room's export feed.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Ical
 */

namespace RadiusTheme\RadiusHotelBookingPro\Ical;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: busy intervals in, RFC 5545 text out.
 *
 * Channels (Booking.com, Airbnb…) sell nights, and a channel guest on
 * night N stays from N at check-in to N+1 at check-out. So night N is taken
 * when a stay overlaps `[ N @ check-in, N+1 @ check-out )` — the hotel's own
 * times, the same rule the import uses in reverse. An Overnight 20:00 → 08:00
 * takes one night; a Half Day 08:30–17:00 on D takes nights D−1 (that guest
 * would still be in the room at 08:30) and D; a stay that touches no night
 * (12:30–13:30) is not exported. The taken nights of one stay are one event.
 *
 * No guest data: every event is "Not available", with a stable UID per
 * stay (booking + start; a moved stay is a new event) or block, so a
 * channel can match it on the next read.
 */
final class IcalCalendar {

	/**
	 * The calendar.
	 *
	 * @param array             $intervals Each `{ kind: line|block, ref_id, s, e }` as the free
	 *                                     `AvailabilityRepository::busy()` returns them (a line's
	 *                                     `ref_id` is its booking); `s`/`e` UTC `Y-m-d H:i:s`.
	 * @param string            $name      Calendar name.
	 * @param string            $host      UID domain.
	 * @param DateTimeZone      $zone      Hotel time zone.
	 * @param DateTimeImmutable $now       DTSTAMP.
	 * @param string            $summary   Event title.
	 * @param string            $check_in  Hotel check-in time `H:i`.
	 * @param string            $check_out Hotel check-out time `H:i`.
	 * @return string
	 */
	public static function build( array $intervals, string $name, string $host, DateTimeZone $zone, DateTimeImmutable $now, string $summary, string $check_in = '14:00', string $check_out = '12:00' ): string {
		$utc   = new DateTimeZone( 'UTC' );
		$stamp = $now->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//RadiusTheme//Radius Hotel Booking Pro//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . self::text( $name ),
		);

		$seen = array();
		foreach ( $intervals as $interval ) {
			$kind = (string) $interval['kind'];
			if ( ! in_array( $kind, array( 'line', 'block' ), true ) ) {
				continue; // Holds are minutes long; a channel must not see them.
			}
			// A line's ref_id is its booking: two stays of one booking on this
			// room differ by their start. A block is unique by its id.
			$uid = 'line' === $kind
				? sprintf( 'rtbp-line-%d-%s@%s', (int) $interval['ref_id'], gmdate( 'Ymd\THis', strtotime( $interval['s'] . ' UTC' ) ), $host )
				: sprintf( 'rtbp-block-%d@%s', (int) $interval['ref_id'], $host );
			if ( isset( $seen[ $uid ] ) ) {
				continue;
			}
			$seen[ $uid ] = true;

			$nights = self::nights( (string) $interval['s'], (string) $interval['e'], $zone, $check_in, $check_out );
			if ( null === $nights ) {
				continue; // Touches no night a channel could sell.
			}
			list( $first, $end ) = $nights;
			array_push(
				$lines,
				'BEGIN:VEVENT',
				'UID:' . $uid,
				'DTSTAMP:' . $stamp,
				'DTSTART;VALUE=DATE:' . $first,
				'DTEND;VALUE=DATE:' . $end,
				'SUMMARY:' . self::text( $summary ),
				'TRANSP:OPAQUE',
				'END:VEVENT'
			);
		}
		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", array_map( array( self::class, 'fold' ), $lines ) ) . "\r\n";
	}

	/**
	 * The nights an interval takes: `[ first (Ymd), end (Ymd, exclusive) ]`,
	 * or null when it takes none.
	 *
	 * @param string       $start_gmt Start, UTC `Y-m-d H:i:s`.
	 * @param string       $end_gmt   End, UTC.
	 * @param DateTimeZone $zone      Hotel time zone.
	 * @param string       $check_in  Check-in time `H:i`.
	 * @param string       $check_out Check-out time `H:i`.
	 * @return string[]|null
	 */
	public static function nights( string $start_gmt, string $end_gmt, DateTimeZone $zone, string $check_in = '14:00', string $check_out = '12:00' ): ?array {
		$utc   = new DateTimeZone( 'UTC' );
		$start = ( new DateTimeImmutable( $start_gmt, $utc ) )->setTimezone( $zone );
		$end   = ( new DateTimeImmutable( $end_gmt, $utc ) )->setTimezone( $zone );
		list( $in_h, $in_m )   = array_map( 'intval', explode( ':', $check_in . ':0' ) );
		list( $out_h, $out_m ) = array_map( 'intval', explode( ':', $check_out . ':0' ) );

		$first = null;
		$last  = null;
		// From the night before the start day (its guest leaves that morning)
		// to the night of the end day.
		$night = $start->setTime( 0, 0 )->modify( '-1 day' );
		$stop  = $end->setTime( 0, 0 );
		for ( ; $night <= $stop; $night = $night->modify( '+1 day' ) ) {
			$from = $night->setTime( $in_h, $in_m );
			$to   = $night->modify( '+1 day' )->setTime( $out_h, $out_m );
			if ( $start < $to && $end > $from ) {
				$first = $first ?? $night;
				$last  = $night;
			}
		}
		return $first ? array( $first->format( 'Ymd' ), $last->modify( '+1 day' )->format( 'Ymd' ) ) : null;
	}

	/**
	 * Escape a TEXT value (RFC 5545 §3.3.11).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function text( string $value ): string {
		$value = str_replace( array( "\r\n", "\r", "\n" ), ' ', $value );
		return str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $value );
	}

	/**
	 * Fold a content line at 75 octets (RFC 5545 §3.1), never inside a
	 * UTF-8 character.
	 *
	 * @param string $line Line.
	 * @return string
	 */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out   = '';
		$chunk = '';
		$limit = 75;
		foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
			if ( strlen( $chunk ) + strlen( $char ) > $limit ) {
				$out  .= $chunk . "\r\n ";
				$chunk = '';
				$limit = 74; // The leading space counts.
			}
			$chunk .= $char;
		}
		return $out . $chunk;
	}
}
