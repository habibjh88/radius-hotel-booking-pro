<?php
/**
 * Employment contracts (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The contract history of an employee (12.9): one row per contract, at
 * most one active. Adding an active contract ends the one before it on the
 * day before the new start. The active contract's job title and department
 * become the employee's. Ending a contract does not deactivate the
 * employee: sign-in is switched off separately (12.4). Every write takes the
 * employee's row lock, so two managers cannot open two active contracts.
 */
class ContractService {

	/**
	 * Contracts.
	 *
	 * @var EmployeeContractRepository
	 */
	private EmployeeContractRepository $contracts;

	/**
	 * Employees.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Constructor.
	 *
	 * @param EmployeeContractRepository|null $contracts Contracts.
	 * @param EmployeeRepository|null         $employees Employees.
	 */
	public function __construct( ?EmployeeContractRepository $contracts = null, ?EmployeeRepository $employees = null ) {
		$this->contracts = $contracts ?? new EmployeeContractRepository();
		$this->employees = $employees ?? new EmployeeRepository();
	}

	/**
	 * An employee's contracts, newest first.
	 *
	 * @param int $employee_id Employee id.
	 * @return EmployeeContract[]
	 */
	public function forEmployee( int $employee_id ): array {
		$this->employee( $employee_id );
		return $this->contracts->forEmployee( $employee_id );
	}

	/**
	 * Add a contract. An active one ends the current active contract.
	 *
	 * @param int   $employee_id Employee id.
	 * @param array $input       `status`, `job_type`, `job_title`, `department`, `start_date`, `end_date`, `notes`.
	 * @return EmployeeContract
	 * @throws DomainException 404, 422.
	 */
	public function create( int $employee_id, array $input ): EmployeeContract {
		$employee = $this->employee( $employee_id );
		$data     = $this->validate( $input, null );

		$id = Transaction::run(
			function () use ( $employee, $data ) {
				$this->employees->lock( (int) $employee->id );
				if ( 'active' === $data['status'] ) {
					$this->refuseBackdated( $employee, 0, (string) $data['start_date'] );
					$this->endOthers( $employee, 0, $data['start_date'] );
				}
				$contract = $this->contracts->create( array_merge( $data, array( 'employee_id' => (int) $employee->id ) ) );
				rtbp_activity(
					'staff.contract_create',
					self::subject( $employee ),
					array(
						'before'      => array_fill_keys( array_keys( self::logged( $data ) ), null ),
						'after'       => self::logged( $data ),
						/* translators: 1: job title or type, 2: employee name. */
						'description' => sprintf( __( 'Added a contract (%1$s) for %2$s', 'radius-hotel-booking-pro' ), self::label( $data ), $employee->fullName() ),
					)
				);
				if ( 'active' === $data['status'] ) {
					$this->followActive( $employee, $data );
				}
				return (int) $contract->id;
			}
		);
		return $this->get( $employee_id, $id );
	}

