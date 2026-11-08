<?php
/**
 * The printable work schedule (R2-T9).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Every active employee against the days of a period (a week or a month):
 * on each day the hours of their work shift, their day off, or their
 * approved time off. Printed from the browser or as a PDF, like a payslip,
 * through the free plugin's documents (`templates/documents/work-schedule.php`).
 */
final class WorkScheduleService {

	/**
	 * The template, overridable from a theme at `radius-hotel-booking/documents/work-schedule.php`.
	 */
	public const TEMPLATE = 'documents/work-schedule.php';

	/**
	 * Most days one schedule may show.
	 */
	public const MAX_DAYS = 62;

	/**
	 * Hook in: the template.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_locate_template', array( self::class, 'locate' ), 10, 2 );
	}

	/**
	 * `rtbp_locate_template`: the add-on's template, unless a theme overrides it.
	 *
	 * @param string $template      Resolved path.
	 * @param string $template_name Name asked for.
	 * @return string
	 */
	public static function locate( $template, $template_name ): string {
		if ( self::TEMPLATE !== $template_name || file_exists( (string) $template ) ) {
			return (string) $template;
		}
		return RADIUS_HOTEL_BOOKING_PRO_DIR . 'templates/' . self::TEMPLATE;
	}

	/**
	 * Render the schedule.
	 *
	 * @param string $from   First day, `Y-m-d`.
	 * @param string $to     Last day, `Y-m-d`.
	 * @param string $format html|pdf.
	 * @return array `{ body, content_type, filename }`.
	 * @throws DomainException 404 without the free document family, 422 for a wrong period.
	 */
	public function render( string $from, string $to, string $format ): array {
		if ( ! PayslipService::available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'Printing needs a newer Radius Hotel Booking.', 'radius-hotel-booking-pro' ) );
		}
		return \RadiusTheme\RadiusHotelBooking\Documents\DocumentService::render( 'work-schedule', $this->data( $from, $to ), $format );
	}

	/**
	 * The schedule's data.
	 *
	 * @param string $from First day, `Y-m-d`.
	 * @param string $to   Last day, `Y-m-d`.
	 * @return array `{ number, from, to, days: [ { date, weekday } ], rows: [ { name, no, job_title, shift, cells: [ { kind: work|off|time_off|none, text } ] } ], hotel, logo_url }`.
	 * @throws DomainException 422.
	 */
	public function data( string $from, string $to ): array {
		if ( ! PayPeriods::valid( $from ) || ! PayPeriods::valid( $to ) || $to < $from ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::invalid( array( 'to' => __( 'Choose a first and a last day, the last not before the first.', 'radius-hotel-booking-pro' ) ) );
		}
		$utc   = new \DateTimeZone( 'UTC' );
		$first = new \DateTimeImmutable( $from, $utc );
		$last  = new \DateTimeImmutable( $to, $utc );
		if ( (int) $first->diff( $last )->days + 1 > self::MAX_DAYS ) {
			/* translators: %d: most days. */
			throw DomainException::invalid( array( 'to' => sprintf( __( 'Print at most %d days at a time.', 'radius-hotel-booking-pro' ), self::MAX_DAYS ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
		}
		$days = array();
		for ( $day = $first; $day <= $last; $day = $day->modify( '+1 day' ) ) {
			$days[] = array(
				'date'    => $day->format( 'Y-m-d' ),
				'weekday' => (int) $day->format( 'N' ),
			);
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a report over the add-on's own tables, read live.
		$employees = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, employee_no, first_name, last_name, job_title FROM %i WHERE is_active = 1 ORDER BY last_name, first_name, id', EmployeeRepository::table() ), ARRAY_A );
		$assigned  = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT employee_id, work_shift_id FROM %i WHERE work_shift_id IS NOT NULL', EmployeePayRepository::table() ), ARRAY_A ) as $row ) {
			$assigned[ (int) $row['employee_id'] ] = (int) $row['work_shift_id'];
		}
		$time_off = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT employee_id, type, start_date, end_date FROM %i WHERE status = 'approved' AND start_date <= %s AND end_date >= %s", TimeOffRepository::table(), $to, $from ), ARRAY_A ) as $row ) {
			$time_off[ (int) $row['employee_id'] ][] = $row;
		}
		// phpcs:enable

		$repo   = new WorkShiftRepository();
		$names  = array();
		foreach ( $repo->byName() as $shift ) {
			$names[ (int) $shift->id ] = (string) $shift->name;
		}
		$shifts = $repo->days( array_values( array_unique( $assigned ) ) );
		$types  = TimeOffService::types();

		$rows = array();
		foreach ( $employees as $employee ) {
			$id       = (int) $employee['id'];
			$shift_id = $assigned[ $id ] ?? 0;
			$cells    = array();
			foreach ( $days as $day ) {
				$cells[] = self::cell( $day, $shift_id ? ( $shifts[ $shift_id ] ?? array() ) : null, $time_off[ $id ] ?? array(), $types );
			}
			$rows[] = array(
				'name'      => trim( $employee['first_name'] . ' ' . $employee['last_name'] ),
				'no'        => (string) $employee['employee_no'],
				'job_title' => (string) $employee['job_title'],
				'shift'     => $shift_id ? ( $names[ $shift_id ] ?? '' ) : '',
				'cells'     => $cells,
			);
		}

		return array(
			// The file name (the documents name files after `number`).
			'number' => 'work-schedule-' . $from . '-' . $to,
			'from'   => $from,
			'to'     => $to,
			'days'   => $days,
			'rows'   => $rows,
		) + \RadiusTheme\RadiusHotelBooking\Documents\DocumentService::business();
	}

	/**
	 * One employee's day: approved time off first, then the shift's hours or day off.
	 *
	 * @param array      $day      `{ date, weekday }`.
	 * @param array|null $shift    Weekday => `{ is_day_off, start_time, end_time }`; null without a shift.
	 * @param array[]    $time_off Their approved time off in the period.
	 * @param array      $types    Time off type => label.
	 * @return array `{ kind: work|off|time_off|none, text, start?, end? }`.
	 */
	private static function cell( array $day, ?array $shift, array $time_off, array $types ): array {
		foreach ( $time_off as $row ) {
			if ( $row['start_date'] <= $day['date'] && $row['end_date'] >= $day['date'] ) {
				return array(
					'kind' => 'time_off',
					'text' => (string) ( $types[ $row['type'] ] ?? $row['type'] ),
				);
			}
		}
		if ( null === $shift ) {
			return array(
				'kind' => 'none',
				'text' => '',
			);
		}
		$hours = $shift[ $day['weekday'] ] ?? array( 'is_day_off' => true );
		if ( ! empty( $hours['is_day_off'] ) || empty( $hours['start_time'] ) ) {
			return array(
				'kind' => 'off',
				'text' => __( 'Off', 'radius-hotel-booking-pro' ),
			);
		}
		return array(
			'kind'  => 'work',
			'text'  => $hours['start_time'] . '–' . $hours['end_time'],
			// Printed on two lines: the PDF engine does not wrap at the dash.
			'start' => $hours['start_time'],
			'end'   => $hours['end_time'],
		);
	}
}
