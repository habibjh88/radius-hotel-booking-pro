<?php
/**
 * The steps every statutory calculator shares.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory;

use InvalidArgumentException;
use RadiusTheme\RadiusHotelBookingPro\Payroll\Decimal;
use RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: no database, no clock — everything comes in, the whole breakdown
 * comes out, so a run line can be recalculated and checked.
 *
 * 1. `gross` per period; pre-tax deductions come off → the **taxable** pay;
 * 2. taxable pay → its **monthly** equivalent (the interval's factor), on
 *    which the subclass works out its lines (`monthlyLines()`): caps and
 *    brackets are monthly figures;
 * 3. each monthly amount back to the period, **rounded per line** to the
 *    currency's precision;
 * 4. net = taxable − employee lines − garnishments (after tax), never below 0.
 */
abstract class AbstractCalculator implements Calculator {

	/**
	 * The site currency's decimals (override to fix them).
	 *
	 * @return int|null
	 */
	public function places(): ?int {
		return null;
	}

	/**
	 * The lines on the monthly taxable pay.
	 *
	 * @param string $monthly Monthly taxable pay.
	 * @param array  $input   The calculate() input.
	 * @return array `{ version: string, lines: { KEY: { code, authority, side (employee|employer),
	 *               kind (tax|contribution), label, base, rate (fraction), amount } } }` — monthly, unrounded.
	 */
	abstract protected function monthlyLines( string $monthly, array $input ): array;

