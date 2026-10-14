<?php
/**
 * Payroll runs, lines and ledger data access (M15 T5b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_payroll_runs`, `pro_payroll_lines` and `pro_payroll_ledger`.
 * Balances are always sums over the ledger of runs that count
 * (`PayrollRun::COUNTING`), never stored.
 */
class PayrollRunRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = PayrollRun::class;

	/**
	 * Table names.
	 *
	 * @param string $name runs|lines|ledger|statutory.
	 * @return string
	 */
	public static function table( string $name = 'runs' ): string {
		return rtbp_table_prefix() . 'pro_payroll_' . $name;
	}

	/**
	 * Lock a run for the rest of the transaction and return it (null when missing).
	 *
	 * @param int $id Run id.
	 * @return PayrollRun|null
	 */
	public function lockRun( int $id ): ?PayrollRun {
		return $this->lockRow( 'pro_payroll_runs', $id ) ? $this->find( $id ) : null;
	}

	/**
	 * Lock a pay schedule row (one run creation per schedule at a time).
	 *
	 * @param int $id Schedule id.
	 * @return bool
	 */
	public function lockSchedule( int $id ): bool {
		return $this->lockRow( 'pro_pay_schedules', $id );
	}

	/**
	 * A live run (not voided) of a schedule whose period overlaps these days, if any.
	 * Overlap, not the same start: a schedule changed after runs exist (another
	 * interval or anchor), or imported runs, must not pay the same days twice.
	 *
	 * @param int    $schedule_id Schedule.
	 * @param string $start       First day (Y-m-d).
	 * @param string $end         Last day (Y-m-d).
	 * @return PayrollRun|null
	 */
	public function liveOverlapping( int $schedule_id, string $start, string $end ): ?PayrollRun {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read under the schedule lock.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE pay_schedule_id = %d AND period_start <= %s AND period_end >= %s AND status <> 'voided' ORDER BY period_start LIMIT 1", self::table(), $schedule_id, $end, $start ), ARRAY_A );
		return $row ? PayrollRun::hydrate( $row ) : null;
	}

	/**
	 * Employees who already have a line on a live run (any schedule) overlapping these days.
	 *
	 * @param int[]  $employee_ids Employees.
	 * @param string $start        First day.
	 * @param string $end          Last day.
	 * @return array<int, string> Employee id => the run's schedule and period, for the message.
	 */
	public function paidOverlapping( array $employee_ids, string $start, string $end ): array {
		global $wpdb;
		$employee_ids = array_values( array_filter( array_map( 'intval', $employee_ids ) ) );
		if ( ! $employee_ids ) {
			return array();
		}
		$in = implode( ',', $employee_ids );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in holds integers only.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT l.employee_id, r.schedule_name, r.period_start, r.period_end FROM %i l JOIN %i r ON r.id = l.run_id WHERE l.employee_id IN ( $in ) AND r.status <> 'voided' AND r.period_start <= %s AND r.period_end >= %s", self::table( 'lines' ), self::table(), $end, $start ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row['employee_id'] ] = sprintf( '%s %s–%s', $row['schedule_name'], substr( (string) $row['period_start'], 0, 10 ), substr( (string) $row['period_end'], 0, 10 ) );
		}
		return $out;
	}

	/**
	 * Lock the pay profiles of a run's employees until the transaction ends
	 * (in id order): one run at a time decides what they have used this month
	 * and year, so two finalisations cannot both take the same quota.
	 *
	 * @param int $run_id Run.
	 * @return void
	 */
	public function lockEmployeesOf( int $run_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock, read inside the transaction.
		$wpdb->get_col( $wpdb->prepare( 'SELECT p.id FROM %i p WHERE p.employee_id IN ( SELECT l.employee_id FROM %i l WHERE l.run_id = %d ) ORDER BY p.id FOR UPDATE', EmployeePayRepository::table(), self::table( 'lines' ), $run_id ) );
	}

	/**
	 * Runs, newest pay date first.
	 *
	 * @param string $status   Status or '' for all.
	 * @param int    $page     Page.
	 * @param int    $per_page Per page.
	 * @return array{0: PayrollRun[], 1: int}
	 */
	public function page( string $status, int $page, int $per_page ): array {
		global $wpdb;
		$where = '' !== $status ? $wpdb->prepare( 'WHERE status = %s', $status ) : '';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where is prepared above.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i {$where}", self::table() ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i {$where} ORDER BY pay_date DESC, id DESC LIMIT %d OFFSET %d", self::table(), $per_page, max( 0, ( $page - 1 ) * $per_page ) ), ARRAY_A );
		// phpcs:enable
		return array( array_map( array( PayrollRun::class, 'hydrate' ), (array) $rows ), $total );
	}

	/**
	 * A run's lines, by employee name.
	 *
	 * @param int $run_id Run.
	 * @return PayrollLine[]
	 */
	public function lines( int $run_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d ORDER BY employee_name, id', self::table( 'lines' ), $run_id ), ARRAY_A );
		return array_map( array( PayrollLine::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * One line of a run, or null.
	 *
	 * @param int $run_id  Run.
	 * @param int $line_id Line.
	 * @return PayrollLine|null
	 */
	public function line( int $run_id, int $line_id ): ?PayrollLine {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read under the run lock.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d AND id = %d', self::table( 'lines' ), $run_id, $line_id ), ARRAY_A );
		return $row ? PayrollLine::hydrate( $row ) : null;
	}

	/**
	 * An employee's line on a run, or null.
	 *
	 * @param int $run_id      Run.
	 * @param int $employee_id Employee.
	 * @return PayrollLine|null
	 */
	public function lineOf( int $run_id, int $employee_id ): ?PayrollLine {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read under the run lock.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %d AND employee_id = %d', self::table( 'lines' ), $run_id, $employee_id ), ARRAY_A );
		return $row ? PayrollLine::hydrate( $row ) : null;
	}

	/**
	 * One line by its id alone (any run), or null.
	 *
	 * @param int $line_id Line.
	 * @return PayrollLine|null
	 */
	public function lineById( int $line_id ): ?PayrollLine {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read live.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table( 'lines' ), $line_id ), ARRAY_A );
		return $row ? PayrollLine::hydrate( $row ) : null;
	}

	/**
	 * An employee's lines on settled runs, newest pay day first (their payslips).
	 *
	 * @param int $employee_id Employee.
	 * @param int $limit       Rows.
	 * @return array[] Line columns plus `pay_date`, `period_start`, `period_end`, `schedule_name`.
	 */
	public function settledLinesOf( int $employee_id, int $limit = 120 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read live.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT l.id, l.run_id, l.breakdown, l.gross, l.employee_tax, l.net, r.pay_date, r.period_start, r.period_end, r.schedule_name FROM %i l INNER JOIN %i r ON r.id = l.run_id WHERE l.employee_id = %d AND r.status = 'settled' ORDER BY r.pay_date DESC, r.id DESC LIMIT %d", self::table( 'lines' ), self::table(), $employee_id, $limit ), ARRAY_A );
	}

	/**
	 * Insert or replace a line (by run + employee), its ledger entries and its statutory rows.
	 *
	 * @param array   $fields    Line columns.
	 * @param array[] $ledger    `{ code, ref?, quantity?, amount?, employer? }`.
	 * @param string  $date      The run's pay date (year and month of the entries).
	 * @param array[] $statutory `{ authority, code, label, kind, base, employee, employer }`.
	 * @return int Line id.
	 */
	public function saveLine( array $fields, array $ledger, string $date, array $statutory = array() ): int {
		global $wpdb;
		$now = current_time( 'mysql' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the run's own rows, under its lock.
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE run_id = %d AND employee_id = %d', self::table( 'lines' ), $fields['run_id'], $fields['employee_id'] ) );
		if ( $id ) {
			$wpdb->update( self::table( 'lines' ), $fields + array( 'updated_at' => $now ), array( 'id' => $id ) );
			$wpdb->delete( self::table( 'ledger' ), array( 'line_id' => $id ), array( '%d' ) );
			$wpdb->delete( self::table( 'statutory' ), array( 'line_id' => $id ), array( '%d' ) );
		} else {
			$wpdb->insert(
				self::table( 'lines' ),
				$fields + array(
					'created_at' => $now,
					'updated_at' => $now,
				)
			);
			$id = (int) $wpdb->insert_id;
		}
		foreach ( $ledger as $entry ) {
			$wpdb->insert(
				self::table( 'ledger' ),
				array(
					'line_id'         => $id,
					'run_id'          => (int) $fields['run_id'],
					'employee_id'     => (int) $fields['employee_id'],
					'code'            => (string) $entry['code'],
					'ref_id'          => isset( $entry['ref'] ) ? (int) $entry['ref'] : null,
					'year'            => (int) substr( $date, 0, 4 ),
					'month'           => (int) substr( $date, 5, 2 ),
					'quantity'        => (string) ( $entry['quantity'] ?? '0' ),
					'amount'          => (string) ( $entry['amount'] ?? '0' ),
					'employer_amount' => (string) ( $entry['employer'] ?? '0' ),
				)
			);
		}
		foreach ( $statutory as $row ) {
			$wpdb->insert(
				self::table( 'statutory' ),
				array(
					'line_id'         => $id,
					'run_id'          => (int) $fields['run_id'],
					'employee_id'     => (int) $fields['employee_id'],
					'year'            => (int) substr( $date, 0, 4 ),
					'month'           => (int) substr( $date, 5, 2 ),
					'authority'       => (string) $row['authority'],
					'code'            => (string) $row['code'],
					'label'           => (string) $row['label'],
					'kind'            => (string) $row['kind'],
					'base'            => (string) ( $row['base'] ?? '0' ),
					'employee_amount' => (string) ( $row['employee'] ?? '0' ),
					'employer_amount' => (string) ( $row['employer'] ?? '0' ),
				)
			);
		}
		// phpcs:enable
		return $id;
	}

	/**
	 * Remove a run's lines with their ledger and statutory rows (the legacy import rewrites them).
	 *
	 * @param int $run_id Run.
	 * @return void
	 */
	public function clearLines( int $run_id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the run's own rows.
		$wpdb->delete( self::table( 'ledger' ), array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( self::table( 'statutory' ), array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( self::table( 'lines' ), array( 'run_id' => $run_id ), array( '%d' ) );
		// phpcs:enable
	}

	/**
	 * Hours of a time-off code an employee used in a year, in runs that count (another run than this one).
	 *
	 * @param int    $employee_id Employee.
	 * @param string $code        paid_time_off|sick_pay|unpaid_time_off.
	 * @param int    $year        Year.
	 * @param int    $except_run  Run to leave out.
	 * @return string
	 */
	public function hoursUsed( int $employee_id, string $code, int $year, int $except_run ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a sum over the ledger.
		return (string) ( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( l.quantity ), 0 ) FROM %i l JOIN %i r ON r.id = l.run_id WHERE l.employee_id = %d AND l.code = %s AND l.year = %d AND l.run_id <> %d AND r.status IN ( 'finalized', 'settled' )", self::table( 'ledger' ), self::table(), $employee_id, $code, $year, $except_run ) ) ?? '0' );
	}

	/**
	 * Monthly deductions / garnishments already taken in a month by runs that count (another run than this one).
	 *
	 * @param int $employee_id Employee.
	 * @param int $year        Year.
	 * @param int $month       Month.
	 * @param int $except_run  Run to leave out.
	 * @return array<int, bool> Row id => true.
	 */
	public function takenInMonth( int $employee_id, int $year, int $month, int $except_run ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read live.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT l.ref_id FROM %i l JOIN %i r ON r.id = l.run_id WHERE l.employee_id = %d AND l.code IN ( 'deduction', 'garnishment' ) AND l.year = %d AND l.month = %d AND l.run_id <> %d AND r.status IN ( 'finalized', 'settled' )", self::table( 'ledger' ), self::table(), $employee_id, $year, $month, $except_run ) );
		return array_fill_keys( array_map( 'intval', (array) $ids ), true );
	}

	/**
	 * What runs that count have taken for a garnishment (another run than this one).
	 *
	 * @param int $ref_id     Deduction row.
	 * @param int $except_run Run to leave out (0 for none).
	 * @return string
	 */
	public function garnished( int $ref_id, int $except_run = 0 ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a sum over the ledger.
		return (string) ( $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( l.amount ), 0 ) FROM %i l JOIN %i r ON r.id = l.run_id WHERE l.ref_id = %d AND l.code = 'garnishment' AND l.run_id <> %d AND r.status IN ( 'finalized', 'settled' )", self::table( 'ledger' ), self::table(), $ref_id, $except_run ) ) ?? '0' );
	}

	/**
	 * The garnishments a run's ledger touches.
	 *
	 * @param int $run_id Run.
	 * @return int[]
	 */
	public function garnishmentsOf( int $run_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read live.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT ref_id FROM %i WHERE run_id = %d AND code = 'garnishment'", self::table( 'ledger' ), $run_id ) ) );
	}

	/**
	 * Whether any run's ledger (any status) took a deduction or garnishment.
	 *
	 * @param int $ref_id Deduction row.
	 * @return int Runs.
	 */
	public function runsTaking( int $ref_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read live.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT( DISTINCT run_id ) FROM %i WHERE ref_id = %d AND code IN ( 'deduction', 'garnishment' )", self::table( 'ledger' ), $ref_id ) );
	}

	/**
	 * Runs of a schedule (any status).
	 *
	 * @param int $schedule_id Schedule.
	 * @return int
	 */
	public function runsOfSchedule( int $schedule_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read live.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE pay_schedule_id = %d', self::table(), $schedule_id ) );
	}

	/**
	 * Delete a draft run with its lines and ledger.
	 *
	 * @param int $run_id Run.
	 * @return void
	 */
	public function deleteRun( int $run_id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the run's own rows, under its lock.
		$wpdb->delete( self::table( 'ledger' ), array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( self::table( 'statutory' ), array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( self::table( 'lines' ), array( 'run_id' => $run_id ), array( '%d' ) );
		$wpdb->delete( self::table(), array( 'id' => $run_id ), array( '%d' ) );
		// phpcs:enable
	}
}
