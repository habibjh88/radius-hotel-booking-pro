<?php
/**
 * Work shifts (M15 T3b, 15.10).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Create, change and delete work shifts with their seven days, in one
 * transaction each; logged as `payroll.shifts`. A shift still in use
 * (employees on it — T4b answers `rtbp_pro_work_shift_in_use`) cannot be
 * deleted.
 */
final class WorkShiftService {

	/**
	 * Repository.
	 *
	 * @var WorkShiftRepository
	 */
	private WorkShiftRepository $shifts;

	/**
	 * Constructor.
	 *
	 * @param WorkShiftRepository|null $shifts Repository.
	 */
	public function __construct( ?WorkShiftRepository $shifts = null ) {
		$this->shifts = $shifts ?? new WorkShiftRepository();
	}

	/**
	 * Every shift with its days, hours a week and number of employees.
	 *
	 * @return array[]
	 */
	public function list(): array {
		$shifts = $this->shifts->byName();
		$days   = $this->shifts->days( array_map( static fn( $shift ) => (int) $shift->id, $shifts ) );
		/**
		 * Employees per work shift (M15 T4b fills it).
		 *
		 * @param array<int, int> $counts Shift id => employees.
		 */
		$counts = (array) apply_filters( 'rtbp_pro_work_shift_employees', array() );
		return array_map(
			static fn( WorkShift $shift ) => self::shape( $shift, $days[ (int) $shift->id ] ?? array(), (int) ( $counts[ (int) $shift->id ] ?? 0 ) ),
			$shifts
		);
	}

