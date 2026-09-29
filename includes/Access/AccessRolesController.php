<?php
/**
 * Access roles REST endpoints.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `access/roles` CRUD (`access.manage`).
 */
class AccessRolesController {

	use GuardsRequests;

	/**
	 * Register the routes on the free router.
	 *
	 * @param object $router The free RouteRegistrar.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'access/roles', array( self::class, 'index' ) );
		$router->post( 'access/roles', array( self::class, 'store' ) );
		$router->get( 'access/roles/(?P<id>\d+)', array( self::class, 'show' ) ); // phpcs:ignore
		$router->put( 'access/roles/(?P<id>\d+)', array( self::class, 'update' ) ); // phpcs:ignore
		$router->delete( 'access/roles/(?P<id>\d+)', array( self::class, 'destroy' ) ); // phpcs:ignore
	}

	/**
	 * GET access/roles.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard( $request, 'access.manage', fn() => ApiResponse::success( array( 'roles' => ( new AccessRoleService() )->all() ) )->send() );
	}

	/**
	 * GET access/roles/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function show( WP_REST_Request $request ) {
		return $this->guard( $request, 'access.manage', fn( $r ) => ApiResponse::success( array( 'role' => ( new AccessRoleService() )->get( (int) $r['id'] ) ) )->send() );
	}

	/**
	 * POST access/roles `{ name, description, levels }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'access.manage',
			fn( $r ) => ApiResponse::created(
				array( 'role' => ( new AccessRoleService() )->create( (array) $r->get_json_params() ) ),
				__( 'Access role created.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * PUT access/roles/{id}: only the fields given.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'access.manage',
			fn( $r ) => ApiResponse::success(
				array( 'role' => ( new AccessRoleService() )->update( (int) $r['id'], (array) $r->get_json_params() ) ),
				__( 'Access role saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * DELETE access/roles/{id}: its holders fall back to the defaults.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function destroy( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'access.manage',
			fn( $r ) => ApiResponse::success(
				array( 'unassigned' => count( ( new AccessRoleService() )->delete( (int) $r['id'] ) ) ),
				__( 'Access role deleted.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}
}
