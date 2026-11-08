<?php
/**
 * The additional pay types (M15 T4c, 12.14).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

defined( 'ABSPATH' ) || exit;

/**
 * The legacy catalogue (`RTA/payroll/class-additional-pay.php`), one place:
 * each type's label, its effect on gross pay and its settings. The effect is
 * the legacy one after its own fix — transport and gratification were once
 * applied as deductions; every type but unpaid time off **adds** to gross.
 * How a run uses them (hours × rate, the vacation percentage, the yearly
 * caps) is M15 T5a's.
 */
final class PayItemTypes {

	/**
	 * Earnings that can count towards vacation pay (R2-T6: the client's list;
	 * `bonus_rules` are the taxable bonus rules, such as seniority).
	 */
	public const VACATION_BASES = array( 'regular', 'overtime', 'bonus', 'commission', 'transportation', 'gratification', 'bonus_rules' );

	/**
	 * Earnings that can count towards gratification (all but itself).
	 */
	public const GRATIFICATION_BASES = array( 'regular', 'overtime', 'bonus', 'commission', 'transportation', 'bonus_rules' );

	/**
	 * Type => definition: `label`, `effect` (add|deduct), `entry` (hours|amount|none
	 * — what a run asks for; `hours` types may be set to days with their `unit`),
	 * `fields` (key => `{ type, required?, default?, min?, max?, options?, when? }`;
	 * `when` = the field applies only while another field has that value).
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		return array(
			'overtime_pay'    => array(
				'label'  => __( 'Overtime pay', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'hours',
				'fields' => array(
					// hour|day: the rate, the yearly allowance and a run's entries (R2-T1).
					'unit' => array(
						'type'    => 'enum',
						'options' => array( 'hour', 'day' ),
						'default' => 'hour',
					),
					// Per hour (or per day); empty = the employee's hourly rate (× a day's hours).
					'rate' => array(
						'type' => 'money',
						'min'  => 0,
					),
				),
			),
			'paid_time_off'   => array(
				'label'  => __( 'Paid time off', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'hours',
				'fields' => array(
					// hour|day: the rate, the yearly allowance and a run's entries (R2-T1).
					'unit' => array(
						'type'    => 'enum',
						'options' => array( 'hour', 'day' ),
						'default' => 'hour',
					),
					// Hours (or days) a year.
					'max' => array(
						'type'     => 'int',
						'required' => true,
						'min'      => 1,
						'max'      => 8784,
					),
				),
			),
			'unpaid_time_off' => array(
				'label'  => __( 'Unpaid time off', 'radius-hotel-booking-pro' ),
				'effect' => 'deduct',
				'entry'  => 'hours',
				'fields' => array(
					// hour|day: the rate, the yearly allowance and a run's entries (R2-T1).
					'unit' => array(
						'type'    => 'enum',
						'options' => array( 'hour', 'day' ),
						'default' => 'hour',
					),
					// Hours (or days); 0 = no limit.
					'max' => array(
						'type' => 'int',
						'min'  => 0,
						'max'  => 8784,
					),
				),
			),
			'sick_pay'        => array(
				'label'  => __( 'Sick pay', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'hours',
				'fields' => array(
					// hour|day: the rate, the yearly allowance and a run's entries (R2-T1).
					'unit' => array(
						'type'    => 'enum',
						'options' => array( 'hour', 'day' ),
						'default' => 'hour',
					),
					// Hours (or days) a year.
					'max' => array(
						'type'     => 'int',
						'required' => true,
						'min'      => 1,
						'max'      => 8784,
					),
				),
			),
			'vacation_pay'    => array(
				'label'  => __( 'Vacation pay', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'none',
				'fields' => self::accrualFields( self::VACATION_BASES, true ),
			),
			'bonus'           => array(
				'label'  => __( 'Bonus', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'amount',
				'fields' => self::periodFields( true ),
			),
			'commission'      => array(
				'label'  => __( 'Commission', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'amount',
				'fields' => self::periodFields( true ),
			),
			'transportation'  => array(
				'label'  => __( 'Transport allowance', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'amount',
				'fields' => self::periodFields( false ),
			),
			// Set up like vacation pay (R2-T4); an amount typed on a run line replaces it.
			'gratification'   => array(
				'label'  => __( 'Gratification', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'amount',
				'fields' => self::accrualFields( self::GRATIFICATION_BASES, false ),
			),
		);
	}

	/**
	 * Fields of a pay paid each period (R2-T3, R2-T5): an amount, or for a
	 * bonus or commission a percentage of the base pay. Empty: the amount is
	 * typed on each pay run, as before. A run line can always replace it.
	 *
	 * @param bool $percent Whether a percentage may be chosen.
	 * @return array
	 */
	private static function periodFields( bool $percent ): array {
		$amount = array(
			'type' => 'money',
			'min'  => 0,
		);
		if ( ! $percent ) {
			return array( 'amount' => $amount );
		}
		return array(
			// amount|percent (of the base pay).
			'calc'    => array(
				'type'    => 'enum',
				'options' => array( 'amount', 'percent' ),
				'default' => 'amount',
			),
			'amount'  => $amount + array( 'when' => array( 'calc' => 'amount' ) ),
			'percent' => array(
				'type' => 'percent',
				'min'  => 0,
				'max'  => 100,
				'when' => array( 'calc' => 'percent' ),
			),
		);
	}

