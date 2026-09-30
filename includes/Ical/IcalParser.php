<?php
/**
 * The iCalendar reader for channel calendars.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Ical
 */

namespace RadiusTheme\RadiusHotelBookingPro\Ical;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: iCalendar text in, busy periods out. Reads only what a closure
 * needs — UID, DTSTART, DTEND (or DURATION), STATUS — and nothing about the
 * guest (a channel's SUMMARY often carries a name, so it is never kept).
 *
 * - An all-day event (`VALUE=DATE`) is a run of nights: `[ start date, end
 *   date )`, at least one night. The caller turns nights into times with the
 *   hotel's check-in and check-out.
 * - A timed event keeps its times: UTC (`…Z`), a `TZID`, or floating (read
 *   in the hotel's time zone).
 * - Cancelled events are skipped; an event with no UID gets one from its dates.
 */
final class IcalParser {

	/**
	 * Most events read from one calendar.
	 */
	public const MAX_EVENTS = 5000;

	/**
	 * Parse a calendar.
	 *
	 * @param string       $text Calendar text.
	 * @param DateTimeZone $zone Hotel time zone (floating times, all-day dates).
	 * @return array{events: array[], skipped: int, complete: bool} Events `{ uid, all_day, start, end }`:
	 *               all-day `start`/`end` are `Y-m-d`; timed ones are DateTimeImmutable.
	 * @throws \InvalidArgumentException When the text is not an iCalendar.
	 */
	public static function parse( string $text, DateTimeZone $zone ): array {
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		if ( false === stripos( $text, 'BEGIN:VCALENDAR' ) ) {
			throw new \InvalidArgumentException( 'Not an iCalendar.' );
		}
		// Unfold continuation lines (RFC 5545 §3.1).
		$text = preg_replace( "/\n[ \t]/", '', $text );

		$events    = array();
		$skipped   = 0;
		$event     = null;
		$truncated = false;
		foreach ( explode( "\n", $text ) as $line ) {
			$line = rtrim( $line );
			if ( 0 === strcasecmp( $line, 'BEGIN:VEVENT' ) ) {
				$event = array();
				continue;
			}
			if ( 0 === strcasecmp( $line, 'END:VEVENT' ) ) {
				$parsed = null === $event ? null : self::event( $event, $zone );
				if ( $parsed ) {
					$events[] = $parsed;
				} else {
					++$skipped;
				}
				$event = null;
				if ( count( $events ) >= self::MAX_EVENTS ) {
					$truncated = true;
					break;
				}
				continue;
			}
			if ( null === $event || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $head, $value ) = explode( ':', $line, 2 );
			$params               = explode( ';', $head );
			$name                 = strtoupper( array_shift( $params ) );
			if ( ! in_array( $name, array( 'UID', 'DTSTART', 'DTEND', 'DURATION', 'STATUS' ), true ) || isset( $event[ $name ] ) ) {
				continue;
			}
			$map = array();
			foreach ( $params as $param ) {
				if ( false !== strpos( $param, '=' ) ) {
					list( $key, $val )            = explode( '=', $param, 2 );
					$map[ strtoupper( $key ) ] = trim( $val, '"' );
				}
			}
			$event[ $name ] = array(
				'value'  => trim( $value ),
				'params' => $map,
			);
		}

		return array(
			'events'   => $events,
			'skipped'  => $skipped,
			// Cut off (no END:VCALENDAR) or over the event limit: the caller
			// must not treat a missing event as a cancelled one.
			'complete' => ! $truncated && false !== stripos( $text, 'END:VCALENDAR' ),
		);
	}

	/**
	 * One event, or null when it cannot be used.
	 *
	 * @param array        $props Properties.
	 * @param DateTimeZone $zone  Hotel time zone.
	 * @return array|null
	 */
	private static function event( array $props, DateTimeZone $zone ): ?array {
		if ( empty( $props['DTSTART'] ) || 'CANCELLED' === strtoupper( $props['STATUS']['value'] ?? '' ) ) {
			return null;
		}
		$start = self::moment( $props['DTSTART'], $zone );
		if ( ! $start ) {
			return null;
		}
		$all_day = 'date' === $start['kind'];

		$end = empty( $props['DTEND'] ) ? null : self::moment( $props['DTEND'], $zone );
		if ( ! $end && ! empty( $props['DURATION'] ) ) {
			try {
				$added = $start['value']->add( new DateInterval( ltrim( strtoupper( $props['DURATION']['value'] ), '+' ) ) );
				$end   = array(
					'kind'  => $start['kind'],
					'value' => $added,
				);
			} catch ( Exception $e ) {
				$end = null;
			}
		}

		if ( $all_day ) {
			$first = $start['value']->format( 'Y-m-d' );
			$last  = $end ? $end['value']->format( 'Y-m-d' ) : '';
			if ( '' === $last || $last <= $first ) {
				$last = $start['value']->modify( '+1 day' )->format( 'Y-m-d' );
			}
			$uid = $props['UID']['value'] ?? '';
			return array(
				'uid'     => self::uid( $uid, $first . '/' . $last ),
				'all_day' => true,
				'start'   => $first,
				'end'     => $last,
			);
		}

		if ( ! $end || $end['value'] <= $start['value'] ) {
			return null;
		}
		return array(
			'uid'     => self::uid( $props['UID']['value'] ?? '', $start['value']->format( 'c' ) . '/' . $end['value']->format( 'c' ) ),
			'all_day' => false,
			'start'   => $start['value'],
			'end'     => $end['value'],
		);
	}

	/**
	 * A DTSTART / DTEND value.
	 *
	 * @param array        $prop `{ value, params }`.
	 * @param DateTimeZone $zone Hotel time zone.
	 * @return array|null `{ kind: date|time, value: DateTimeImmutable }`.
	 */
	private static function moment( array $prop, DateTimeZone $zone ): ?array {
		$value = $prop['value'];
		if ( 'DATE' === strtoupper( $prop['params']['VALUE'] ?? '' ) || preg_match( '/^\d{8}$/', $value ) ) {
			$date = DateTimeImmutable::createFromFormat( '!Ymd', substr( $value, 0, 8 ), $zone );
			return $date && $date->format( 'Ymd' ) === substr( $value, 0, 8 ) ? array(
				'kind'  => 'date',
				'value' => $date,
			) : null;
		}
		if ( ! preg_match( '/^(\d{8}T\d{6})(Z?)$/i', $value, $m ) ) {
			return null;
		}
		$tz = $zone;
		if ( $m[2] ) {
			$tz = new DateTimeZone( 'UTC' );
		} elseif ( ! empty( $prop['params']['TZID'] ) ) {
			try {
				$tz = new DateTimeZone( $prop['params']['TZID'] );
			} catch ( Exception $e ) {
				$tz = $zone; // An unknown zone name (a Windows one): read as the hotel's.
			}
		}
		$moment = DateTimeImmutable::createFromFormat( 'Ymd\THis', strtoupper( $m[1] ), $tz );
		return $moment ? array(
			'kind'  => 'time',
			'value' => $moment,
		) : null;
	}

	/**
	 * The UID to reconcile by: the channel's, or one made from the dates.
	 *
	 * @param string $uid      Channel UID.
	 * @param string $fallback Dates.
	 * @return string At most 191 characters (the column).
	 */
	private static function uid( string $uid, string $fallback ): string {
		$uid = trim( $uid );
		if ( '' === $uid ) {
			return 'dates-' . md5( $fallback );
		}
		return strlen( $uid ) > 191 ? 'md5-' . md5( $uid ) : $uid;
	}
}
