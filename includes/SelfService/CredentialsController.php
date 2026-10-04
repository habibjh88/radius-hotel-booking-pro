<?php
/**
 * My password and PIN API (M16).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\SelfService
 */

namespace RadiusTheme\RadiusHotelBookingPro\SelfService;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `POST me/password` and `POST me/pin` — any signed-in dashboard user, on
 * their own account only (never an id from the request).
 */
class CredentialsController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->post( 'me/password', array( self::class, 'password' ) );
		$router->post( 'me/pin', array( self::class, 'pin' ) );
	}

	/**
	 * POST me/password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function password( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			null,
			fn( $request ) => ApiResponse::success(
				( new CredentialsService() )->change_password( get_current_user_id(), (array) $request->get_json_params() ),
				__( 'Your password is changed. You are signed out everywhere else.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * POST me/pin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function pin( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			null,
			fn( $request ) => ApiResponse::success(
				array( 'security' => ( new CredentialsService() )->change_pin( get_current_user_id(), (array) $request->get_json_params() ) ),
				__( 'Your PIN is changed.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}
}
