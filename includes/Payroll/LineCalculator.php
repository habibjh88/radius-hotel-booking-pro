<?php
/**
 * One employee's payroll line for one pay period (M15 T5a, 15.1, 15.8).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: everything comes in (the pay profile, the additional pay types and
 * what was entered for them, the deductions, what the year and the month
 * have used already), the line comes out — its breakdown and its **ledger
 * entries**. The ledger (hours of paid / sick / unpaid time off, vacation
 * pay kept, each deduction and garnishment taken) is what balances are
 * summed from, over the lines of runs that count; a voided run simply stops
 * counting.
 *
 * Order (the legacy `run_payroll`, `class-request.php`):
 *
 * 1. **Base pay** — a salary: its share of the year for the period
 *    (`PayDefinition`); by the hour: hours × rate (hours default to the
 *    shift's hours in the period, worked out by the caller).
 * 2. **Additional pay** — overtime (hours × its rate, else the hourly rate);
 *    paid time off and sick pay are already inside the base pay: within the
 *    yearly quota nothing changes, the hours over it are **taken off** at
 *    the hourly rate (legacy `splitPtoEntry`); unpaid time off is taken off;
 *    bonus, commission, transport, gratification add their amount.
 * 3. **Vacation pay** — percent of the base pay (less what was taken off)
 *    plus the chosen earnings: added to gross (`pay`) or kept for later
 *    (`retain`, a ledger entry).
 * 4. **Pre-tax deductions** — an amount, or a % of gross; a monthly one is
 *    taken once a calendar month (the % then on the month's equivalent) and
 *    skipped when the month has it already; the employer share likewise.
 * 5. **Statutory** — `PayrollCalculator` on gross less the deductions.
 * 6. **Garnishments** on the take-home pay — a court order: the amount
 *    requested, at most its % of take-home pay (the legacy took the % and
 *    ignored the request — `COURT_ORDER_RULE`); another: an amount or a %,
 *    never more than what remains; never below zero net.
 * 7. **Custom items** (the legacy client request): an addition (with its own
 *    flat tax, outside the statutory lines) or a deduction, on net only.
 *
 * Every amount is rounded to the currency's precision where it is made.
 */
final class LineCalculator {

	/**
	 * Court order: `min` = the lower of the request and its % (default);
	 * `percent` = the % alone (the legacy).
	 */
	public const COURT_ORDER_RULE = 'min';

