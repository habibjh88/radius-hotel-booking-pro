<?php
/**
 * Archiving the activity log (M14 T4).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\ActivityLog;

use RadiusTheme\RadiusHotelBooking\Storage\ProtectedFiles;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Exports the log to protected files and, only after checking them, removes
 * the archived rows (features 14.11–14.13).
 *
 * - An archive covers every row after the previous archive: a JSON file with
 *   the full rows (every hash, so it can be verified on its own) and a CSV for
 *   people. Both are protected files of kind `activity_archive`.
 * - Purging is verified: the JSON file's SHA-256, the chain inside the file
 *   (from the previous archive's last hash), and a row-by-row match of its
 *   hashes with the database rows still in that range. Only archives at the
 *   start of the chain are purged, oldest first, so the remaining chain has no
 *   gap. Rows are deleted in batches of BATCH; each batch is one transaction
 *   that also moves the head's anchor to the file's hash of the last deleted
 *   row, so a crash leaves a consistent chain and the purge resumes where it
 *   stopped.
 * - The JSON file is an array with one row per line, read back line by line.
 * - Nothing here edits a row.
 */
final class ActivityArchiver {

	const FILE_KIND = 'activity_archive';
	const CRON_HOOK = 'rtbp_pro_activity_archive';
	const LOCK      = 'rtbp_pro_activity_archive';
	const BATCH     = 1000;

	/**
	 * The archives table.
	 *
	 * @return string
	 */
	public static function table(): string {
		return rtbp_table_prefix() . 'pro_log_archives';
	}

