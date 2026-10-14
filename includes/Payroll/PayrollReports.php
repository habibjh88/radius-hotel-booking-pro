<?php
/**
 * Payroll reports (M15 T7b, 15.16, 15.17).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

defined( 'ABSPATH' ) || exit;

/**
 * Two reports added to the free Reports screen through
 * `rtbp_report_definitions` (with its period picker and CSV / XLSX export),
 * both behind `page.payroll`, both over the **settled** pay runs whose pay
 * day falls in the period (a month for the monthly view, a year for the
 * yearly one; the by-month table is the year's summary):
 *
 * - `payroll` (15.16): totals — gross, employee taxes and contributions,
 *   deductions, garnishments, net, the hotel's contributions and cost — by
 *   month and by employee;
 * - `payroll-statutory` (15.17): every contribution to every authority for
 *   the period — employee and hotel shares, due, paid, left — and by month.
 */
final class PayrollReports {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_report_definitions', array( self::class, 'definitions' ) );
	}

	/**
	 * `rtbp_report_definitions`.
	 *
	 * @param array $definitions Name => definition.
	 * @return array
	 */
	public static function definitions( $definitions ): array {
		$definitions                      = (array) $definitions;
		$definitions['payroll']           = array(
			'label'  => __( 'Payroll', 'radius-hotel-booking-pro' ),
			'access' => 'page.payroll',
			'modes'  => false,
			'build'  => static fn( $range ) => ( new self() )->payroll( (string) $range->from, (string) $range->to ),
			'export' => static fn( $range ) => ( new self() )->payrollTables( (string) $range->from, (string) $range->to ),
		);
		$definitions['payroll-statutory'] = array(
			'label'  => __( 'Statutory contributions', 'radius-hotel-booking-pro' ),
			'access' => 'page.payroll',
			'modes'  => false,
			'build'  => static fn( $range ) => ( new self() )->statutory( (string) $range->from, (string) $range->to ),
			'export' => static fn( $range ) => ( new self() )->statutoryTables( (string) $range->from, (string) $range->to ),
		);
		return $definitions;
	}

	/**
	 * The payroll summary for a period of pay days.
	 *
	 * @param string $from First day (Y-m-d).
	 * @param string $to   Last day.
	 * @return array `{ totals, months, employees }`.
	 */
	public function payroll( string $from, string $to ): array {
		global $wpdb;
		$l = PayrollRunRepository::table( 'lines' );
		$r = PayrollRunRepository::table();
		$s = PayrollRunRepository::table( 'statutory' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- report sums over the run tables.
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT l.id, l.employee_id, l.employee_name, l.employee_no, l.gross, l.employee_tax, l.employer_tax, l.net, l.employer_cost, YEAR( r.pay_date ) AS y, MONTH( r.pay_date ) AS m, r.id AS run_id FROM %i l JOIN %i r ON r.id = l.run_id WHERE r.status = 'settled' AND r.pay_date BETWEEN %s AND %s", $l, $r, $from, $to ), ARRAY_A );
		$parts = $wpdb->get_results( $wpdb->prepare( "SELECT s.line_id, s.kind, SUM( s.employee_amount ) AS employee, SUM( s.employer_amount ) AS employer FROM %i s JOIN %i r ON r.id = s.run_id WHERE r.status = 'settled' AND r.pay_date BETWEEN %s AND %s AND s.kind IN ( 'deduction', 'garnishment' ) GROUP BY s.line_id, s.kind", $s, $r, $from, $to ), ARRAY_A );
		// phpcs:enable
		$by_line = array();
		foreach ( (array) $parts as $part ) {
			$by_line[ (int) $part['line_id'] ][ $part['kind'] ] = $part;
		}

		$blank     = array(
			'gross'        => '0',
			'employee_tax' => '0',
			'deductions'   => '0',
			'garnishments' => '0',
			'custom'       => '0',
			'net'          => '0',
			'employer_tax' => '0',
			'employer_cost' => '0',
		);
		$totals    = $blank + array(
			'runs'      => 0,
			'employees' => 0,
			'payslips'  => 0,
		);
		$months    = array();
		$employees = array();
		$runs      = array();
		foreach ( (array) $lines as $line ) {
			$add = array(
				'gross'         => (string) $line['gross'],
				'employee_tax'  => (string) $line['employee_tax'],
				'deductions'    => (string) ( $by_line[ (int) $line['id'] ]['deduction']['employee'] ?? '0' ),
				'garnishments'  => (string) ( $by_line[ (int) $line['id'] ]['garnishment']['employee'] ?? '0' ),
				'net'           => (string) $line['net'],
				'custom'        => '0',
				// The hotel's contributions: employer lines and its deduction shares.
				'employer_tax'  => Decimal::add( Decimal::of( (string) $line['employer_tax'] ), Decimal::of( (string) ( $by_line[ (int) $line['id'] ]['deduction']['employer'] ?? '0' ) ) ),
				'employer_cost' => (string) $line['employer_cost'],
			);
			// One-off items (after their tax): what makes gross − taxes − deductions − garnishments reach net.
			$add['custom'] = Decimal::sub( Decimal::of( $add['net'] ), Decimal::sub( Decimal::sub( Decimal::sub( Decimal::of( $add['gross'] ), Decimal::of( $add['employee_tax'] ) ), Decimal::of( $add['deductions'] ) ), Decimal::of( $add['garnishments'] ) ) );
			$month         = sprintf( '%04d-%02d', $line['y'], $line['m'] );
			$emp   = (int) $line['employee_id'];
			$months[ $month ]  = self::sum(
				$months[ $month ] ?? $blank + array(
					'month' => $month,
					'payslips' => 0,
				),
				$add
			);
			++$months[ $month ]['payslips'];
			$employees[ $emp ] = self::sum(
				$employees[ $emp ] ?? $blank + array(
					'name' => (string) $line['employee_name'],
					'number' => (string) $line['employee_no'],
					'payslips' => 0,
				),
				$add
			);
			++$employees[ $emp ]['payslips'];
			$totals            = self::sum( $totals, $add );
			++$totals['payslips'];
			$runs[ (int) $line['run_id'] ] = true;
		}
		$totals['runs']      = count( $runs );
		$totals['employees'] = count( $employees );
		ksort( $months );
		uasort( $employees, static fn( $a, $b ) => strcasecmp( $a['name'], $b['name'] ) );

		return array(
			'from'      => $from,
			'to'        => $to,
			'totals'    => self::money( $totals ),
			'months'    => array_values( array_map( array( self::class, 'money' ), $months ) ),
			'employees' => array_values( array_map( array( self::class, 'money' ), $employees ) ),
		);
	}

	/**
	 * The statutory summary for a period of pay days.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array `{ totals, items, months }`.
	 */
	public function statutory( string $from, string $to ): array {
		global $wpdb;
		$s = PayrollRunRepository::table( 'statutory' );
		$r = PayrollRunRepository::table();
		$p = StatutoryService::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- report sums over the run tables.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT s.authority, s.code, s.kind, MAX( s.label ) AS label, s.year, s.month, SUM( s.employee_amount ) AS employee, SUM( s.employer_amount ) AS employer FROM %i s JOIN %i r ON r.id = s.run_id WHERE r.status = 'settled' AND r.pay_date BETWEEN %s AND %s GROUP BY s.authority, s.code, s.kind, s.year, s.month", $s, $r, $from, $to ), ARRAY_A );
		$from_key = (int) substr( $from, 0, 4 ) * 100 + (int) substr( $from, 5, 2 );
		$to_key   = (int) substr( $to, 0, 4 ) * 100 + (int) substr( $to, 5, 2 );
		$paid     = $wpdb->get_results( $wpdb->prepare( 'SELECT authority, code, year, month, SUM( amount ) AS paid FROM %i WHERE voided_at IS NULL AND ( year * 100 + month ) BETWEEN %d AND %d GROUP BY authority, code, year, month', $p, $from_key, $to_key ), ARRAY_A );
		// phpcs:enable
		$paid_by = array();
		foreach ( (array) $paid as $row ) {
			$paid_by[ $row['authority'] . '|' . $row['code'] . '|' . $row['year'] . '|' . $row['month'] ] = (string) $row['paid'];
		}

		$items  = array();
		$months = array();
		$totals = array(
			'employee' => '0',
			'employer' => '0',
			'due'      => '0',
			'paid'     => '0',
			'balance'  => '0',
		);
		foreach ( (array) $rows as $row ) {
			$key   = $row['authority'] . '|' . $row['code'];
			$due   = Decimal::add( Decimal::of( (string) $row['employee'] ), Decimal::of( (string) $row['employer'] ) );
			$paid  = Decimal::min( $due, Decimal::of( $paid_by[ $key . '|' . $row['year'] . '|' . $row['month'] ] ?? '0' ) );
			$add   = array(
				'employee' => (string) $row['employee'],
				'employer' => (string) $row['employer'],
				'due'      => $due,
				'paid'     => $paid,
				'balance'  => Decimal::sub( $due, $paid ),
			);
			$items[ $key ] = self::sum(
				$items[ $key ] ?? array(
					'authority' => (string) $row['authority'],
					'code'      => (string) $row['code'],
					'label'     => (string) $row['label'],
					'kind'      => (string) $row['kind'],
					'employee'  => '0',
					'employer'  => '0',
					'due'       => '0',
					'paid'      => '0',
					'balance'   => '0',
				),
				$add
			);
			$month            = sprintf( '%04d-%02d', $row['year'], $row['month'] );
			$months[ $month ] = self::sum(
				$months[ $month ] ?? array(
					'month'    => $month,
					'employee' => '0',
					'employer' => '0',
					'due'      => '0',
					'paid'     => '0',
					'balance'  => '0',
				),
				$add
			);
			$totals           = self::sum( $totals, $add );
		}
		ksort( $months );
		$items = array_values( $items );
		usort( $items, static fn( $a, $b ) => array( 'statutory' !== $a['kind'], $a['authority'], $a['code'] ) <=> array( 'statutory' !== $b['kind'], $b['authority'], $b['code'] ) );

		return array(
			'from'   => $from,
			'to'     => $to,
			'totals' => self::money( $totals ),
			'items'  => array_map( array( self::class, 'money' ), $items ),
			'months' => array_values( array_map( array( self::class, 'money' ), $months ) ),
		);
	}

	/**
	 * The payroll report as export tables.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array[]
	 */
	public function payrollTables( string $from, string $to ): array {
		$report  = $this->payroll( $from, $to );
		$columns = array(
			__( 'Payslips', 'radius-hotel-booking-pro' ),
			__( 'Gross pay', 'radius-hotel-booking-pro' ),
			__( 'Employee taxes and contributions', 'radius-hotel-booking-pro' ),
			__( 'Deductions', 'radius-hotel-booking-pro' ),
			__( 'Garnishments', 'radius-hotel-booking-pro' ),
			__( 'One-off items', 'radius-hotel-booking-pro' ),
			__( 'Net pay', 'radius-hotel-booking-pro' ),
			__( 'Hotel contributions', 'radius-hotel-booking-pro' ),
			__( 'Cost to the hotel', 'radius-hotel-booking-pro' ),
		);
		$figures = static fn( $row ) => array( $row['payslips'], $row['gross'], $row['employee_tax'], $row['deductions'], $row['garnishments'], $row['custom'], $row['net'], $row['employer_tax'], $row['employer_cost'] );
		return array(
			array(
				/* translators: 1: first day, 2: last day. */
				'title'   => sprintf( __( 'Payroll by month, %1$s to %2$s', 'radius-hotel-booking-pro' ), $from, $to ),
				'columns' => array_merge( array( __( 'Month', 'radius-hotel-booking-pro' ) ), $columns ),
				'rows'    => array_map( static fn( $row ) => array_merge( array( $row['month'] ), $figures( $row ) ), $report['months'] ),
			),
			array(
				'title'   => __( 'Payroll by employee', 'radius-hotel-booking-pro' ),
				'columns' => array_merge( array( __( 'Employee', 'radius-hotel-booking-pro' ), __( 'Number', 'radius-hotel-booking-pro' ) ), $columns ),
				'rows'    => array_map( static fn( $row ) => array_merge( array( $row['name'], $row['number'] ), $figures( $row ) ), $report['employees'] ),
			),
		);
	}

	/**
	 * The statutory summary as export tables.
	 *
	 * @param string $from First day.
	 * @param string $to   Last day.
	 * @return array[]
	 */
	public function statutoryTables( string $from, string $to ): array {
		$report  = $this->statutory( $from, $to );
		$figures = array(
			__( 'Employees', 'radius-hotel-booking-pro' ),
			__( 'Hotel', 'radius-hotel-booking-pro' ),
			__( 'Due', 'radius-hotel-booking-pro' ),
			__( 'Paid', 'radius-hotel-booking-pro' ),
			__( 'Left to pay', 'radius-hotel-booking-pro' ),
		);
		$values  = static fn( $row ) => array( $row['employee'], $row['employer'], $row['due'], $row['paid'], $row['balance'] );
		return array(
			array(
				/* translators: 1: first day, 2: last day. */
				'title'   => sprintf( __( 'Statutory contributions, %1$s to %2$s', 'radius-hotel-booking-pro' ), $from, $to ),
				'columns' => array_merge( array( __( 'Authority', 'radius-hotel-booking-pro' ), __( 'Contribution', 'radius-hotel-booking-pro' ), __( 'Code', 'radius-hotel-booking-pro' ) ), $figures ),
				'rows'    => array_map( static fn( $row ) => array_merge( array( $row['authority'], $row['label'], $row['code'] ), $values( $row ) ), $report['items'] ),
			),
			array(
				'title'   => __( 'Statutory contributions by month', 'radius-hotel-booking-pro' ),
				'columns' => array_merge( array( __( 'Month', 'radius-hotel-booking-pro' ) ), $figures ),
				'rows'    => array_map( static fn( $row ) => array_merge( array( $row['month'] ), $values( $row ) ), $report['months'] ),
			),
		);
	}

	/**
	 * Add one row's figures into a total (decimal strings; other keys kept).
	 *
	 * @param array $total Total.
	 * @param array $add   Figures.
	 * @return array
	 */
	private static function sum( array $total, array $add ): array {
		foreach ( $add as $key => $value ) {
			$total[ $key ] = Decimal::add( Decimal::of( (string) ( $total[ $key ] ?? '0' ) ), Decimal::of( number_format( (float) $value, 2, '.', '' ) ) );
		}
		return $total;
	}

	/**
	 * Money fields as 2-decimal strings.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function money( array $row ): array {
		foreach ( $row as $key => $value ) {
			if ( in_array( $key, array( 'gross', 'employee_tax', 'deductions', 'garnishments', 'custom', 'net', 'employer_tax', 'employer_cost', 'employee', 'employer', 'due', 'paid', 'balance' ), true ) ) {
				$row[ $key ] = number_format( (float) $value, 2, '.', '' );
			}
		}
		return $row;
	}
}
