<?php
/**
 * Time off (M15 T8a, 15.18–15.20, 17.3).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Requests for time off and their decisions, as the legacy
 * (`class-payroll-controller.php` 1880–2090):
 *
 * - types `day_off` (vacation), `sick`, `absent`, `late`, `unpaid`, `other`;
 * - days = calendar days, both ends included;
 * - **vacation quota** (Settings → Payroll, 0 = unlimited): a `day_off`
 *   request is refused when the year's approved vacation days (by the
 *   request's start) plus its own go over it — checked on request and again
 *   on approval (other pending requests do not count until approved);
 * - pending → approved | declined by `timeoff.approve`; the employee may
 *   cancel their own pending request (self-service, T9).
 *
 * Added: a request may not overlap another pending or approved one of the
 * same employee. Every decision runs under the employee's row lock, so two
 * approvals cannot both fit in the last days of a quota. Logged
 * `timeoff.request|approve|decline` against the employee.
 */
final class TimeOffService {

	public const TYPES = array( 'day_off', 'sick', 'absent', 'late', 'unpaid', 'other' );

	public const STATUSES = array( 'pending', 'approved', 'declined', 'cancelled' );

	/**
	 * Repository.
	 *
	 * @var TimeOffRepository
	 */
	private TimeOffRepository $rows;

	/**
	 * Constructor.
	 *
	 * @param TimeOffRepository|null $rows Repository.
	 */
	public function __construct( ?TimeOffRepository $rows = null ) {
		$this->rows = $rows ?? new TimeOffRepository();
	}

	/**
	 * Type => label.
	 *
	 * @return array<string, string>
	 */
	public static function types(): array {
		return array(
			'day_off' => __( 'Vacation day off', 'radius-hotel-booking-pro' ),
			'sick'    => __( 'Sick', 'radius-hotel-booking-pro' ),
			'absent'  => __( 'Absent', 'radius-hotel-booking-pro' ),
			'late'    => __( 'Late', 'radius-hotel-booking-pro' ),
			'unpaid'  => __( 'Unpaid leave', 'radius-hotel-booking-pro' ),
			'other'   => __( 'Other', 'radius-hotel-booking-pro' ),
		);
	}

	/**
	 * Requests with their employee and, for vacation, the year's balance.
	 *
	 * @param array $filters `{ status?, employee_id?, year? }`.
	 * @return array[]
	 */
	public function list( array $filters = array() ): array {
		$status = in_array( $filters['status'] ?? '', self::STATUSES, true ) ? $filters['status'] : '';
		$rows   = $this->rows->search(
			array(
				'status'      => $status,
				'employee_id' => (int) ( $filters['employee_id'] ?? 0 ),
				'year'        => (int) ( $filters['year'] ?? 0 ),
			)
		);
		$names  = array();
		return array_map(
			function ( $row ) use ( &$names ) {
				$id = (int) $row['employee_id'];
				if ( ! isset( $names[ $id ] ) ) {
					$names[ $id ] = self::employee( $id );
				}
				return self::shape( $row, $names[ $id ] ) + array( 'balance' => $this->balance( $id, (int) substr( (string) $row['start_date'], 0, 4 ) ) );
			},
			$rows
		);
	}

	/**
	 * The balance of an employee for a year: the vacation quota, and approved days per type.
	 *
	 * @param int $employee_id Employee.
	 * @param int $year        Year.
	 * @return array{year:int, max:int, used:int, remaining:int|null, by_type:array<string, int>}
	 */
	public function balance( int $employee_id, int $year ): array {
		$max     = (int) PayrollSettings::get( 'vacationDaysYear', 0 );
		$by_type = array_merge( array_fill_keys( self::TYPES, 0 ), $this->rows->approvedByType( $employee_id, $year ) );
		return array(
			'year'      => $year,
			'max'       => $max,
			'used'      => $by_type['day_off'],
			'remaining' => $max > 0 ? max( 0, $max - $by_type['day_off'] ) : null,
			'by_type'   => $by_type,
		);
	}

