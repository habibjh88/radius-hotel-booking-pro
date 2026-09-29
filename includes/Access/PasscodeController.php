<?php
/**
 * Passcode REST endpoints.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET access/passcode` and `POST access/unlock` (any dashboard user), `POST access/users/{id}/pin` and
 * `POST access/fallback-pin` (`access.manage`). No response ever carries a PIN
 * or a hash.
 */
class PasscodeController {

	use GuardsRequests;

	/**
	 * Register the routes on the free router.
	 *
	 * @param object $router The free RouteRegistrar.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'access/passcode', array( self::class, 'status' ) );
		$router->post( 'access/unlock', array( self::class, 'unlock' ) );
		$router->post( 'access/users/(?P<id>\d+)/pin', array( self::class, 'set_pin' ) ); // phpcs:ignore
		$router->post( 'access/fallback-pin', array( self::class, 'set_fallback' ) );
	}

	/**
	 * GET access/passcode: the current user's PIN status and the passcode
	 * settings the dialog needs. Flags only; never a PIN or a hash.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function status( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			null,
			function () {
				$user_id = get_current_user_id();
				$until   = PasscodeService::locked_until( $user_id );
				return ApiResponse::success(
					array(
						'has_pin'          => PasscodeService::has_pin( $user_id ),
						'has_fallback'     => PasscodeService::has_fallback(),
						'fallback_enabled' => (bool) AccessSettings::get( 'fallbackEnabled', true ),
						'can_enter_pin'    => PasscodeService::can_enter_pin( $user_id ),
						'locked_until'     => $until ? gmdate( 'c', $until ) : null,
						'scope'            => AccessSettings::global_scope() ? 'global' : 'per_key',
						'validity'         => (int) AccessSettings::get( 'passcodeValidity', 5 ),
					)
				)->send();
			}
		);
	}

	/**
	 * POST access/unlock `{ pin, keys }` (or `key`) → `{ token, expires_at, scope, keys }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function unlock( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			null,
			function ( WP_REST_Request $request ) {
				// `keys` (every key a refused request listed) or a single `key`.
				$keys   = $request->get_param( 'keys' );
				$keys   = is_array( $keys ) ? $keys : array( $request->get_param( 'key' ) );
				$result = PasscodeService::unlock(
					get_current_user_id(),
					(string) $request->get_param( 'pin' ),
					array_map( static fn( $key ) => sanitize_text_field( (string) $key ), $keys )
				);
				return is_wp_error( $result )
					? $this->error( $result )
					: ApiResponse::success( $result, __( 'Unlocked.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}

	/**
	 * POST access/users/{id}/pin `{ pin }`; `pin: null` removes it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function set_pin( WP_REST_Request $request ) {
		// An administrator may always reset their own PIN, without entering it:
		// they are never locked out (review #6). Everyone else needs access.manage.
		$self_admin = current_user_can( 'manage_options' ) && (int) $request->get_param( 'id' ) === get_current_user_id();

		return $this->guard(
			$request,
			$self_admin ? null : 'access.manage',
			function ( WP_REST_Request $request ) {
				$user_id = (int) $request->get_param( 'id' );
				if ( ! get_user_by( 'id', $user_id ) ) {
					return ApiResponse::notFound( __( 'This staff member does not exist.', 'radius-hotel-booking-pro' ) )->send();
				}
				if ( user_can( $user_id, 'manage_options' ) && ! current_user_can( 'manage_options' ) ) {
					return $this->error( new WP_Error( 'admin_target', __( 'Only an administrator can change an administrator’s PIN.', 'radius-hotel-booking-pro' ) ) );
				}

				$pin = self::pin_param( $request );
				if ( is_wp_error( $pin ) ) {
					return $this->error( $pin );
				}
				$result = PasscodeService::set_pin( $user_id, $pin );
				if ( is_wp_error( $result ) ) {
					return $this->error( $result );
				}

				return ApiResponse::success(
					array( 'has_pin' => PasscodeService::has_pin( $user_id ) ),
					null === $pin ? __( 'PIN removed.', 'radius-hotel-booking-pro' ) : __( 'PIN saved.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * POST access/fallback-pin `{ pin }`; `pin: null` removes it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function set_fallback( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'access.manage',
			function ( WP_REST_Request $request ) {
				$pin = self::pin_param( $request );
				if ( is_wp_error( $pin ) ) {
					return $this->error( $pin );
				}
				$result = PasscodeService::set_fallback( $pin );
				if ( is_wp_error( $result ) ) {
					return $this->error( $result );
				}

				return ApiResponse::success(
					array( 'has_fallback' => PasscodeService::has_fallback() ),
					null === $pin ? __( 'Fallback PIN removed.', 'radius-hotel-booking-pro' ) : __( 'Fallback PIN saved.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * The `pin` of a set/remove request. Removing needs an explicit
	 * `"pin": null`; a missing field is an error, never a removal (review #5).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string|null|WP_Error
	 */
	private static function pin_param( WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();
		if ( ! array_key_exists( 'pin', $body ) ) {
			return new WP_Error( 'pin_format', __( 'Send a PIN, or null to remove it.', 'radius-hotel-booking-pro' ) );
		}
		return null === $body['pin'] ? null : (string) $body['pin'];
	}

	/**
	 * A service error as the API envelope.
	 *
	 * @param WP_Error $error Error.
	 * @return mixed
	 */
	private function error( WP_Error $error ) {
		$statuses = array(
			'pin_format'  => 422,
			'invalid_key' => 422,
			'pin_not_set' => 409,
			'pin_locked'  => 429,
			'pin_busy'    => 429,
		);
		$code     = (string) $error->get_error_code();
		$response = ApiResponse::error( $error->get_error_message(), $statuses[ $code ] ?? 403 )->withCode( $code );

		// withData() arrives with free M13 T2; without it the code and message still reach the client.
		if ( method_exists( $response, 'withData' ) ) {
			$response->withData( (array) $error->get_error_data() );
		}

		return $response->send();
	}
}
