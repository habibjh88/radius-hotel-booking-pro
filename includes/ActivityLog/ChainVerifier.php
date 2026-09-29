<?php
/**
 * Verifies the activity log's hash chain.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Walks `pro_activity_log` in id order and checks it from both ends
 * (feature 14.18, M14 review #2):
 *
 * - `edited`: a row's HMAC does not match its contents;
 * - `unlinked`: `prev_hash` is not the hash of the row before it (a row was
 *   removed or inserted);
 * - the start: the head's anchor must be the last hash of the newest purged
 *   archive ('' when nothing was purged);
 * - the end: the last row must be the head's last id and hash, and the number
 *   of rows the head's count (catches deleted recent rows and an emptied
 *   table);
 * - the head itself must carry a valid seal.
 */
final class ChainVerifier {

	const BATCH = 1000;

	/**
	 * Verify the whole chain.
	 *
	 * @param int $max_report How many broken rows to list.
	 * @return array{ok: bool, rows: int, first_id: int, last_id: int, broken_count: int, broken: array, problems: string[], notes: string[]}
	 */
	public static function verify( int $max_report = 20 ): array {
		global $wpdb;
		$table = ActivityLogger::table();

		$problems = array();
		$head     = ActivityLogger::head();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- archive index.
		$purged_hash = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT last_hash FROM %i WHERE purged = 1 ORDER BY last_id DESC LIMIT 1', ActivityArchiver::table() ) );

		if ( null === $head ) {
			$head = array(
				'last_id'   => 0,
				'last_hash' => '',
				'row_count' => 0,
				'anchor'    => '',
				'mac'       => '',
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live count.
			$any = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', ActivityArchiver::table() ) );
			if ( $any ) {
				$problems[] = 'head_missing';
			}
		} elseif ( ! hash_equals( ActivityLogger::head_mac( $head ), (string) $head['mac'] ) ) {
			$problems[] = 'head_forged';
		}

		$expected = (string) $head['anchor'];
		$after    = 0;
		$rows     = 0;
		$first    = 0;
		$last     = 0;
		$last_hsh = '';
		$count    = 0;
		$broken   = array();

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a live read of the chain.
			$batch = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id > %d ORDER BY id ASC LIMIT %d', $table, $after, self::BATCH ), ARRAY_A );

			foreach ( $batch as $row ) {
				$id       = (int) $row['id'];
				$first    = $first ? $first : $id;
				$last     = $id;
				$last_hsh = (string) $row['hash'];
				++$rows;

				$reasons = array();
				if ( ! hash_equals( (string) $row['prev_hash'], $expected ) ) {
					$reasons[] = 'unlinked';
				}
				if ( ! hash_equals( ActivityLogger::hash( $row, (string) $row['prev_hash'] ), (string) $row['hash'] ) ) {
					$reasons[] = 'edited';
				}
				if ( $reasons ) {
					++$count;
					if ( count( $broken ) < $max_report ) {
						$broken[] = array(
							'id'      => $id,
							'reasons' => $reasons,
							'action'  => (string) $row['action'],
							'time'    => (string) $row['created_at'],
						);
					}
				}

				// Continue from what the row claims, so one edit is one finding.
				$expected = (string) $row['hash'];
				$after    = $id;
			}
			$full = count( $batch ) === self::BATCH;
		} while ( $full );

		// The start: the anchor is the last hash of the newest purged archive,
		// or - while a purge is part-way - a hash inside the oldest unpurged
		// archive, which then covers the rows just before the first live row.
		// The anchor can only move through the sealed head, so this is enough.
		$notes = array();
		if ( ! hash_equals( $purged_hash, (string) $head['anchor'] ) ) {
			$next = $first ? $first : (int) $head['last_id'] + 1;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- archive index.
			$covering = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE purged = 0 AND first_id < %d AND last_id + 1 >= %d', ActivityArchiver::table(), $next, $next ) );
			if ( $covering ) {
				$notes[] = 'purge_incomplete';
			} else {
				$problems[] = 'anchor_mismatch';
			}
		}

		// The end of the chain must be the head's.
		if ( $rows ) {
			if ( $last !== (int) $head['last_id'] || ! hash_equals( (string) $head['last_hash'], $last_hsh ) ) {
				$problems[] = 'tail_missing';
			}
		} elseif ( (int) $head['row_count'] > 0 || ( '' !== (string) $head['last_hash'] && ! hash_equals( (string) $head['last_hash'], (string) $head['anchor'] ) ) ) {
			$problems[] = 'tail_missing';
		}
		if ( $rows !== (int) $head['row_count'] ) {
			$problems[] = 'count_mismatch';
		}

		return array(
			'ok'           => 0 === $count && ! $problems,
			'rows'         => $rows,
			'first_id'     => $first,
			'last_id'      => $last,
			'broken_count' => $count,
			'broken'       => $broken,
			'problems'     => array_values( array_unique( $problems ) ),
			'notes'        => $notes,
		);
	}
}
