<?php
/**
 * Scheduled export endpoints (M11 T4).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Export
 */

namespace RadiusTheme\RadiusHotelBookingPro\Export;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET exports/schedules` (`page.exports`); `POST exports/schedules`,
 * `PUT` / `DELETE exports/schedules/{id}` (`exports.generate`: whoever may
 * make export files may schedule them).
 */
class ScheduleController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router Free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'exports/schedules', array( self::class, 'index' ) );
		$router->post( 'exports/schedules', array( self::class, 'store' ) );
		$router->put( 'exports/schedules/(?P<id>\d+)', array( self::class, 'update' ) ); // phpcs:ignore
		$router->delete( 'exports/schedules/(?P<id>\d+)', array( self::class, 'destroy' ) ); // phpcs:ignore
	}

	/**
	 * Request fields (scalars only).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function input( WP_REST_Request $request ): array {
		return array_map( 'sanitize_text_field', array_filter( (array) $request->get_params(), 'is_scalar' ) );
	}

	/**
	 * GET exports/schedules.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard( $request, 'page.exports', fn() => ApiResponse::success( array( 'items' => ( new ScheduleService() )->all() ) )->send() );
	}

	/**
	 * POST exports/schedules.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard( $request, 'exports.generate', fn( $request ) => ApiResponse::created( ( new ScheduleService() )->create( self::input( $request ) ) )->send() );
	}

	/**
	 * PUT exports/schedules/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update( WP_REST_Request $request ) {
		return $this->guard( $request, 'exports.generate', fn( $request ) => ApiResponse::success( ( new ScheduleService() )->update( (int) $request->get_param( 'id' ), self::input( $request ) ) )->send() );
	}

	/**
	 * DELETE exports/schedules/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function destroy( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'exports.generate',
			function ( $request ) {
				( new ScheduleService() )->delete( (int) $request->get_param( 'id' ) );
				return ApiResponse::success( array(), __( 'The scheduled export was removed.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}
}
