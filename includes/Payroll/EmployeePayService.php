<?php
/**
 * Employee pay profiles (M15 T4b, 12.12, 12.13).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Read and save an employee's pay profile: the pay schedule and work shift
 * they are on and how they are paid. Saved as one change, logged as
 * `payroll.pay_profile` against the employee (so it shows in the file's
 * History). Also answers the schedule and shift employee counts and keeps a
 * schedule or shift in use from being deleted.
 */
final class EmployeePayService {

	/**
	 * Repository.
	 *
	 * @var EmployeePayRepository
	 */
	private EmployeePayRepository $pay;

	/**
	 * Constructor.
	 *
	 * @param EmployeePayRepository|null $pay Repository.
	 */
	public function __construct( ?EmployeePayRepository $pay = null ) {
		$this->pay = $pay ?? new EmployeePayRepository();
	}

	/**
	 * Hook in: the counts and in-use answers for schedules and shifts.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_pro_pay_schedule_employees', static fn( $counts ) => ( new EmployeePayRepository() )->countsBy( 'pay_schedule_id' ) + (array) $counts );
		add_filter( 'rtbp_pro_work_shift_employees', static fn( $counts ) => ( new EmployeePayRepository() )->countsBy( 'work_shift_id' ) + (array) $counts );
		add_filter( 'rtbp_pro_pay_schedule_in_use', array( self::class, 'scheduleInUse' ), 10, 2 );
		add_filter( 'rtbp_pro_work_shift_in_use', array( self::class, 'shiftInUse' ), 10, 2 );
	}

	/**
	 * `rtbp_pro_pay_schedule_in_use`.
	 *
	 * @param string $reason Reason so far.
	 * @param int    $id     Schedule id.
	 * @return string
	 */
	public static function scheduleInUse( $reason, $id ): string {
		$n = (int) ( ( new EmployeePayRepository() )->countsBy( 'pay_schedule_id' )[ (int) $id ] ?? 0 );
		if ( '' !== (string) $reason || ! $n ) {
			return (string) $reason;
		}
		/* translators: %d: number of employees. */
		return sprintf( _n( '%d employee is on this pay schedule. Move them to another one first.', '%d employees are on this pay schedule. Move them to another one first.', $n, 'radius-hotel-booking-pro' ), $n );
	}

	/**
	 * `rtbp_pro_work_shift_in_use`.
	 *
	 * @param string $reason Reason so far.
	 * @param int    $id     Shift id.
	 * @return string
	 */
	public static function shiftInUse( $reason, $id ): string {
		$n = (int) ( ( new EmployeePayRepository() )->countsBy( 'work_shift_id' )[ (int) $id ] ?? 0 );
		if ( '' !== (string) $reason || ! $n ) {
			return (string) $reason;
		}
		/* translators: %d: number of employees. */
		return sprintf( _n( '%d employee works this shift. Move them to another one first.', '%d employees work this shift. Move them to another one first.', $n, 'radius-hotel-booking-pro' ), $n );
	}

	/**
	 * An employee's profile with what it means per pay period, and the
	 * schedules and shifts to choose from.
	 *
	 * @param int $employee_id Pro employee id.
	 * @return array
	 * @throws DomainException 404 when the employee does not exist.
	 */
	public function get( int $employee_id ): array {
		$this->employee( $employee_id );
		$profile   = $this->pay->forEmployee( $employee_id );
		$schedules = ( new PayScheduleRepository() )->byName();
		$shifts    = ( new WorkShiftService() )->list();
		return array(
			'pay'        => self::shape( $profile, $schedules, $shifts ),
			'items'      => (object) ( new EmployeePayItemRepository() )->forEmployee( $employee_id ),
			'item_types' => self::itemTypes(),
			'schedules' => array_map(
				static fn( PaySchedule $s ) => array(
					'id'             => (int) $s->id,
					'name'           => (string) $s->name,
					'interval_label' => PayPeriods::intervals()[ (string) $s->pay_interval ][0] ?? (string) $s->pay_interval,
				),
				$schedules
			),
			'shifts'    => array_map(
				static fn( array $shift ) => array(
					'id'             => $shift['id'],
					'name'           => $shift['name'],
					'hours_per_week' => $shift['hours_per_week'],
				),
				$shifts
			),
		);
	}

