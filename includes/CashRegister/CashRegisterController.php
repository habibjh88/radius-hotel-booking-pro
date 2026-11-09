<?php
/**
 * Cash register API (R3-T5 – R3-T8).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\CashRegister
 */

namespace RadiusTheme\RadiusHotelBookingPro\CashRegister;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET cash-register` (`register.use`): my open register with its live
 *   tally, and my last closed ones;
 * - `POST cash-register/open` `{ opening_float? }` and
 *   `POST cash-register/close` `{ counted_cash, note? }` (`register.use`);
 * - `GET cash-register/sessions?user_id=&status=&from=&to=&page=`
 *   (`register.manage`): every register;
 * - `GET cash-register/sessions/{id}` (`register.use`; someone else's needs
 *   `register.manage`).
 */
class CashRegisterController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'cash-register', array( self::class, 'mine' ) );
		$router->post( 'cash-register/open', array( self::class, 'open' ) );
		$router->post( 'cash-register/close', array( self::class, 'close' ) );
		$router->get( 'cash-register/sessions', array( self::class, 'index' ) );
		$router->get( 'cash-register/sessions/(?P<id>\d+)', array( self::class, 'show' ) );
	}

	/**
	 * GET cash-register.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function mine( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'register.use',
			fn() => ApiResponse::success( ( new CashRegisterService() )->mine( get_current_user_id() ) )->send()
		);
	}

	/**
	 * POST cash-register/open.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function open( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'register.use',
			fn( $request ) => ApiResponse::success(
				( new CashRegisterService() )->open( get_current_user_id(), $request->get_param( 'opening_float' ) ),
				__( 'Cash register opened.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * POST cash-register/close.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function close( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'register.use',
			fn( $request ) => ApiResponse::success(
				array( 'session' => ( new CashRegisterService() )->close( get_current_user_id(), $request->get_param( 'counted_cash' ), (string) $request->get_param( 'note' ) ) ),
				__( 'Cash register closed.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * GET cash-register/sessions.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'register.manage',
			function ( $request ) {
				$date    = static fn( $value ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ) ? (string) $value : '';
				$status  = sanitize_key( (string) $request->get_param( 'status' ) );
				$filters = array(
					'user_id' => absint( $request->get_param( 'user_id' ) ),
					'status'  => in_array( $status, array( 'open', 'closed' ), true ) ? $status : '',
					'from'    => $date( $request->get_param( 'from' ) ),
					'to'      => $date( $request->get_param( 'to' ) ),
				);
				$page    = max( 1, absint( $request->get_param( 'page' ) ) );
				$per     = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ? absint( $request->get_param( 'per_page' ) ) : 20 ) );
				$result  = ( new CashRegisterService() )->all( $filters, $page, $per );
				return ApiResponse::success(
					$result,
					null,
					array(
						'total'    => $result['total'],
						'page'     => $page,
						'per_page' => $per,
					)
				)->send();
			}
		);
	}

	/**
	 * GET cash-register/sessions/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function show( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'register.use',
			fn( $request ) => ApiResponse::success(
				array( 'session' => ( new CashRegisterService() )->get( (int) $request->get_param( 'id' ), get_current_user_id() ) )
			)->send()
		);
	}
}
