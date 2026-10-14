<?php
/**
 * Pay periods of a schedule (M15 T3a, 15.9).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Pure date arithmetic on calendar dates (`Y-m-d`, no time, no time zone
 * shifts). A schedule is an interval plus one anchor period: its last day
 * `E` and its pay day `P` (`P` ≥ `E`); period `k` (any integer, 0 = the
 * anchor) is:
 *
 * - weekly / biweekly: ends `E + 7k` / `E + 14k` days, starts 6 / 13 days before;
 * - monthly: ends `k` months after `E` (a month-end anchor stays on month
 *   ends; another day is clamped to short months), starts the day after the
 *   previous end;
 * - twice-monthly: the halves of the month, 1–15 and 16–last day (`E` must
 *   be the 15th or a month end) — the legacy system stepped 15 days, which
 *   drifts off the month;
 *
 * and is paid `P − E` days after its end. The period to pay next is the
 * first one whose pay day is today or later.
 */
final class PayPeriods {

	/**
	 * Interval => [ label, longest pay delay in days ].
	 *
	 * @return array<string, array{0:string, 1:int}>
	 */
	public static function intervals(): array {
		return array(
			'weekly'       => array( __( 'Weekly', 'radius-hotel-booking-pro' ), 7 ),
			'biweekly'     => array( __( 'Every two weeks', 'radius-hotel-booking-pro' ), 14 ),
			'twicemonthly' => array( __( 'Twice a month', 'radius-hotel-booking-pro' ), 15 ),
			'monthly'      => array( __( 'Monthly', 'radius-hotel-booking-pro' ), 31 ),
		);
	}

	/**
	 * Period `k` of a schedule.
	 *
	 * @param string $interval   Interval.
	 * @param string $period_end Anchor period end (Y-m-d).
	 * @param string $pay_day    Anchor pay day (Y-m-d).
	 * @param int    $k          Period index (0 = the anchor).
	 * @return array{index:int, start:string, end:string, pay_date:string}
	 * @throws InvalidArgumentException On a bad schedule.
	 */
	public static function period( string $interval, string $period_end, string $pay_day, int $k ): array {
		$end   = self::date( $period_end );
		$delay = self::delay( $period_end, $pay_day );

		switch ( $interval ) {
			case 'weekly':
			case 'biweekly':
				$days  = 'weekly' === $interval ? 7 : 14;
				$e     = $end->modify( sprintf( '%+d days', $k * $days ) );
				$start = $e->modify( sprintf( '-%d days', $days - 1 ) );
				break;
			case 'monthly':
				$e     = self::monthEnd( $end, $k );
				$start = self::monthEnd( $end, $k - 1 )->modify( '+1 day' );
				break;
			case 'twicemonthly':
				$half  = self::half( $end ) + $k;
				$e     = self::halfEnd( $half );
				$start = self::halfEnd( $half - 1 )->modify( '+1 day' );
				break;
			default:
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
				throw new InvalidArgumentException( 'Unknown pay interval: ' . $interval );
		}

		return array(
			'index'    => $k,
			'start'    => $start->format( 'Y-m-d' ),
			'end'      => $e->format( 'Y-m-d' ),
			'pay_date' => $e->modify( sprintf( '+%d days', $delay ) )->format( 'Y-m-d' ),
		);
	}

	/**
	 * The period to pay next on a day: the first whose pay day is that day or later.
	 *
	 * @param string $interval   Interval.
	 * @param string $period_end Anchor period end.
	 * @param string $pay_day    Anchor pay day.
	 * @param string $today      Y-m-d.
	 * @return array{index:int, start:string, end:string, pay_date:string}
	 */
	public static function next( string $interval, string $period_end, string $pay_day, string $today ): array {
		$avg = array(
			'weekly'       => 7,
			'biweekly'     => 14,
			'twicemonthly' => 15.2,
			'monthly'      => 30.4,
		)[ $interval ] ?? 30.4;
		// Estimate, then step to the exact period (a few steps at most).
		$k = (int) floor( ( self::date( $today )->getTimestamp() - self::date( $pay_day )->getTimestamp() ) / DAY_IN_SECONDS / $avg );
		while ( self::period( $interval, $period_end, $pay_day, $k )['pay_date'] < $today ) {
			++$k;
		}
		while ( self::period( $interval, $period_end, $pay_day, $k - 1 )['pay_date'] >= $today ) {
			--$k;
		}
		return self::period( $interval, $period_end, $pay_day, $k );
	}

