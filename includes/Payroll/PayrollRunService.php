<?php
/**
 * Pay runs and their lifecycle (M15 T5b, 15.1–15.8).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * The state machine (the legacy rules, `class-payroll-controller.php`):
 *
 * Create → draft → finalize → finalized → settle → settled → void → voided;
 * a finalized run may revert to draft (re-run: edit, recalculate); a draft
 * may be removed.
 *
 * - create: one line per employee on the schedule (pay profile) whose file is
 *   active, for the schedule's next period (or one given); one live run per
 *   schedule and period (a voided one does not count: run it again);
 * - a draft's lines are edited (entries, custom items, hours) and recalculated
 *   with the employees' current settings;
 * - finalize: not before the period's last day (legacy), every net ≥ 0; the
 *   lines are locked as calculated;
 * - settle: a payment method (and an optional reference) for every line;
 * - void: a settled run, with a reason; it stays in the history;
 * - revert: a finalized run back to draft; remove: a draft, gone.
 *
 * Every move runs in a transaction under the run's row lock, re-reads the
 * status inside it, is logged (`payroll.*`, subject `payroll_run`), and fires
 * `rtbp_pro_payroll_run_status` after the commit (T6 records the statutory
 * contributions of settled runs). Balances follow from the ledger of
 * finalized / settled runs; each garnishment's `remaining_amount` is
 * re-derived whenever a run starts or stops counting.
 */
final class PayrollRunService {

	/**
	 * Repository.
	 *
	 * @var PayrollRunRepository
	 */
	private PayrollRunRepository $runs;

	/**
	 * Constructor.
	 *
	 * @param PayrollRunRepository|null $runs Repository.
	 */
	public function __construct( ?PayrollRunRepository $runs = null ) {
		$this->runs = $runs ?? new PayrollRunRepository();
	}

