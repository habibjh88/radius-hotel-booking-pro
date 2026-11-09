<?php
/**
 * Employee data access.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_employees`.
 */
class EmployeeRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = Employee::class;

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_employees';
	}

	/**
	 * The employee file of a user, or null.
	 *
	 * @param int $user_id User id.
	 * @return Employee|null
	 */
	public function forUser( int $user_id ): ?Employee {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- unique key; sign-in check.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d', self::table(), $user_id ), ARRAY_A );
		return $row ? Employee::hydrate( $row ) : null;
	}

	/**
	 * Whether a user's employee file is deactivated (false when the user has
	 * no employee file: only employee files can be switched off).
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public function isInactiveUser( int $user_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the sign-in check must see the live state.
		$active = $wpdb->get_var( $wpdb->prepare( 'SELECT is_active FROM %i WHERE user_id = %d', self::table(), $user_id ) );
		return null !== $active && ! (int) $active;
	}

	/**
	 * A page of employees with their user login.
	 *
	 * @param string $q      Search: name, employee number, phone, e-mail, job title.
	 * @param string $active `active`, `inactive` or '' (all).
	 * @param int    $page   Page.
	 * @param int    $per    Per page.
	 * @param array  $more   `{ department, job_title }` (exact, '' = any), `sort` (name|job_title|department|shift, '' = default), `dir`.
	 * @return array{items: array[], total: int}
	 */
	public function page( string $q, string $active, int $page, int $per, array $more = array() ): array {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( 'active' === $active || 'inactive' === $active ) {
			$where[] = 'e.is_active = %d';
			$args[]  = 'active' === $active ? 1 : 0;
		}
		$q = trim( $q );
		if ( '' !== $q ) {
			$like    = '%' . $wpdb->esc_like( $q ) . '%';
			$where[] = '( CONCAT( e.first_name, \' \', e.last_name ) LIKE %s OR e.employee_no LIKE %s OR e.phone LIKE %s OR e.email LIKE %s OR e.job_title LIKE %s OR u.user_login LIKE %s )';
			array_push( $args, $like, $like, $like, $like, $like, $like );
		}
		foreach ( array( 'department', 'job_title' ) as $column ) {
			if ( '' !== (string) ( $more[ $column ] ?? '' ) ) {
				$where[] = "TRIM( e.{$column} ) = %s";
				$args[]  = (string) $more[ $column ];
			}
		}
		$where = implode( ' AND ', $where );
		// Active first, then the chosen column (empty values last), then the name.
		$dir   = 'desc' === ( $more['dir'] ?? '' ) ? 'DESC' : 'ASC';
		$order = array(
			'name'       => "e.first_name {$dir}, e.last_name {$dir}",
			'job_title'  => "e.job_title = '' ASC, e.job_title {$dir}",
			'department' => "e.department = '' ASC, e.department {$dir}",
			'shift'      => "ws.name IS NULL ASC, ws.name {$dir}",
		)[ $more['sort'] ?? '' ] ?? '';
		$order = 'e.is_active DESC, ' . ( '' !== $order ? $order . ', ' : '' ) . 'e.first_name, e.last_name, e.id';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where holds only fixed conditions with placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e LEFT JOIN %i u ON u.ID = e.user_id WHERE $where", array_merge( array( self::table(), $wpdb->users ), $args ) ) );
		// The work shift is the payroll one (on the pay profile); Pro installs its tables whatever features are on.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, u.user_login, ws.id AS work_shift_id, ws.name AS work_shift_name FROM %i e LEFT JOIN %i u ON u.ID = e.user_id LEFT JOIN %i p ON p.employee_id = e.id LEFT JOIN %i ws ON ws.id = p.work_shift_id WHERE $where ORDER BY $order LIMIT %d OFFSET %d",
				array_merge( array( self::table(), $wpdb->users, self::payTable(), self::shiftTable() ), $args, array( $per, ( max( 1, $page ) - 1 ) * $per ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		return array(
			'items' => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * An employee's work shift (the payroll one, on their pay profile).
	 *
	 * @param int $employee_id Employee id.
	 * @return array{work_shift_id: int|null, work_shift_name: string}
	 */
	public function workShift( int $employee_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one row for the staff file.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ws.id, ws.name FROM %i p JOIN %i ws ON ws.id = p.work_shift_id WHERE p.employee_id = %d', self::payTable(), self::shiftTable(), $employee_id ), ARRAY_A );
		return array(
			'work_shift_id'   => $row ? (int) $row['id'] : null,
			'work_shift_name' => $row ? (string) $row['name'] : '',
		);
	}

	/**
	 * The pay profiles table (M15).
	 *
	 * @return string
	 */
	private static function payTable(): string {
		return rtbp_table_prefix() . 'pro_employee_pay';
	}

	/**
	 * The work shifts table (M15).
	 *
	 * @return string
	 */
	private static function shiftTable(): string {
		return rtbp_table_prefix() . 'pro_work_shifts';
	}

	/**
	 * Lock an employee row until the transaction ends.
	 *
	 * @param int $id Employee id.
	 * @return bool Whether it exists.
	 */
	public function lock( int $id ): bool {
		return $this->lockRow( 'pro_employees', $id );
	}
}
