<?php
/**
 * Per-person access REST endpoints.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `access/users` (list, one person, assign a role, overrides and profile
 * fields), `access/profile-fields`, `access/me/profile-fields`, and
 * `access/reset-overrides` (`access.manage` plus `access.reset_overrides`,
 * which defaults to passcode).
 */
class UserAccessController {

	use GuardsRequests;

	/**
	 * Register the routes on the free router.
	 *
	 * @param object $router The free RouteRegistrar.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'access/users', array( self::class, 'index' ) );
		$router->get( 'access/users/(?P<id>\d+)', array( self::class, 'show' ) ); // phpcs:ignore
		$router->put( 'access/users/(?P<id>\d+)', array( self::class, 'update' ) ); // phpcs:ignore
		$router->post( 'access/reset-overrides', array( self::class, 'reset' ) );
		$router->get( 'access/profile-fields', array( self::class, 'profile_fields' ) );
		$router->get( 'access/me/profile-fields', array( self::class, 'my_profile_fields' ) );
	}

	/**
	 * GET access/users.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard( $request, 'access.manage', fn() => ApiResponse::success( array( 'users' => ( new UserAccessService() )->users() ) )->send() );
	}

	/**
	 * GET access/users/{id}: role, overrides, effective map with sources.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function show( WP_REST_Request $request ) {
		return $this->guard( $request, 'access.manage', fn( $r ) => ApiResponse::success( ( new UserAccessService() )->profile( (int) $r['id'] ) )->send() );
	}

	/**
	 * PUT access/users/{id} `{ access_role?, overrides? }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'access.manage',
			function ( $r ) {
				// Only an administrator changes an administrator (review #6).
				if ( user_can( (int) $r['id'], 'manage_options' ) && ! current_user_can( 'manage_options' ) ) {
					return ApiResponse::forbidden( __( 'Only an administrator can change an administrator’s permissions.', 'radius-hotel-booking-pro' ) )
						->withCode( 'admin_target' )
						->send();
				}
				return ApiResponse::success(
					( new UserAccessService() )->update( (int) $r['id'], (array) $r->get_json_params() ),
					__( 'Permissions saved.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * GET access/profile-fields: the fields and the global defaults, for the
	 * editors (Settings → Passcodes, roles, staff).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function profile_fields( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'access.manage',
			fn() => ApiResponse::success(
				array(
					'fields'   => ProfileFields::describe(),
					'defaults' => (object) ProfileFields::defaults(),
				)
			)->send()
		);
	}

	/**
	 * GET access/me/profile-fields: which fields the current user may change
	 * on their own profile (M16 reads it; the M16 endpoints enforce it).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function my_profile_fields( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			null,
			function () {
				$labels = array_column( ProfileFields::describe(), 'label', 'key' );
				$fields = array_map(
					static fn( $row ) => $row + array( 'label' => $labels[ $row['key'] ] ?? $row['key'] ),
					ProfileFields::resolve( get_current_user_id() )
				);
				return ApiResponse::success( array( 'fields' => $fields ) )->send();
			}
		);
	}

	/**
	 * POST access/reset-overrides `{ user_id? }`: one person, or everyone.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function reset( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			array( 'access.manage', 'access.reset_overrides' ),
			function ( $r ) {
				$user_id = $r->get_param( 'user_id' );
				$count   = ( new UserAccessService() )->resetOverrides( null === $user_id || '' === $user_id ? null : (int) $user_id );
				return ApiResponse::success(
					array( 'users' => $count ),
					__( 'Personal overrides removed.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}
}
