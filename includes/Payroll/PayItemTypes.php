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
	 * Earnings that can accrue vacation pay.
	 */
	public const VACATION_BASES = array( 'regular', 'overtime', 'bonus', 'commission' );

	/**
	 * Type => definition: `label`, `effect` (add|deduct), `entry` (hours|amount|none
	 * — what a run asks for), `fields` (key => `{ type, required?, min?, max?, options? }`).
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
					// Per hour; empty = the employee's hourly rate.
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
					// Hours a year.
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
					// Hours; 0 = no limit.
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
					// Hours a year.
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
				'fields' => array(
					// retain = accrue through the year; pay = add to every pay period.
					'method'   => array(
						'type'     => 'enum',
						'options'  => array( 'retain', 'pay' ),
						'required' => true,
					),
					// Percent of the earnings below.
					'percent'  => array(
						'type'     => 'percent',
						'required' => true,
						'min'      => 0.01,
						'max'      => 100,
					),
					// Earnings that accrue it; regular pay always does.
					'payitems' => array(
						'type'    => 'set',
						'options' => self::VACATION_BASES,
					),
				),
			),
			'bonus'           => array(
				'label'  => __( 'Bonus', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'amount',
				'fields' => array(),
			),
			'commission'      => array(
				'label'  => __( 'Commission', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'amount',
				'fields' => array(),
			),
			'transportation'  => array(
				'label'  => __( 'Transport allowance', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'amount',
				'fields' => array(),
			),
			'gratification'   => array(
				'label'  => __( 'Gratification', 'radius-hotel-booking-pro' ),
				'effect' => 'add',
				'entry'  => 'amount',
				'fields' => array(),
			),
		);
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
			$raw   = $input[ $key ] ?? null;
			$empty = null === $raw || '' === $raw || array() === $raw;
			if ( $empty ) {
				if ( ! empty( $field['required'] ) ) {
					$errors[ $key ] = __( 'This is required.', 'radius-hotel-booking-pro' );
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
