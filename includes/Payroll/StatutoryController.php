<?php
/**
 * Statutory contributions API (M15 T6).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET payroll/statutory` (the months), `GET …/statutory/{year}/{month}`
 * (`page.payroll`); `POST …/statutory/payments` and
 * `POST …/statutory/payments/{id}/void` (`payroll.statutory_pay`).
 * Registered only when Pro's GuardsRequests exists.
 */
class StatutoryController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'payroll/statutory', array( self::class, 'index' ) );
		$router->get( 'payroll/statutory/(?P<year>\d{4})/(?P<month>\d{1,2})', array( self::class, 'show' ) );
		$router->post( 'payroll/statutory/payments', array( self::class, 'store' ) );
		$router->post( 'payroll/statutory/payments/(?P<id>\d+)/void', array( self::class, 'void' ) );
	}

	/**
	 * GET statutory.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard( $request, 'page.payroll', fn() => ApiResponse::success( array( 'months' => ( new StatutoryService() )->months() ) )->send() );
	}

	/**
	 * GET statutory/{year}/{month}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function show( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			fn( $request ) => ApiResponse::success( ( new StatutoryService() )->month( (int) $request->get_param( 'year' ), (int) $request->get_param( 'month' ) ) )->send()
		);
	}

	/**
	 * POST statutory/payments.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.statutory_pay',
			function ( $request ) {
				$input = array();
				foreach ( array( 'year', 'month', 'authority', 'code', 'amount', 'paid_on', 'method', 'reference' ) as $key ) {
					$input[ $key ] = $request->get_param( $key );
				}
				return ApiResponse::created( ( new StatutoryService() )->record( $input ), __( 'Payment recorded.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}

	/**
	 * POST statutory/payments/{id}/void `{ reason }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function void( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.statutory_pay',
			fn( $request ) => ApiResponse::success( ( new StatutoryService() )->void( (int) $request->get_param( 'id' ), (string) $request->get_param( 'reason' ) ), __( 'Payment voided.', 'radius-hotel-booking-pro' ) )->send()
		);
	}
}
