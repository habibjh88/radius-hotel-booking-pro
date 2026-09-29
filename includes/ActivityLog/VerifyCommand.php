<?php
/**
 * `wp radius-hotel-booking log:verify`.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\ActivityLog;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Checks that the activity log has not been altered (feature 14.18).
 */
class VerifyCommand {

	/**
	 * Verify the activity log's hash chain.
	 *
	 * Reports rows whose contents were changed (`edited`) and places where a
	 * row was removed or inserted (`unlinked`). Exits with an error when the
	 * chain is broken.
	 *
	 * ## OPTIONS
	 *
	 * [--show=<n>]
	 * : How many broken rows to list.
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp radius-hotel-booking log:verify
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ): void {
		unset( $args );
		$result = ChainVerifier::verify( max( 1, (int) ( $assoc_args['show'] ?? 20 ) ) );

		if ( in_array( 'purge_incomplete', $result['notes'], true ) ) {
			WP_CLI::log( 'Note: an archive is only partly removed from the log; archiving with removal again finishes it.' );
		}
		if ( $result['ok'] ) {
			WP_CLI::success(
				$result['rows']
					? sprintf( 'Chain intact: %d rows (ids %d to %d).', $result['rows'], $result['first_id'], $result['last_id'] )
					: 'The activity log is empty, and nothing is missing from it.'
			);
			return;
		}

		$problems = array(
			'head_missing'    => 'The log head record is missing.',
			'head_forged'     => 'The log head record was changed outside the plugin.',
			'anchor_mismatch' => 'The start of the log does not match the last removed archive: older entries were deleted.',
			'tail_missing'    => 'The newest entries are missing: rows were deleted from the end of the log.',
			'count_mismatch'  => 'The number of entries does not match the log head.',
		);
		foreach ( $result['problems'] as $problem ) {
			WP_CLI::warning( $problems[ $problem ] ?? $problem );
		}

		$labels = array(
			'edited'   => 'contents changed',
			'unlinked' => 'a row before it was removed or inserted',
		);
		foreach ( $result['broken'] as $row ) {
			WP_CLI::warning(
				sprintf(
					'Row %d (%s, %s): %s.',
					$row['id'],
					$row['action'],
					$row['time'],
					implode( '; ', array_map( static fn( $reason ) => $labels[ $reason ] ?? $reason, $row['reasons'] ) )
				)
			);
		}
		WP_CLI::error(
			$result['broken']
				? sprintf( 'Chain broken: %d of %d rows fail. First broken row: %d.', $result['broken_count'], $result['rows'], $result['broken'][0]['id'] )
				: 'Chain broken: see the warnings above.'
		);
	}
}
