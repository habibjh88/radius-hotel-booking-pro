<?php
/**
 * Activity log REST endpoints.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\ActivityLog;

use RadiusTheme\RadiusHotelBooking\ActivityLog\ActionCatalog;
use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `activity` (list), `activity/actions` (filter data), both `page.activity_log`;
 * `activity/subject/{type}/{id}` (one record's history), guarded by that
 * record's own page key; `activity/archives` (list, archive now),
 * `activity.archive`.
 */
class ActivityController {

	use GuardsRequests;

	/**
	 * Register the routes on the free router.
	 *
	 * @param object $router The free RouteRegistrar.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'activity', array( self::class, 'index' ) );
		$router->get( 'activity/actions', array( self::class, 'actions' ) );
		$router->get( 'activity/subject/(?P<type>[a-z_]+)/(?P<id>[A-Za-z0-9_.-]+)', array( self::class, 'subject' ) ); // phpcs:ignore
		$router->get( 'activity/archives', array( self::class, 'archives' ) );
		$router->post( 'activity/archives', array( self::class, 'archive_now' ) );
	}

	/**
	 * GET activity: filtered, paginated (features 14.5–14.9).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.activity_log',
			function ( WP_REST_Request $r ) {
				$result = ActivityQuery::search( $r->get_params() );
				return ApiResponse::success(
					array( 'rows' => $result['rows'] ),
					null,
					array(
						'total'    => $result['total'],
						'page'     => $result['page'],
						'per_page' => $result['per_page'],
						'pages'    => $result['pages'],
					)
				)->send();
			}
		);
	}

	/**
	 * GET activity/actions: the catalogue, kinds and people, for the filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function actions( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.activity_log',
			function () {
				$actions = array();
				foreach ( ActionCatalog::actions() as $key => $action ) {
					$actions[] = array( 'key' => $key ) + $action;
				}
				$kinds = array();
				foreach ( ActionCatalog::kinds() as $key => $label ) {
					$kinds[] = array(
						'key'   => $key,
						'label' => $label,
					);
				}
				return ApiResponse::success(
					array(
						'actions' => $actions,
						'kinds'   => $kinds,
						'people'  => ActivityQuery::people(),
					)
				)->send();
			}
		);
	}

	/**
	 * GET activity/subject/{type}/{id}: one record's history.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function subject( WP_REST_Request $request ) {
		$type = sanitize_key( (string) $request->get_param( 'type' ) );

		return $this->guard(
			$request,
			self::subject_key( $type ),
			fn( WP_REST_Request $r ) => ApiResponse::success(
				array( 'rows' => ActivityQuery::subject( $type, (string) $r->get_param( 'id' ) ) )
			)->send()
		);
	}

	/**
	 * GET activity/archives: past archives, the next automatic run, and the
	 * archive settings (14.11, 14.13).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function archives( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'activity.archive',
			function () {
				$next = ActivityArchiver::next_run();
				return ApiResponse::success(
					array(
						'archives' => ActivityArchiver::all(),
						'next_run' => $next ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ) ) : null,
						'settings' => array(
							'archiveEvery'      => (int) ActivitySettings::get( 'archiveEvery', 12 ),
							'purgeAfterArchive' => (bool) ActivitySettings::get( 'purgeAfterArchive', false ),
						),
					)
				)->send();
			}
		);
	}

	/**
	 * POST activity/archives `{ purge? }`: archive now; purge the archived rows
	 * when asked or when the setting is on (14.12, 14.13).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function archive_now( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'activity.archive',
			function ( WP_REST_Request $r ) {
				$purge  = null === $r->get_param( 'purge' )
					? (bool) ActivitySettings::get( 'purgeAfterArchive', false )
					: rest_sanitize_boolean( $r->get_param( 'purge' ) );
				$result = ActivityArchiver::archive( get_current_user_id(), $purge );
				if ( is_wp_error( $result ) ) {
					$status = 'nothing_to_archive' === $result->get_error_code() ? 409 : ( 'archive_busy' === $result->get_error_code() ? 429 : 500 );
					return ApiResponse::error( $result->get_error_message(), $status )->withCode( (string) $result->get_error_code() )->send();
				}
				if ( $purge && '' !== ( $result['purge_error'] ?? '' ) ) {
					$message = sprintf(
						/* translators: %s: why the entries were not removed. */
						__( 'Archive written, but the entries were not removed: %s', 'radius-hotel-booking-pro' ),
						$result['purge_error']
					);
				} elseif ( ! empty( $result['purged'] ) ) {
					$message = __( 'Archive written and the archived entries removed.', 'radius-hotel-booking-pro' );
				} else {
					$message = __( 'Archive written.', 'radius-hotel-booking-pro' );
				}
				return ApiResponse::created( array( 'archive' => $result ), $message )->send();
			}
		);
	}

	/**
	 * The page key that guards a subject's history: whoever may open the
	 * record may read its history. Unknown types need the log page.
	 *
	 * @param string $type Subject type.
	 * @return string
	 */
	public static function subject_key( string $type ): string {
		$keys = array(
			'booking'  => 'page.bookings',
			'guest'    => 'page.guests',
			'room'     => 'page.rooms',
			'floor'    => 'page.rooms',
			'rate'     => 'page.rates',
			'employee' => 'page.staff',
		);

		/**
		 * Filters the access key that guards a record's history.
		 *
		 * @param string $key  Access key.
		 * @param string $type Subject type.
		 */
		return (string) apply_filters( 'rtbp_pro_activity_subject_key', $keys[ $type ] ?? 'page.activity_log', $type );
	}
}
