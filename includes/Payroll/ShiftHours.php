<?php
/**
 * Working hours of a work shift (M15 T3b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: a shift is seven days (ISO weekday 1–7), each a day off or
 * `start`–`end` (`H:i`). An end at or before the start runs past midnight
 * (18:00–08:00 = 14 h; 08:00–08:00 = 24 h). Counted in minutes — the legacy
 * helper read only the hours part of the difference, so a 24-hour shift
 * counted 0 h (not copied). Hours per week turn a salary into an hourly
 * rate (unpaid time off, overtime); hours in a period are a run's starting
 * hours (T5a).
 */
final class ShiftHours {

	/**
	 * Hours of one day's shift.
	 *
	 * @param array $day `{ is_day_off, start_time, end_time }`.
	 * @return float
	 */
	public static function forDay( array $day ): float {
		if ( ! empty( $day['is_day_off'] ) ) {
			return 0.0;
		}
		$start = self::minutes( (string) ( $day['start_time'] ?? '' ) );
		$end   = self::minutes( (string) ( $day['end_time'] ?? '' ) );
		if ( null === $start || null === $end ) {
			return 0.0;
		}
		$length = $end - $start;
		if ( $length <= 0 ) {
			$length += 24 * 60;
		}
		return round( $length / 60, 4 );
	}

	/**
	 * Whether a day's shift runs past midnight.
	 *
	 * @param array $day Day.
	 * @return bool
	 */
	public static function overnight( array $day ): bool {
		if ( ! empty( $day['is_day_off'] ) ) {
			return false;
		}
		$start = self::minutes( (string) ( $day['start_time'] ?? '' ) );
		$end   = self::minutes( (string) ( $day['end_time'] ?? '' ) );
		return null !== $start && null !== $end && $end <= $start;
	}

	/**
	 * Hours a week.
	 *
	 * @param array[] $days Weekday => day.
	 * @return float
	 */
	public static function perWeek( array $days ): float {
		return round( array_sum( array_map( array( self::class, 'forDay' ), $days ) ), 4 );
	}

	/**
	 * Hours in a period, counting each shift on the day it starts.
	 *
	 * @param array[] $days Weekday => day.
	 * @param string  $from First day (Y-m-d).
	 * @param string  $to   Last day (Y-m-d).
	 * @return float
	 */
	public static function inPeriod( array $days, string $from, string $to ): float {
		$utc   = new DateTimeZone( 'UTC' );
		$day   = new DateTimeImmutable( $from, $utc );
		$last  = new DateTimeImmutable( $to, $utc );
		$total = 0.0;
		while ( $day <= $last ) {
			$total += self::forDay( (array) ( $days[ (int) $day->format( 'N' ) ] ?? array( 'is_day_off' => true ) ) );
			$day    = $day->modify( '+1 day' );
		}
		return round( $total, 4 );
	}

	/**
	 * `H:i` (or `H:i:s`) → minutes after midnight; null when not a time.
	 *
	 * @param string $time Time.
	 * @return int|null
	 */
	public static function minutes( string $time ): ?int {
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(:\d{2})?$/', $time, $m ) ) {
			return null;
		}
		return (int) $m[1] * 60 + (int) $m[2];
	}
}