	/**
	 * Calculate a line.
	 *
	 * @param array $in `{ pay_date, interval, statutory (the calculator's settings), profile: { pay_type, amount, amount_period },
	 *                  hours (base hours of the period), hours_per_week (the shift's),
	 *                  items: { type: settings }, entries: { type: hours|amount },
	 *                  used: { paid_time_off, sick_pay } (hours used this year before this line),
	 *                  deductions: [ rows as DeductionService::shape(), active and within their dates ],
	 *                  taken_this_month: { deduction id: true }, custom: [ { type, name, amount, taxable?, tax_rate? } ] }`.
	 * @return array `{ hourly_rate, hours, earnings, vacation, gross, deductions, employer_deductions,
	 *               statutory, garnishments, custom, net, employer_cost, ledger }` (decimal strings).
	 * @throws DomainException 422 when a needed figure is missing (an hourly rate, the hours of an hourly employee).
	 */
	public static function calculate( array $in ): array {
		$profile  = (array) ( $in['profile'] ?? array() );
		$type     = (string) ( $profile['pay_type'] ?? '' );
		$interval = (string) ( $in['interval'] ?? '' );
		$items    = (array) ( $in['items'] ?? array() );
		$entries  = (array) ( $in['entries'] ?? array() );
		$used     = (array) ( $in['used'] ?? array() );
		$hours    = Decimal::of( $in['hours'] ?? '0' );
		$rate     = PayDefinition::hourlyRate( $type, $profile['amount'] ?? '0', (string) ( $profile['amount_period'] ?? 'month' ), (float) ( $in['hours_per_week'] ?? 0 ) );
		$errors   = array();

		// 1. Base pay.
		if ( 'hourly' === $type ) {
			$base = Decimal::money( Decimal::mul( $hours, (string) $rate ) );
		} elseif ( 'salaried' === $type ) {
			$base = Decimal::money( PayDefinition::salaryForPeriod( $profile['amount'] ?? '0', (string) ( $profile['amount_period'] ?? 'month' ), $interval ) );
		} else {
			throw DomainException::invalid( array( 'pay_type' => __( 'This employee has no pay set up.', 'radius-hotel-booking-pro' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
		}

		// 2. Additional pay.
		$types    = PayItemTypes::all();
		$earnings = array();
		$ledger   = array();
		$off      = '0'; // Taken off the base pay (unpaid time off, time off over the quota).
		$gross    = $base;
		$vac_base = $base;
		$per_rate = static function ( string $code ) use ( $rate, &$errors ): ?string {
			if ( null === $rate ) {
				$errors[ "entries.$code" ] = __( 'Give the employee a work shift first: their hourly rate comes from its hours.', 'radius-hotel-booking-pro' );
				return null;
			}
			return $rate;
		};
		foreach ( $types as $code => $def ) {
			if ( ! array_key_exists( $code, $items ) || ! isset( $entries[ $code ] ) || '' === (string) $entries[ $code ] || 'vacation_pay' === $code ) {
				continue;
			}
			$quantity = Decimal::max( '0', Decimal::of( $entries[ $code ] ) );
			if ( 0 === Decimal::cmp( $quantity, '0' ) ) {
				continue;
			}
			$settings = (array) $items[ $code ];
			$line     = array(
				'code'     => $code,
				'label'    => $def['label'],
				'quantity' => $quantity,
				'rate'     => null,
				'amount'   => '0',
				'effect'   => $def['effect'],
			);
			switch ( $code ) {
				case 'overtime_pay':
					$line['rate'] = isset( $settings['rate'] ) && '' !== (string) $settings['rate'] ? Decimal::of( $settings['rate'] ) : $per_rate( $code );
					if ( null !== $line['rate'] ) {
						$line['amount'] = Decimal::money( Decimal::mul( $quantity, $line['rate'] ) );
						$gross          = Decimal::add( $gross, $line['amount'] );
						if ( in_array( 'overtime', (array) ( $items['vacation_pay']['payitems'] ?? array() ), true ) ) {
							$vac_base = Decimal::add( $vac_base, $line['amount'] );
						}
					}
					break;
				case 'paid_time_off':
				case 'sick_pay':
					// Inside the base pay; the hours over the yearly quota are taken off.
					$quota  = Decimal::of( (string) (int) ( $settings['max'] ?? 0 ) );
					$left   = Decimal::max( '0', Decimal::sub( $quota, Decimal::of( (string) ( $used[ $code ] ?? '0' ) ) ) );
					$within = Decimal::min( $quantity, $left );
					$over   = Decimal::sub( $quantity, $within );
					if ( Decimal::cmp( $within, '0' ) > 0 ) {
						$ledger[] = array(
							'code'     => $code,
							'quantity' => $within,
						);
					}
					$line['quantity'] = $within;
					if ( Decimal::cmp( $over, '0' ) > 0 ) {
						$line['over'] = $over;
						$line['rate'] = $per_rate( $code );
						if ( null !== $line['rate'] ) {
							$line['amount'] = Decimal::money( Decimal::mul( $over, $line['rate'] ) );
							$line['effect'] = 'deduct';
							$off            = Decimal::add( $off, $line['amount'] );
						}
						$ledger[] = array(
							'code'     => 'unpaid_time_off',
							'quantity' => $over,
						);
					}
					break;
				case 'unpaid_time_off':
					$line['rate'] = $per_rate( $code );
					if ( null !== $line['rate'] ) {
						$line['amount'] = Decimal::money( Decimal::mul( $quantity, $line['rate'] ) );
						$off            = Decimal::add( $off, $line['amount'] );
					}
					$ledger[] = array(
						'code'     => $code,
						'quantity' => $quantity,
					);
					break;
				default:
					// An amount entered for the period.
					$line['quantity'] = null;
					$line['amount']   = Decimal::money( $quantity );
					$gross            = Decimal::add( $gross, $line['amount'] );
					if ( in_array( $code, array( 'bonus', 'commission' ), true ) && in_array( $code, (array) ( $items['vacation_pay']['payitems'] ?? array() ), true ) ) {
						$vac_base = Decimal::add( $vac_base, $line['amount'] );
					}
			}
			$earnings[] = $line;
		}
		if ( $errors ) {
			throw DomainException::invalid( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
		}
		$gross    = Decimal::max( '0', Decimal::sub( $gross, $off ) );
		$vac_base = Decimal::max( '0', Decimal::sub( $vac_base, $off ) );

		// 3. Vacation pay.
		$vacation = null;
		if ( isset( $items['vacation_pay'] ) ) {
			$settings = (array) $items['vacation_pay'];
			$amount   = Decimal::money( Decimal::mul( $vac_base, Decimal::div( Decimal::of( (string) ( $settings['percent'] ?? '0' ) ), '100' ) ) );
			$method   = 'retain' === ( $settings['method'] ?? '' ) ? 'retain' : 'pay';
			$vacation = array(
				'method'  => $method,
				'base'    => $vac_base,
				'percent' => (string) ( $settings['percent'] ?? '0' ),
				'amount'  => $amount,
			);
			if ( 'pay' === $method ) {
				$gross = Decimal::add( $gross, $amount );
			} elseif ( Decimal::cmp( $amount, '0' ) > 0 ) {
				$ledger[] = array(
					'code'   => 'vacation_retained',
					'amount' => $amount,
				);
			}
		}

		// 4. Pre-tax deductions.
		$taken    = (array) ( $in['taken_this_month'] ?? array() );
		$pre_tax  = array();
		$employer = array();
		foreach ( (array) ( $in['deductions'] ?? array() ) as $row ) {
			if ( 'deduction' !== ( $row['kind'] ?? '' ) ) {
				continue;
			}
			$monthly = 'per_month' === ( $row['calculation'] ?? '' );
			if ( $monthly && ! empty( $taken[ (int) $row['id'] ] ) ) {
				continue;
			}
			$employee_share = self::share( (string) $row['amount_type'], $row['amount'] ?? '0', $row['percent'] ?? '0', $gross, $monthly, $interval );
			$employer_share = 'none' === ( $row['employer_amount_type'] ?? 'none' ) ? '0' : self::share( (string) $row['employer_amount_type'], $row['employer_amount'] ?? '0', $row['employer_percent'] ?? '0', $gross, $monthly, $interval );
			$pre_tax[]      = array(
				'key'             => 'deduction_' . (int) $row['id'],
				'label'           => (string) $row['name'],
				'amount'          => $employee_share,
				'employer_amount' => $employer_share,
			);
			$employer[]     = array(
				'ref'    => (int) $row['id'],
				'label'  => (string) $row['name'],
				'amount' => $employer_share,
			);
		}

		// 5. Statutory (before garnishments: they are taken from take-home pay).
		// The calculator's own figures, frozen with the line (`statutory`; older
		// lines kept a top-level `injury_rate`).
		$common    = array(
			'gross'    => $gross,
			'interval' => $interval,
			'pay_date' => (string) ( $in['pay_date'] ?? '' ),
			'pre_tax'  => $pre_tax,
		) + (array) ( $in['statutory'] ?? array() ) + ( isset( $in['injury_rate'] ) ? array( 'injury_rate' => (string) $in['injury_rate'] ) : array() );
		$statutory = Statutory::calculator()->calculate( $common );
		$take_home = $statutory['net'];

		// 6. Garnishments.
		$post_tax = array();
		foreach ( (array) ( $in['deductions'] ?? array() ) as $row ) {
			if ( 'garnishment' !== ( $row['kind'] ?? '' ) ) {
				continue;
			}
			$monthly = 'per_month' === ( $row['calculation'] ?? '' );
			if ( $monthly && ! empty( $taken[ (int) $row['id'] ] ) ) {
				continue;
			}
			if ( 'court_order' === $row['type'] ) {
				$cap    = Decimal::mul( $take_home, Decimal::div( Decimal::of( (string) $row['max_percent'] ), '100' ) );
				$amount = 'percent' === self::COURT_ORDER_RULE ? $cap : Decimal::min( Decimal::of( (string) $row['total_amount'] ), $cap );
			} else {
				$amount = self::share( (string) $row['amount_type'], $row['amount'] ?? '0', $row['percent'] ?? '0', $take_home, $monthly, $interval );
				$amount = Decimal::min( $amount, Decimal::max( '0', Decimal::of( (string) ( $row['remaining_amount'] ?? '0' ) ) ) );
			}
			$post_tax[] = array(
				'key'    => 'garnishment_' . (int) $row['id'],
				'label'  => (string) $row['name'],
				'amount' => Decimal::money( $amount ),
			);
		}
		if ( $post_tax ) {
			$statutory = Statutory::calculator()->calculate( $common + array( 'post_tax' => $post_tax ) );
		}
		foreach ( $statutory['pre_tax'] as $taken_row ) {
			if ( 0 === Decimal::cmp( $taken_row['amount'], '0' ) && 0 === Decimal::cmp( $taken_row['employer_amount'], '0' ) ) {
				continue;
			}
			$ledger[] = array(
				'code'     => 'deduction',
				'ref'      => (int) substr( $taken_row['key'], strlen( 'deduction_' ) ),
				'amount'   => $taken_row['amount'],
				'employer' => $taken_row['employer_amount'],
			);
		}
		foreach ( $statutory['post_tax'] as $taken_row ) {
			if ( 0 === Decimal::cmp( $taken_row['amount'], '0' ) ) {
				continue;
			}
			$ledger[] = array(
				'code'   => 'garnishment',
				'ref'    => (int) substr( $taken_row['key'], strlen( 'garnishment_' ) ),
				'amount' => $taken_row['amount'],
			);
		}

		// 7. Custom items, on net only.
		$custom      = array();
		$custom_net  = '0';
		$custom_cost = '0';
		foreach ( (array) ( $in['custom'] ?? array() ) as $item ) {
			$amount = Decimal::money( Decimal::max( '0', Decimal::of( $item['amount'] ?? '0' ) ) );
			if ( 0 === Decimal::cmp( $amount, '0' ) ) {
				continue;
			}
			$deduct  = 'deduction' === ( $item['type'] ?? '' );
			$taxable = ! $deduct && ! empty( $item['taxable'] );
			$tax     = $taxable ? Decimal::money( Decimal::mul( $amount, Decimal::div( Decimal::of( (string) max( 0, min( 100, (float) ( $item['tax_rate'] ?? 0 ) ) ) ), '100' ) ) ) : '0';
			$effect  = $deduct ? Decimal::sub( '0', $amount ) : Decimal::sub( $amount, $tax );
			$custom[]   = array(
				'type'   => $deduct ? 'deduction' : 'addition',
				'name'   => sanitize_text_field( (string) ( $item['name'] ?? '' ) ),
				'amount' => $amount,
				'tax'    => $tax,
				'net'    => $effect,
			);
			$custom_net  = Decimal::add( $custom_net, $effect );
			$custom_cost = $deduct ? Decimal::sub( $custom_cost, $amount ) : Decimal::add( $custom_cost, $amount );
		}

		$employer_deductions = $statutory['employer_deductions_total'];
		return array(
			'hourly_rate'         => $rate,
			'hours'               => $hours,
			'base'                => $base,
			'earnings'            => $earnings,
			'taken_off'           => $off,
			'vacation'            => $vacation,
			'gross'               => $gross,
			'deductions'          => $statutory['pre_tax'],
			'employer_deductions' => $employer,
			'statutory'           => $statutory,
			'garnishments'        => $statutory['post_tax'],
			'custom'              => $custom,
			'net'                 => Decimal::add( $statutory['net'], $custom_net ),
			// What the line costs the hotel: gross, its statutory share, its deduction shares
			// and the one-off items (an addition's full amount, its tax included; a deduction kept back).
			'employer_cost'       => Decimal::add( Decimal::add( Decimal::add( $gross, $statutory['employer_total'] ), $employer_deductions ), $custom_cost ),
			'ledger'              => $ledger,
		);
	}

	/**
	 * A share: a fixed amount, or a % of a base; a monthly % on the month's equivalent.
	 *
	 * @param string $kind     amount|percent.
	 * @param mixed  $amount   Amount.
	 * @param mixed  $percent  Percent.
	 * @param string $base     Base (gross before tax, take-home after).
	 * @param bool   $monthly  Taken once a month.
	 * @param string $interval Pay interval.
	 * @return string Rounded to the currency's precision.
	 */
	private static function share( string $kind, $amount, $percent, string $base, bool $monthly, string $interval ): string {
		if ( 'percent' !== $kind ) {
			return Decimal::money( Decimal::max( '0', Decimal::of( (string) $amount ) ) );
		}
		$value = Decimal::mul( $base, Decimal::div( Decimal::of( (string) $percent ), '100' ) );
		if ( $monthly ) {
			list( $num, $den ) = Decimal::ratio( Statutory::INTERVALS[ $interval ] ?? '1' );
			$value             = Decimal::div( Decimal::mul( $value, $num ), $den );
		}
		return Decimal::money( $value );
	}
}
