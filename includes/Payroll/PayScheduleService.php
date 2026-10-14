<?php
/**
 * Pay schedules (M15 T3a, 15.9).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Support\Dates;

defined( 'ABSPATH' ) || exit;

/**
 * Create, change and delete pay schedules. Each write is logged as
 * `payroll.schedules`. A schedule still in use (employees on it, runs made
 * with it — later tasks answer `rtbp_pro_pay_schedule_in_use`) cannot be
 * deleted. Changing the dates of a schedule moves its future periods only:
 * runs keep the period they were made for.
 */
final class PayScheduleService {

	/**
	 * Repository.
	 *
	 * @var PayScheduleRepository
	 */
	private PayScheduleRepository $schedules;

	/**
	 * Constructor.
	 *
	 * @param PayScheduleRepository|null $schedules Repository.
	 */
	public function __construct( ?PayScheduleRepository $schedules = null ) {
		$this->schedules = $schedules ?? new PayScheduleRepository();
	}

	/**
	 * Every schedule with its next period and number of employees.
	 *
	 * @param string|null $today Y-m-d (default: the site's today).
	 * @return array[]
	 */
	public function list( ?string $today = null ): array {
		$today = $today ?? Dates::today();
		/**
		 * Employees per pay schedule (M15 T4b fills it).
		 *
		 * @param array<int, int> $counts Schedule id => employees.
		 */
		$counts = (array) apply_filters( 'rtbp_pro_pay_schedule_employees', array() );
		return array_map(
			static fn( PaySchedule $schedule ) => self::shape( $schedule, $today, (int) ( $counts[ (int) $schedule->id ] ?? 0 ) ),
			$this->schedules->byName()
		);
	}

