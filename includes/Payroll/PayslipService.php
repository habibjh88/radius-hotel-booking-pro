<?php
/**
 * Payslips (M15 T7a, ADR-005).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * One employee's payslip for one run, in the free plugin's document family:
 * the data built here, the template `documents/payslip.php` shipped by this
 * add-on (found through the free `rtbp_locate_template` filter, so a theme
 * may still override it at `radius-hotel-booking/documents/payslip.php`),
 * rendered by the free document renderers — the HTML print view, and Pro's
 * PDF (the same HTML; ADR-005). A payslip exists once the run is finalised;
 * a voided run's payslips say so. Year to date sums the employee's lines of
 * settled runs paid earlier that year, plus this one.
 */
final class PayslipService {

	public const TEMPLATE = 'documents/payslip.php';

	/**
	 * Hook in: the template.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_locate_template', array( self::class, 'locate' ), 10, 2 );
	}

	/**
	 * `rtbp_locate_template`: the add-on's payslip template, unless a theme overrides it.
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
	 * Whether the free document family is there (an older free plugin has no `business()`).
	 *
	 * @return bool
	 */
	public static function available(): bool {
		$service = '\\RadiusTheme\\RadiusHotelBooking\\Documents\\DocumentService';
		return class_exists( $service ) && method_exists( $service, 'business' ) && method_exists( $service, 'render' );
	}

	/**
	 * The formats a payslip can be rendered in (html; pdf with Pro).
	 *
	 * @return string[]
	 */
	public static function formats(): array {
		return self::available() ? array_keys( \RadiusTheme\RadiusHotelBooking\Documents\DocumentService::renderers() ) : array();
	}

	/**
	 * A payslip's data.
	 *
	 * @param int $run_id  Run.
	 * @param int $line_id Line.
	 * @return array
	 * @throws DomainException 404, 409 `not_final`.
	 */
	public function data( int $run_id, int $line_id ): array {
		$runs = new PayrollRunRepository();
		$run  = $run_id > 0 ? $runs->find( $run_id ) : null;
		$line = $run ? $runs->line( $run_id, $line_id ) : null;
		if ( ! $run instanceof PayrollRun || ! $line ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'This payslip does not exist.', 'radius-hotel-booking-pro' ) );
		}
		if ( PayrollRun::DRAFT === (string) $run->status ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::conflict( 'not_final', __( 'Payslips are made once the pay run is finalised.', 'radius-hotel-booking-pro' ) );
		}
		$repo     = '\\RadiusTheme\\RadiusHotelBookingPro\\Staff\\EmployeeRepository';
		$employee = class_exists( $repo ) ? ( new $repo() )->find( (int) $line->employee_id ) : null;
		$pay_date = substr( (string) $run->pay_date, 0, 10 );

		return array(
			'type'          => 'payslip',
			'number'        => sprintf( 'PS-%d-%s', (int) $run->id, '' !== (string) $line->employee_no ? (string) $line->employee_no : (string) $line->employee_id ),
			'status'        => (string) $run->status,
			'voided'        => PayrollRun::VOIDED === (string) $run->status,
			'schedule'      => (string) $run->schedule_name,
			'period_start'  => substr( (string) $run->period_start, 0, 10 ),
			'period_end'    => substr( (string) $run->period_end, 0, 10 ),
			'pay_date'      => $pay_date,
			'employee'      => array(
				'name'        => (string) $line->employee_name,
				'number'      => (string) $line->employee_no,
				'job_title'   => $employee ? (string) $employee->job_title : '',
				'department'  => $employee ? (string) $employee->department : '',
				'cnps_number' => $employee ? (string) $employee->cnps_no : '',
				'tax_number'  => $employee ? (string) $employee->tax_no : '',
			),
			'breakdown'     => $line->json( 'breakdown' ),
			'payment'       => array(
				'method'    => (string) $line->payment_method,
				'reference' => (string) $line->payment_ref,
			),
			'year_to_date'  => self::yearToDate( (int) $line->employee_id, $pay_date, (int) $run->id, $line ),
			'footer'        => '',
		) + ( self::available() ? \RadiusTheme\RadiusHotelBooking\Documents\DocumentService::business() : array(
			'hotel'    => array(),
			'logo_url' => '',
		) );
	}

	/**
	 * Render a payslip.
	 *
	 * @param int    $run_id  Run.
	 * @param int    $line_id Line.
	 * @param string $format  html|pdf.
	 * @param string $action  The activity action (`self.payslip_download` from self-service).
	 * @return array `{ body, content_type, filename }`.
	 * @throws DomainException 404 (also when the format or the free document family is missing), 409.
	 */
	public function render( int $run_id, int $line_id, string $format, string $action = 'payroll.payslip_download' ): array {
		if ( ! self::available() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON.
			throw DomainException::notFound( __( 'Payslips need a newer Radius Hotel Booking.', 'radius-hotel-booking-pro' ) );
		}
		$data = $this->data( $run_id, $line_id );
		$out  = \RadiusTheme\RadiusHotelBooking\Documents\DocumentService::render( 'payslip', $data, $format );
		if ( function_exists( 'rtbp_activity' ) ) {
			rtbp_activity(
				$action,
				array(
					'type'  => 'payroll_run',
					'id'    => $run_id,
					'label' => $data['number'],
				),
				array(
					/* translators: 1: payslip number, 2: employee name. */
					'description' => sprintf( __( 'Opened payslip %1$s of %2$s', 'radius-hotel-booking-pro' ), $data['number'], $data['employee']['name'] ),
				)
			);
		}
		return $out;
	}

	/**
	 * The year's totals up to this payslip: settled runs paid earlier that year, plus this line.
	 *
	 * @param int         $employee_id Employee.
	 * @param string      $pay_date    Pay date.
	 * @param int         $run_id      This run.
	 * @param PayrollLine $line        This line.
	 * @return array{gross: string, employee_tax: string, net: string}
	 */
	private static function yearToDate( int $employee_id, string $pay_date, int $run_id, PayrollLine $line ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a sum, read live.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COALESCE( SUM( l.gross ), 0 ) AS gross, COALESCE( SUM( l.employee_tax ), 0 ) AS employee_tax, COALESCE( SUM( l.net ), 0 ) AS net FROM %i l JOIN %i r ON r.id = l.run_id WHERE l.employee_id = %d AND r.status = 'settled' AND r.id <> %d AND YEAR( r.pay_date ) = %d AND r.pay_date <= %s", PayrollRunRepository::table( 'lines' ), PayrollRunRepository::table(), $employee_id, $run_id, (int) substr( $pay_date, 0, 4 ), $pay_date ), ARRAY_A );
		$sum = static fn( $earlier, $own ) => number_format( (float) $earlier + (float) $own, 2, '.', '' );
		return array(
			'gross'        => $sum( $row['gross'] ?? 0, $line->gross ),
			'employee_tax' => $sum( $row['employee_tax'] ?? 0, $line->employee_tax ),
			'net'          => $sum( $row['net'] ?? 0, $line->net ),
		);
	}
}