	/**
	 * Hook in: schedules and deductions with runs cannot be deleted.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter(
			'rtbp_pro_pay_schedule_in_use',
			static function ( $reason, $id ) {
				$n = ( new PayrollRunRepository() )->runsOfSchedule( (int) $id );
				if ( '' !== (string) $reason || ! $n ) {
					return (string) $reason;
				}
				/* translators: %d: number of pay runs. */
				return sprintf( _n( '%d pay run was made with this pay schedule; it stays in the history.', '%d pay runs were made with this pay schedule; they stay in the history.', $n, 'radius-hotel-booking-pro' ), $n );
			},
			20,
			2
		);
		add_filter(
			'rtbp_pro_deduction_in_use',
			static function ( $reason, $id ) {
				$n = ( new PayrollRunRepository() )->runsTaking( (int) $id );
				if ( '' !== (string) $reason || ! $n ) {
					return (string) $reason;
				}
				/* translators: %d: number of pay runs. */
				return sprintf( _n( '%d pay run took it. Mark it completed instead.', '%d pay runs took it. Mark it completed instead.', $n, 'radius-hotel-booking-pro' ), $n );
			},
			10,
			2
		);
	}

	/**
	 * Runs, newest first.
	 *
	 * @param string $status   Status filter ('' = all).
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 * @return array{runs: array[], total: int}
	 */
	public function list( string $status = '', int $page = 1, int $per_page = 20 ): array {
		$status            = in_array( $status, array( PayrollRun::DRAFT, PayrollRun::FINALIZED, PayrollRun::SETTLED, PayrollRun::VOIDED ), true ) ? $status : '';
		list( $runs, $total ) = $this->runs->page( $status, max( 1, $page ), max( 1, min( 100, $per_page ) ) );
		return array(
			'runs'  => array_map( array( self::class, 'shapeRun' ), $runs ),
			'total' => $total,
		);
	}

	/**
	 * One run with its lines.
	 *
	 * @param int $id Run id.
	 * @return array
	 * @throws DomainException 404.
	 */
	public function get( int $id ): array {
		$run = $this->find( $id );
		$types = array();
		foreach ( PayItemTypes::all() as $key => $type ) {
			$types[] = array(
				'key'   => $key,
				'label' => $type['label'],
				'entry' => $type['entry'],
			);
		}
		return self::shapeRun( $run ) + array(
			'lines'      => array_map( array( self::class, 'shapeLine' ), $this->runs->lines( $id ) ),
			// For the line editor: what each additional pay type asks for.
			'item_types' => $types,
			// Payslips exist once the run is finalised: the formats they come in.
			'payslip_formats' => PayrollRun::DRAFT === (string) $run->status ? array() : PayslipService::formats(),
		);
	}

	/**
	 * Create a draft run for a schedule's next period without a run (or period `k`).
	 *
	 * @param int      $schedule_id  Schedule.
	 * @param int|null $period_index Period, or null for the next one to pay.
	 * @return array As get().
	 * @throws DomainException 404, 409 `run_exists` / `no_employees`.
	 */
	public function create( int $schedule_id, ?int $period_index = null ): array {
		$schedule = ( new PayScheduleRepository() )->find( $schedule_id );
		if ( ! $schedule instanceof PaySchedule ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'This pay schedule does not exist.', 'radius-hotel-booking-pro' ) );
		}
		$skipped = array();
		$id      = Transaction::run(
			function () use ( $schedule, $period_index, &$skipped ) {
				$this->runs->lockSchedule( (int) $schedule->id );
				if ( null === $period_index ) {
					// The next period to pay that has no run yet.
					$period = $schedule->nextPeriod( Dates::today() );
					for ( $guard = 0; $guard < 60 && $this->runs->liveOverlapping( (int) $schedule->id, $period['start'], $period['end'] ); $guard++ ) {
						$period = $schedule->period( $period['index'] + 1 );
					}
				} else {
					$period = $schedule->period( $period_index );
				}
				$live = $this->runs->liveOverlapping( (int) $schedule->id, $period['start'], $period['end'] );
				if ( $live ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
					throw DomainException::conflict( 'run_exists', __( 'These days are already in a pay run of this schedule. Void it first to run them again.', 'radius-hotel-booking-pro' ), array( 'run_id' => (int) $live->id ) );
				}
				// Employees on the schedule who worked in the period: active, or deactivated during or after it (their last pay).
				$profiles = array_filter(
					( new EmployeePayRepository() )->forSchedule( (int) $schedule->id ),
					static function ( $p ) use ( $period ) {
						$employee = self::employee( (int) $p->employee_id );
						return $employee && ( $employee['active'] || ( '' !== $employee['deactivated_on'] && $employee['deactivated_on'] >= $period['start'] ) );
					}
				);
				// Someone already paid for some of these days (moved from another schedule): left out, said.
				$paid = $this->runs->paidOverlapping( array_map( static fn( $p ) => (int) $p->employee_id, $profiles ), $period['start'], $period['end'] );
				foreach ( $paid as $employee_id => $where ) {
					$skipped[] = sprintf( '%s (%s)', self::employee( $employee_id )['name'] ?? '#' . $employee_id, $where );
				}
				$profiles = array_filter( $profiles, static fn( $p ) => ! isset( $paid[ (int) $p->employee_id ] ) );
				if ( ! $profiles ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
					throw DomainException::conflict( 'no_employees', __( 'No employee on this pay schedule can be paid for this period: put employees on it from their Pay tab (someone already paid for these days on another schedule is left out).', 'radius-hotel-booking-pro' ), array( 'skipped' => $skipped ) );
				}
				$run = $this->runs->create(
					array(
						'pay_schedule_id' => (int) $schedule->id,
						'schedule_name'   => (string) $schedule->name,
						'pay_interval'    => (string) $schedule->pay_interval,
						'period_index'    => (int) $period['index'],
						'period_start'    => $period['start'],
						'period_end'      => $period['end'],
						'pay_date'        => $period['pay_date'],
						'status'          => PayrollRun::DRAFT,
						'created_by'      => get_current_user_id() ? get_current_user_id() : null,
					)
				);
				foreach ( $profiles as $profile ) {
					$this->calculateLine( $run, (int) $profile->employee_id, array() );
				}
				$this->totals( (int) $run->id );
				$run = $this->find( (int) $run->id );
				self::log(
					'payroll.run',
					$run,
					array(),
					array( 'employees' => count( $profiles ) ) + ( $skipped ? array( 'left_out' => $skipped ) : array() ),
					/* translators: %s: run (schedule and period). */
					sprintf( __( 'Ran payroll %s', 'radius-hotel-booking-pro' ), $run->label() )
				);
				return (int) $run->id;
			}
		);
		$this->announce( $id, '', PayrollRun::DRAFT );
		return $this->get( $id ) + array( 'skipped' => $skipped );
	}

	/**
	 * Edit a draft line: entries `{ type: hours|amount }`, `custom` items, `hours` (base hours; empty = the shift's).
	 *
	 * @param int   $run_id  Run.
	 * @param int   $line_id Line.
	 * @param array $input   `{ entries?, custom?, hours? }`.
	 * @return array As get().
	 * @throws DomainException 404, 409 `illegal_transition`, 422.
	 */
	public function updateLine( int $run_id, int $line_id, array $input ): array {
		Transaction::run(
			function () use ( $run_id, $line_id, $input ) {
				$run  = $this->locked( $run_id, array( PayrollRun::DRAFT ), 'edit_line' );
				$line = $this->runs->line( $run_id, $line_id );
				if ( ! $line ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
					throw DomainException::notFound( __( 'This line is not on the pay run.', 'radius-hotel-booking-pro' ) );
				}
				$before = $line->json( 'inputs' );
				$inputs = self::cleanInputs( array_merge( $before, array_intersect_key( $input, array_flip( array( 'entries', 'custom', 'hours' ) ) ) ) );
				$this->calculateLine( $run, (int) $line->employee_id, $inputs );
				$this->totals( $run_id );
				if ( $before !== $inputs ) {
					self::log(
						'payroll.edit_line',
						$run,
						array( 'employee' => (string) $line->employee_name ) + self::inputSummary( $before ),
						array( 'employee' => (string) $line->employee_name ) + self::inputSummary( $inputs ),
						/* translators: 1: employee, 2: run. */
						sprintf( __( 'Changed the payroll line of %1$s in %2$s', 'radius-hotel-booking-pro' ), $line->employee_name, $run->label() )
					);
				}
			}
		);
		return $this->get( $run_id );
	}

	/**
	 * Recalculate every line of a draft with the employees' current settings (the re-run after a revert).
	 *
	 * @param int $run_id Run.
	 * @return array As get().
	 * @throws DomainException 404, 409.
	 */
	public function recalculate( int $run_id ): array {
		Transaction::run(
			function () use ( $run_id ) {
				$run = $this->locked( $run_id, array( PayrollRun::DRAFT ), 'recalculate' );
				foreach ( $this->runs->lines( $run_id ) as $line ) {
					$this->calculateLine( $run, (int) $line->employee_id, $line->json( 'inputs' ) );
				}
				$this->totals( $run_id );
				/* translators: %s: run. */
				self::log( 'payroll.run', $run, array(), array( 'recalculated' => true ), sprintf( __( 'Recalculated payroll %s', 'radius-hotel-booking-pro' ), $run->label() ) );
			}
		);
		return $this->get( $run_id );
	}

	/**
	 * Finalise a draft: not before the period's last day, no negative net.
	 *
	 * @param int $run_id Run.
	 * @return array As get().
	 * @throws DomainException 409 `period_open` / `negative_net` / `illegal_transition`.
	 */
	public function finalize( int $run_id ): array {
		return $this->move(
			$run_id,
			PayrollRun::DRAFT,
			PayrollRun::FINALIZED,
			'payroll.finalize',
			function ( PayrollRun $run ) {
				if ( substr( (string) $run->period_end, 0, 10 ) > Dates::today() ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
					throw DomainException::conflict( 'period_open', __( 'This pay run cannot be finalised yet: its pay period has not ended.', 'radius-hotel-booking-pro' ) );
				}
				// Recalculate under the employees' locks (taken by move()): what other
				// runs took this month and year may have changed since the draft.
				foreach ( $this->runs->lines( (int) $run->id ) as $line ) {
					$this->calculateLine( $run, (int) $line->employee_id, $line->json( 'inputs' ) );
				}
				$this->totals( (int) $run->id );
				foreach ( $this->runs->lines( (int) $run->id ) as $line ) {
					if ( (float) $line->net < 0 ) {
						/* translators: %s: employee name. */
						throw DomainException::conflict( 'negative_net', sprintf( __( 'The net pay of %s is below zero. Correct the line first.', 'radius-hotel-booking-pro' ), $line->employee_name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
					}
				}
				return array(
					'finalized_at' => current_time( 'mysql' ),
					'finalized_by' => get_current_user_id(),
				);
			}
		);
	}

	/**
	 * Settle a finalised run: a payment method for every line.
	 *
	 * @param int   $run_id   Run.
	 * @param array $payments Line id => `{ method, reference? }`.
	 * @return array As get().
	 * @throws DomainException 409, 422 (`payments.<line>.method`).
	 */
	public function settle( int $run_id, array $payments ): array {
		return $this->move(
			$run_id,
			PayrollRun::FINALIZED,
			PayrollRun::SETTLED,
			'payroll.settle',
			function ( PayrollRun $run ) use ( $payments ) {
				$errors = array();
				$rows   = array();
				foreach ( $this->runs->lines( (int) $run->id ) as $line ) {
					$entry  = (array) ( $payments[ $line->id ] ?? $payments[ (string) $line->id ] ?? array() );
					$method = mb_substr( sanitize_text_field( (string) ( $entry['method'] ?? '' ) ), 0, 60 );
					if ( '' === $method ) {
						$errors[ 'payments.' . $line->id . '.method' ] = __( 'Enter how this employee was paid.', 'radius-hotel-booking-pro' );
						continue;
					}
					$rows[ (int) $line->id ] = array(
						'payment_method' => $method,
						'payment_ref'    => mb_substr( sanitize_text_field( (string) ( $entry['reference'] ?? '' ) ), 0, 100 ),
					);
				}
				if ( $errors ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
					throw DomainException::invalid( $errors, __( 'Enter the payment method for every employee.', 'radius-hotel-booking-pro' ) );
				}
				global $wpdb;
				foreach ( $rows as $line_id => $fields ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the run's own lines, under its lock.
					$wpdb->update( PayrollRunRepository::table( 'lines' ), $fields, array( 'id' => $line_id ) );
				}
				return array(
					'settled_at' => current_time( 'mysql' ),
					'settled_by' => get_current_user_id(),
				);
			}
		);
	}

	/**
	 * Void a settled run, with a reason; it stays in the history.
	 *
	 * @param int    $run_id Run.
	 * @param string $reason Why.
	 * @return array As get().
	 * @throws DomainException 409, 422 `reason`.
	 */
	public function void( int $run_id, string $reason ): array {
		$reason = mb_substr( sanitize_text_field( $reason ), 0, 255 );
		if ( '' === $reason ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::invalid( array( 'reason' => __( 'Say why the pay run is voided.', 'radius-hotel-booking-pro' ) ) );
		}
		return $this->move(
			$run_id,
			PayrollRun::SETTLED,
			PayrollRun::VOIDED,
			'payroll.void',
			static fn() => array(
				'voided_at'   => current_time( 'mysql' ),
				'voided_by'   => get_current_user_id(),
				'void_reason' => $reason,
			),
			$reason
		);
	}

	/**
	 * Revert a finalised run to draft (to correct and finalise it again).
	 *
	 * @param int $run_id Run.
	 * @return array As get().
	 * @throws DomainException 409.
	 */
	public function revert( int $run_id ): array {
		return $this->move(
			$run_id,
			PayrollRun::FINALIZED,
			PayrollRun::DRAFT,
			'payroll.revert',
			static fn() => array(
				'finalized_at' => null,
				'finalized_by' => null,
			)
		);
	}

	/**
	 * Remove a draft run.
	 *
	 * @param int $run_id Run.
	 * @return void
	 * @throws DomainException 404, 409.
	 */
	public function remove( int $run_id ): void {
		Transaction::run(
			function () use ( $run_id ) {
				$run = $this->locked( $run_id, array( PayrollRun::DRAFT ), 'remove' );
				$this->runs->deleteRun( $run_id );
				/* translators: %s: run. */
				self::log( 'payroll.remove', $run, self::shapeRun( $run ), array(), sprintf( __( 'Removed the draft pay run %s', 'radius-hotel-booking-pro' ), $run->label() ) );
			}
		);
		$this->announce( $run_id, PayrollRun::DRAFT, '' );
	}

	/**
	 * A status move under the run's lock.
	 *
	 * @param int      $run_id Run.
	 * @param string   $from   Required status.
	 * @param string   $to     New status.
	 * @param string   $action Activity action.
	 * @param callable $checks `fn( PayrollRun ): array` — checks, and extra columns to write.
	 * @param string   $why    A reason for the log.
	 * @return array As get().
	 * @throws DomainException 404, 409.
	 */
	private function move( int $run_id, string $from, string $to, string $action, callable $checks, string $why = '' ): array {
		Transaction::run(
			function () use ( $run_id, $from, $to, $action, $checks, $why ) {
				$run = $this->locked( $run_id, array( $from ), substr( $action, strlen( 'payroll.' ) ) );
				// Run lock first, then the employees in id order: no deadlock between two runs.
				$this->runs->lockEmployeesOf( $run_id );
				$extra = (array) $checks( $run );
				$this->runs->update( $run_id, array( 'status' => $to ) + $extra );
				// Garnishments: what remains follows the runs that count.
				foreach ( $this->runs->garnishmentsOf( $run_id ) as $ref ) {
					$this->syncRemaining( $ref );
				}
				$labels = array(
					/* translators: %s: pay run. */
					'payroll.finalize' => __( 'Finalised payroll %s', 'radius-hotel-booking-pro' ),
					/* translators: %s: pay run. */
					'payroll.settle'   => __( 'Settled payroll %s', 'radius-hotel-booking-pro' ),
					/* translators: %s: pay run. */
					'payroll.void'     => __( 'Voided payroll %s', 'radius-hotel-booking-pro' ),
					/* translators: %s: pay run. */
					'payroll.revert'   => __( 'Reverted payroll %s to draft', 'radius-hotel-booking-pro' ),
				);
				self::log(
					$action,
					$run,
					array( 'status' => $from ),
					array( 'status' => $to ) + ( '' !== $why ? array( 'reason' => $why ) : array() ),
					sprintf( $labels[ $action ] ?? '%s', $run->label() )
				);
			}
		);
		$this->announce( $run_id, $from, $to );
		return $this->get( $run_id );
	}

	/**
	 * A line is only ever recalculated by the calculator that made it: if a
	 * country add-on was switched off (or another one installed) since, the
	 * line would silently lose or change its taxes.
	 *
	 * @param PayrollLine|null $line The stored line, if any.
	 * @return void
	 * @throws DomainException 409 `calculator_changed`.
	 */
	private static function sameCalculator( $line ): void {
		if ( ! $line ) {
			return;
		}
		$version = (string) ( $line->json( 'breakdown' )['statutory']['table_version'] ?? '' );
		if ( '' === $version ) {
			return;
		}
		// `key:version`; lines made before the calculators were pluggable say `ci-legacy`.
		$made_by = false !== strpos( $version, ':' ) ? strstr( $version, ':', true ) : 'ci';
		$current = Statutory::calculator();
		if ( $made_by !== $current->key() ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::conflict(
				'calculator_changed',
				sprintf(
					/* translators: %s: the calculator in use now. */
					__( 'This pay run was calculated with other tax rules than the ones in use now (%s). Re-activate the add-on that provided them, or void the run and start a new one.', 'radius-hotel-booking-pro' ),
					$current->label()
				)
			);
			// phpcs:enable
		}
	}

	/**
	 * Calculate (or recalculate) one employee's line with their current settings.
	 *
	 * @param PayrollRun $run         Run (locked by the caller).
	 * @param int        $employee_id Pro employee id.
	 * @param array      $inputs      Clean inputs.
	 * @return void
	 * @throws DomainException From the calculator.
	 */
	private function calculateLine( PayrollRun $run, int $employee_id, array $inputs ): void {
		self::sameCalculator( $this->runs->lineOf( (int) $run->id, $employee_id ) );
		$employee = self::employee( $employee_id );
		if ( ! $employee ) {
			// The Pro file is gone: keep the name and number the line already had.
			$line     = $this->runs->lineOf( (int) $run->id, $employee_id );
			$employee = array(
				'name' => $line ? (string) $line->employee_name : '#' . $employee_id,
				'no'   => $line ? (string) $line->employee_no : '',
			);
		}
		$profile  = ( new EmployeePayRepository() )->forEmployee( $employee_id );
		$items    = ( new EmployeePayItemRepository() )->forEmployee( $employee_id );
		$pay_date = substr( (string) $run->pay_date, 0, 10 );
		$year     = (int) substr( $pay_date, 0, 4 );
		$month    = (int) substr( $pay_date, 5, 2 );

		// Hours: the shift's in the period, unless entered.
		$shift_days = $profile && $profile->work_shift_id ? ( new WorkShiftService() )->days( (int) $profile->work_shift_id ) : array();
		$per_week   = ShiftHours::perWeek( $shift_days );
		$hours      = isset( $inputs['hours'] ) && '' !== (string) $inputs['hours'] ? (string) $inputs['hours'] : (string) ShiftHours::inPeriod( $shift_days, substr( (string) $run->period_start, 0, 10 ), substr( (string) $run->period_end, 0, 10 ) );

		// Deductions and garnishments that apply on the pay date, with what remains.
		$deductions = array();
		foreach ( array_map( array( DeductionService::class, 'shape' ), ( new EmployeeDeductionRepository() )->forEmployee( $employee_id ) ) as $row ) {
			if ( 'active' !== $row['status'] || ( $row['start_date'] && $row['start_date'] > $pay_date ) || ( $row['end_date'] && $row['end_date'] < $pay_date ) ) {
				continue;
			}
			if ( 'garnishment' === $row['kind'] && 'other' === $row['type'] ) {
				$row['remaining_amount'] = Decimal::max( '0', Decimal::sub( $row['total_amount'], $this->runs->garnished( (int) $row['id'], (int) $run->id ) ) );
			}
			$deductions[] = $row;
		}

		$settings = array(
			'pay_date'         => $pay_date,
			'interval'         => (string) $run->pay_interval,
			/**
			 * Figures a country calculator needs, frozen with the line so a
			 * recalculation gives the same result (TATA: the work accident rate).
			 *
			 * @param array $settings Name => value.
			 */
			'statutory'        => (array) apply_filters( 'rtbp_pro_payroll_statutory_settings', array() ),
			'profile'          => $profile ? array(
				'pay_type'      => (string) $profile->pay_type,
				'amount'        => (string) $profile->amount,
				'amount_period' => (string) $profile->amount_period,
			) : array(),
			'hours'            => $hours,
			'hours_per_week'   => $per_week,
			'items'            => $items,
			'used'             => array(
				'paid_time_off' => $this->runs->hoursUsed( $employee_id, 'paid_time_off', $year, (int) $run->id ),
				'sick_pay'      => $this->runs->hoursUsed( $employee_id, 'sick_pay', $year, (int) $run->id ),
			),
			'deductions'       => $deductions,
			'taken_this_month' => $this->runs->takenInMonth( $employee_id, $year, $month, (int) $run->id ),
		);
		$result   = LineCalculator::calculate(
			$settings + array(
				'entries' => (array) ( $inputs['entries'] ?? array() ),
				'custom' => (array) ( $inputs['custom'] ?? array() ),
			)
		);
		$money    = static fn( $value ) => number_format( (float) $value, 2, '.', '' );
		$this->runs->saveLine(
			array(
				'run_id'        => (int) $run->id,
				'employee_id'   => $employee_id,
				'employee_name' => $employee['name'],
				'employee_no'   => $employee['no'],
				'inputs'        => wp_json_encode( $inputs ),
				'settings'      => wp_json_encode( $settings ),
				'breakdown'     => wp_json_encode( $result ),
				'gross'         => $money( $result['gross'] ),
				'employee_tax'  => $money( $result['statutory']['employee_total'] ),
				'employer_tax'  => $money( $result['statutory']['employer_total'] ),
				'net'           => $money( $result['net'] ),
				'employer_cost' => $money( $result['employer_cost'] ),
			),
			$result['ledger'],
			$pay_date,
			self::statutoryRows( $result, $deductions )
		);
	}

	/**
	 * What a line owes each authority: the calculator's lines by code (employee
	 * and employer share together), each deduction's shares (`DED_<id>`) and
	 * each garnishment (`GAR_<id>`). Zero rows are left out.
	 *
	 * @param array   $result     LineCalculator result.
	 * @param array[] $deductions The deduction rows the line used.
	 * @return array[]
	 */
	private static function statutoryRows( array $result, array $deductions ): array {
		$rows = array();
		foreach ( $result['statutory']['lines'] as $line ) {
			$key = $line['authority'] . '|' . $line['code'];
			if ( ! isset( $rows[ $key ] ) ) {
				$rows[ $key ] = array(
					'authority' => $line['authority'],
					'code'      => $line['code'],
					'label'     => $line['label'],
					'kind'      => 'statutory',
					'base'      => $line['base'],
					'employee'  => '0',
					'employer'  => '0',
				);
			}
			$side                  = 'employee' === $line['side'] ? 'employee' : 'employer';
			$rows[ $key ][ $side ] = Decimal::add( $rows[ $key ][ $side ], $line['amount'] );
		}
		$by_id = array();
		foreach ( $deductions as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}
		foreach ( $result['deductions'] as $taken ) {
			$id     = (int) substr( $taken['key'], strlen( 'deduction_' ) );
			$rows[] = array(
				'authority' => (string) ( $by_id[ $id ]['authority'] ?? '' ),
				'code'      => 'DED_' . $id,
				'label'     => (string) $taken['label'],
				'kind'      => 'deduction',
				'base'      => $result['gross'],
				'employee'  => $taken['amount'],
				'employer'  => $taken['employer_amount'],
			);
		}
		foreach ( $result['garnishments'] as $taken ) {
			$id     = (int) substr( $taken['key'], strlen( 'garnishment_' ) );
			$rows[] = array(
				'authority' => (string) ( $by_id[ $id ]['authority'] ?? '' ),
				'code'      => 'GAR_' . $id,
				'label'     => (string) $taken['label'],
				'kind'      => 'garnishment',
				'base'      => '0',
				'employee'  => $taken['amount'],
				'employer'  => '0',
			);
		}
		// The tax withheld on one-off additions is owed to the tax authority like the salary tax.
		$custom_tax  = '0';
		$custom_base = '0';
		foreach ( (array) ( $result['custom'] ?? array() ) as $item ) {
			$custom_tax  = Decimal::add( $custom_tax, (string) ( $item['tax'] ?? '0' ) );
			$custom_base = Decimal::add( $custom_base, 'addition' === ( $item['type'] ?? '' ) && 0 !== Decimal::cmp( (string) ( $item['tax'] ?? '0' ), '0' ) ? (string) $item['amount'] : '0' );
		}
		$rows[] = array(
			'authority' => Statutory::calculator()->taxAuthority(),
			'code'      => 'CUSTOM_TAX',
			'label'     => __( 'Tax on one-off additions', 'radius-hotel-booking-pro' ),
			'kind'      => 'statutory',
			'base'      => $custom_base,
			'employee'  => $custom_tax,
			'employer'  => '0',
		);
		return array_values(
			array_filter(
				$rows,
				static fn( $row ) => 0 !== Decimal::cmp( Decimal::of( $row['employee'] ), '0' ) || 0 !== Decimal::cmp( Decimal::of( $row['employer'] ), '0' )
			)
		);
	}

	/**
	 * Re-add a run's totals from its lines.
	 *
	 * @param int $run_id Run.
	 * @return void
	 */
	private function totals( int $run_id ): void {
		$sum = array(
			'gross'         => '0',
			'employee_tax'  => '0',
			'employer_tax'  => '0',
			'net'           => '0',
			'employer_cost' => '0',
		);
		$lines = $this->runs->lines( $run_id );
		foreach ( $lines as $line ) {
			foreach ( $sum as $key => $value ) {
				$sum[ $key ] = Decimal::add( $value, Decimal::of( number_format( (float) $line->{$key}, 2, '.', '' ) ) );
			}
		}
		$this->runs->update(
			$run_id,
			array(
				'employees'           => count( $lines ),
				'gross_total'         => $sum['gross'],
				'employee_tax_total'  => $sum['employee_tax'],
				'employer_tax_total'  => $sum['employer_tax'],
				'net_total'           => $sum['net'],
				'employer_cost_total' => $sum['employer_cost'],
			)
		);
	}

	/**
	 * A garnishment's remaining amount from the runs that count.
	 *
	 * @param int $ref_id Deduction row.
	 * @return void
	 */
	private function syncRemaining( int $ref_id ): void {
		$row = ( new EmployeeDeductionRepository() )->find( $ref_id );
		if ( ! $row instanceof EmployeeDeduction || 'other' !== $row->type ) {
			return;
		}
		$remaining = Decimal::max( '0', Decimal::sub( number_format( (float) $row->total_amount, 2, '.', '' ), $this->runs->garnished( $ref_id ) ) );
		( new EmployeeDeductionRepository() )->update( $ref_id, array( 'remaining_amount' => $remaining ) );
	}

	/**
	 * A run locked in one of the given states, or 404 / 409.
	 *
	 * @param int      $run_id Run.
	 * @param string[] $states Allowed states.
	 * @param string   $action What is attempted.
	 * @return PayrollRun
	 * @throws DomainException 404, 409 `illegal_transition`.
	 */
	private function locked( int $run_id, array $states, string $action ): PayrollRun {
		$run = $this->runs->lockRun( $run_id );
		if ( ! $run ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'This pay run does not exist.', 'radius-hotel-booking-pro' ) );
		}
		if ( ! in_array( (string) $run->status, $states, true ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::conflict(
				'illegal_transition',
				/* translators: %s: run status. */
				sprintf( __( 'Not possible while the pay run is %s.', 'radius-hotel-booking-pro' ), self::statusLabel( (string) $run->status ) ),
				array(
					'action' => $action,
					'from'   => (string) $run->status,
				)
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $run;
	}

	/**
	 * A run, or 404.
	 *
	 * @param int $id Id.
	 * @return PayrollRun
	 * @throws DomainException 404.
	 */
	private function find( int $id ): PayrollRun {
		$run = $id > 0 ? $this->runs->find( $id ) : null;
		if ( ! $run instanceof PayrollRun ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'This pay run does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $run;
	}

	/**
	 * After the commit: `rtbp_pro_payroll_run_status` (statutory contributions, T6).
	 *
	 * @param int    $run_id Run.
	 * @param string $from   Old status ('' = new).
	 * @param string $to     New status ('' = removed).
	 * @return void
	 */
	private function announce( int $run_id, string $from, string $to ): void {
		Transaction::afterCommit(
			static function () use ( $run_id, $from, $to ) {
				/**
				 * A pay run changed status (after the commit).
				 *
				 * @param int    $run_id Run id.
				 * @param string $from   Old status ('' when created).
				 * @param string $to     New status ('' when removed).
				 */
				do_action( 'rtbp_pro_payroll_run_status', $run_id, $from, $to );
			}
		);
	}

	/**
	 * Clean line inputs.
	 *
	 * @param array $input Input.
	 * @return array
	 * @throws DomainException 422.
	 */
	private static function cleanInputs( array $input ): array {
		$errors  = array();
		$entries = array();
		foreach ( (array) ( $input['entries'] ?? array() ) as $type => $value ) {
			$type = sanitize_key( (string) $type );
			if ( '' === (string) $value || null === $value ) {
				continue;
			}
			if ( ! isset( PayItemTypes::all()[ $type ] ) || ! is_numeric( $value ) || (float) $value < 0 ) {
				$errors[ "entries.$type" ] = __( 'Enter a number of 0 or more.', 'radius-hotel-booking-pro' );
				continue;
			}
			$entries[ $type ] = (string) round( (float) $value, 2 );
		}
		$custom = array();
		foreach ( array_values( (array) ( $input['custom'] ?? array() ) ) as $i => $item ) {
			$name   = mb_substr( sanitize_text_field( (string) ( $item['name'] ?? '' ) ), 0, 100 );
			$amount = $item['amount'] ?? '';
			if ( '' === $name && ( '' === (string) $amount || 0.0 === (float) $amount ) ) {
				continue;
			}
			if ( '' === $name ) {
				$errors[ "custom.$i.name" ] = __( 'Enter a name.', 'radius-hotel-booking-pro' );
			}
			if ( ! is_numeric( $amount ) || (float) $amount <= 0 ) {
				$errors[ "custom.$i.amount" ] = __( 'Enter an amount above zero.', 'radius-hotel-booking-pro' );
			}
			$rate     = (float) ( $item['tax_rate'] ?? 0 );
			$custom[] = array(
				'type'     => 'deduction' === ( $item['type'] ?? '' ) ? 'deduction' : 'addition',
				'name'     => $name,
				'amount'   => (string) round( (float) $amount, 2 ),
				'taxable'  => 'deduction' !== ( $item['type'] ?? '' ) && ! empty( $item['taxable'] ),
				'tax_rate' => max( 0, min( 100, $rate ) ),
			);
		}
		$hours = $input['hours'] ?? '';
		if ( '' !== (string) $hours && null !== $hours && ( ! is_numeric( $hours ) || (float) $hours < 0 || (float) $hours > 744 ) ) {
			$errors['hours'] = __( 'Enter the hours worked, from 0 to 744.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::invalid( $errors );
		}
		return array(
			'entries' => $entries,
			'custom'  => $custom,
			'hours'   => '' === (string) $hours || null === $hours ? '' : (string) round( (float) $hours, 2 ),
		);
	}

	/**
	 * Inputs in a few log fields.
	 *
	 * @param array $inputs Inputs.
	 * @return array
	 */
	private static function inputSummary( array $inputs ): array {
		$out = array();
		foreach ( (array) ( $inputs['entries'] ?? array() ) as $type => $value ) {
			$out[ $type ] = $value;
		}
		foreach ( (array) ( $inputs['custom'] ?? array() ) as $item ) {
			$out[ 'custom: ' . $item['name'] ] = ( 'deduction' === $item['type'] ? '−' : '+' ) . $item['amount'];
		}
		if ( '' !== (string) ( $inputs['hours'] ?? '' ) ) {
			$out['hours'] = $inputs['hours'];
		}
		return $out;
	}

	/**
	 * A status in words.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function statusLabel( string $status ): string {
		$labels = array(
			PayrollRun::DRAFT     => __( 'draft', 'radius-hotel-booking-pro' ),
			PayrollRun::FINALIZED => __( 'finalised', 'radius-hotel-booking-pro' ),
			PayrollRun::SETTLED   => __( 'settled', 'radius-hotel-booking-pro' ),
			PayrollRun::VOIDED    => __( 'voided', 'radius-hotel-booking-pro' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * The API shape of a run.
	 *
	 * @param PayrollRun $run Run.
	 * @return array
	 */
	public static function shapeRun( PayrollRun $run ): array {
		$money = static fn( $v ) => number_format( (float) $v, 2, '.', '' );
		return array(
			'id'                  => (int) $run->id,
			'pay_schedule_id'     => (int) $run->pay_schedule_id,
			'schedule_name'       => (string) $run->schedule_name,
			'pay_interval'        => (string) $run->pay_interval,
			'period_start'        => substr( (string) $run->period_start, 0, 10 ),
			'period_end'          => substr( (string) $run->period_end, 0, 10 ),
			'pay_date'            => substr( (string) $run->pay_date, 0, 10 ),
			'status'              => (string) $run->status,
			'employees'           => (int) $run->employees,
			'gross_total'         => $money( $run->gross_total ),
			'employee_tax_total'  => $money( $run->employee_tax_total ),
			'employer_tax_total'  => $money( $run->employer_tax_total ),
			'net_total'           => $money( $run->net_total ),
			'employer_cost_total' => $money( $run->employer_cost_total ),
			'finalized_at'        => $run->finalized_at ? (string) $run->finalized_at : null,
			'settled_at'          => $run->settled_at ? (string) $run->settled_at : null,
			'voided_at'           => $run->voided_at ? (string) $run->voided_at : null,
			'void_reason'         => $run->void_reason ? (string) $run->void_reason : null,
			'can_finalize_from'   => substr( (string) $run->period_end, 0, 10 ),
		);
	}

	/**
	 * The API shape of a line.
	 *
	 * @param PayrollLine $line Line.
	 * @return array
	 */
	public static function shapeLine( PayrollLine $line ): array {
		return array(
			'id'             => (int) $line->id,
			'employee_id'    => (int) $line->employee_id,
			'employee_name'  => (string) $line->employee_name,
			'employee_no'    => (string) $line->employee_no,
			// Objects, never `[]`: an empty PHP array would reach JS as an array.
			'inputs'         => (object) array(
				'entries' => (object) ( $line->json( 'inputs' )['entries'] ?? array() ),
				'custom'  => array_values( (array) ( $line->json( 'inputs' )['custom'] ?? array() ) ),
				'hours'   => (string) ( $line->json( 'inputs' )['hours'] ?? '' ),
			),
			'breakdown'      => $line->json( 'breakdown' ),
			'gross'          => number_format( (float) $line->gross, 2, '.', '' ),
			'employee_tax'   => number_format( (float) $line->employee_tax, 2, '.', '' ),
			'employer_tax'   => number_format( (float) $line->employer_tax, 2, '.', '' ),
			'net'            => number_format( (float) $line->net, 2, '.', '' ),
			'employer_cost'  => number_format( (float) $line->employer_cost, 2, '.', '' ),
			'payment_method' => $line->payment_method ? (string) $line->payment_method : null,
			'payment_ref'    => $line->payment_ref ? (string) $line->payment_ref : null,
			'items'          => array_keys( (array) ( $line->json( 'settings' )['items'] ?? array() ) ),
		);
	}

	/**
	 * Log a write against the run.
	 *
	 * @param string     $action      Action.
	 * @param PayrollRun $run         Run.
	 * @param array      $before      Before.
	 * @param array      $after       After.
	 * @param string     $description Description.
	 * @return void
	 */
	private static function log( string $action, PayrollRun $run, array $before, array $after, string $description ): void {
		if ( ! function_exists( 'rtbp_activity' ) ) {
			return;
		}
		rtbp_activity(
			$action,
			array(
				'type'  => 'payroll_run',
				'id'    => (int) $run->id,
				'label' => $run->label(),
			),
			array(
				'before'      => $before,
				'after'       => $after,
				'description' => $description,
			)
		);
	}

	/**
	 * A Pro employee: `{ name, no, active, deactivated_on }` (`Y-m-d` or ''), or null when the file is gone.
	 *
	 * @param int $employee_id Id.
	 * @return array|null
	 */
	private static function employee( int $employee_id ): ?array {
		$repo     = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\EmployeeRepository';
		$employee = class_exists( $repo ) ? ( new $repo() )->find( $employee_id ) : null;
		if ( ! $employee ) {
			return null;
		}
		return array(
			'name'           => method_exists( $employee, 'fullName' ) ? (string) $employee->fullName() : '#' . $employee_id,
			'no'             => (string) $employee->employee_no,
			'active'         => (bool) $employee->is_active,
			'deactivated_on' => $employee->deactivated_at ? substr( (string) $employee->deactivated_at, 0, 10 ) : '',
		);
	}
}