	/**
	 * One schedule, or 404.
	 *
	 * @param int $id Id.
	 * @return PaySchedule
	 * @throws DomainException 404.
	 */
	public function get( int $id ): PaySchedule {
		$schedule = $id > 0 ? $this->schedules->find( $id ) : null;
		if ( ! $schedule instanceof PaySchedule ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::notFound( __( 'This pay schedule does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $schedule;
	}

	/**
	 * Create a schedule.
	 *
	 * @param array $input `{ name, pay_interval, period_end, next_pay_day }`.
	 * @return PaySchedule
	 * @throws DomainException 422.
	 */
	public function create( array $input ): PaySchedule {
		$data     = $this->validate( $input, 0 );
		$schedule = $this->schedules->create( $data );
		if ( ! $schedule->id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( array( 'name' => __( 'This name is already used by another pay schedule.', 'radius-hotel-booking-pro' ) ) );
		}
		self::log(
			$schedule,
			array(),
			$data,
			/* translators: %s: pay schedule name. */
			sprintf( __( 'Added the pay schedule %s', 'radius-hotel-booking-pro' ), $schedule->name )
		);
		return $schedule;
	}

	/**
	 * Change a schedule (only the fields sent).
	 *
	 * @param int   $id    Id.
	 * @param array $input Fields.
	 * @return PaySchedule
	 * @throws DomainException 404, 422.
	 */
	public function update( int $id, array $input ): PaySchedule {
		$schedule = $this->get( $id );
		$before   = self::fields( $schedule );
		$data     = $this->validate( array_merge( $before, array_intersect_key( $input, $before ) ), $id );
		$changed  = array_diff_assoc( $data, $before );
		if ( ! $changed ) {
			return $schedule;
		}
		$this->schedules->update( $id, $changed );
		$after = $this->get( $id );
		self::log(
			$after,
			array_intersect_key( $before, $changed ),
			$changed,
			/* translators: %s: pay schedule name. */
			sprintf( __( 'Changed the pay schedule %s', 'radius-hotel-booking-pro' ), $after->name )
		);
		return $after;
	}

	/**
	 * Delete a schedule nothing uses.
	 *
	 * @param int $id Id.
	 * @return void
	 * @throws DomainException 404, 409 `schedule_in_use`.
	 */
	public function delete( int $id ): void {
		$schedule = $this->get( $id );
		/**
		 * Why a pay schedule cannot be deleted ('' = it can): employees on it, runs made with it.
		 *
		 * @param string $reason Reason.
		 * @param int    $id     Schedule id.
		 */
		$reason = (string) apply_filters( 'rtbp_pro_pay_schedule_in_use', '', $id );
		if ( '' !== $reason ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict( 'schedule_in_use', $reason );
		}
		$this->schedules->delete( $id );
		self::log(
			$schedule,
			self::fields( $schedule ),
			array(),
			/* translators: %s: pay schedule name. */
			sprintf( __( 'Deleted the pay schedule %s', 'radius-hotel-booking-pro' ), $schedule->name )
		);
	}

	/**
	 * The API shape.
	 *
	 * @param PaySchedule $schedule  Schedule.
	 * @param string      $today     Y-m-d.
	 * @param int         $employees Employees on it.
	 * @return array
	 */
	public static function shape( PaySchedule $schedule, string $today, int $employees = 0 ): array {
		$next = $schedule->nextPeriod( $today );
		return array(
			'id'             => (int) $schedule->id,
			'name'           => (string) $schedule->name,
			'pay_interval'   => (string) $schedule->pay_interval,
			'interval_label' => PayPeriods::intervals()[ (string) $schedule->pay_interval ][0] ?? (string) $schedule->pay_interval,
			'period_end'     => (string) $schedule->period_end,
			'next_pay_day'   => (string) $schedule->next_pay_day,
			'next_period'    => $next,
			'upcoming'       => array( $next, $schedule->period( $next['index'] + 1 ), $schedule->period( $next['index'] + 2 ) ),
			'employees'      => $employees,
		);
	}

	/**
	 * Validated, normalised fields.
	 *
	 * @param array $input  Input.
	 * @param int   $except The schedule being edited.
	 * @return array
	 * @throws DomainException 422.
	 */
	private function validate( array $input, int $except ): array {
		$data   = array(
			'name'         => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'pay_interval' => sanitize_key( (string) ( $input['pay_interval'] ?? '' ) ),
			'period_end'   => substr( trim( (string) ( $input['period_end'] ?? '' ) ), 0, 10 ),
			'next_pay_day' => substr( trim( (string) ( $input['next_pay_day'] ?? '' ) ), 0, 10 ),
		);
		$errors = PayPeriods::problems( $data['pay_interval'], $data['period_end'], $data['next_pay_day'] );
		if ( '' === $data['name'] ) {
			$errors['name'] = __( 'Enter a name.', 'radius-hotel-booking-pro' );
		} elseif ( mb_strlen( $data['name'] ) > 100 ) {
			$errors['name'] = __( 'This is too long.', 'radius-hotel-booking-pro' );
		} elseif ( $this->schedules->nameTaken( $data['name'], $except ) ) {
			$errors['name'] = __( 'This name is already used by another pay schedule.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		return $data;
	}

	/**
	 * The stored fields of a schedule.
	 *
	 * @param PaySchedule $schedule Schedule.
	 * @return array
	 */
	private static function fields( PaySchedule $schedule ): array {
		return array(
			'name'         => (string) $schedule->name,
			'pay_interval' => (string) $schedule->pay_interval,
			'period_end'   => substr( (string) $schedule->period_end, 0, 10 ),
			'next_pay_day' => substr( (string) $schedule->next_pay_day, 0, 10 ),
		);
	}

	/**
	 * Log a write.
	 *
	 * @param PaySchedule $schedule    Schedule.
	 * @param array       $before      Before.
	 * @param array       $after       After.
	 * @param string      $description Description.
	 * @return void
	 */
	private static function log( PaySchedule $schedule, array $before, array $after, string $description ): void {
		if ( ! function_exists( 'rtbp_activity' ) ) {
			return;
		}
		rtbp_activity(
			'payroll.schedules',
			array(
				'type'  => 'pay_schedule',
				'id'    => (int) $schedule->id,
				'label' => (string) $schedule->name,
			),
			array(
				'before'      => $before,
				'after'       => $after,
				'description' => $description,
			)
		);
	}
}
