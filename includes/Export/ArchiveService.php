<?php
/**
 * Archive and remove (M11 T3b, 11.4).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Export
 */

namespace RadiusTheme\RadiusHotelBookingPro\Export;

use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Services\Export\BookingExport;
use RadiusTheme\RadiusHotelBooking\Services\Export\ExportService;
use RadiusTheme\RadiusHotelBooking\Services\Reports\ReportRange;
use RadiusTheme\RadiusHotelBooking\Storage\ProtectedFiles;
use RadiusTheme\RadiusHotelBooking\Support\Dates;
use RadiusTheme\RadiusHotelBookingPro\Reports\XlsxWriter;

defined( 'ABSPATH' ) || exit;

/**
 * Writes a period's booking export, verifies it, and only then deletes the
 * finished bookings it holds from the live database (the legacy *delete
 * after*, made safe).
 *
 * 1. **The file first** — free's `ExportService::generate( 'bookings', … )`
 *    (logged `exports.generate`); the period must have ended before today.
 * 2. **Verified** — the stored file is read back and its SHA-256 must equal
 *    the one recorded in the file index and in the library row; any mismatch
 *    and nothing is deleted.
 * 3. **Only finished bookings** — a booking is removed only when **every** one
 *    of its lines is `checked_out`, `cancelled`, `declined` or `no_show`, over
 *    (`occupied_until_gmt` ≤ now), and — by arrival — starts inside the period
 *    (so every row removed is in the file); and when neither it nor its lines
 *    changed after the export began. Future and in-house stays are never
 *    touched: their bookings are *kept* and counted.
 * 4. **Transactional per chunk** of 100 bookings: the bookings are locked
 *    (`FOR UPDATE`) and re-checked inside the transaction, then everything of
 *    theirs goes — invoice versions, invoices, payments, notes, lines, the
 *    booking (decision 2026-10-01: like the legacy *Booking Backup*). Files
 *    of their invoices are deleted after the commit. Guests stay.
 * 5. **Logged** — `exports.archive_remove` with the counts, the reference
 *    range, the period, the file and the reason given.
 */
class ArchiveService {

	/**
	 * Line statuses of a finished stay.
	 */
	public const TERMINAL = array( 'checked_out', 'cancelled', 'declined', 'no_show' );

	/**
	 * Bookings per transaction.
	 */
	public const CHUNK = 100;