	/**
	 * Calculate.
	 *
	 * @param array $input See Calculator::calculate().
	 * @return array See Calculator::calculate().
	 * @throws InvalidArgumentException On bad input.
	 */
	public function calculate( array $input ): array {
		$interval = (string) ( $input['interval'] ?? '' );
		if ( ! isset( Statutory::INTERVALS[ $interval ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Unknown pay interval: ' . $interval );
		}
		$gross = Decimal::of( $input['gross'] ?? '0' );
		if ( Decimal::cmp( $gross, '0' ) < 0 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Gross pay cannot be negative.' );
		}

		// 1. Pre-tax deductions (each rounded), capped at the gross.
		$pre       = array();
		$pre_total = '0';
		$employer  = '0';
		foreach ( (array) ( $input['pre_tax'] ?? array() ) as $item ) {
			$amount    = Decimal::money( Decimal::max( '0', Decimal::of( $item['amount'] ?? '0' ) ) );
			$amount    = Decimal::min( $amount, Decimal::sub( $gross, $pre_total ) );
			$share     = Decimal::money( Decimal::max( '0', Decimal::of( $item['employer_amount'] ?? '0' ) ) );
			$pre_total = Decimal::add( $pre_total, $amount );
			$employer  = Decimal::add( $employer, $share );
			$pre[]     = array(
				'key'             => (string) ( $item['key'] ?? '' ),
				'label'           => (string) ( $item['label'] ?? '' ),
				'amount'          => $amount,
				'employer_amount' => $share,
			);
		}
		$taxable = Decimal::sub( $gross, $pre_total );

		// 2. Monthly figures.
		list( $num, $den ) = Decimal::ratio( Statutory::INTERVALS[ $interval ] );
		$to_month          = static fn( string $amount ): string => Decimal::div( Decimal::mul( $amount, $num ), $den );
		$to_period         = static fn( string $amount ): string => Decimal::div( Decimal::mul( $amount, $den ), $num );
		$monthly           = $to_month( $taxable );
		$result            = $this->monthlyLines( $monthly, $input );

		// 3. Back to the period, rounded per line.
		$lines          = array();
		$employee_total = '0';
		$employer_total = '0';
		foreach ( (array) $result['lines'] as $key => $line ) {
			$amount        = Decimal::money( $to_period( Decimal::of( (string) $line['amount'] ) ) );
			$lines[ $key ] = array(
				'code'      => (string) $line['code'],
				'authority' => (string) $line['authority'],
				'side'      => 'employer' === $line['side'] ? 'employer' : 'employee',
				'kind'      => (string) ( $line['kind'] ?? 'contribution' ),
				'label'     => (string) $line['label'],
				'base'      => $to_period( Decimal::of( (string) $line['base'] ) ),
				'rate'      => Decimal::mul( Decimal::of( (string) $line['rate'] ), '100' ),
				'amount'    => $amount,
			);
			if ( 'employee' === $lines[ $key ]['side'] ) {
				$employee_total = Decimal::add( $employee_total, $amount );
			} else {
				$employer_total = Decimal::add( $employer_total, $amount );
			}
		}

		// 4. Garnishments after tax, never below zero net.
		$after      = Decimal::sub( $taxable, $employee_total );
		$post       = array();
		$post_total = '0';
		foreach ( (array) ( $input['post_tax'] ?? array() ) as $item ) {
			$amount     = Decimal::money( Decimal::max( '0', Decimal::of( $item['amount'] ?? '0' ) ) );
			$amount     = Decimal::min( $amount, Decimal::max( '0', Decimal::sub( $after, $post_total ) ) );
			$post_total = Decimal::add( $post_total, $amount );
			$post[]     = array(
				'key'    => (string) ( $item['key'] ?? '' ),
				'label'  => (string) ( $item['label'] ?? '' ),
				'amount' => $amount,
			);
		}

		return array(
			'table_version'             => $this->key() . ':' . (string) $result['version'],
			'interval'                  => $interval,
			'gross'                     => $gross,
			'pre_tax'                   => $pre,
			'pre_tax_total'             => $pre_total,
			'employer_deductions_total' => $employer,
			'taxable'                   => $taxable,
			'monthly_taxable'           => $monthly,
			'lines'                     => $lines,
			'employee_total'            => $employee_total,
			'employer_total'            => $employer_total,
			'post_tax'                  => $post,
			'post_tax_total'            => $post_total,
			'net'                       => Decimal::money( Decimal::sub( $after, $post_total ) ),
		);
	}

	/**
	 * A bracket scale on a base: `[ [ up_to|null, rate ], … ]`, rates as
	 * fractions. `progressive`: each slice at its own rate; `flat`: the rate
	 * of the bracket the base falls in, on the whole base.
	 *
	 * @param string $base     Base.
	 * @param array  $brackets Brackets, ascending.
	 * @param string $mode     `progressive` or `flat`.
	 * @return array `{ amount, rate }` (rate: the effective fraction).
	 */
	public static function scale( string $base, array $brackets, string $mode = 'progressive' ): array {
		if ( Decimal::cmp( $base, '0' ) <= 0 ) {
			return array(
				'amount' => '0',
				'rate'   => '0',
			);
		}
		// The last bracket always runs to infinity: a limit there would leave the top untaxed.
		$brackets = array_values( $brackets );
		if ( $brackets ) {
			$brackets[ count( $brackets ) - 1 ][0] = null;
		}
		if ( 'flat' === $mode ) {
			foreach ( $brackets as list( $up_to, $rate ) ) {
				if ( null === $up_to || '' === (string) $up_to || Decimal::cmp( $base, Decimal::of( (string) $up_to ) ) <= 0 ) {
					return array(
						'amount' => Decimal::mul( $base, Decimal::of( (string) $rate ) ),
						'rate'   => Decimal::of( (string) $rate ),
					);
				}
			}
			return array(
				'amount' => '0',
				'rate'   => '0',
			);
		}
		$amount = '0';
		$from   = '0';
		foreach ( $brackets as list( $up_to, $rate ) ) {
			$top   = null === $up_to || '' === (string) $up_to ? $base : Decimal::min( $base, Decimal::of( (string) $up_to ) );
			$slice = Decimal::sub( $top, $from );
			if ( Decimal::cmp( $slice, '0' ) > 0 ) {
				$amount = Decimal::add( $amount, Decimal::mul( $slice, Decimal::of( (string) $rate ) ) );
			}
			if ( null === $up_to || '' === (string) $up_to || Decimal::cmp( $base, Decimal::of( (string) $up_to ) ) <= 0 ) {
				break;
			}
			$from = Decimal::of( (string) $up_to );
		}
		return array(
			'amount' => $amount,
			'rate'   => Decimal::div( $amount, $base ),
		);
	}
}
