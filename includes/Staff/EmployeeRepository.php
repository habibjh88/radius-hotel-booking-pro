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
	 * @return array{items: array[], total: int}
	 */
	public function page( string $q, string $active, int $page, int $per ): array {
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
		$where = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $where holds only fixed conditions with placeholders.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i e LEFT JOIN %i u ON u.ID = e.user_id WHERE $where", array_merge( array( self::table(), $wpdb->users ), $args ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.*, u.user_login FROM %i e LEFT JOIN %i u ON u.ID = e.user_id WHERE $where ORDER BY e.is_active DESC, e.first_name, e.last_name, e.id LIMIT %d OFFSET %d",
				array_merge( array( self::table(), $wpdb->users ), $args, array( $per, ( max( 1, $page ) - 1 ) * $per ) )
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
	 * Lock an employee row until the transaction ends.
	 *
	 * @param int $id Employee id.
	 * @return bool Whether it exists.
	 */
	public function lock( int $id ): bool {
		return $this->lockRow( 'pro_employees', $id );
	}
}