	/**
	 * Why a schedule is invalid ('' when valid): field => message.
	 *
	 * @param string $interval   Interval.
	 * @param string $period_end Anchor period end.
	 * @param string $pay_day    Anchor pay day.
	 * @return array<string, string>
	 */
	public static function problems( string $interval, string $period_end, string $pay_day ): array {
		$errors    = array();
		$intervals = self::intervals();
		if ( ! isset( $intervals[ $interval ] ) ) {
			$errors['pay_interval'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
		}
		if ( ! self::valid( $period_end ) ) {
			$errors['period_end'] = __( 'Enter a valid date.', 'radius-hotel-booking-pro' );
		}
		if ( ! self::valid( $pay_day ) ) {
			$errors['next_pay_day'] = __( 'Enter a valid date.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			return $errors;
		}
		$delay = self::delay( $period_end, $pay_day );
		if ( $delay < 0 ) {
			$errors['next_pay_day'] = __( 'The pay day cannot be before the end of the pay period.', 'radius-hotel-booking-pro' );
		} elseif ( $delay > $intervals[ $interval ][1] ) {
			$errors['next_pay_day'] = sprintf(
				/* translators: %d: number of days. */
				_n( 'Pay at most %d day after the end of the pay period.', 'Pay at most %d days after the end of the pay period.', $intervals[ $interval ][1], 'radius-hotel-booking-pro' ),
				$intervals[ $interval ][1]
			);
		}
		if ( 'twicemonthly' === $interval ) {
			$end = self::date( $period_end );
			if ( '15' !== $end->format( 'd' ) && $end->format( 'd' ) !== $end->format( 't' ) ) {
				$errors['period_end'] = __( 'Twice a month, a pay period ends on the 15th or on the last day of the month.', 'radius-hotel-booking-pro' );
			}
		}
		return $errors;
	}

	/**
	 * A valid calendar date.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function valid( string $value ): bool {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Days from the period end to the pay day.
	 *
	 * @param string $period_end End.
	 * @param string $pay_day    Pay day.
	 * @return int
	 */
	private static function delay( string $period_end, string $pay_day ): int {
		return (int) self::date( $period_end )->diff( self::date( $pay_day ) )->format( '%r%a' );
	}

	/**
	 * A date at midnight UTC.
	 *
	 * @param string $value Y-m-d.
	 * @return DateTimeImmutable
	 * @throws InvalidArgumentException On a bad date.
	 */
	private static function date( string $value ): DateTimeImmutable {
		if ( ! self::valid( $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Not a date: ' . $value );
		}
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	}

	/**
	 * The anchor's day `k` months later: a month end stays a month end, another day is clamped.
	 *
	 * @param DateTimeImmutable $end Anchor end.
	 * @param int               $k   Months.
	 * @return DateTimeImmutable
	 */
	private static function monthEnd( DateTimeImmutable $end, int $k ): DateTimeImmutable {
		$first = $end->modify( 'first day of this month' )->modify( sprintf( '%+d months', $k ) );
		$last  = (int) $first->format( 't' );
		$day   = $end->format( 'd' ) === $end->format( 't' ) ? $last : min( (int) $end->format( 'd' ), $last );
		return $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'm' ), $day );
	}

	/**
	 * A half-month as a running number: year × 24 + month × 2 + (0 for 1–15, 1 for 16–end).
	 *
	 * @param DateTimeImmutable $end A 15th or a month end.
	 * @return int
	 */
	private static function half( DateTimeImmutable $end ): int {
		return (int) $end->format( 'Y' ) * 24 + ( (int) $end->format( 'n' ) - 1 ) * 2 + ( (int) $end->format( 'd' ) > 15 ? 1 : 0 );
	}

	/**
	 * The last day of a half-month.
	 *
	 * @param int $half Running number.
	 * @return DateTimeImmutable
	 */
	private static function halfEnd( int $half ): DateTimeImmutable {
		$year  = intdiv( $half, 24 );
		$month = intdiv( $half % 24, 2 ) + 1;
		$date  = new DateTimeImmutable( sprintf( '%04d-%02d-01', $year, $month ), new DateTimeZone( 'UTC' ) );
		return 0 === $half % 2 ? $date->setDate( $year, $month, 15 ) : $date->modify( 'last day of this month' );
	}
}
