<?php
/**
 * Reads the activity log.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\ActivityLog;

use RadiusTheme\RadiusHotelBooking\ActivityLog\ActionCatalog;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only queries on `pro_activity_log` for the log screen (features
 * 14.5–14.9) and the per-record timeline. Every value is bound with
 * `$wpdb->prepare()`; the search uses `esc_like()`.
 */
final class ActivityQuery {

	/**
	 * A filtered page of rows, newest first.
	 *
	 * @param array $filters `user_id`, `from`, `to` (site-local Y-m-d), `kind`, `action`, `q`, `page`, `per_page`.
	 * @return array{rows: array[], total: int, page: int, per_page: int, pages: int}
	 */
	public static function search( array $filters ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$params[] = (int) $filters['user_id'];
		}
		if ( self::is_date( $filters['from'] ?? '' ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['from'] . ' 00:00:00';
		}
		if ( self::is_date( $filters['to'] ?? '' ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['to'] . ' 23:59:59';
		}
		if ( ! empty( $filters['kind'] ) && isset( ActionCatalog::kinds()[ $filters['kind'] ] ) ) {
			$where[]  = 'kind = %s';
			$params[] = (string) $filters['kind'];
		}
		if ( ! empty( $filters['action'] ) ) {
			$where[]  = 'action = %s';
			$params[] = sanitize_text_field( (string) $filters['action'] );
		}
		$q = trim( sanitize_text_field( (string) ( $filters['q'] ?? '' ) ) );
		if ( '' !== $q ) {
			$like     = '%' . $wpdb->esc_like( $q ) . '%';
			$where[]  = '(description LIKE %s OR subject_label LIKE %s OR user_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$per_page = min( 100, max( 1, (int) ( $filters['per_page'] ?? 25 ) ) );
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$sql      = implode( ' AND ', $where );
		$table    = ActivityLogger::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $sql is built only from the fixed fragments above; every value is a placeholder (counted at run time).
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE $sql", array_merge( array( $table ), $params ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE $sql ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( array( $table ), $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'rows'     => array_map( array( self::class, 'shape' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
			'pages'    => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * The history of one record, newest first.
	 *
	 * @param string $type Subject type.
	 * @param string $id   Subject id.
	 * @param int    $limit Rows.
	 * @return array[]
	 */
	public static function subject( string $type, string $id, int $limit = 100 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live read of the log.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE subject_type = %s AND subject_id = %s ORDER BY id DESC LIMIT %d',
				ActivityLogger::table(),
				$type,
				$id,
				min( 500, max( 1, $limit ) )
			),
			ARRAY_A
		);
		return array_map( array( self::class, 'shape' ), (array) $rows );
	}

	/**
	 * The people who appear in the log, for the staff filter.
	 *
	 * @return array[] `[ { id, name } ]`.
	 */
	public static function people(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small distinct list.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, MAX(user_name) AS name FROM %i WHERE user_id > 0 GROUP BY user_id ORDER BY name LIMIT 500', ActivityLogger::table() ), ARRAY_A );
		return array_map(
			static fn( $row ) => array(
				'id'   => (int) $row['user_id'],
				'name' => (string) $row['name'],
			),
			(array) $rows
		);
	}

	/**
	 * The API shape of a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	public static function shape( array $row ): array {
		return array(
			'id'           => (int) $row['id'],
			'time'         => (string) $row['created_at'],
			'time_gmt'     => (string) $row['created_at_gmt'],
			'last_seen'    => (string) $row['last_seen_gmt'],
			'repeat_count' => (int) $row['repeat_count'],
			'user'         => array(
				'id'   => (int) $row['user_id'],
				'name' => (string) $row['user_name'],
				'role' => (string) $row['user_role'],
			),
			'action'       => (string) $row['action'],
			'action_label' => ActionCatalog::label( (string) $row['action'] ),
			'kind'         => (string) $row['kind'],
			'subject'      => '' === (string) $row['subject_type'] ? null : array(
				'type'  => (string) $row['subject_type'],
				'id'    => (string) $row['subject_id'],
				'label' => (string) $row['subject_label'],
			),
			'description'  => (string) $row['description'],
			'changes'      => '' === (string) $row['changes'] ? null : json_decode( (string) $row['changes'], true ),
			'meta'         => '' === (string) $row['meta'] ? null : json_decode( (string) $row['meta'], true ),
			'ip'           => (string) $row['ip'],
			'device'       => (string) $row['device'],
		);
	}

	/**
	 * Whether a value is a Y-m-d date.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_date( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value );
	}
}