	/**
	 * Archive every row since the last archive, then purge when asked.
	 *
	 * @param int  $user_id Who asked (0 = the scheduler).
	 * @param bool $purge   Remove the archived rows after verifying the file.
	 * @return array|WP_Error The archive (see shape()), or an error: `nothing_to_archive`, `archive_busy`, `archive_failed`.
	 */
	public static function archive( int $user_id, bool $purge ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a named MySQL lock: one archive at a time.
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::LOCK ) ) ) {
			return new WP_Error( 'archive_busy', __( 'An archive is already being written. Try again in a minute.', 'radius-hotel-booking-pro' ) );
		}

		try {
			$from = self::last_archived_id() + 1;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the range is fixed now; rows added meanwhile go to the next archive.
			$to = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( MAX(id), 0 ) FROM %i', ActivityLogger::table() ) );
			if ( $to < $from ) {
				return new WP_Error( 'nothing_to_archive', __( 'There is nothing new to archive.', 'radius-hotel-booking-pro' ) );
			}

			$written = self::write_files( $from, $to );
			if ( is_wp_error( $written ) ) {
				return $written;
			}

			$now = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- archive index.
			$wpdb->insert(
				self::table(),
				array(
					'period_start' => $written['period_start'],
					'period_end'   => $written['period_end'],
					'row_count'    => $written['rows'],
					'first_id'     => $written['first_id'],
					'last_id'      => $written['last_id'],
					'file_token'   => $written['json'],
					'csv_token'    => $written['csv'],
					'file_sha256'  => $written['sha256'],
					'last_hash'    => $written['last_hash'],
					'purged'       => 0,
					'created_by'   => $user_id,
					'created_at'   => $now,
					'updated_at'   => $now,
				)
			);
			$id = (int) $wpdb->insert_id;
			if ( ! $id ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the archive index could not be written.
				error_log( '[radius-hotel-booking-pro] Log archive written but not indexed: ' . $wpdb->last_error );
				return new WP_Error( 'archive_failed', __( 'The archive file was written but could not be recorded.', 'radius-hotel-booking-pro' ) );
			}

			rtbp_activity(
				'activity.archived',
				array(
					'type'  => 'log_archive',
					'id'    => $id,
					'label' => sprintf( '#%d', $written['first_id'] ) . '–' . sprintf( '#%d', $written['last_id'] ),
				),
				array(
					'actor'       => $user_id,
					'rows'        => $written['rows'],
					'description' => sprintf(
						/* translators: %d: number of log entries. */
						_n( 'Archived %d log entry', 'Archived %d log entries', $written['rows'], 'radius-hotel-booking-pro' ),
						$written['rows']
					),
				)
			);

			$purge_error = '';
			if ( $purge ) {
				$result      = self::purge_ready( $user_id );
				$purge_error = $result['error'];
			}

			return self::shape( self::find( $id ) ) + array( 'purge_error' => $purge_error );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- release the named lock.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::LOCK ) );
		}
	}

	/**
	 * Purge every unpurged archive at the start of the chain, oldest first,
	 * stopping at the first one that does not verify.
	 *
	 * @param int $user_id Who asked.
	 * @return array{removed: int, error: string} Rows removed, and why it stopped ('' = all done).
	 */
	public static function purge_ready( int $user_id ): array {
		global $wpdb;
		$removed = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- archive index.
		$pending = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE purged = 0 ORDER BY first_id ASC', self::table() ), ARRAY_A );
		foreach ( (array) $pending as $archive ) {
			$result = self::purge( $archive, $user_id );
			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a purge was refused; say why.
				error_log( sprintf( '[radius-hotel-booking-pro] Log archive %d not purged: %s', (int) $archive['id'], $result->get_error_message() ) );
				return array(
					'removed' => $removed,
					'error'   => $result->get_error_message(),
				);
			}
			$removed += $result;
		}
		return array(
			'removed' => $removed,
			'error'   => '',
		);
	}

	/**
	 * Verify one archive against its file and the database, then delete its
	 * rows in batches. Safe to run again after an interruption.
	 *
	 * @param array $archive Archive row.
	 * @param int   $user_id Who asked.
	 * @return int|WP_Error Rows removed, or why not.
	 */
	public static function purge( array $archive, int $user_id ) {
		global $wpdb;
		$log   = ActivityLogger::table();
		$first = (int) $archive['first_id'];
		$last  = (int) $archive['last_id'];

		// Only at the start of the chain: no older rows may remain.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live check.
		$older = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE id < %d', $log, $first ) );
		if ( $older ) {
			return new WP_Error( 'purge_order', __( 'Older entries must be removed first.', 'radius-hotel-booking-pro' ) );
		}

		$file = ProtectedFiles::get( (string) $archive['file_token'] );
		$path = $file ? ProtectedFiles::path( $file ) : '';
		if ( ! $path || ! is_readable( $path ) || ! hash_equals( (string) $archive['file_sha256'], (string) hash_file( 'sha256', $path ) ) ) {
			return new WP_Error( 'purge_file', __( 'The archive file is missing or has changed.', 'radius-hotel-booking-pro' ) );
		}

		// The chain inside the file, from the previous archive's last hash.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- archive index.
		$expected = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT last_hash FROM %i WHERE last_id < %d ORDER BY last_id DESC LIMIT 1', self::table(), $first ) );
		$chain    = array(); // id => array( prev, hash ), in order.
		foreach ( self::read_rows( $path ) as $row ) {
			if ( ! hash_equals( (string) $row['prev_hash'], $expected ) || ! hash_equals( ActivityLogger::hash( $row, (string) $row['prev_hash'] ), (string) $row['hash'] ) ) {
				return new WP_Error( 'purge_chain', __( 'The archive file does not verify.', 'radius-hotel-booking-pro' ) );
			}
			$chain[ (int) $row['id'] ] = array( (string) $row['prev_hash'], (string) $row['hash'] );
			$expected                  = (string) $row['hash'];
		}
		if ( count( $chain ) !== (int) $archive['row_count'] || array_key_last( $chain ) !== $last ) {
			return new WP_Error( 'purge_file', __( 'The archive file does not hold the expected rows.', 'radius-hotel-booking-pro' ) );
		}

		// Where a previous run stopped: the head's anchor is the file's hash
		// just before the first row still in the database.
		$head = ActivityLogger::head();
		if ( ! $head || ! hash_equals( ActivityLogger::head_mac( $head ), (string) $head['mac'] ) ) {
			return new WP_Error( 'purge_head', __( 'The log head record is missing or has changed.', 'radius-hotel-booking-pro' ) );
		}
		$ids   = array_keys( $chain );
		$start = null;
		foreach ( $ids as $index => $id ) {
			if ( hash_equals( $chain[ $id ][0], (string) $head['anchor'] ) ) {
				$start = $index;
				break;
			}
		}
		if ( null === $start ) {
			return new WP_Error( 'purge_anchor', __( 'The log does not continue from this archive.', 'radius-hotel-booking-pro' ) );
		}
		$ids = array_slice( $ids, $start );

		// The database rows still in the range are exactly the file's rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live comparison.
		$db = $wpdb->get_results( $wpdb->prepare( 'SELECT id, hash FROM %i WHERE id BETWEEN %d AND %d ORDER BY id', $log, $first, $last ), ARRAY_A );
		$db = array_map( 'strval', array_column( (array) $db, 'hash', 'id' ) );
		$fi = array();
		foreach ( $ids as $id ) {
			$fi[ $id ] = $chain[ $id ][1];
		}
		if ( $db !== $fi ) {
			return new WP_Error( 'purge_mismatch', __( 'The database no longer matches the archive file.', 'radius-hotel-booking-pro' ) );
		}

		$removed = 0;
		foreach ( array_chunk( $ids, self::BATCH ) as $n => $batch ) {
			$head = ActivityLogger::lock_head();
			if ( null === $head || ! hash_equals( $chain[ $batch[0] ][0], (string) $head['anchor'] ) ) {
				ActivityLogger::rollback();
				return new WP_Error( 'purge_anchor', __( 'The log changed during the removal; run it again.', 'radius-hotel-booking-pro' ) );
			}
			$last_in = (int) end( $batch );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the verified purge, the only delete of log rows.
			$deleted = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id BETWEEN %d AND %d', $log, (int) $batch[0], $last_in ) );
			if ( count( $batch ) !== $deleted ) {
				ActivityLogger::rollback();
				return new WP_Error( 'purge_mismatch', __( 'The database no longer matches the archive file.', 'radius-hotel-booking-pro' ) );
			}
			$head['anchor']    = $chain[ $last_in ][1];
			$head['row_count'] = max( 0, (int) $head['row_count'] - $deleted );
			ActivityLogger::write_head( $head );
			if ( $last_in === $last ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- archive index, same transaction.
				$wpdb->update(
					self::table(),
					array(
						'purged' => 1,
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $archive['id'] )
				);
			}
			ActivityLogger::commit();
			$removed += $deleted;
			unset( $n );
		}

		rtbp_activity(
			'activity.purged',
			array(
				'type'  => 'log_archive',
				'id'    => (int) $archive['id'],
				'label' => sprintf( '#%d', $first ) . '–' . sprintf( '#%d', $last ),
			),
			array(
				'actor'       => $user_id,
				'rows'        => $removed,
				'description' => sprintf(
					/* translators: %d: number of log entries. */
					_n( 'Removed %d archived log entry', 'Removed %d archived log entries', $removed, 'radius-hotel-booking-pro' ),
					$removed
				),
			)
		);

		return $removed;
	}

	/**
	 * The rows of an archive file, one per line.
	 *
	 * @param string $path File.
	 * @return \Generator<array>
	 */
	public static function read_rows( string $path ): \Generator {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming our own archive file.
		$handle = fopen( $path, 'rb' );
		if ( ! $handle ) {
			return;
		}
		try {
			while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- line reader.
				$line = rtrim( trim( $line ), ',' );
				if ( '' === $line || '[' === $line || ']' === $line ) {
					continue;
				}
				$row = json_decode( $line, true );
				if ( is_array( $row ) ) {
					yield $row;
				}
			}
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with fopen.
		}
	}

	/**
	 * Scheduler callback: archive when due (14.11).
	 *
	 * @return void
	 */
	public static function run_due(): void {
		$next = self::next_run();
		if ( null === $next || $next > time() ) {
			return;
		}
		self::archive( 0, (bool) ActivitySettings::get( 'purgeAfterArchive', false ) );
	}

	/**
	 * When the next automatic archive is due (Unix time), or null when off.
	 *
	 * @return int|null
	 */
	public static function next_run(): ?int {
		global $wpdb;
		$months = (int) ActivitySettings::get( 'archiveEvery', 12 );
		if ( $months <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small read.
		$last = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM %i', self::table() ) );
		if ( '' === $last ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small read.
			$last = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT MIN(created_at_gmt) FROM %i', ActivityLogger::table() ) );
		}
		if ( '' === $last ) {
			return null; // Nothing logged yet.
		}
		return strtotime( $last . ' UTC +' . $months . ' months' );
	}

	/**
	 * Every archive, newest first.
	 *
	 * @return array[]
	 */
	public static function all(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- archive index.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT 200', self::table() ), ARRAY_A );
		return array_map( array( self::class, 'shape' ), (array) $rows );
	}

	/**
	 * The API shape of an archive.
	 *
	 * @param array|null $row Row.
	 * @return array
	 */
	public static function shape( ?array $row ): array {
		if ( ! $row ) {
			return array();
		}
		$by = (int) $row['created_by'];
		return array(
			'id'           => (int) $row['id'],
			'period_start' => (string) $row['period_start'],
			'period_end'   => (string) $row['period_end'],
			'rows'         => (int) $row['row_count'],
			'first_id'     => (int) $row['first_id'],
			'last_id'      => (int) $row['last_id'],
			'purged'       => (bool) $row['purged'],
			'created_at'   => get_date_from_gmt( (string) $row['created_at'] ),
			'created_by'   => $by ? (string) ( get_user_by( 'id', $by )->display_name ?? '' ) : __( 'Automatic', 'radius-hotel-booking-pro' ),
			'json_url'     => '' !== (string) $row['file_token'] ? ProtectedFiles::url( (string) $row['file_token'] ) : '',
			'csv_url'      => '' !== (string) $row['csv_token'] ? ProtectedFiles::url( (string) $row['csv_token'] ) : '',
		);
	}

	/**
	 * One archive row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	private static function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- archive index.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * The last id any archive covers.
	 *
	 * @return int
	 */
	public static function last_archived_id(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- archive index.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE( MAX(last_id), 0 ) FROM %i', self::table() ) );
	}

	/**
	 * Stream the rows `$from`..`$to` to a JSON and a CSV file, stored as
	 * protected files.
	 *
	 * @param int $from First id.
	 * @param int $to   Last id.
	 * @return array|WP_Error `{ json, csv, sha256, rows, first_id, last_id, period_start, period_end, last_hash }`.
	 */
	private static function write_files( int $from, int $to ) {
		global $wpdb;

		// wp_tempnam() is loaded in wp-admin and WP-CLI, not in REST requests.
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$json_tmp = wp_tempnam( 'rtbp-log.json' );
		$csv_tmp  = wp_tempnam( 'rtbp-log.csv' );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming large exports to temp files.
		$json = fopen( $json_tmp, 'wb' );
		$csv  = fopen( $csv_tmp, 'wb' );
		if ( ! $json || ! $csv ) {
			return new WP_Error( 'archive_failed', __( 'The archive file could not be written.', 'radius-hotel-booking-pro' ) );
		}

		fwrite( $csv, "\xEF\xBB\xBF" ); // UTF-8 BOM, so spreadsheet apps read accents.
		// No escape character: RFC 4180 (a backslash before a quote must not
		// stop the quote being doubled; review #3).
		fputcsv( $csv, array( 'id', 'time', 'user', 'role', 'action', 'kind', 'record', 'description', 'changes', 'ip', 'device', 'repeat_count' ), ',', '"', '' );
		fwrite( $json, "[\n" );

		$after = $from - 1;
		$out   = array(
			'rows'         => 0,
			'first_id'     => 0,
			'last_id'      => 0,
			'period_start' => '',
			'period_end'   => '',
			'last_hash'    => '',
		);
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- batch read for the export.
			$batch = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d', ActivityLogger::table(), $after, $to, self::BATCH ), ARRAY_A );
			foreach ( $batch as $row ) {
				// One row per line, so the file can be verified line by line.
				fwrite( $json, ( $out['rows'] ? ",\n" : '' ) . wp_json_encode( $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
				fputcsv(
					$csv,
					array_map(
						array( self::class, 'csv_cell' ),
						array( $row['id'], $row['created_at'], $row['user_name'], $row['user_role'], $row['action'], $row['kind'], trim( $row['subject_type'] . ' ' . $row['subject_id'] . ' ' . $row['subject_label'] ), $row['description'], $row['changes'], $row['ip'], $row['device'], $row['repeat_count'] )
					),
					',',
					'"',
					''
				);
				++$out['rows'];
				$out['first_id']     = $out['first_id'] ? $out['first_id'] : (int) $row['id'];
				$out['last_id']      = (int) $row['id'];
				$out['period_start'] = '' !== $out['period_start'] ? $out['period_start'] : (string) $row['created_at'];
				$out['period_end']   = (string) $row['created_at'];
				$out['last_hash']    = (string) $row['hash'];
				$after               = (int) $row['id'];
			}
			$full = count( $batch ) === self::BATCH;
		} while ( $full );

		fwrite( $json, "\n]" );
		fclose( $json );
		fclose( $csv );
		// phpcs:enable

		if ( ! $out['rows'] ) {
			wp_delete_file( $json_tmp );
			wp_delete_file( $csv_tmp );
			return new WP_Error( 'nothing_to_archive', __( 'There is nothing new to archive.', 'radius-hotel-booking-pro' ) );
		}

		$sha  = (string) hash_file( 'sha256', $json_tmp );
		$name = sprintf( 'activity-log-%d-%d', $out['first_id'], $out['last_id'] );
		$json = ProtectedFiles::put_file( self::FILE_KIND, $json_tmp, $name . '.json', true, 'application/json' );
		$csv  = ProtectedFiles::put_file( self::FILE_KIND, $csv_tmp, $name . '.csv', true, 'text/csv' );

		return $out + array(
			'json'   => (string) $json->token,
			'csv'    => (string) $csv->token,
			'sha256' => $sha,
		);
	}

	/**
	 * A CSV cell that a spreadsheet will not run as a formula.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function csv_cell( $value ): string {
		$value = (string) $value;
		return '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $value : $value;
	}
}
