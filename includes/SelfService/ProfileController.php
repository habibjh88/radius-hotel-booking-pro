<?php
/**
 * My profile API (M16).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\SelfService
 */

namespace RadiusTheme\RadiusHotelBookingPro\SelfService;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET me/profile` and `PUT me/profile` — any signed-in dashboard user, on
 * their own profile only (the user is always the signed-in one, never an id
 * from the request); the field map decides what may change.
 */
class ProfileController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'me/profile', array( self::class, 'show' ) );
		$router->put( 'me/profile', array( self::class, 'update' ) );
	}

	/**
	 * GET me/profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function show( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			null,
			fn() => ApiResponse::success( array( 'profile' => ( new ProfileService() )->get( get_current_user_id() ) ) )->send()
		);
	}

	/**
	 * PUT me/profile.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			null,
			fn( $request ) => ApiResponse::success(
				array( 'profile' => ( new ProfileService() )->update( get_current_user_id(), (array) $request->get_json_params() ) ),
				__( 'Your profile is saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}
}
