<?php
/**
 * Recurring deductions and garnishments (M15 T4d, 12.15).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * An employee's recurring deductions (before tax) and garnishments (after
 * tax), as the legacy employment tab:
 *
 * - deduction `health_insurance` / `other`: name, authority, day of the
 *   month it is remitted, per payslip or per month, the employee's share (an
 *   amount or a % of gross) and the employer's (none, amount or %);
 * - garnishment `court_order`: name, start / end, an amount requested per
 *   period and the most it may take (% of disposable pay);
 * - garnishment `other`: name, start / end, a total to recover, taken as an
 *   amount or a % per payslip or per month, with what remains.
 *
 * What remains is set to the total on creation; changing the total moves
 * it only while nothing has been taken yet (the legacy rule). Status:
 * active, suspended, completed. Each write is logged `payroll.pay_profile`
 * against the employee. How a run takes them is M15 T5a's.
 */
final class DeductionService {

	public const TYPES = array(
		'deduction'   => array( 'health_insurance', 'other' ),
		'garnishment' => array( 'court_order', 'other' ),
	);

	public const STATUSES = array( 'active', 'suspended', 'completed' );

	/**
	 * Repository.
	 *
	 * @var EmployeeDeductionRepository
	 */
	private EmployeeDeductionRepository $rows;

	/**
	 * Constructor.
	 *
	 * @param EmployeeDeductionRepository|null $rows Repository.
	 */
	public function __construct( ?EmployeeDeductionRepository $rows = null ) {
		$this->rows = $rows ?? new EmployeeDeductionRepository();
	}

	/**
	 * An employee's deductions and garnishments.
	 *
	 * @param int $employee_id Pro employee id.
	 * @return array[]
	 */
	public function list( int $employee_id ): array {
		self::employee( $employee_id );
		return array_map( array( self::class, 'shape' ), $this->rows->forEmployee( $employee_id ) );
	}

	/**
	 * Add one.
	 *
	 * @param int   $employee_id Pro employee id.
	 * @param array $input       Fields.
	 * @return array Shape.
	 * @throws DomainException 404, 422.
	 */
	public function create( int $employee_id, array $input ): array {
		$employee = self::employee( $employee_id );
		$data     = self::validate( $input );
		$data    += array(
			'employee_id'      => $employee_id,
			'remaining_amount' => 'garnishment' === $data['kind'] && 'other' === $data['type'] ? $data['total_amount'] : '0.00',
			'status'           => 'active',
		);
		$row      = $this->rows->create( $data );
		self::log( $employee, array(), self::summary( $data ), __( 'Added', 'radius-hotel-booking-pro' ), $data['name'] );
		return self::shape( $row );
	}

