<?php
/**
 * Time off API (M15 T8a).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET payroll/time-off` (`page.payroll`: the queue and the history);
 * `POST payroll/time-off` (a request for an employee),
 * `POST …/time-off/{id}/approve|decline` (`timeoff.approve`). The staff's own
 * requests are T9's (`me/time-off`). Registered only when Pro's
 * GuardsRequests exists.
 */
class TimeOffController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'payroll/time-off', array( self::class, 'index' ) );
		$router->get( 'payroll/time-off/balance', array( self::class, 'balance' ) );
		$router->post( 'payroll/time-off', array( self::class, 'store' ) );
		$router->post( 'payroll/time-off/(?P<id>\d+)/approve', array( self::class, 'approve' ) );
		$router->post( 'payroll/time-off/(?P<id>\d+)/decline', array( self::class, 'decline' ) );
	}

	/**
	 * GET time-off `?status=&employee_id=&year=`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			fn( $request ) => ApiResponse::success(
				array(
					'requests' => ( new TimeOffService() )->list(
						array(
							'status'      => (string) $request->get_param( 'status' ),
							'employee_id' => (int) $request->get_param( 'employee_id' ),
							'year'        => (int) $request->get_param( 'year' ),
						)
					),
					'types'    => TimeOffService::types(),
					'quota'    => (int) PayrollSettings::get( 'vacationDaysYear', 0 ),
					'today'    => current_time( 'Y-m-d' ),
					'employees' => TimeOffService::employeeOptions(),
				)
			)->send()
		);
	}

	/**
	 * GET time-off/balance `?employee_id=&year=`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function balance( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			fn( $request ) => ApiResponse::success(
				( new TimeOffService() )->balance(
					(int) $request->get_param( 'employee_id' ),
					(int) $request->get_param( 'year' ) ? (int) $request->get_param( 'year' ) : (int) current_time( 'Y' )
				)
			)->send()
		);
	}

	/**
	 * POST time-off `{ employee_id, type, start_date, end_date?, reason? }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'timeoff.approve',
			fn( $request ) => ApiResponse::created(
				array(
					'request' => ( new TimeOffService() )->request(
						(int) $request->get_param( 'employee_id' ),
						array(
							'type'       => $request->get_param( 'type' ),
							'start_date' => $request->get_param( 'start_date' ),
							'end_date'   => $request->get_param( 'end_date' ),
							'reason'     => $request->get_param( 'reason' ),
						)
					),
				),
				__( 'Request added.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * POST time-off/{id}/approve `{ note? }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function approve( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'timeoff.approve',
			fn( $request ) => ApiResponse::success( array( 'request' => ( new TimeOffService() )->approve( (int) $request->get_param( 'id' ), (string) $request->get_param( 'note' ) ) ), __( 'Time off approved.', 'radius-hotel-booking-pro' ) )->send()
		);
	}

	/**
	 * POST time-off/{id}/decline `{ note? }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function decline( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'timeoff.approve',
			fn( $request ) => ApiResponse::success( array( 'request' => ( new TimeOffService() )->decline( (int) $request->get_param( 'id' ), (string) $request->get_param( 'note' ) ) ), __( 'Time off declined.', 'radius-hotel-booking-pro' ) )->send()
		);
	}
}