	/**
	 * Change a contract. Making a terminated contract active again is refused
	 * while another one is active.
	 *
	 * @param int   $employee_id Employee id.
	 * @param int   $id          Contract id.
	 * @param array $input       Fields to change.
	 * @return EmployeeContract
	 * @throws DomainException 404, 409, 422.
	 */
	public function update( int $employee_id, int $id, array $input ): EmployeeContract {
		$employee = $this->employee( $employee_id );
		$this->get( $employee_id, $id );

		Transaction::run(
			function () use ( $employee, $employee_id, $id, $input ) {
				$this->employees->lock( (int) $employee->id );
				// Read and validate under the lock: another manager may have ended it meanwhile.
				$contract = $this->get( $employee_id, $id );
				$data     = $this->validate( $input, $contract );
				$status   = $data['status'] ?? (string) $contract->status;
				if ( 'active' === $status && 'active' !== $contract->status && $this->contracts->activeFor( (int) $employee->id, (int) $contract->id ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
					throw DomainException::conflict( 'active_contract', __( 'Another contract is active. End it first.', 'radius-hotel-booking-pro' ) );
				}
				$before = self::logged( $contract->toArray() );
				$after  = self::logged( array_merge( $contract->toArray(), $data ) );
				$diff   = self::diff( $before, $after );
				if ( ! $diff['after'] ) {
					return;
				}
				$this->contracts->update( (int) $contract->id, $data );
				rtbp_activity(
					'staff.contract_edit',
					self::subject( $employee ),
					array_merge(
						$diff,
						array(
							/* translators: 1: job title or type, 2: employee name. */
							'description' => sprintf( __( 'Changed the contract (%1$s) of %2$s', 'radius-hotel-booking-pro' ), self::label( array_merge( $contract->toArray(), $data ) ), $employee->fullName() ),
						)
					)
				);
				if ( 'active' === $status ) {
					$this->followActive( $employee, $data );
				}
			}
		);
		return $this->get( $employee_id, $id );
	}

	/**
	 * End an active contract.
	 *
	 * @param int    $employee_id Employee id.
	 * @param int    $id          Contract id.
	 * @param string $end_date    Last day (Y-m-d; today when empty).
	 * @param string $reason      Why (optional, logged).
	 * @return EmployeeContract
	 * @throws DomainException 404, 409, 422.
	 */
	public function terminate( int $employee_id, int $id, string $end_date, string $reason = '' ): EmployeeContract {
		$employee = $this->employee( $employee_id );
		$this->get( $employee_id, $id );
		$end_date = '' === trim( $end_date ) ? Dates::today() : trim( $end_date );
		$reason   = sanitize_textarea_field( $reason );

		Transaction::run(
			function () use ( $employee, $employee_id, $id, $end_date, $reason ) {
				$this->employees->lock( (int) $employee->id );
				// Checked under the lock: two "End" clicks must not both succeed.
				$contract = $this->get( $employee_id, $id );
				if ( 'active' !== $contract->status ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
					throw DomainException::conflict( 'no_change', __( 'This contract has already ended.', 'radius-hotel-booking-pro' ) );
				}
				if ( ! Dates::is_date( $end_date ) || ( $contract->start_date && $end_date < substr( (string) $contract->start_date, 0, 10 ) ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
					throw DomainException::invalid( array( 'end_date' => __( 'The end date must be on or after the start date.', 'radius-hotel-booking-pro' ) ) );
				}
				$this->end( $employee, $contract, $end_date, $reason );
			}
		);
		return $this->get( $employee_id, $id );
	}

	/**
	 * Delete a contract entered by mistake.
	 *
	 * @param int    $employee_id Employee id.
	 * @param int    $id          Contract id.
	 * @param string $reason      Why (logged).
	 * @return void
	 * @throws DomainException 404.
	 */
	public function delete( int $employee_id, int $id, string $reason = '' ): void {
		$employee = $this->employee( $employee_id );
		$contract = $this->get( $employee_id, $id );
		$reason   = sanitize_textarea_field( $reason );

		Transaction::run(
			function () use ( $employee, $contract, $reason ) {
				$this->employees->lock( (int) $employee->id );
				$this->contracts->delete( (int) $contract->id );
				$before = self::logged( $contract->toArray() );
				rtbp_activity(
					'staff.contract_delete',
					self::subject( $employee ),
					array(
						'before'      => $before,
						'after'       => array_fill_keys( array_keys( $before ), null ),
						'description' => '' === $reason
							/* translators: 1: job title or type, 2: employee name. */
							? sprintf( __( 'Deleted the contract (%1$s) of %2$s', 'radius-hotel-booking-pro' ), self::label( $contract->toArray() ), $employee->fullName() )
							/* translators: 1: job title or type, 2: employee name, 3: reason. */
							: sprintf( __( 'Deleted the contract (%1$s) of %2$s: %3$s', 'radius-hotel-booking-pro' ), self::label( $contract->toArray() ), $employee->fullName(), $reason ),
					)
				);
			}
		);
	}

	/**
	 * One contract of an employee, or 404.
	 *
	 * @param int $employee_id Employee id.
	 * @param int $id          Contract id.
	 * @return EmployeeContract
	 * @throws DomainException 404.
	 */
	public function get( int $employee_id, int $id ): EmployeeContract {
		$contract = $id > 0 ? $this->contracts->find( $id ) : null;
		if ( ! $contract instanceof EmployeeContract || (int) $contract->employee_id !== $employee_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::notFound( __( 'This contract does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $contract;
	}

	/**
	 * The employee, or 404.
	 *
	 * @param int $employee_id Employee id.
	 * @return Employee
	 */
	private function employee( int $employee_id ): Employee {
		return ( new EmployeeService( $this->employees ) )->get( $employee_id );
	}

	/**
	 * A new active contract must start after the current active one: an
	 * older contract is recorded as ended, or it would cut the current one short.
	 *
	 * @param Employee $employee Employee.
	 * @param int      $except   Contract being saved (0 for a new one).
	 * @param string   $start    Its first day.
	 * @return void
	 * @throws DomainException 422.
	 */
	private function refuseBackdated( Employee $employee, int $except, string $start ): void {
		foreach ( $this->contracts->activeFor( (int) $employee->id, $except ) as $current ) {
			if ( $start <= substr( (string) $current->start_date, 0, 10 ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
				throw DomainException::invalid( array( 'start_date' => __( 'This starts before the current contract. Record a past contract as Ended.', 'radius-hotel-booking-pro' ) ) );
			}
		}
	}

	/**
	 * End the other active contracts on the day before a new start (never
	 * before their own start).
	 *
	 * @param Employee $employee   Employee.
	 * @param int      $except     Contract to keep.
	 * @param string   $next_start The new contract's first day.
	 * @return void
	 */
	private function endOthers( Employee $employee, int $except, string $next_start ): void {
		$day_before = Dates::add_days( Dates::local( $next_start . ' 00:00:00' ), -1 )->format( 'Y-m-d' );
		foreach ( $this->contracts->activeFor( (int) $employee->id, $except ) as $other ) {
			$start = substr( (string) $other->start_date, 0, 10 );
			$end   = $other->end_date ? substr( (string) $other->end_date, 0, 10 ) : $day_before;
			$end   = min( $end, $day_before );
			$this->end( $employee, $other, max( $start, $end ), __( 'A new contract started.', 'radius-hotel-booking-pro' ) );
		}
	}

	/**
	 * Mark a contract terminated and log it.
	 *
	 * @param Employee         $employee Employee.
	 * @param EmployeeContract $contract Contract.
	 * @param string           $end_date Last day.
	 * @param string           $reason   Why.
	 * @return void
	 */
	private function end( Employee $employee, EmployeeContract $contract, string $end_date, string $reason ): void {
		$this->contracts->update(
			(int) $contract->id,
			array(
				'status'   => 'terminated',
				'end_date' => $end_date,
			)
		);
		rtbp_activity(
			'staff.contract_end',
			self::subject( $employee ),
			array(
				'before'      => array(
					'status'   => 'active',
					'end_date' => $contract->end_date ? substr( (string) $contract->end_date, 0, 10 ) : null,
				),
				'after'       => array(
					'status'   => 'terminated',
					'end_date' => $end_date,
				),
				'description' => '' === $reason
					/* translators: 1: job title or type, 2: employee name. */
					? sprintf( __( 'Ended the contract (%1$s) of %2$s', 'radius-hotel-booking-pro' ), self::label( $contract->toArray() ), $employee->fullName() )
					/* translators: 1: job title or type, 2: employee name, 3: reason. */
					: sprintf( __( 'Ended the contract (%1$s) of %2$s: %3$s', 'radius-hotel-booking-pro' ), self::label( $contract->toArray() ), $employee->fullName(), $reason ),
			)
		);
	}

	/**
	 * The employee's job title and department follow the active contract
	 * (when the contract gives them).
	 *
	 * @param Employee $employee Employee.
	 * @param array    $data     Active contract fields.
	 * @return void
	 */
	private function followActive( Employee $employee, array $data ): void {
		$fields = array();
		foreach ( array( 'job_title', 'department' ) as $field ) {
			if ( isset( $data[ $field ] ) && '' !== $data[ $field ] && $data[ $field ] !== (string) $employee->{$field} ) {
				$fields[ $field ] = $data[ $field ];
			}
		}
		if ( $fields ) {
			( new EmployeeService( $this->employees ) )->update( (int) $employee->id, $fields );
		}
	}

	/**
	 * Validate contract input.
	 *
	 * @param array                 $input    Input.
	 * @param EmployeeContract|null $contract The contract being changed.
	 * @return array Columns.
	 * @throws DomainException 422.
	 */
	private function validate( array $input, ?EmployeeContract $contract ): array {
		$has    = static fn( $key ) => null === $contract || array_key_exists( $key, $input );
		$old    = $contract ? $contract->toArray() : array();
		$errors = array();
		$data   = array();

		if ( $has( 'status' ) ) {
			$data['status'] = sanitize_key( (string) ( $input['status'] ?? 'active' ) );
			if ( ! in_array( $data['status'], EmployeeContract::STATUSES, true ) ) {
				$errors['status'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
			}
		}
		if ( $has( 'job_type' ) ) {
			$data['job_type'] = sanitize_key( (string) ( $input['job_type'] ?? 'full_time' ) );
			if ( ! in_array( $data['job_type'], EmployeeContract::JOB_TYPES, true ) ) {
				$errors['job_type'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
			}
		}
		foreach ( array( 'job_title', 'department' ) as $field ) {
			if ( $has( $field ) ) {
				$data[ $field ] = sanitize_text_field( (string) ( $input[ $field ] ?? '' ) );
				if ( mb_strlen( $data[ $field ] ) > 100 ) {
					$errors[ $field ] = __( 'This is too long.', 'radius-hotel-booking-pro' );
				}
			}
		}
		foreach ( array( 'start_date', 'end_date' ) as $field ) {
			if ( $has( $field ) ) {
				$value          = trim( (string) ( $input[ $field ] ?? '' ) );
				$data[ $field ] = '' === $value ? null : $value;
				if ( null !== $data[ $field ] && ! Dates::is_date( $value ) ) {
					$errors[ $field ] = __( 'Enter a valid date.', 'radius-hotel-booking-pro' );
				}
			}
		}
		if ( $has( 'notes' ) ) {
			$data['notes'] = sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) );
			if ( mb_strlen( $data['notes'] ) > 2000 ) {
				$errors['notes'] = __( 'This is too long.', 'radius-hotel-booking-pro' );
			}
		}

		$start  = array_key_exists( 'start_date', $data ) ? $data['start_date'] : ( isset( $old['start_date'] ) ? substr( (string) $old['start_date'], 0, 10 ) : null );
		$end    = array_key_exists( 'end_date', $data ) ? $data['end_date'] : ( isset( $old['end_date'] ) ? substr( (string) $old['end_date'], 0, 10 ) : null );
		$status = $data['status'] ?? ( $old['status'] ?? 'active' );
		if ( null === $start && ! isset( $errors['start_date'] ) ) {
			$errors['start_date'] = __( 'Enter the start date.', 'radius-hotel-booking-pro' );
		}
		if ( $start && $end && ! isset( $errors['start_date'] ) && ! isset( $errors['end_date'] ) && $end < $start ) {
			$errors['end_date'] = __( 'The end date must be on or after the start date.', 'radius-hotel-booking-pro' );
		}
		if ( 'terminated' === $status && null === $end && ! isset( $errors['end_date'] ) ) {
			$errors['end_date'] = __( 'An ended contract needs its end date.', 'radius-hotel-booking-pro' );
		}

		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		return $data;
	}

	/**
	 * The logged fields of a contract (empty values as null).
	 *
	 * @param array $row Contract columns.
	 * @return array
	 */
	private static function logged( array $row ): array {
		$out = array();
		foreach ( array( 'status', 'job_type', 'job_title', 'department', 'start_date', 'end_date', 'notes' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$value         = $row[ $field ];
				$out[ $field ] = null === $value || '' === $value ? null : ( str_ends_with( $field, '_date' ) ? substr( (string) $value, 0, 10 ) : (string) $value );
			}
		}
		return $out;
	}

	/**
	 * The changed fields: `{ before, after }`.
	 *
	 * @param array $before Before.
	 * @param array $after  After.
	 * @return array
	 */
	private static function diff( array $before, array $after ): array {
		$out = array(
			'before' => array(),
			'after'  => array(),
		);
		foreach ( $after as $field => $value ) {
			if ( ( $before[ $field ] ?? null ) !== $value ) {
				$out['before'][ $field ] = $before[ $field ] ?? null;
				$out['after'][ $field ]  = $value;
			}
		}
		return $out;
	}

	/**
	 * A short name for a contract: its job title, or its job type.
	 *
	 * @param array $row Contract fields.
	 * @return string
	 */
	private static function label( array $row ): string {
		$title = trim( (string) ( $row['job_title'] ?? '' ) );
		if ( '' !== $title ) {
			return $title;
		}
		$types = self::jobTypes();
		return (string) ( $types[ $row['job_type'] ?? '' ] ?? ( $row['job_type'] ?? '' ) );
	}

	/**
	 * Job types with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function jobTypes(): array {
		return array(
			'full_time'  => __( 'Full time', 'radius-hotel-booking-pro' ),
			'part_time'  => __( 'Part time', 'radius-hotel-booking-pro' ),
			'contract'   => __( 'Fixed-term contract', 'radius-hotel-booking-pro' ),
			'internship' => __( 'Internship', 'radius-hotel-booking-pro' ),
			'other'      => __( 'Other', 'radius-hotel-booking-pro' ),
		);
	}

	/**
	 * The employee as the log subject.
	 *
	 * @param Employee $employee Employee.
	 * @return array
	 */
	private static function subject( Employee $employee ): array {
		return array(
			'type'  => 'employee',
			'id'    => (int) $employee->id,
			'label' => $employee->fullName(),
		);
	}
}
