<?php
/**
 * Staff self-service: my payslips and my time off (M15 T9, 16.7–16.9).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Everything here acts on the **signed-in user's own employee file**, found
 * from their user id — never on an id from the request. A payslip is one of
 * their lines on a **settled** run; any other line id answers 403, the same
 * whether it is someone else's, a draft or does not exist, so ids cannot be
 * probed. Time off reuses `TimeOffService` (the same quota, overlap and lock
 * rules as the office), logged as self-service.
 */
final class MyPayrollService {

	/**
	 * The signed-in user's employee id.
	 *
	 * @return int
	 * @throws DomainException 404 `no_employee_file` when they have none.
	 */
	public static function employeeId(): int {
		$repo     = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\EmployeeRepository';
		$user     = get_current_user_id();
		$employee = $user && class_exists( $repo ) && method_exists( $repo, 'forUser' ) ? ( new $repo() )->forUser( $user ) : null;
		if ( ! $employee ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw new DomainException( 'no_employee_file', __( 'Your account has no employee file, so there is no payroll to show.', 'radius-hotel-booking-pro' ), 404 );
		}
		return (int) $employee->id;
	}

	/**
	 * My payslips: settled runs, newest first.
	 *
	 * @return array[] `{ id, run_id, pay_date, period_start, period_end, schedule, hours, gross, deductions, net }`.
	 */
	public function payslips(): array {
		return array_map(
			static function ( $row ) {
				$breakdown = json_decode( (string) $row['breakdown'], true );
				return array(
					'id'           => (int) $row['id'],
					'run_id'       => (int) $row['run_id'],
					'pay_date'     => substr( (string) $row['pay_date'], 0, 10 ),
					'period_start' => substr( (string) $row['period_start'], 0, 10 ),
					'period_end'   => substr( (string) $row['period_end'], 0, 10 ),
					'schedule'     => (string) $row['schedule_name'],
					'hours'        => is_array( $breakdown ) && isset( $breakdown['hours'] ) ? (string) $breakdown['hours'] : '0',
					'gross'        => (string) $row['gross'],
					'employee_tax' => (string) $row['employee_tax'],
					'net'          => (string) $row['net'],
				);
			},
			( new PayrollRunRepository() )->settledLinesOf( self::employeeId() )
		);
	}

	/**
	 * My payslip as a file.
	 *
	 * @param int $line_id Line.
	 * @return array `{ body, content_type, filename }`.
	 * @throws DomainException 403 `not_your_payslip`.
	 */
	public function payslip( int $line_id ): array {
		$runs = new PayrollRunRepository();
		$line = $line_id > 0 ? $runs->lineById( $line_id ) : null;
		$run  = $line ? $runs->find( (int) $line->run_id ) : null;
		if ( ! $line || (int) $line->employee_id !== self::employeeId() || ! $run instanceof PayrollRun || PayrollRun::SETTLED !== (string) $run->status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw new DomainException( 'not_your_payslip', __( 'You can only download your own payslips.', 'radius-hotel-booking-pro' ), 403 );
		}
		return ( new PayslipService() )->render( (int) $run->id, $line_id, 'pdf', 'self.payslip_download' );
	}

	/**
	 * My time off for a year: requests, balance, types.
	 *
	 * @param int $year Year.
	 * @return array
	 */
	public function timeOff( int $year ): array {
		$me       = self::employeeId();
		$time_off = new TimeOffService();
		return array(
			'requests' => array_map(
				// The list's per-row balance is for the office; mine is sent once.
				static fn( $r ) => array_diff_key( $r, array( 'balance' => 1 ) ),
				$time_off->list(
					array(
						'employee_id' => $me,
						'year'        => $year,
					)
				)
			),
			'balance'  => $time_off->balance( $me, $year ),
			'types'    => TimeOffService::types(),
			'today'    => current_time( 'Y-m-d' ),
		);
	}

	/**
	 * Ask for time off.
	 *
	 * @param array $input `{ type, start_date, end_date?, reason? }`.
	 * @return array The request.
	 */
	public function request( array $input ): array {
		return ( new TimeOffService() )->request( self::employeeId(), $input, true );
	}

	/**
	 * Cancel my pending request.
	 *
	 * @param int $id Request.
	 * @return array The request.
	 */
	public function cancel( int $id ): array {
		return ( new TimeOffService() )->cancel( $id, self::employeeId() );
	}
}