	/**
	 * Fields of vacation pay and gratification (R2-T2, R2-T4): paid with each
	 * pay or kept through the year; a percentage of the earnings that count,
	 * or an amount each period.
	 *
	 * @param string[] $bases    Earnings that may count.
	 * @param bool     $required Whether the percentage or amount must be given
	 *                           (gratification may be left to each run, and
	 *                           starts on an amount: before R2-T4 it was typed
	 *                           on each run).
	 * @return array
	 */
	private static function accrualFields( array $bases, bool $required ): array {
		return array(
			// retain = accrue through the year; pay = add to every pay period.
			'method'   => array(
				'type'     => 'enum',
				'options'  => array( 'retain', 'pay' ),
				'default'  => 'pay',
			),
			// percent|amount; vacation pay saved before R2-T2 is a percentage.
			'calc'     => array(
				'type'    => 'enum',
				'options' => array( 'percent', 'amount' ),
				'default' => $required ? 'percent' : 'amount',
			),
			// Percent of the earnings below.
			'percent'  => array(
				'type'     => 'percent',
				'required' => $required,
				'min'      => 0.01,
				'max'      => 100,
				'when'     => array( 'calc' => 'percent' ),
			),
			// An amount each pay period.
			'amount'   => array(
				'type'     => 'money',
				'required' => $required,
				'min'      => 0,
				'when'     => array( 'calc' => 'amount' ),
			),
			// Earnings that count; regular pay always does.
			'payitems' => array(
				'type'    => 'set',
				'options' => $bases,
				'when'    => array( 'calc' => 'percent' ),
			),
		);
	}

	/**
	 * The unit a type's settings and entries are in: `day` or `hour` (also
	 * for settings saved before the unit existed).
	 *
	 * @param array $settings The type's settings.
	 * @return string
	 */
	public static function unit( array $settings ): string {
		return 'day' === ( $settings['unit'] ?? '' ) ? 'day' : 'hour';
	}

	/**
	 * A type's settings, validated: `[ settings, errors ]` (errors keyed by field).
	 *
	 * @param string $type  Type.
	 * @param array  $input Settings sent.
	 * @return array{0: array, 1: array<string, string>}
	 */
	public static function clean( string $type, array $input ): array {
		$fields   = self::all()[ $type ]['fields'] ?? array();
		$settings = array();
		$errors   = array();
		foreach ( $fields as $key => $field ) {
			// A field that applies to one choice only (`when`: field => value).
			foreach ( (array) ( $field['when'] ?? array() ) as $other => $wanted ) {
				$chosen = $input[ $other ] ?? null;
				$chosen = null === $chosen || '' === $chosen ? ( $fields[ $other ]['default'] ?? null ) : $chosen;
				if ( (string) $chosen !== (string) $wanted ) {
					continue 2;
				}
			}
			$raw   = $input[ $key ] ?? null;
			$empty = null === $raw || '' === $raw || array() === $raw;
			if ( $empty ) {
				if ( ! empty( $field['required'] ) ) {
					$errors[ $key ] = __( 'This is required.', 'radius-hotel-booking-pro' );
				} elseif ( isset( $field['default'] ) ) {
					$settings[ $key ] = $field['default'];
				} elseif ( 'set' === $field['type'] ) {
					$settings[ $key ] = array( 'regular' );
				} elseif ( 'int' === $field['type'] ) {
					$settings[ $key ] = 0;
				}
				continue;
			}
			switch ( $field['type'] ) {
				case 'enum':
					if ( ! in_array( (string) $raw, $field['options'], true ) ) {
						$errors[ $key ] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
						break;
					}
					$settings[ $key ] = (string) $raw;
					break;
				case 'set':
					$values = array_values( array_intersect( $field['options'], array_map( 'strval', (array) $raw ) ) );
					// Regular pay always accrues vacation (the legacy form forced it on).
					$settings[ $key ] = array_values( array_unique( array_merge( array( 'regular' ), $values ) ) );
					break;
				default:
					$number = is_numeric( $raw ) ? (float) $raw : null;
					$whole  = 'int' !== $field['type'] || ( null !== $number && floor( $number ) === $number );
					if ( null === $number || ! $whole || $number < ( $field['min'] ?? 0 ) || ( isset( $field['max'] ) && $number > $field['max'] ) ) {
						$errors[ $key ] = isset( $field['max'] )
							/* translators: 1: smallest value, 2: largest value. */
							? sprintf( __( 'Enter a number from %1$s to %2$s.', 'radius-hotel-booking-pro' ), $field['min'] ?? 0, $field['max'] )
							: __( 'Enter an amount of 0 or more.', 'radius-hotel-booking-pro' );
						break;
					}
					$settings[ $key ] = 'int' === $field['type'] ? (int) $number : round( $number, 'percent' === $field['type'] ? 3 : 2 );
			}
		}
		return array( $settings, $errors );
	}
}