	/**
	 * One shift, or 404.
	 *
	 * @param int $id Id.
	 * @return WorkShift
	 * @throws DomainException 404.
	 */
	public function get( int $id ): WorkShift {
		$shift = $id > 0 ? $this->shifts->find( $id ) : null;
		if ( ! $shift instanceof WorkShift ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::notFound( __( 'This work shift does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $shift;
	}

	/**
	 * A shift's seven days.
	 *
	 * @param int $id Shift id.
	 * @return array<int, array>
	 */
	public function days( int $id ): array {
		return $this->shifts->days( array( $id ) )[ $id ] ?? array();
	}

	/**
	 * Create a shift.
	 *
	 * @param array $input `{ name, description?, days: { 1..7: { is_day_off, start_time, end_time } } }`.
	 * @return WorkShift
	 * @throws DomainException 422.
	 */
	public function create( array $input ): WorkShift {
		list( $data, $days ) = $this->validate( $input, 0 );
		$shift               = Transaction::run(
			function () use ( $data, $days ) {
				$shift = $this->shifts->create( $data );
				if ( ! $shift->id ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
					throw DomainException::invalid( array( 'name' => __( 'This name is already used by another work shift.', 'radius-hotel-booking-pro' ) ) );
				}
				$this->shifts->replaceDays( (int) $shift->id, $days );
				self::log(
					$shift,
					array(),
					$data + array( 'days' => self::summary( $days ) ),
					/* translators: %s: work shift name. */
					sprintf( __( 'Added the work shift %s', 'radius-hotel-booking-pro' ), $shift->name )
				);
				return $shift;
			}
		);
		return $shift;
	}

	/**
	 * Change a shift (only the fields sent; `days` replaces all seven).
	 *
	 * @param int   $id    Id.
	 * @param array $input Fields.
	 * @return WorkShift
	 * @throws DomainException 404, 422.
	 */
	public function update( int $id, array $input ): WorkShift {
		$shift  = $this->get( $id );
		$before = array(
			'name'        => (string) $shift->name,
			'description' => (string) $shift->description,
		);
		$old    = $this->days( $id );
		list( $data, $days ) = $this->validate(
			array(
				'name'        => $input['name'] ?? $before['name'],
				'description' => $input['description'] ?? $before['description'],
				'days'        => $input['days'] ?? $old,
			),
			$id
		);
		$changed      = array_diff_assoc( $data, $before );
		$days_changed = self::summary( $days ) !== self::summary( $old );
		if ( ! $changed && ! $days_changed ) {
			return $shift;
		}
		Transaction::run(
			function () use ( $id, $changed, $days_changed, $days, $before, $old ) {
				if ( $changed ) {
					$this->shifts->update( $id, $changed );
				}
				if ( $days_changed ) {
					$this->shifts->replaceDays( $id, $days );
				}
				$after = $this->get( $id );
				self::log(
					$after,
					array_intersect_key( $before, $changed ) + ( $days_changed ? array( 'days' => self::summary( $old ) ) : array() ),
					$changed + ( $days_changed ? array( 'days' => self::summary( $days ) ) : array() ),
					/* translators: %s: work shift name. */
					sprintf( __( 'Changed the work shift %s', 'radius-hotel-booking-pro' ), $after->name )
				);
			}
		);
		return $this->get( $id );
	}

	/**
	 * Delete a shift nobody works.
	 *
	 * @param int $id Id.
	 * @return void
	 * @throws DomainException 404, 409 `shift_in_use`.
	 */
	public function delete( int $id ): void {
		$shift = $this->get( $id );
		/**
		 * Why a work shift cannot be deleted ('' = it can): employees on it.
		 *
		 * @param string $reason Reason.
		 * @param int    $id     Shift id.
		 */
		$reason = (string) apply_filters( 'rtbp_pro_work_shift_in_use', '', $id );
		if ( '' !== $reason ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict( 'shift_in_use', $reason );
		}
		$days = $this->days( $id );
		Transaction::run(
			function () use ( $id, $shift, $days ) {
				$this->shifts->replaceDays( $id, array() );
				$this->shifts->delete( $id );
				self::log(
					$shift,
					array(
						'name' => (string) $shift->name,
						'days' => self::summary( $days ),
					),
					array(),
					/* translators: %s: work shift name. */
					sprintf( __( 'Deleted the work shift %s', 'radius-hotel-booking-pro' ), $shift->name )
				);
			}
		);
	}

	/**
	 * The API shape.
	 *
	 * @param WorkShift $shift     Shift.
	 * @param array     $days      Weekday => day.
	 * @param int       $employees Employees on it.
	 * @return array
	 */
	public static function shape( WorkShift $shift, array $days, int $employees = 0 ): array {
		$out = array();
		for ( $weekday = 1; $weekday <= 7; $weekday++ ) {
			$day             = $days[ $weekday ] ?? array(
				'is_day_off' => true,
				'start_time' => null,
				'end_time'   => null,
			);
			$out[ $weekday ] = $day + array(
				'hours'     => ShiftHours::forDay( $day ),
				'overnight' => ShiftHours::overnight( $day ),
			);
		}
		return array(
			'id'             => (int) $shift->id,
			'name'           => (string) $shift->name,
			'description'    => (string) $shift->description,
			'days'           => $out,
			'hours_per_week' => ShiftHours::perWeek( $out ),
			'employees'      => $employees,
		);
	}

	/**
	 * Validated fields and days.
	 *
	 * @param array $input  Input.
	 * @param int   $except The shift being edited.
	 * @return array{0: array, 1: array<int, array>}
	 * @throws DomainException 422.
	 */
	private function validate( array $input, int $except ): array {
		$errors = array();
		$data   = array(
			'name'        => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
		);
		if ( '' === $data['name'] ) {
			$errors['name'] = __( 'Enter a name.', 'radius-hotel-booking-pro' );
		} elseif ( mb_strlen( $data['name'] ) > 100 ) {
			$errors['name'] = __( 'This is too long.', 'radius-hotel-booking-pro' );
		} elseif ( $this->shifts->nameTaken( $data['name'], $except ) ) {
			$errors['name'] = __( 'This name is already used by another work shift.', 'radius-hotel-booking-pro' );
		}
		if ( mb_strlen( $data['description'] ) > 500 ) {
			$errors['description'] = __( 'This is too long.', 'radius-hotel-booking-pro' );
		}

		$input_days = (array) ( $input['days'] ?? array() );
		$days       = array();
		for ( $weekday = 1; $weekday <= 7; $weekday++ ) {
			$day = (array) ( $input_days[ $weekday ] ?? $input_days[ (string) $weekday ] ?? array( 'is_day_off' => true ) );
			$off = filter_var( $day['is_day_off'] ?? false, FILTER_VALIDATE_BOOLEAN );
			if ( $off ) {
				$days[ $weekday ] = array(
					'is_day_off' => true,
					'start_time' => null,
					'end_time'   => null,
				);
				continue;
			}
			$start = substr( trim( (string) ( $day['start_time'] ?? '' ) ), 0, 5 );
			$end   = substr( trim( (string) ( $day['end_time'] ?? '' ) ), 0, 5 );
			if ( null === ShiftHours::minutes( $start ) ) {
				$errors[ "days.$weekday.start_time" ] = __( 'Enter a time.', 'radius-hotel-booking-pro' );
			}
			if ( null === ShiftHours::minutes( $end ) ) {
				$errors[ "days.$weekday.end_time" ] = __( 'Enter a time.', 'radius-hotel-booking-pro' );
			}
			$days[ $weekday ] = array(
				'is_day_off' => false,
				'start_time' => $start,
				'end_time'   => $end,
			);
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		return array( $data, $days );
	}

	/**
	 * The week in one line for the log: "1 18:00–08:00, 2 off, …".
	 *
	 * @param array $days Weekday => day.
	 * @return string
	 */
	private static function summary( array $days ): string {
		$parts = array();
		for ( $weekday = 1; $weekday <= 7; $weekday++ ) {
			$day     = (array) ( $days[ $weekday ] ?? array( 'is_day_off' => true ) );
			$parts[] = $weekday . ' ' . ( ! empty( $day['is_day_off'] ) ? 'off' : $day['start_time'] . '–' . $day['end_time'] );
		}
		return implode( ', ', $parts );
	}

	/**
	 * Log a write.
	 *
	 * @param WorkShift $shift       Shift.
	 * @param array     $before      Before.
	 * @param array     $after       After.
	 * @param string    $description Description.
	 * @return void
	 */
	private static function log( WorkShift $shift, array $before, array $after, string $description ): void {
		if ( ! function_exists( 'rtbp_activity' ) ) {
			return;
		}
		rtbp_activity(
			'payroll.shifts',
			array(
				'type'  => 'work_shift',
				'id'    => (int) $shift->id,
				'label' => (string) $shift->name,
			),
			array(
				'before'      => $before,
				'after'       => $after,
				'description' => $description,
			)
		);
	}
}