	/**
	 * Archive and remove.
	 *
	 * @param array $input `from`, `to`, `mode`, `format`, `reason`.
	 * @return array `{ export, removed, kept, lines, payments, invoices, first_reference, last_reference }`.
	 * @throws DomainException 422 on a bad period or reason, 500 when the file cannot be verified.
	 */
	public function run( array $input ): array {
		$reason = trim( sanitize_text_field( (string) ( $input['reason'] ?? '' ) ) );
		$range  = ReportRange::fromInput( $input );
		$errors = array();
		if ( $range->to >= Dates::today() ) {
			$errors['to'] = __( 'Choose a period that ended before today.', 'radius-hotel-booking-pro' );
		}
		if ( strlen( $reason ) < 3 ) {
			$errors['reason'] = __( 'Say why these bookings are being removed.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::invalid( $errors );
		}

		// Changes after this moment keep a booking out of the removal. `updated_at`
		// is written by two clocks — the ORM's site time, and MySQL's own
		// `ON UPDATE CURRENT_TIMESTAMP` in the session's zone — so the earlier
		// of the two is the threshold: a later change on either clock is always
		// seen. (A booking changed in the last hours may then be kept: the safe
		// side.)
		global $wpdb;
		$started = min( current_time( 'mysql' ), (string) $wpdb->get_var( 'SELECT NOW()' ) );
		// One second back: `updated_at` has whole seconds, a change in the run's
		// first second must not look older than the run (M11 critical review).
		$started = gmdate( 'Y-m-d H:i:s', strtotime( $started . ' UTC' ) - 1 );
		$now_gmt = Dates::to_gmt_db( Dates::now() );

		// 1. The file.
		$export = ( new ExportService() )->generate( 'bookings', $input );

		// 2. Verified: the bytes, then what they hold — the line ids read back
		// from the stored file. A booking with any line not in it is kept.
		$in_file = $this->verify( (int) $export['id'] );

		// 3–4. The finished bookings, chunk by chunk.
		$candidates = $this->candidates( $range );
		$eligible   = $this->eligible( $range, $candidates, $started, $now_gmt );
		$totals     = array(
			'removed'  => 0,
			'lines'    => 0,
			'payments' => 0,
			'invoices' => 0,
		);
		$refs       = array();
		$part = 0;
		foreach ( array_chunk( $eligible, self::CHUNK ) as $chunk ) {
			$files = array();
			$done  = Transaction::run(
				function () use ( $chunk, $range, $started, $now_gmt, $in_file, &$files ) {
					return $this->removeChunk( $chunk, $range, $started, $now_gmt, $in_file, $files );
				}
			);
			foreach ( array( 'removed', 'lines', 'payments', 'invoices' ) as $key ) {
				$totals[ $key ] += $done[ $key ];
			}
			$refs = array_merge( $refs, $done['references'] );
			foreach ( $files as $token ) {
				ProtectedFiles::delete( $token );
			}
			// Recorded as each chunk commits: a run that dies later still leaves
			// a trace of what it removed.
			if ( $done['removed'] ) {
				++$part;
				$this->record( (int) $export['id'], $range, $done, $part, $reason, (string) ( $export['file']['name'] ?? '' ), (string) ( $export['file']['sha256'] ?? '' ) );
			}
		}
		sort( $refs, SORT_NATURAL );
		$kept = count( $candidates ) - $totals['removed'];

		// Cached figures and counters no longer hold.
		foreach ( array( 'RadiusTheme\\RadiusHotelBooking\\Services\\Reports\\ReportService', 'RadiusTheme\\RadiusHotelBooking\\Services\\Dashboard\\DashboardCounters' ) as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'flush' ) ) {
				$class::flush();
			}
		}

		$first = $refs ? (string) reset( $refs ) : '';
		$last  = $refs ? (string) end( $refs ) : '';
		if ( ! $part ) {
			// Nothing removed: the run is still recorded.
			$this->record(
				(int) $export['id'],
				$range,
				array(
					'removed'    => 0,
					'lines'      => 0,
					'payments'   => 0,
					'invoices'   => 0,
					'references' => array(),
				),
				0,
				$reason,
				(string) ( $export['file']['name'] ?? '' ),
				(string) ( $export['file']['sha256'] ?? '' ),
				$kept
			);
		}