	/**
	 * Change one (its kind and type stay; status may change).
	 *
	 * @param int   $employee_id Pro employee id.
	 * @param int   $id          Row id.
	 * @param array $input       Fields.
	 * @return array Shape.
	 * @throws DomainException 404, 422.
	 */
	public function update( int $employee_id, int $id, array $input ): array {
		$employee = self::employee( $employee_id );
		$row      = $this->get( $employee_id, $id );
		$data     = self::validate(
			array_merge(
				$input,
				array(
					'kind' => $row->kind,
					'type' => $row->type,
				)
			)
		);
		$status   = isset( $input['status'] ) ? sanitize_key( (string) $input['status'] ) : (string) $row->status;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( array( 'status' => __( 'Choose from the list.', 'radius-hotel-booking-pro' ) ) );
		}
		$data['status'] = $status;
		// What remains follows a new total only while nothing has been taken.
		$untouched                = Money::equals( (float) $row->total_amount, (float) $row->remaining_amount );
		$data['remaining_amount'] = $untouched && 'other' === $row->type && 'garnishment' === $row->kind ? $data['total_amount'] : number_format( (float) $row->remaining_amount, 2, '.', '' );
		$before                   = self::summary( self::raw( $row ) );
		$after                    = self::summary( $data );
		if ( $before === $after ) {
			return self::shape( $row );
		}
		$this->rows->update( $id, $data );
		self::log( $employee, $before, $after, __( 'Changed', 'radius-hotel-booking-pro' ), $data['name'] );
		return self::shape( $this->get( $employee_id, $id ) );
	}

	/**
	 * Delete one nothing has been taken for yet (otherwise end it: completed / suspended).
	 *
	 * @param int $employee_id Pro employee id.
	 * @param int $id          Row id.
	 * @return void
	 * @throws DomainException 404, 409 `deduction_in_use`.
	 */
	public function delete( int $employee_id, int $id ): void {
		$employee = self::employee( $employee_id );
		$row      = $this->get( $employee_id, $id );
		/**
		 * Why a deduction or garnishment cannot be deleted ('' = it can): pay runs took it (M15 T5a).
		 *
		 * @param string $reason Reason.
		 * @param int    $id     Row id.
		 */
		$reason = (string) apply_filters( 'rtbp_pro_deduction_in_use', '', $id );
		if ( '' !== $reason ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict( 'deduction_in_use', $reason );
		}
		$this->rows->delete( $id );
		self::log( $employee, self::summary( self::raw( $row ) ), array(), __( 'Deleted', 'radius-hotel-booking-pro' ), (string) $row->name );
	}

	/**
	 * One row of the employee, or 404.
	 *
	 * @param int $employee_id Employee.
	 * @param int $id          Row.
	 * @return EmployeeDeduction
	 * @throws DomainException 404.
	 */
	private function get( int $employee_id, int $id ): EmployeeDeduction {
		$row = $id > 0 ? $this->rows->find( $id ) : null;
		if ( ! $row instanceof EmployeeDeduction || (int) $row->employee_id !== $employee_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::notFound( __( 'This deduction does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $row;
	}

	/**
	 * Validated fields for the kind and type.
	 *
	 * @param array $input Input.
	 * @return array
	 * @throws DomainException 422.
	 */
	private static function validate( array $input ): array {
		$errors = array();
		$kind   = sanitize_key( (string) ( $input['kind'] ?? '' ) );
		$type   = sanitize_key( (string) ( $input['type'] ?? '' ) );
		if ( ! in_array( $type, self::TYPES[ $kind ] ?? array(), true ) ) {
			$errors['type'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		$text   = static fn( $key, $max ) => mb_substr( sanitize_text_field( (string) ( $input[ $key ] ?? '' ) ), 0, $max );
		$number = static fn( $key ) => is_numeric( $input[ $key ] ?? null ) ? (float) $input[ $key ] : null;
		$date   = static fn( $key ) => PayPeriods::valid( (string) ( $input[ $key ] ?? '' ) ) ? (string) $input[ $key ] : null;
		$money  = static fn( $value ) => number_format( (float) Money::round( (float) $value ), 2, '.', '' );

		$data = array(
			'kind'                 => $kind,
			'type'                 => $type,
			'name'                 => $text( 'name', 191 ),
			'authority'            => $text( 'authority', 191 ),
			'remittance_day'       => null,
			'calculation'          => in_array( $input['calculation'] ?? '', array( 'per_payslip', 'per_month' ), true ) ? (string) $input['calculation'] : 'per_payslip',
			'start_date'           => null,
			'end_date'             => null,
			'amount_type'          => 'amount',
			'amount'               => '0.00',
			'percent'              => '0.000',
			'employer_amount_type' => 'none',
			'employer_amount'      => '0.00',
			'employer_percent'     => '0.000',
			'total_amount'         => '0.00',
			'max_percent'          => '0.000',
		);
		if ( '' === $data['name'] ) {
			$errors['name'] = __( 'Enter the description shown on the payslip.', 'radius-hotel-booking-pro' );
		}
		$day = $number( 'remittance_day' );
		if ( null !== $day ) {
			if ( $day < 1 || $day > 31 || floor( $day ) !== $day ) {
				$errors['remittance_day'] = __( 'Enter a day from 1 to 31.', 'radius-hotel-booking-pro' );
			} else {
				$data['remittance_day'] = (int) $day;
			}
		}

		// A share: an amount or a % (of gross before tax; of disposable pay after).
		$share = static function ( string $prefix, bool $optional ) use ( $input, $number, $money, &$errors, &$data ) {
			$type_key = $prefix . 'amount_type';
			// Not sent: no employer share (optional), an amount (the employee's).
			$kind     = (string) ( $input[ $type_key ] ?? ( $optional ? 'none' : 'amount' ) );
			$allowed  = $optional ? array( 'none', 'amount', 'percent' ) : array( 'amount', 'percent' );
			if ( ! in_array( $kind, $allowed, true ) ) {
				$errors[ $type_key ] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
				return;
			}
			$data[ $type_key ] = $kind;
			if ( 'amount' === $kind ) {
				$value = $number( $prefix . 'amount' );
				if ( null === $value || $value <= 0 ) {
					$errors[ $prefix . 'amount' ] = __( 'Enter an amount above zero.', 'radius-hotel-booking-pro' );
					return;
				}
				$data[ $prefix . 'amount' ] = $money( $value );
			} elseif ( 'percent' === $kind ) {
				$value = $number( $prefix . 'percent' );
				if ( null === $value || $value <= 0 || $value > 100 ) {
					$errors[ $prefix . 'percent' ] = __( 'Enter a percentage above 0, up to 100.', 'radius-hotel-booking-pro' );
					return;
				}
				$data[ $prefix . 'percent' ] = number_format( $value, 3, '.', '' );
			}
		};

		if ( 'deduction' === $kind ) {
			if ( '' === $data['authority'] ) {
				$errors['authority'] = __( 'Enter who the money is paid to.', 'radius-hotel-booking-pro' );
			}
			if ( null === $data['remittance_day'] && ! isset( $errors['remittance_day'] ) ) {
				$errors['remittance_day'] = __( 'Enter a day from 1 to 31.', 'radius-hotel-booking-pro' );
			}
			$share( '', false );
			$share( 'employer_', true );
		} else {
			$data['start_date'] = $date( 'start_date' );
			$data['end_date']   = $date( 'end_date' );
			if ( null === $data['start_date'] ) {
				$errors['start_date'] = __( 'Enter a valid date.', 'radius-hotel-booking-pro' );
			}
			if ( '' !== (string) ( $input['end_date'] ?? '' ) && null === $data['end_date'] ) {
				$errors['end_date'] = __( 'Enter a valid date.', 'radius-hotel-booking-pro' );
			} elseif ( $data['end_date'] && $data['start_date'] && $data['end_date'] < $data['start_date'] ) {
				$errors['end_date'] = __( 'The end cannot be before the start.', 'radius-hotel-booking-pro' );
			}
			$total = $number( 'total_amount' );
			if ( null === $total || $total <= 0 ) {
				$errors['total_amount'] = __( 'Enter an amount above zero.', 'radius-hotel-booking-pro' );
			} else {
				$data['total_amount'] = $money( $total );
			}
			if ( 'court_order' === $type ) {
				$max = $number( 'max_percent' );
				if ( null === $max || $max <= 0 || $max > 100 ) {
					$errors['max_percent'] = __( 'Enter a percentage above 0, up to 100.', 'radius-hotel-booking-pro' );
				} else {
					$data['max_percent'] = number_format( $max, 3, '.', '' );
				}
				$data['calculation'] = 'per_payslip';
			} else {
				$share( '', false );
			}
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		return $data;
	}

	/**
	 * The API shape.
	 *
	 * @param EmployeeDeduction $row Row.
	 * @return array
	 */
	public static function shape( EmployeeDeduction $row ): array {
		return array( 'id' => (int) $row->id ) + self::raw( $row ) + array(
			'remaining_amount' => number_format( (float) $row->remaining_amount, 2, '.', '' ),
			'status'           => (string) $row->status,
		);
	}

	/**
	 * The stored fields, normalised as validate() returns them.
	 *
	 * @param EmployeeDeduction $row Row.
	 * @return array
	 */
	private static function raw( EmployeeDeduction $row ): array {
		return array(
			'kind'                 => (string) $row->kind,
			'type'                 => (string) $row->type,
			'name'                 => (string) $row->name,
			'authority'            => (string) $row->authority,
			'remittance_day'       => null !== $row->remittance_day ? (int) $row->remittance_day : null,
			'calculation'          => (string) $row->calculation,
			'start_date'           => $row->start_date ? substr( (string) $row->start_date, 0, 10 ) : null,
			'end_date'             => $row->end_date ? substr( (string) $row->end_date, 0, 10 ) : null,
			'amount_type'          => (string) $row->amount_type,
			'amount'               => number_format( (float) $row->amount, 2, '.', '' ),
			'percent'              => number_format( (float) $row->percent, 3, '.', '' ),
			'employer_amount_type' => (string) $row->employer_amount_type,
			'employer_amount'      => number_format( (float) $row->employer_amount, 2, '.', '' ),
			'employer_percent'     => number_format( (float) $row->employer_percent, 3, '.', '' ),
			'total_amount'         => number_format( (float) $row->total_amount, 2, '.', '' ),
			'max_percent'          => number_format( (float) $row->max_percent, 3, '.', '' ),
			'status'               => (string) $row->status,
		);
	}

	/**
	 * The fields worth logging (no empty ones).
	 *
	 * @param array $data Fields.
	 * @return array
	 */
	private static function summary( array $data ): array {
		unset( $data['remaining_amount'] );
		return array_filter(
			$data,
			static fn( $value ) => null !== $value && '' !== $value && ! ( is_numeric( $value ) && 0.0 === (float) $value && ! is_int( $value ) )
		);
	}

	/**
	 * Log a write against the employee.
	 *
	 * @param array  $employee Employee `{ id, name }`.
	 * @param array  $before   Before.
	 * @param array  $after    After.
	 * @param string $verb     Added / Changed / Deleted.
	 * @param string $what     The deduction's name.
	 * @return void
	 */
	private static function log( array $employee, array $before, array $after, string $verb, string $what ): void {
		if ( ! function_exists( 'rtbp_activity' ) ) {
			return;
		}
		rtbp_activity(
			'payroll.pay_profile',
			array(
				'type'  => 'employee',
				'id'    => $employee['id'],
				'label' => $employee['name'],
			),
			array(
				'before'      => $before,
				'after'       => $after,
				/* translators: 1: Added / Changed / Deleted, 2: deduction name, 3: employee name. */
				'description' => sprintf( __( '%1$s the deduction "%2$s" of %3$s', 'radius-hotel-booking-pro' ), $verb, $what, $employee['name'] ),
			)
		);
	}

	/**
	 * The Pro employee, or 404.
	 *
	 * @param int $employee_id Id.
	 * @return array{id:int, name:string}
	 * @throws DomainException 404.
	 */
	private static function employee( int $employee_id ): array {
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