	/**
	 * Save an employee's profile (every field; the form sends them all).
	 *
	 * @param int   $employee_id Pro employee id.
	 * @param array $input       `{ pay_schedule_id?, work_shift_id?, pay_type, amount, amount_period }`.
	 * @return array As get().
	 * @throws DomainException 404, 422.
	 */
	public function save( int $employee_id, array $input ): array {
		$employee = $this->employee( $employee_id );
		$data     = $this->validate( $input );
		$current  = $this->pay->forEmployee( $employee_id );
		$before   = $current ? self::fields( $current ) : array();
		$changed  = array_diff_assoc( $data, $before );
		if ( $current && ! $changed ) {
			return $this->get( $employee_id );
		}
		if ( $current ) {
			$this->pay->update( (int) $current->id, $changed );
		} else {
			$this->pay->create( $data + array( 'employee_id' => $employee_id ) );
		}
		if ( function_exists( 'rtbp_activity' ) ) {
			rtbp_activity(
				'payroll.pay_profile',
				array(
					'type'  => 'employee',
					'id'    => $employee_id,
					'label' => $employee['name'],
				),
				array(
					'before'      => self::readable( array_intersect_key( $before, $changed ) ),
					'after'       => self::readable( $current ? $changed : $data ),
					/* translators: %s: employee name. */
					'description' => sprintf( __( 'Changed the pay of %s', 'radius-hotel-booking-pro' ), $employee['name'] ),
				)
			);
		}
		return $this->get( $employee_id );
	}