		return array(
			'export'          => ( new ExportService() )->find( (int) $export['id'] ),
			'removed'         => $totals['removed'],
			'kept'            => $kept,
			'lines'           => $totals['lines'],
			'payments'        => $totals['payments'],
			'invoices'        => $totals['invoices'],
			'first_reference' => $first,
			'last_reference'  => $last,
		);
	}

	/**
	 * Read the stored file back and compare its checksum with the index and
	 * the library row.
	 *
	 * @param int $export_id Library row.
	 * @return array<int, true> Line id => true: every line the file holds.
	 * @throws DomainException 500 `archive_unverified`.
	 */
	private function verify( int $export_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one row by id, read fresh.
		$row  = $wpdb->get_row( $wpdb->prepare( 'SELECT file_token, file_sha256, status, format, params, row_count FROM %i WHERE id = %d', rtbp_table( 'exports' ), $export_id ), ARRAY_A );
		$file = $row ? ProtectedFiles::get( (string) $row['file_token'] ) : null;
		$path = $file ? ProtectedFiles::path( $file ) : '';
		$ok   = $row && $file && 'done' === $row['status'] && '' !== (string) $row['file_sha256']
			&& is_readable( $path ) && filesize( $path ) > 0
			&& hash_equals( (string) $row['file_sha256'], (string) $file->sha256 )
			&& hash_equals( (string) $row['file_sha256'], (string) hash_file( 'sha256', $path ) );
		$ids = $ok ? $this->lineIds( $path, (string) $row['format'], (array) json_decode( (string) $row['params'], true ) ) : null;
		// Every row the export wrote must be read back.
		if ( ! $ok || null === $ids || count( $ids ) !== (int) $row['row_count'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw new DomainException( 'archive_unverified', __( 'The archive file could not be verified, so nothing was removed. Try again.', 'radius-hotel-booking-pro' ), 500 );
		}
		return $ids;
	}

	/**
	 * The *Line ID* column (the last one) of a stored booking export, read
	 * back from the file itself — CSV, or the XLSX sheet streamed through
	 * `XMLReader` (memory stays flat).
	 *
	 * @param string $path   File.
	 * @param string $format `csv` or `xlsx`.
	 * @param array  $params The export's params (the CSV separator).
	 * @return array<int, true>|null Line id => true; null when unreadable.
	 */
	private function lineIds( string $path, string $format, array $params ): ?array {
		$columns = count( BookingExport::columns() );
		$ids     = array();
		if ( 'xlsx' === $format ) {
			if ( ! class_exists( '\XMLReader' ) ) {
				return null;
			}
			$reader = new \XMLReader();
			if ( ! $reader->open( 'zip://' . $path . '#xl/worksheets/sheet1.xml' ) ) {
				return null;
			}
			$column = XlsxWriter::column( $columns - 1 );
			while ( $reader->read() ) {
				if ( \XMLReader::ELEMENT === $reader->nodeType && 'c' === $reader->localName
					&& preg_match( '/^' . $column . '(\d+)$/', (string) $reader->getAttribute( 'r' ), $match ) && '1' !== $match[1] ) {
					$cell = new \SimpleXMLElement( $reader->readOuterXml() );
					$id   = (int) ( $cell->v ?? 0 );
					if ( $id > 0 ) {
						$ids[ $id ] = true;
					}
				}
			}
			$reader->close();
			return $ids;
		}
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading our own stored file back.
		if ( false === $handle ) {
			return null;
		}
		$separator = in_array( $params['separator'] ?? ',', array( ',', ';' ), true ) ? $params['separator'] : ',';
		if ( "\xEF\xBB\xBF" !== fread( $handle, 3 ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- see above.
			rewind( $handle );
		}
		$header = fgetcsv( $handle, 0, $separator, '"', '' );
		if ( ! is_array( $header ) || count( $header ) !== $columns ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
			return null;
		}
		$line = fgetcsv( $handle, 0, $separator, '"', '' );
		while ( false !== $line ) {
			$id = (int) ( $line[ $columns - 1 ] ?? 0 );
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
			$line = fgetcsv( $handle, 0, $separator, '"', '' );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- see above.
		return $ids;
	}

	/**
	 * Record a committed chunk: the library row's count, and a log entry.
	 *
	 * @param int         $export_id Library row.
	 * @param ReportRange $range     Period.
	 * @param array       $done      The chunk's counts and references.
	 * @param int         $part      Chunk number (0 = nothing was removed).
	 * @param string      $reason    The reason given.
	 * @param string      $file      File name.
	 * @param string      $sha256    File checksum.
	 * @param int|null    $kept      Bookings kept (the summary entry).
	 * @return void
	 */
	private function record( int $export_id, ReportRange $range, array $done, int $part, string $reason, string $file, string $sha256, ?int $kept = null ): void {
		global $wpdb;
		if ( $done['removed'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- our own row, counted up as chunks commit.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET removed_count = removed_count + %d, updated_at = %s WHERE id = %d', rtbp_table( 'exports' ), (int) $done['removed'], current_time( 'mysql' ), $export_id ) );
		}
		$refs = $done['references'];
		sort( $refs, SORT_NATURAL );
		$first = $refs ? (string) reset( $refs ) : '';
		$last  = $refs ? (string) end( $refs ) : '';
		rtbp_activity(
			'exports.archive_remove',
			array(
				'type'  => 'export',
				'id'    => $export_id,
				'label' => $file,
			),
			array(
				'after'       => array(
					'from'            => $range->from,
					'to'              => $range->to,
					'mode'            => $range->mode,
					'part'            => $part,
					'removed'         => (int) $done['removed'],
					'kept'            => $kept,
					'lines'           => (int) $done['lines'],
					'payments'        => (int) $done['payments'],
					'invoices'        => (int) $done['invoices'],
					'first_reference' => $first,
					'last_reference'  => $last,
					'file_sha256'     => $sha256,
					'reason'          => $reason,
				),
				'description' => $done['removed']
					? sprintf(
						/* translators: 1: bookings removed, 2: first reference, 3: last reference, 4: first day, 5: last day, 6: reason. */
						__( 'Archived and removed %1$d bookings (%2$s to %3$s) of %4$s to %5$s: %6$s', 'radius-hotel-booking-pro' ),
						(int) $done['removed'],
						$first,
						$last,
						$range->from,
						$range->to,
						$reason
					)
					: sprintf(
						/* translators: 1: first day, 2: last day, 3: bookings kept, 4: reason. */
						__( 'Archived %1$s to %2$s; no booking could be removed (%3$d kept): %4$s', 'radius-hotel-booking-pro' ),
						$range->from,
						$range->to,
						(int) $kept,
						$reason
					),
			)
		);
	}


	/**
	 * Bookings with lines in the period (what the export holds).
	 *
	 * @param ReportRange $range Period.
	 * @return int[]
	 */
	private function candidates( ReportRange $range ): array {
		global $wpdb;
		$period = $this->period( $range );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $period is prepared; read fresh.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT b.id FROM %i br JOIN %i b ON b.id = br.booking_id AND b.deleted_at IS NULL WHERE {$period} ORDER BY b.id",
				rtbp_table( 'booking_rooms' ),
				rtbp_table( 'bookings' )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The candidates that may go (see the class notes).
	 *
	 * @param ReportRange $range   Period.
	 * @param int[]       $ids     Bookings to check.
	 * @param string      $started Local time the run began.
	 * @param string      $now_gmt Now (GMT).
	 * @return int[]
	 */
	private function eligible( ReportRange $range, array $ids, string $started, string $now_gmt ): array {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$in       = implode( ',', array_map( 'intval', $ids ) );
		$terminal = "'" . implode( "','", self::TERMINAL ) . "'";
		$outside  = 'created' === $range->mode
			? '0'
			: $wpdb->prepare( '( l.start_at_gmt < %s OR l.start_at_gmt >= %s )', $range->from_gmt, $range->to_gmt );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $in are integers, $terminal constant, $outside prepared; read fresh.
		$out = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT b.id FROM %i b
				WHERE b.id IN ( {$in} ) AND b.deleted_at IS NULL AND b.updated_at <= %s
					AND NOT EXISTS (
						SELECT 1 FROM %i l WHERE l.booking_id = b.id
							AND ( l.status NOT IN ( {$terminal} ) OR l.occupied_until_gmt > %s OR l.updated_at > %s OR {$outside} )
					)
				ORDER BY b.id",
				rtbp_table( 'bookings' ),
				$started,
				rtbp_table( 'booking_rooms' ),
				$now_gmt,
				$started
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $out );
	}

	/**
	 * Remove one chunk inside a transaction: lock, re-check, delete.
	 *
	 * @param int[]       $ids     Bookings.
	 * @param ReportRange $range   Period.
	 * @param string      $started Local time the run began.
	 * @param string      $now_gmt Now (GMT).
	 * @param array       $in_file Line id => true: the lines the verified file holds.
	 * @param string[]    $files   Protected file tokens to delete after the commit (filled).
	 * @return array `{ removed, lines, payments, invoices, references }`.
	 * @throws \RuntimeException When a delete fails (the transaction rolls back).
	 */
	private function removeChunk( array $ids, ReportRange $range, string $started, string $now_gmt, array $in_file, array &$files ): array {
		global $wpdb;
		$in = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $in / $ids are integers; deletes of our own rows inside a transaction.
		// Every statement is checked: a failure throws and the whole chunk rolls
		// back (wpdb resets last_error on each query, so one check at the end
		// would only see the last statement — M11 critical review).
		$must = static function ( $result ) use ( $wpdb ) {
			if ( false === $result || null === $result || '' !== (string) $wpdb->last_error ) {
				throw new \RuntimeException( 'Removing archived bookings failed: ' . esc_html( (string) $wpdb->last_error ) );
			}
			return $result;
		};
		$must( $wpdb->query( $wpdb->prepare( "SELECT id FROM %i WHERE id IN ( {$in} ) FOR UPDATE", rtbp_table( 'bookings' ) ) ) );
		// Re-checked under the lock: a booking changed meanwhile stays.
		$ids = $this->eligible( $range, $ids, $started, $now_gmt );
		$must( true );
		// And only bookings whose every line is in the verified file.
		if ( $ids ) {
			$lines = $must( $wpdb->get_results( $wpdb->prepare( 'SELECT id, booking_id FROM %i WHERE booking_id IN ( ' . implode( ',', $ids ) . ' )', rtbp_table( 'booking_rooms' ) ), ARRAY_A ) );
			$out   = array();
			foreach ( (array) $lines as $line ) {
				if ( ! isset( $in_file[ (int) $line['id'] ] ) ) {
					$out[ (int) $line['booking_id'] ] = true;
				}
			}
			$ids = array_values( array_filter( $ids, static fn( $id ) => ! isset( $out[ $id ] ) ) );
		}
		if ( ! $ids ) {
			return array(
				'removed'    => 0,
				'lines'      => 0,
				'payments'   => 0,
				'invoices'   => 0,
				'references' => array(),
			);
		}
		$in         = implode( ',', $ids );
		$references = $must( $wpdb->get_col( $wpdb->prepare( "SELECT reference FROM %i WHERE id IN ( {$in} )", rtbp_table( 'bookings' ) ) ) );
		$invoices   = array_map( 'intval', (array) $must( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM %i WHERE booking_id IN ( {$in} )", rtbp_table( 'invoices' ) ) ) ) );
		$files      = array_merge(
			$files,
			array_filter( (array) $must( $wpdb->get_col( $wpdb->prepare( "SELECT file_token FROM %i WHERE booking_id IN ( {$in} ) AND file_token IS NOT NULL AND file_token <> ''", rtbp_table( 'invoices' ) ) ) ) )
		);
		if ( $invoices ) {
			$inv   = implode( ',', $invoices );
			$files = array_merge( $files, array_filter( (array) $must( $wpdb->get_col( $wpdb->prepare( "SELECT file_token FROM %i WHERE invoice_id IN ( {$inv} ) AND file_token IS NOT NULL AND file_token <> ''", rtbp_table( 'invoice_versions' ) ) ) ) ) );
			$must( $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE invoice_id IN ( {$inv} )", rtbp_table( 'invoice_versions' ) ) ) );
		}
		$invoices_removed = (int) $must( $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE booking_id IN ( {$in} )", rtbp_table( 'invoices' ) ) ) );
		$payments_removed = (int) $must( $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE booking_id IN ( {$in} )", rtbp_table( 'payments' ) ) ) );
		$must( $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE notable_type = 'booking' AND notable_id IN ( {$in} )", rtbp_table( 'notes' ) ) ) );
		$lines_removed = (int) $must( $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE booking_id IN ( {$in} )", rtbp_table( 'booking_rooms' ) ) ) );
		$removed       = (int) $must( $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ( {$in} )", rtbp_table( 'bookings' ) ) ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array(
			'removed'    => $removed,
			'lines'      => $lines_removed,
			'payments'   => $payments_removed,
			'invoices'   => $invoices_removed,
			'references' => array_map( 'strval', (array) $references ),
		);
	}

	/**
	 * The period condition (lines `br`, bookings `b`).
	 *
	 * @param ReportRange $range Period.
	 * @return string Prepared SQL.
	 */
	private function period( ReportRange $range ): string {
		global $wpdb;
		return 'created' === $range->mode
			? $wpdb->prepare( 'b.created_at_gmt >= %s AND b.created_at_gmt < %s', $range->from_gmt, $range->to_gmt )
			: $wpdb->prepare( 'br.start_at_gmt >= %s AND br.start_at_gmt < %s', $range->from_gmt, $range->to_gmt );
	}
}
