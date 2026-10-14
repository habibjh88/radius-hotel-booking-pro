<?php
/**
 * Deductions and garnishments API (M15 T4d).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET payroll/employees/{id}/deductions` (`page.payroll`);
 * `POST …/deductions`, `PUT|DELETE …/deductions/{d}` (`payroll.pay_profile`).
 * Registered only when Pro's GuardsRequests exists.
 */
class DeductionController {

	use GuardsRequests;

	private const FIELDS = array( 'kind', 'type', 'name', 'authority', 'remittance_day', 'calculation', 'start_date', 'end_date', 'amount_type', 'amount', 'percent', 'employer_amount_type', 'employer_amount', 'employer_percent', 'total_amount', 'max_percent', 'status' );

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$base = 'payroll/employees/(?P<id>\d+)/deductions';
		$router->get( $base, array( self::class, 'index' ) );
		$router->post( $base, array( self::class, 'store' ) );
		$router->put( $base . '/(?P<deduction>\d+)', array( self::class, 'update' ) );
		$router->delete( $base . '/(?P<deduction>\d+)', array( self::class, 'destroy' ) );
	}

	/**
	 * GET.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			fn( $request ) => ApiResponse::success( array( 'deductions' => ( new DeductionService() )->list( (int) $request->get_param( 'id' ) ) ) )->send()
		);
	}

	/**
	 * POST.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.pay_profile',
			fn( $request ) => ApiResponse::created(
				array( 'deduction' => ( new DeductionService() )->create( (int) $request->get_param( 'id' ), self::fields( $request ) ) ),
				__( 'Deduction added.', 'radius-hotel-booking-pro' )
			)->send()
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
				array( 'deduction' => ( new DeductionService() )->update( (int) $request->get_param( 'id' ), (int) $request->get_param( 'deduction' ), self::fields( $request ) ) ),
				__( 'Deduction saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * DELETE.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function destroy( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.pay_profile',
			function ( $request ) {
				( new DeductionService() )->delete( (int) $request->get_param( 'id' ), (int) $request->get_param( 'deduction' ) );
				return ApiResponse::success( array(), __( 'Deduction deleted.', 'radius-hotel-booking-pro' ) )->send();
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
		foreach ( self::FIELDS as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$out[ $key ] = $request->get_param( $key );
			}
		}
		return $out;
	}
}
