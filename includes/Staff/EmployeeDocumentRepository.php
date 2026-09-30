<?php
/**
 * Employee document data access.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_employee_documents`.
 */
class EmployeeDocumentRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = EmployeeDocument::class;

	/**
	 * The table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_employee_documents';
	}

	/**
	 * An employee's documents, newest first.
	 *
	 * @param int $employee_id Employee id.
	 * @return EmployeeDocument[]
	 */
	public function forEmployee( int $employee_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a short list, read live.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE employee_id = %d ORDER BY id DESC', self::table(), $employee_id ), ARRAY_A );
		return array_map( array( EmployeeDocument::class, 'hydrate' ), (array) $rows );
	}

	/**
	 * The document that holds a protected file, or null.
	 *
	 * @param string $token File token.
	 * @return EmployeeDocument|null
	 */
	public function forToken( string $token ): ?EmployeeDocument {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- download check.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE file_token = %s', self::table(), $token ), ARRAY_A );
		return $row ? EmployeeDocument::hydrate( $row ) : null;
	}
}