	/**
	 * Save an employee's additional pay types (12.14): the full set, each with
	 * its settings; a type left out is no longer paid. One log entry.
	 *
	 * @param int   $employee_id Pro employee id.
	 * @param array $items       Type => settings.
	 * @return array As get().
	 * @throws DomainException 404, 422 (`items.<type>.<field>`).
	 */
	public function saveItems( int $employee_id, array $items ): array {
		$employee = $this->employee( $employee_id );
		$types    = PayItemTypes::all();
		$clean    = array();
		$errors   = array();
		foreach ( $items as $type => $settings ) {
			$type = sanitize_key( (string) $type );
			if ( ! isset( $types[ $type ] ) ) {
				$errors[ "items.$type" ] = __( 'This pay type does not exist.', 'radius-hotel-booking-pro' );
				continue;
			}
			list( $value, $field_errors ) = PayItemTypes::clean( $type, (array) $settings );
			foreach ( $field_errors as $field => $message ) {
				$errors[ "items.$type.$field" ] = $message;
			}
			$clean[ $type ] = $value;
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		// Catalogue order, so a save in another order is no change.
		$clean  = array_intersect_key( array_replace( array_fill_keys( array_keys( $types ), null ), $clean ), $clean );
		$repo   = new EmployeePayItemRepository();
		$before = self::itemSummary( $repo->forEmployee( $employee_id ) );
		$after  = self::itemSummary( $clean );
		if ( $before === $after ) {
			return $this->get( $employee_id );
		}
		Transaction::run(
			static function () use ( $repo, $employee_id, $clean, $before, $after, $employee ) {
				$repo->replace( $employee_id, $clean );
				if ( function_exists( 'rtbp_activity' ) ) {
					rtbp_activity(
						'payroll.pay_profile',
						array(
							'type'  => 'employee',
							'id'    => $employee_id,
							'label' => $employee['name'],
						),
						array(
							'before'      => array( 'additional_pay' => $before ),
							'after'       => array( 'additional_pay' => $after ),
							/* translators: %s: employee name. */
							'description' => sprintf( __( 'Changed the additional pay of %s', 'radius-hotel-booking-pro' ), $employee['name'] ),
						)
					);
				}
			}
		);
		return $this->get( $employee_id );
	}

	/**
	 * The catalogue for the form: `[ { key, label, effect, entry, fields } ]`.
	 *
	 * @return array[]
	 */
	private static function itemTypes(): array {
		$out = array();
		foreach ( PayItemTypes::all() as $key => $type ) {
			$out[] = array( 'key' => $key ) + $type;
		}
		return $out;
	}

	/**
	 * Additional pay in one line for the log: "Vacation pay (pay, 6.25 %), Transport allowance".
	 *
	 * @param array<string, array> $items Type => settings.
	 * @return string
	 */
	private static function itemSummary( array $items ): string {
		$types = PayItemTypes::all();
		$parts = array();
		foreach ( $types as $key => $type ) {
			if ( ! array_key_exists( $key, $items ) ) {
				continue;
			}
			$details = array();
			foreach ( (array) $items[ $key ] as $field => $value ) {
				if ( 'payitems' === $field ) {
					$value = implode( '+', (array) $value );
				}
				if ( '' !== (string) $value && 0 !== $value ) {
					$details[] = $field . ' ' . $value;
				}
			}
			$parts[] = $type['label'] . ( $details ? ' (' . implode( ', ', $details ) . ')' : '' );
		}
		return implode( ', ', $parts );
	}

	/**
	 * The API shape of a profile (defaults when there is none yet).
	 *
	 * @param EmployeePay|null $profile   Profile.
	 * @param PaySchedule[]    $schedules Schedules.
	 * @param array[]          $shifts    Shifts (WorkShiftService::list()).
	 * @return array
	 */
	public static function shape( ?EmployeePay $profile, array $schedules, array $shifts ): array {
		$fields   = $profile ? self::fields( $profile ) : array(
			'pay_schedule_id' => 0,
			'work_shift_id'   => 0,
			'pay_type'        => 'salaried',
			'amount'          => '0.00',
			'amount_period'   => 'month',
		);
		$schedule = null;
		foreach ( $schedules as $s ) {
			if ( (int) $s->id === (int) $fields['pay_schedule_id'] ) {
				$schedule = $s;
			}
		}
		$hours = 0.0;
		foreach ( $shifts as $shift ) {
			if ( (int) $shift['id'] === (int) $fields['work_shift_id'] ) {
				$hours = (float) $shift['hours_per_week'];
			}
		}
		$salaried = 'salaried' === $fields['pay_type'];
		$rate     = PayDefinition::hourlyRate( $fields['pay_type'], $fields['amount'], $fields['amount_period'], $hours );
		return $fields + array(
			'saved'             => null !== $profile,
			// A salary in the schedule's pay period (null without a schedule, or for hourly pay).
			'salary_per_period' => $salaried && $schedule ? Money::round( (float) PayDefinition::salaryForPeriod( $fields['amount'], $fields['amount_period'], (string) $schedule->pay_interval ) ) : null,
			'hourly_rate'       => null !== $rate ? Money::round( (float) $rate ) : null,
			'hours_per_week'    => $hours,
		);
	}

	/**
	 * Validated fields.
	 *
	 * @param array $input Input.
	 * @return array
	 * @throws DomainException 422.
	 */
	private function validate( array $input ): array {
		$errors   = array();
		$type     = sanitize_key( (string) ( $input['pay_type'] ?? '' ) );
		$period   = sanitize_key( (string) ( $input['amount_period'] ?? '' ) );
		$amount   = $input['amount'] ?? '';
		$schedule = (int) ( $input['pay_schedule_id'] ?? 0 );
		$shift    = (int) ( $input['work_shift_id'] ?? 0 );
		if ( ! in_array( $type, PayDefinition::TYPES, true ) ) {
			$errors['pay_type'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
		}
		if ( 'hourly' === $type ) {
			$period = 'hour';
		} elseif ( ! isset( PayDefinition::PERIODS[ $period ] ) ) {
			$errors['amount_period'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
		}
		if ( ! is_numeric( $amount ) || (float) $amount < 0 || (float) $amount > 999999999999 ) {
			$errors['amount'] = __( 'Enter an amount of 0 or more.', 'radius-hotel-booking-pro' );
		}
		if ( $schedule && ! ( new PayScheduleRepository() )->find( $schedule ) ) {
			$errors['pay_schedule_id'] = __( 'This pay schedule does not exist.', 'radius-hotel-booking-pro' );
		}
		if ( $shift && ! ( new WorkShiftRepository() )->find( $shift ) ) {
			$errors['work_shift_id'] = __( 'This work shift does not exist.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		return array(
			'pay_schedule_id' => $schedule ? $schedule : null,
			'work_shift_id'   => $shift ? $shift : null,
			'pay_type'        => $type,
			'amount'          => number_format( (float) Money::round( (float) $amount ), 2, '.', '' ),
			'amount_period'   => $period,
		);
	}

	/**
	 * Log values with the schedule and shift by name, not id.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private static function readable( array $fields ): array {
		$out = array();
		foreach ( $fields as $key => $value ) {
			if ( 'pay_schedule_id' === $key ) {
				$schedule              = $value ? ( new PayScheduleRepository() )->find( (int) $value ) : null;
				$out['pay_schedule'] = $schedule ? (string) $schedule->name : '';
			} elseif ( 'work_shift_id' === $key ) {
				$shift              = $value ? ( new WorkShiftRepository() )->find( (int) $value ) : null;
				$out['work_shift'] = $shift ? (string) $shift->name : '';
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * The stored fields.
	 *
	 * @param EmployeePay $profile Profile.
	 * @return array
	 */
	private static function fields( EmployeePay $profile ): array {
		return array(
			'pay_schedule_id' => $profile->pay_schedule_id ? (int) $profile->pay_schedule_id : null,
			'work_shift_id'   => $profile->work_shift_id ? (int) $profile->work_shift_id : null,
			'pay_type'        => (string) $profile->pay_type,
			'amount'          => number_format( (float) $profile->amount, 2, '.', '' ),
			'amount_period'   => (string) $profile->amount_period,
		);
	}

	/**
	 * The Pro employee, or 404.
	 *
	 * @param int $employee_id Id.
	 * @return array{id:int, name:string}
	 * @throws DomainException 404.
	 */
	private function employee( int $employee_id ): array {
		$repo     = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\EmployeeRepository';
		$employee = $employee_id > 0 && class_exists( $repo ) ? ( new $repo() )->find( $employee_id ) : null;
		if ( ! $employee ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::notFound( __( 'This employee does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return array(
			'id'   => (int) $employee->id,
			'name' => method_exists( $employee, 'fullName' ) ? (string) $employee->fullName() : '#' . $employee_id,
		);
	}
}
