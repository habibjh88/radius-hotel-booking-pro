<?php
/**
 * Work shifts API (M15 T3b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET payroll/shifts` (`page.payroll`), `POST payroll/shifts`,
 * `PUT|DELETE payroll/shifts/{id}` (`payroll.shifts`). Registered only
 * when Pro's GuardsRequests exists (Plugin::boot()).
 */
class WorkShiftController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'payroll/shifts', array( self::class, 'index' ) );
		$router->post( 'payroll/shifts', array( self::class, 'store' ) );
		$router->put( 'payroll/shifts/(?P<id>\d+)', array( self::class, 'update' ) );
		$router->delete( 'payroll/shifts/(?P<id>\d+)', array( self::class, 'destroy' ) );
	}

	/**
	 * GET payroll/shifts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			fn() => ApiResponse::success( array( 'shifts' => ( new WorkShiftService() )->list() ) )->send()
		);
	}

	/**
	 * POST payroll/shifts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.shifts',
			function ( $request ) {
				$service = new WorkShiftService();
				$shift   = $service->create( self::fields( $request ) );
				return ApiResponse::created(
					array( 'shift' => WorkShiftService::shape( $shift, $service->days( (int) $shift->id ) ) ),
					__( 'Work shift added.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * PUT payroll/shifts/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.shifts',
			function ( $request ) {
				$service = new WorkShiftService();
				$shift   = $service->update( (int) $request->get_param( 'id' ), self::fields( $request ) );
				return ApiResponse::success(
					array( 'shift' => WorkShiftService::shape( $shift, $service->days( (int) $shift->id ) ) ),
					__( 'Work shift saved.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * DELETE payroll/shifts/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function destroy( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.shifts',
			function ( $request ) {
				( new WorkShiftService() )->delete( (int) $request->get_param( 'id' ) );
				return ApiResponse::success( array(), __( 'Work shift deleted.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}

	/**
	 * The fields sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function fields( WP_REST_Request $request ): array {
		$out = array();
		foreach ( array( 'name', 'description' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$out[ $key ] = (string) $request->get_param( $key );
			}
		}
		if ( is_array( $request->get_param( 'days' ) ) ) {
			$out['days'] = $request->get_param( 'days' );
		}
		return $out;
	}
}