	/**
	 * Active employees to pick from (id, name, number), from the Pro staff list.
	 *
	 * @return array[]
	 */
	public static function employeeOptions(): array {
		$repo = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\EmployeeRepository';
		if ( ! class_exists( $repo ) || ! method_exists( $repo, 'page' ) ) {
			return array();
		}
		return array_map(
			fn( $row ) => array(
				'id'     => (int) $row['id'],
				'name'   => trim( $row['first_name'] . ' ' . $row['last_name'] ),
				'number' => (string) $row['employee_no'],
			),
			( new $repo() )->page( '', 'active', 1, 1000 )['items']
		);
	}

	/**
	 * Request time off for an employee.
	 *
	 * @param int   $employee_id  Employee (Pro file).
	 * @param array $input        `{ type, start_date, end_date?, reason? }`.
	 * @param bool  $self_service Asked by the employee themself (logged as such).
	 * @return array The request.
	 * @throws DomainException 404, 409 `quota_exceeded` / `overlap`, 422.
	 */
	public function request( int $employee_id, array $input, bool $self_service = false ): array {
		$employee = self::employee( $employee_id );
		if ( ! $employee['exists'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'This employee does not exist.', 'radius-hotel-booking-pro' ) );
		}
		$data = self::validate( $input );
		$id   = Transaction::run(
			function () use ( $employee_id, $data, $employee, $self_service ) {
				$this->rows->lockEmployee( $employee_id );
				$this->assertFits( $employee_id, $data, 0 );
				global $wpdb;
				$now = current_time( 'mysql' );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- plain insert under the employee lock.
				$wpdb->insert(
					TimeOffRepository::table(),
					$data + array(
						'employee_id'  => $employee_id,
						'status'       => 'pending',
						'requested_by' => get_current_user_id() ? get_current_user_id() : null,
						'requested_at' => $now,
						'created_at'   => $now,
						'updated_at'   => $now,
					)
				);
				$id = (int) $wpdb->insert_id;
				self::log(
					$self_service ? 'self.timeoff_request' : 'timeoff.request',
					$employee_id,
					$employee['name'],
					array(),
					array_intersect_key( $data, array_flip( array( 'type', 'start_date', 'end_date', 'days' ) ) ),
					/* translators: 1: type, 2: days, 3: employee. */
					sprintf( __( 'Requested %1$s, %2$d days, for %3$s', 'radius-hotel-booking-pro' ), self::types()[ $data['type'] ], $data['days'], $employee['name'] )
				);
				return $id;
			}
		);
		return self::shape( (array) $this->rows->one( $id ), $employee );
	}

	/**
	 * Approve a pending request (the quota re-checked).
	 *
	 * @param int    $id   Request.
	 * @param string $note Optional note.
	 * @return array The request.
	 * @throws DomainException 404, 409 `not_pending` / `quota_exceeded` / `overlap`.
	 */
	public function approve( int $id, string $note = '' ): array {
		return $this->decide( $id, 'approved', $note );
	}

	/**
	 * Decline a pending request.
	 *
	 * @param int    $id   Request.
	 * @param string $note Optional note (why).
	 * @return array The request.
	 * @throws DomainException 404, 409 `not_pending`.
	 */
	public function decline( int $id, string $note = '' ): array {
		return $this->decide( $id, 'declined', $note );
	}

	/**
	 * Cancel one's own pending request (self-service, T9).
	 *
	 * @param int $id          Request.
	 * @param int $employee_id The employee asking (must own it).
	 * @return array The request.
	 * @throws DomainException 404, 409 `not_pending`.
	 */
	public function cancel( int $id, int $employee_id ): array {
		return $this->decide( $id, 'cancelled', '', $employee_id );
	}

	/**
	 * A decision under the employee's lock.
	 *
	 * @param int    $id    Request.
	 * @param string $to    approved|declined|cancelled.
	 * @param string $note  Note.
	 * @param int    $owner For a cancel: the employee who must own it.
	 * @return array
	 * @throws DomainException 404, 409.
	 */
	private function decide( int $id, string $to, string $note, int $owner = 0 ): array {
		$row = $this->rows->one( $id );
		if ( ! $row || ( $owner && (int) $row['employee_id'] !== $owner ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'This request does not exist.', 'radius-hotel-booking-pro' ) );
		}
		$employee_id = (int) $row['employee_id'];
		$employee    = self::employee( $employee_id );
		$note        = mb_substr( sanitize_text_field( $note ), 0, 255 );
		Transaction::run(
			function () use ( $id, $to, $note, $employee_id, $employee ) {
				$this->rows->lockEmployee( $employee_id );
				$row = (array) $this->rows->one( $id, true );
				if ( 'pending' !== ( $row['status'] ?? '' ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
					throw DomainException::conflict( 'not_pending', __( 'This request was already decided.', 'radius-hotel-booking-pro' ) );
				}
				if ( 'approved' === $to ) {
					$this->assertFits( $employee_id, $row, $id );
				}
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the locked row.
				$wpdb->update(
					TimeOffRepository::table(),
					array(
						'status'      => $to,
						'reviewed_by' => get_current_user_id() ? get_current_user_id() : null,
						'reviewed_at' => current_time( 'mysql' ),
						'review_note' => '' !== $note ? $note : null,
						'updated_at'  => current_time( 'mysql' ),
					),
					array( 'id' => $id )
				);
				$labels = array(
					/* translators: 1: type, 2: days, 3: employee. */
					'approved'  => __( 'Approved %1$s, %2$d days, for %3$s', 'radius-hotel-booking-pro' ),
					/* translators: 1: type, 2: days, 3: employee. */
					'declined'  => __( 'Declined %1$s, %2$d days, for %3$s', 'radius-hotel-booking-pro' ),
					/* translators: 1: type, 2: days, 3: employee. */
					'cancelled' => __( 'Cancelled the request for %1$s, %2$d days, of %3$s', 'radius-hotel-booking-pro' ),
				);
				$actions = array(
					'approved'  => 'timeoff.approve',
					'declined'  => 'timeoff.decline',
					'cancelled' => 'self.timeoff_cancel',
				);
				self::log(
					$actions[ $to ],
					$employee_id,
					$employee['name'],
					array( 'status' => 'pending' ),
					array( 'status' => $to ) + ( '' !== $note ? array( 'note' => $note ) : array() ),
					sprintf( $labels[ $to ], self::types()[ $row['type'] ] ?? $row['type'], (int) $row['days'], $employee['name'] )
				);
			}
		);
		return self::shape( (array) $this->rows->one( $id ), $employee );
	}

	/**
	 * No overlap, and a vacation request fits the year's quota.
	 *
	 * @param int   $employee_id Employee.
	 * @param array $data        `{ type, start_date, end_date, days }`.
	 * @param int   $except      The request itself (on approval).
	 * @return void
	 * @throws DomainException 409 `overlap` / `quota_exceeded`.
	 */
	private function assertFits( int $employee_id, array $data, int $except ): void {
		$clash = $this->rows->overlapping( $employee_id, (string) $data['start_date'], (string) $data['end_date'], $except );
		if ( $clash ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::conflict(
				'overlap',
				/* translators: 1: first day, 2: last day. */
				sprintf( __( 'Another request already covers some of these days (%1$s to %2$s).', 'radius-hotel-booking-pro' ), $clash['start_date'], $clash['end_date'] ),
				array( 'request_id' => (int) $clash['id'] )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( 'day_off' !== $data['type'] ) {
			return;
		}
		$balance = $this->balance( $employee_id, (int) substr( (string) $data['start_date'], 0, 4 ) );
		if ( $balance['max'] > 0 && (int) $data['days'] > (int) $balance['remaining'] ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::conflict(
				'quota_exceeded',
				sprintf(
					/* translators: 1: days asked, 2: days left, 3: year. */
					_n( '%1$d vacation day asked, but only %2$d left in %3$d.', '%1$d vacation days asked, but only %2$d left in %3$d.', (int) $data['days'], 'radius-hotel-booking-pro' ),
					(int) $data['days'],
					(int) $balance['remaining'],
					$balance['year']
				),
				array( 'balance' => $balance )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * Validated request fields.
	 *
	 * @param array $input Input.
	 * @return array `{ type, start_date, end_date, days, reason }`.
	 * @throws DomainException 422.
	 */
	private static function validate( array $input ): array {
		$errors = array();
		$type   = sanitize_key( (string) ( $input['type'] ?? '' ) );
		$start  = substr( trim( (string) ( $input['start_date'] ?? '' ) ), 0, 10 );
		$end    = substr( trim( (string) ( $input['end_date'] ?? '' ) ), 0, 10 );
		$end    = '' === $end ? $start : $end;
		if ( ! in_array( $type, self::TYPES, true ) ) {
			$errors['type'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
		}
		if ( ! PayPeriods::valid( $start ) ) {
			$errors['start_date'] = __( 'Enter a valid date.', 'radius-hotel-booking-pro' );
		}
		if ( ! PayPeriods::valid( $end ) ) {
			$errors['end_date'] = __( 'Enter a valid date.', 'radius-hotel-booking-pro' );
		} elseif ( ! isset( $errors['start_date'] ) && $end < $start ) {
			$errors['end_date'] = __( 'The last day cannot be before the first.', 'radius-hotel-booking-pro' );
		}
		$days = 0;
		if ( ! $errors ) {
			$days = (int) ( new \DateTimeImmutable( $start ) )->diff( new \DateTimeImmutable( $end ) )->days + 1;
			if ( $days > 366 ) {
				$errors['end_date'] = __( 'A request covers at most a year.', 'radius-hotel-booking-pro' );
			}
		}
		$reason = mb_substr( sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) ), 0, 1000 );
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::invalid( $errors );
		}
		return array(
			'type'       => $type,
			'start_date' => $start,
			'end_date'   => $end,
			'days'       => $days,
			'reason'     => $reason,
		);
	}

	/**
	 * The API shape of a request.
	 *
	 * @param array $row      Row.
	 * @param array $employee `{ name, number }`.
	 * @return array
	 */
	public static function shape( array $row, array $employee ): array {
		return array(
			'id'            => (int) $row['id'],
			'employee_id'   => (int) $row['employee_id'],
			'employee_name' => $employee['name'],
			'employee_no'   => $employee['number'],
			'type'          => (string) $row['type'],
			'type_label'    => self::types()[ (string) $row['type'] ] ?? (string) $row['type'],
			'start_date'    => substr( (string) $row['start_date'], 0, 10 ),
			'end_date'      => substr( (string) $row['end_date'], 0, 10 ),
			'days'          => (int) $row['days'],
			'reason'        => (string) $row['reason'],
			'status'        => (string) $row['status'],
			'requested_at'  => (string) $row['requested_at'],
			'reviewed_at'   => $row['reviewed_at'] ? (string) $row['reviewed_at'] : null,
			'review_note'   => $row['review_note'] ? (string) $row['review_note'] : null,
		);
	}

	/**
	 * The Pro employee's name and number (`exists` false when missing).
	 *
	 * @param int $employee_id Id.
	 * @return array{exists: bool, name: string, number: string}
	 */
	private static function employee( int $employee_id ): array {
		$repo     = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\EmployeeRepository';
		$employee = $employee_id > 0 && class_exists( $repo ) ? ( new $repo() )->find( $employee_id ) : null;
		return array(
			'exists' => (bool) $employee,
			'name'   => $employee && method_exists( $employee, 'fullName' ) ? (string) $employee->fullName() : '#' . $employee_id,
			'number' => $employee ? (string) $employee->employee_no : '',
		);
	}

	/**
	 * Log against the employee.
	 *
	 * @param string $action      Action.
	 * @param int    $employee_id Employee.
	 * @param string $name        Employee name.
	 * @param array  $before      Before.
	 * @param array  $after       After.
	 * @param string $description Description.
	 * @return void
	 */
	private static function log( string $action, int $employee_id, string $name, array $before, array $after, string $description ): void {
		if ( ! function_exists( 'rtbp_activity' ) ) {
			return;
		}
		rtbp_activity(
			$action,
			array(
				'type'  => 'employee',
				'id'    => $employee_id,
				'label' => $name,
			),
			array(
				'before'      => $before,
				'after'       => $after,
				'description' => $description,
			)
		);
	}
}
