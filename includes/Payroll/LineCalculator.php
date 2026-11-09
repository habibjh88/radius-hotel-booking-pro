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
 *    bonus, commission and transport add the amount typed on the run, else
 *    the Pay tab's amount or percentage of base pay — the bonus only in a
 *    run that pays bonuses (`pay_bonus`). Hours may be set per
 *    day (a day = the shift's average working day).
 * 3. **Gratification, then vacation pay** — a percentage of the earnings
 *    that count (regular pay less what was taken off, plus the chosen
 *    earnings), or an amount: added to gross (`pay`) or kept for later
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
	 *                  items: { type: settings }, entries: { type: hours|amount }, pay_bonus (the Pay tab's bonus counts),
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
		// What each earning came to, for vacation pay and gratification's "earnings that count" (R2-T6).
		$counts   = array();
		$per_rate = static function ( string $code ) use ( $rate, &$errors ): ?string {
			if ( null === $rate ) {
				$errors[ "entries.$code" ] = __( 'Give the employee a work shift first: their hourly rate comes from its hours.', 'radius-hotel-booking-pro' );
				return null;
			}
			return $rate;
		};
		foreach ( $types as $code => $def ) {
			// Vacation pay and gratification are worked out below, from the earnings that count.
			if ( ! array_key_exists( $code, $items ) || in_array( $code, array( 'vacation_pay', 'gratification' ), true ) ) {
				continue;
			}
			$settings = (array) $items[ $code ];
			if ( isset( $entries[ $code ] ) && '' !== (string) $entries[ $code ] ) {
				$quantity = Decimal::max( '0', Decimal::of( $entries[ $code ] ) );
			} elseif ( 'amount' === $def['entry'] ) {
				// A bonus is paid once or twice a year: the Pay tab's only in a run that pays bonuses.
				if ( 'bonus' === $code && empty( $in['pay_bonus'] ) ) {
					continue;
				}
				// Nothing typed on the run: the Pay tab's amount or percentage (R2-T3, R2-T5).
				$quantity = self::periodAmount( $settings, $base );
				if ( null === $quantity ) {
					continue;
				}
			} else {
				continue;
			}
			if ( 0 === Decimal::cmp( $quantity, '0' ) ) {
				continue;
			}
			// Set per day (R2-T1): days become hours here, so the yearly quota, the
			// ledger and the hourly rate keep working in hours; the line shows days.
			$day_hours = null;
			if ( 'hours' === $def['entry'] && 'day' === PayItemTypes::unit( $settings ) ) {
				$day_hours = Decimal::of( (float) ( $in['hours_per_day'] ?? 0 ) );
				if ( Decimal::cmp( $day_hours, '0' ) <= 0 ) {
					$errors[ "entries.$code" ] = __( 'Give the employee a work shift first: a day’s hours come from it.', 'radius-hotel-booking-pro' );
					continue;
				}
				$quantity = Decimal::mul( $quantity, $day_hours );
				if ( isset( $settings['max'] ) ) {
					$settings['max'] = Decimal::mul( Decimal::of( (string) $settings['max'] ), $day_hours );
				}
				if ( isset( $settings['rate'] ) && '' !== (string) $settings['rate'] ) {
					$settings['rate'] = Decimal::div( Decimal::of( (string) $settings['rate'] ), $day_hours );
				}
			}
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
						$line['amount']     = Decimal::money( Decimal::mul( $quantity, $line['rate'] ) );
						$gross              = Decimal::add( $gross, $line['amount'] );
						$counts['overtime'] = $line['amount'];
					}
					break;
				case 'paid_time_off':
				case 'sick_pay':
					// Inside the base pay; the hours over the yearly quota are taken off.
					$quota  = Decimal::of( (string) ( $settings['max'] ?? 0 ) );
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
					$counts[ $code ]  = $line['amount'];
			}
			if ( null !== $day_hours ) {
				$line['unit'] = 'day';
				foreach ( array( 'quantity', 'over' ) as $key ) {
					if ( isset( $line[ $key ] ) ) {
						$line[ $key ] = Decimal::div( $line[ $key ], $day_hours );
					}
				}
				if ( null !== $line['rate'] ) {
					$line['rate'] = Decimal::mul( $line['rate'], $day_hours );
				}
			}
			$earnings[] = $line;
		}
		if ( $errors ) {
			throw DomainException::invalid( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
		}

		// 2b. Bonus rules (R1-T17): a fixed amount or a % of the base pay; a taxable
		// one is an earning, a non-taxable one is added to net pay with the custom items.
		$rule_custom = array();
		foreach ( (array) ( $in['bonuses'] ?? array() ) as $bonus ) {
			$value  = Decimal::max( '0', Decimal::of( (string) ( $bonus['value'] ?? '0' ) ) );
			$amount = 'percent' === ( $bonus['kind'] ?? '' )
				? Decimal::money( Decimal::mul( $base, Decimal::div( $value, '100' ) ) )
				: Decimal::money( $value );
			if ( 0 === Decimal::cmp( $amount, '0' ) ) {
				continue;
			}
			if ( ! empty( $bonus['taxable'] ) ) {
				$earnings[] = array(
					'code'     => 'bonus_rule',
					'key'      => (string) ( $bonus['key'] ?? '' ),
					'label'    => (string) ( $bonus['label'] ?? '' ),
					'quantity' => null,
					'rate'     => 'percent' === ( $bonus['kind'] ?? '' ) ? $value : null,
					'amount'   => $amount,
					'effect'   => 'add',
				);
				$gross                 = Decimal::add( $gross, $amount );
				$counts['bonus_rules'] = Decimal::add( $counts['bonus_rules'] ?? '0', $amount );
			} else {
				$rule_custom[] = array(
					'type'    => 'addition',
					'name'    => (string) ( $bonus['label'] ?? '' ),
					'amount'  => $amount,
					'taxable' => false,
				);
			}
		}
		// 2c. Additions (R2-T8): an amount or a % of base pay on every payslip; a
		// taxable one is an earning, the others are added to net pay.
		foreach ( (array) ( $in['deductions'] ?? array() ) as $row ) {
			if ( 'addition' !== ( $row['kind'] ?? '' ) ) {
				continue;
			}
			$percent = 'percent' === ( $row['amount_type'] ?? '' );
			$amount  = $percent
				? Decimal::money( Decimal::mul( $base, Decimal::div( Decimal::of( (string) ( $row['percent'] ?? '0' ) ), '100' ) ) )
				: Decimal::money( Decimal::of( (string) ( $row['amount'] ?? '0' ) ) );
			if ( Decimal::cmp( $amount, '0' ) <= 0 ) {
				continue;
			}
			if ( 'taxable' === ( $row['type'] ?? '' ) ) {
				$earnings[] = array(
					'code'     => 'addition',
					'key'      => (string) ( $row['id'] ?? '' ),
					'label'    => (string) ( $row['name'] ?? '' ),
					'quantity' => null,
					'rate'     => $percent ? (string) ( $row['percent'] ?? '0' ) : null,
					'amount'   => $amount,
					'effect'   => 'add',
				);
				$gross      = Decimal::add( $gross, $amount );
			} else {
				$rule_custom[] = array(
					'type'    => 'addition',
					'name'    => (string) ( $row['name'] ?? '' ),
					'amount'  => $amount,
					'taxable' => false,
				);
			}
		}

		$gross             = Decimal::max( '0', Decimal::sub( $gross, $off ) );
		$counts['regular'] = Decimal::max( '0', Decimal::sub( $base, $off ) );

		// 3. Gratification, then vacation pay (R2-T2, R2-T4): a percentage of the
		// earnings that count, or an amount; paid now or kept through the year.
		// A paid gratification counts towards vacation pay, never the reverse.
		$gratification = null;
		if ( isset( $items['gratification'] ) ) {
			$typed         = isset( $entries['gratification'] ) && '' !== (string) $entries['gratification'] ? Decimal::max( '0', Decimal::of( $entries['gratification'] ) ) : null;
			$gratification = self::accrual( (array) $items['gratification'], $counts, $typed );
			if ( 'pay' === $gratification['method'] ) {
				$gross                   = Decimal::add( $gross, $gratification['amount'] );
				$counts['gratification'] = $gratification['amount'];
			} elseif ( Decimal::cmp( $gratification['amount'], '0' ) > 0 ) {
				$ledger[] = array(
					'code'   => 'gratification_retained',
					'amount' => $gratification['amount'],
				);
			}
			if ( 0 === Decimal::cmp( $gratification['amount'], '0' ) ) {
				$gratification = null;
			}
		}
		$vacation = null;
		if ( isset( $items['vacation_pay'] ) ) {
			$vacation = self::accrual( (array) $items['vacation_pay'], $counts, null );
			if ( 'pay' === $vacation['method'] ) {
				$gross = Decimal::add( $gross, $vacation['amount'] );
			} elseif ( Decimal::cmp( $vacation['amount'], '0' ) > 0 ) {
				$ledger[] = array(
					'code'   => 'vacation_retained',
					'amount' => $vacation['amount'],
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
		foreach ( array_merge( $rule_custom, (array) ( $in['custom'] ?? array() ) ) as $item ) {
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
			'gratification'       => $gratification,
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
	 * A pay set on the Pay tab for each period (R2-T3, R2-T5): its amount, or
	 * a percentage of the base pay; null when nothing is set (typed on the run).
	 *
	 * @param array  $settings The type's settings.
	 * @param string $base     Base pay of the period.
	 * @return string|null
	 */
	private static function periodAmount( array $settings, string $base ): ?string {
		if ( 'percent' === ( $settings['calc'] ?? '' ) ) {
			return isset( $settings['percent'] ) && '' !== (string) $settings['percent']
				? Decimal::money( Decimal::mul( $base, Decimal::div( Decimal::of( (string) $settings['percent'] ), '100' ) ) )
				: null;
		}
		return isset( $settings['amount'] ) && '' !== (string) $settings['amount'] ? Decimal::money( Decimal::of( (string) $settings['amount'] ) ) : null;
	}

	/**
	 * Vacation pay or gratification for the period (R2-T2, R2-T4).
	 *
	 * @param array       $settings Its settings: method, calc, percent, amount, payitems.
	 * @param array       $counts   Earning => amount this period (`regular` already less time off).
	 * @param string|null $typed    An amount typed on the run line, which replaces the setting.
	 * @return array `{ method, calc, base, percent, amount }` (`base`, `percent` null for an amount).
	 */
	private static function accrual( array $settings, array $counts, ?string $typed ): array {
		$method = 'retain' === ( $settings['method'] ?? '' ) ? 'retain' : 'pay';
		if ( null !== $typed || 'amount' === ( $settings['calc'] ?? '' ) ) {
			return array(
				'method'  => $method,
				'calc'    => 'amount',
				'base'    => null,
				'percent' => null,
				'amount'  => Decimal::money( $typed ?? Decimal::of( (string) ( $settings['amount'] ?? '0' ) ) ),
			);
		}
		$base = '0';
		foreach ( array_unique( array_merge( array( 'regular' ), (array) ( $settings['payitems'] ?? array() ) ) ) as $key ) {
			$base = Decimal::add( $base, $counts[ $key ] ?? '0' );
		}
		$percent = (string) ( $settings['percent'] ?? '' );
		return array(
			'method'  => $method,
			'calc'    => 'percent',
			'base'    => $base,
			'percent' => '' !== $percent ? $percent : '0',
			'amount'  => Decimal::money( Decimal::mul( $base, Decimal::div( Decimal::of( '' !== $percent ? $percent : '0' ), '100' ) ) ),
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
