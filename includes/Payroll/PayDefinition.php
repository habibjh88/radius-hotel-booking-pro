<?php
/**
 * How an employee is paid, per pay period (M15 T4b, 12.13).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. A salary is given per week, month or year and turned into the pay
 * period of the employee's schedule through its yearly amount (as the legacy
 * `Staff::getSalaryForPeriod()`): weekly ÷ 52, every two weeks ÷ 26, twice a
 * month ÷ 24, monthly ÷ 12. An hourly employee is paid rate × hours. The
 * hourly rate of a salaried employee (unpaid time off, overtime) is the
 * yearly salary ÷ (the shift's hours a week × 52), as the legacy
 * `getHourlyRate()`.
 */
final class PayDefinition {

	public const TYPES = array( 'salaried', 'hourly' );

	/**
	 * Salary periods => times a year.
	 */
	public const PERIODS = array(
		'week'  => '52',
		'month' => '12',
		'year'  => '1',
	);

	/**
	 * Pay intervals => pay periods a year.
	 */
	public const PERIODS_A_YEAR = array(
		'weekly'       => '52',
		'biweekly'     => '26',
		'twicemonthly' => '24',
		'monthly'      => '12',
	);

	/**
	 * A salary a year.
	 *
	 * @param mixed  $amount Salary.
	 * @param string $period week|month|year.
	 * @return string
	 * @throws InvalidArgumentException On an unknown period.
	 */
	public static function yearly( $amount, string $period ): string {
		if ( ! isset( self::PERIODS[ $period ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Unknown salary period: ' . $period );
		}
		return Decimal::mul( Decimal::of( $amount ), self::PERIODS[ $period ] );
	}

	/**
	 * A salary for one pay period of an interval.
	 *
	 * @param mixed  $amount   Salary.
	 * @param string $period   week|month|year.
	 * @param string $interval weekly|biweekly|twicemonthly|monthly.
	 * @return string
	 * @throws InvalidArgumentException On an unknown interval.
	 */
	public static function salaryForPeriod( $amount, string $period, string $interval ): string {
		if ( ! isset( self::PERIODS_A_YEAR[ $interval ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Unknown pay interval: ' . $interval );
		}
		return Decimal::div( self::yearly( $amount, $period ), self::PERIODS_A_YEAR[ $interval ] );
	}

	/**
	 * The hourly rate: the rate itself, or a salary over the shift's hours.
	 *
	 * @param string $type           salaried|hourly.
	 * @param mixed  $amount         Salary or rate.
	 * @param string $period         Salary period (salaried).
	 * @param float  $hours_per_week The work shift's hours a week (salaried).
	 * @return string|null Null when a salaried employee has no working hours (no shift).
	 */
	public static function hourlyRate( string $type, $amount, string $period, float $hours_per_week ): ?string {
		if ( 'hourly' === $type ) {
			return Decimal::of( $amount );
		}
		if ( $hours_per_week <= 0 ) {
			return null;
		}
		return Decimal::div( self::yearly( $amount, $period ), Decimal::mul( Decimal::of( (string) $hours_per_week ), '52' ) );
	}
}
