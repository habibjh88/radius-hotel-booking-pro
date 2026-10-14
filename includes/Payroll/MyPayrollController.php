<?php
/**
 * Staff self-service API: my payslips and my time off (M15 T9).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET me/payslips`, `GET me/payslips/{id}/pdf`, `GET|POST me/time-off`,
 * `POST me/time-off/{id}/cancel` — all behind `page.my_payroll` (open by
 * default) and always on the signed-in user's own employee file.
 */
class MyPayrollController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'me/payslips', array( self::class, 'payslips' ) );
		$router->get( 'me/payslips/(?P<id>\d+)/pdf', array( self::class, 'payslip' ) );
		$router->get( 'me/time-off', array( self::class, 'time_off' ) );
		$router->post( 'me/time-off', array( self::class, 'request_time_off' ) );
		$router->post( 'me/time-off/(?P<id>\d+)/cancel', array( self::class, 'cancel_time_off' ) );
	}

	/**
	 * GET me/payslips.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function payslips( WP_REST_Request $request ) {
		return $this->guard( $request, 'page.my_payroll', fn() => ApiResponse::success( array( 'payslips' => ( new MyPayrollService() )->payslips() ) )->send() );
	}

	/**
	 * GET me/payslips/{id}/pdf: the file, base64 in the envelope (as the office's payslip).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function payslip( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.my_payroll',
			function ( $request ) {
				$out = ( new MyPayrollService() )->payslip( (int) $request->get_param( 'id' ) );
				return ApiResponse::success(
					array(
						'filename'     => (string) $out['filename'],
						'content_type' => (string) $out['content_type'],
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a binary file (PDF) in a JSON body.
						'body'         => base64_encode( (string) $out['body'] ),
					)
				)->send();
			}
		);
	}

	/**
	 * GET me/time-off `?year=`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function time_off( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.my_payroll',
			fn( $request ) => ApiResponse::success( ( new MyPayrollService() )->timeOff( (int) $request->get_param( 'year' ) ? (int) $request->get_param( 'year' ) : (int) current_time( 'Y' ) ) )->send()
		);
	}

	/**
	 * POST me/time-off `{ type, start_date, end_date?, reason? }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function request_time_off( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.my_payroll',
			fn( $request ) => ApiResponse::created(
				array(
					'request' => ( new MyPayrollService() )->request(
						array(
							'type'       => $request->get_param( 'type' ),
							'start_date' => $request->get_param( 'start_date' ),
							'end_date'   => $request->get_param( 'end_date' ),
							'reason'     => $request->get_param( 'reason' ),
						)
					),
				),
				__( 'Request sent for approval.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * POST me/time-off/{id}/cancel.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function cancel_time_off( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.my_payroll',
			fn( $request ) => ApiResponse::success( array( 'request' => ( new MyPayrollService() )->cancel( (int) $request->get_param( 'id' ) ) ), __( 'Request cancelled.', 'radius-hotel-booking-pro' ) )->send()
		);
	}
}
