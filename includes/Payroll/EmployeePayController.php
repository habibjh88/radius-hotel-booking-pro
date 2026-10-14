<?php
/**
 * Employee pay profile API (M15 T4b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET payroll/employees/{id}/pay` (`page.payroll`) and
 * `PUT payroll/employees/{id}/pay` (`payroll.pay_profile`) — `{id}` is
 * the Pro employee id — and `PUT …/pay-items` (`payroll.pay_profile`, the
 * additional pay types). Registered only when Pro's GuardsRequests exists.
 */
class EmployeePayController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'payroll/employees/(?P<id>\d+)/pay', array( self::class, 'show' ) );
		$router->put( 'payroll/employees/(?P<id>\d+)/pay', array( self::class, 'update' ) );
		$router->put( 'payroll/employees/(?P<id>\d+)/pay-items', array( self::class, 'items' ) );
	}

	/**
	 * GET.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function show( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			fn( $request ) => ApiResponse::success( ( new EmployeePayService() )->get( (int) $request->get_param( 'id' ) ) )->send()
		);
	}

	/**
	 * PUT.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.pay_profile',
			fn( $request ) => ApiResponse::success(
				( new EmployeePayService() )->save(
					(int) $request->get_param( 'id' ),
					array(
						'pay_schedule_id' => $request->get_param( 'pay_schedule_id' ),
						'work_shift_id'   => $request->get_param( 'work_shift_id' ),
						'pay_type'        => $request->get_param( 'pay_type' ),
						'amount'          => $request->get_param( 'amount' ),
						'amount_period'   => $request->get_param( 'amount_period' ),
					)
				),
				__( 'Pay saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * PUT …/pay-items: `{ items: { type: settings } }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function items( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.pay_profile',
			fn( $request ) => ApiResponse::success(
				( new EmployeePayService() )->saveItems( (int) $request->get_param( 'id' ), (array) ( $request->get_param( 'items' ) ?? array() ) ),
				__( 'Additional pay saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}
}
