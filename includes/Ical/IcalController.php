<?php
/**
 * The iCal API (M08, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Ical
 */

namespace RadiusTheme\RadiusHotelBookingPro\Ical;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Staff (`availability.manage`: the links are secrets):
 * - `GET ical/feeds` every room with its link and its channel calendars;
 * - `POST ical/imports` `{ room_id, name, url, interval_minutes }` (read at once),
 *   `PUT` / `DELETE ical/imports/{id}`, `POST ical/imports/{id}/sync`;
 * - `GET ical/exports` every room with its feed link;
 * - `POST ical/exports/{room_id}` publish, or give a new link;
 * - `DELETE ical/exports/{room_id}` stop publishing.
 *
 * Public: `GET public/ical/{token}.ics`, no sign-in. Limited per token (a
 * channel polls a few times an hour) and, for unknown tokens, per address.
 */
class IcalController {

	use GuardsRequests;

	const KEY = 'availability.manage';

	/**
	 * Reads of one feed allowed per hour.
	 */
	const FEED_LIMIT = 120;

	/**
	 * Unknown-token requests allowed per address per hour.
	 */
	const MISS_LIMIT = 30;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'ical/feeds', array( self::class, 'feeds' ) );
		$router->post( 'ical/imports', array( self::class, 'create_import' ) );
		$router->put( 'ical/imports/(?P<id>\d+)', array( self::class, 'update_import' ) ); // phpcs:ignore
		$router->delete( 'ical/imports/(?P<id>\d+)', array( self::class, 'delete_import' ) ); // phpcs:ignore
		$router->post( 'ical/imports/(?P<id>\d+)/sync', array( self::class, 'sync_import' ) ); // phpcs:ignore
		$router->get( 'ical/exports', array( self::class, 'exports' ) );
		$router->post( 'ical/exports/(?P<room_id>\d+)', array( self::class, 'publish' ) ); // phpcs:ignore
		$router->delete( 'ical/exports/(?P<room_id>\d+)', array( self::class, 'unpublish' ) ); // phpcs:ignore
		$router->get( 'public/ical/(?P<token>[a-f0-9]{48})\.ics', array( self::class, 'feed' ) ); // phpcs:ignore
	}

	/**
	 * GET ical/feeds.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function feeds( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn() => ApiResponse::success(
				array(
					'rooms'     => ( new IcalImportService() )->rooms(),
					'intervals' => IcalImportService::INTERVALS,
				)
			)->send()
		);
	}

	/**
	 * POST ical/imports.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_import( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $request ) => ApiResponse::created( ( new IcalImportService() )->create( (array) $request->get_json_params() ), __( 'Channel calendar added.', 'radius-hotel-booking-pro' ) )->send()
		);
	}

	/**
	 * PUT ical/imports/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_import( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $request ) => ApiResponse::success( ( new IcalImportService() )->update( (int) $request->get_param( 'id' ), (array) $request->get_json_params() ), __( 'Channel calendar saved.', 'radius-hotel-booking-pro' ) )->send()
		);
	}

	/**
	 * DELETE ical/imports/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_import( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $request ) => ApiResponse::success( ( new IcalImportService() )->delete( (int) $request->get_param( 'id' ) ), __( 'Channel calendar removed.', 'radius-hotel-booking-pro' ) )->send()
		);
	}

	/**
	 * POST ical/imports/{id}/sync.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function sync_import( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			function ( $request ) {
				$service = new IcalImportService();
				$id      = (int) $request->get_param( 'id' );
				$sync    = $service->sync( $id, true );
				return ApiResponse::success(
					array(
						'sync' => $sync,
						'feed' => $service->get( $id ),
					),
					$sync['ok'] ? __( 'Calendar read.', 'radius-hotel-booking-pro' ) : $sync['error']
				)->send();
			}
		);
	}

	/**
	 * GET ical/exports.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function exports( WP_REST_Request $request ) {
		return $this->guard( $request, self::KEY, fn() => ApiResponse::success( array( 'rooms' => ( new IcalExportService() )->rooms() ) )->send() );
	}

	/**
	 * POST ical/exports/{room_id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function publish( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $request ) => ApiResponse::success(
				array( 'room' => ( new IcalExportService() )->publish( (int) $request->get_param( 'room_id' ) ) ),
				__( 'Calendar link ready.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * DELETE ical/exports/{room_id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function unpublish( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $request ) => ApiResponse::success(
				array( 'room' => ( new IcalExportService() )->unpublish( (int) $request->get_param( 'room_id' ) ) ),
				__( 'Calendar no longer published.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * GET public/ical/{token}.ics: sends the calendar and exits (a REST
	 * response would be JSON).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed A REST response when refused; exits after sending the calendar.
	 */
	public function feed( WP_REST_Request $request ) {
		$result = self::answer( (string) $request->get_param( 'token' ), self::client_ip() );
		if ( 200 !== $result['status'] ) {
			return ApiResponse::error( $result['message'], $result['status'] )->send();
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="' . $result['filename'] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $result['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar built by IcalCalendar, every value escaped for iCalendar.
		exit;
	}

	/**
	 * What the public route answers, without sending it (so it can be
	 * checked from a script).
	 *
	 * @param string $token Token from the URL.
	 * @param string $ip    Client address.
	 * @return array `{ status, message?, filename?, body? }`.
	 */
	public static function answer( string $token, string $ip ): array {
		if ( ! self::within( 'miss_' . md5( $ip ), self::MISS_LIMIT, false ) ) {
			return array(
				'status'  => 429,
				'message' => __( 'Too many requests. Try again later.', 'radius-hotel-booking-pro' ),
			);
		}
		// Counted per token before any work, so a polling loop costs nothing.
		if ( ! self::within( 'feed_' . md5( $token ), self::FEED_LIMIT, true ) ) {
			return array(
				'status'  => 429,
				'message' => __( 'Too many requests. Try again later.', 'radius-hotel-booking-pro' ),
			);
		}
		$feed = ( new IcalExportService() )->feed( $token );
		if ( ! $feed ) {
			self::within( 'miss_' . md5( $ip ), self::MISS_LIMIT, true );
			return array(
				'status'  => 404,
				'message' => __( 'Calendar not found.', 'radius-hotel-booking-pro' ),
			);
		}
		return array_merge( array( 'status' => 200 ), $feed );
	}

	/**
	 * A fixed one-hour window counter. Returns whether the key is still
	 * under its limit; with `$count`, counts this request first.
	 *
	 * @param string $key   Counter key.
	 * @param int    $limit Requests per hour.
	 * @param bool   $count Count this request.
	 * @return bool
	 */
	private static function within( string $key, int $limit, bool $count ): bool {
		$name   = 'rtbp_pro_ical_' . $key;
		$now    = time();
		$window = get_transient( $name );
		if ( ! is_array( $window ) || ( $window['until'] ?? 0 ) <= $now ) {
			$window = array(
				'n'     => 0,
				'until' => $now + HOUR_IN_SECONDS,
			);
		}
		if ( $count ) {
			++$window['n'];
			set_transient( $name, $window, max( 1, $window['until'] - $now ) );
		}
		return $window['n'] <= $limit;
	}

	/**
	 * The client address. Only REMOTE_ADDR: a forwarded header is chosen by
	 * the caller, so it cannot key a limit.
	 *
	 * @return string
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : 'unknown';
	}
}
