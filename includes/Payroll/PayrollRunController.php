<?php
/**
 * Pay runs API (M15 T5b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `payroll/runs`: list and one run (`page.payroll`); create (`payroll.run`),
 * recalculate (`payroll.run`), edit a line (`payroll.edit_line`), finalize,
 * settle, void, revert (their own keys), delete a draft (`payroll.remove`).
 * Registered only when Pro's GuardsRequests exists.
 */
class PayrollRunController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$base = 'payroll/runs';
		$run  = $base . '/(?P<id>\d+)';
		$router->get( $base, array( self::class, 'index' ) );
		$router->post( $base, array( self::class, 'store' ) );
		$router->get( $run, array( self::class, 'show' ) );
		$router->delete( $run, array( self::class, 'destroy' ) );
		$router->put( $run . '/lines/(?P<line>\d+)', array( self::class, 'line' ) );
		$router->get( $run . '/lines/(?P<line>\d+)/payslip', array( self::class, 'payslip' ) );
		foreach ( array( 'recalculate', 'finalize', 'settle', 'void', 'revert' ) as $verb ) {
			$router->post( $run . '/' . $verb, array( self::class, $verb ) );
		}
	}

	/**
	 * GET runs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			fn( $request ) => ApiResponse::success( ( new PayrollRunService() )->list( (string) $request->get_param( 'status' ), (int) ( $request->get_param( 'page' ) ?? 1 ), (int) ( $request->get_param( 'per_page' ) ?? 20 ) ) )->send()
		);
	}

	/**
	 * GET runs/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function show( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			fn( $request ) => ApiResponse::success( array( 'run' => ( new PayrollRunService() )->get( (int) $request->get_param( 'id' ) ) ) )->send()
		);
	}

	/**
	 * POST runs `{ schedule_id, period_index? }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.run',
			function ( $request ) {
				$index = $request->get_param( 'period_index' );
				return ApiResponse::created(
					array( 'run' => ( new PayrollRunService() )->create( (int) $request->get_param( 'schedule_id' ), null === $index || '' === $index ? null : (int) $index ) ),
					__( 'Pay run created.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * PUT runs/{id}/lines/{line} `{ entries?, custom?, hours? }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function line( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.edit_line',
			function ( $request ) {
				$input = array();
				foreach ( array( 'entries', 'custom', 'hours' ) as $key ) {
					if ( null !== $request->get_param( $key ) ) {
						$input[ $key ] = $request->get_param( $key );
					}
				}
				return ApiResponse::success(
					array( 'run' => ( new PayrollRunService() )->updateLine( (int) $request->get_param( 'id' ), (int) $request->get_param( 'line' ), $input ) ),
					__( 'Line saved.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * GET runs/{id}/lines/{line}/payslip?format=html|pdf: the file, base64 in the JSON
	 * envelope — through the API client, a PIN-level `page.payroll` asks for the PIN
	 * (a plain link could not send it).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function payslip( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			function ( $request ) {
				$out = ( new PayslipService() )->render( (int) $request->get_param( 'id' ), (int) $request->get_param( 'line' ), sanitize_key( (string) ( $request->get_param( 'format' ) ?? 'html' ) ) );
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
	 * POST runs/{id}/recalculate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function recalculate( WP_REST_Request $request ) {
		return $this->move( $request, 'payroll.run', fn( $s, $id ) => $s->recalculate( $id ), __( 'Pay run recalculated.', 'radius-hotel-booking-pro' ) );
	}

	/**
	 * POST runs/{id}/finalize.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function finalize( WP_REST_Request $request ) {
		return $this->move( $request, 'payroll.finalize', fn( $s, $id ) => $s->finalize( $id ), __( 'Pay run finalised.', 'radius-hotel-booking-pro' ) );
	}

	/**
	 * POST runs/{id}/settle `{ payments: { line: { method, reference? } } }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function settle( WP_REST_Request $request ) {
		return $this->move( $request, 'payroll.settle', fn( $s, $id, $r ) => $s->settle( $id, (array) ( $r->get_param( 'payments' ) ?? array() ) ), __( 'Pay run settled.', 'radius-hotel-booking-pro' ) );
	}

	/**
	 * POST runs/{id}/void `{ reason }`.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function void( WP_REST_Request $request ) {
		return $this->move( $request, 'payroll.void', fn( $s, $id, $r ) => $s->void( $id, (string) $r->get_param( 'reason' ) ), __( 'Pay run voided.', 'radius-hotel-booking-pro' ) );
	}

	/**
	 * POST runs/{id}/revert.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function revert( WP_REST_Request $request ) {
		return $this->move( $request, 'payroll.revert', fn( $s, $id ) => $s->revert( $id ), __( 'Pay run back to draft.', 'radius-hotel-booking-pro' ) );
	}

	/**
	 * DELETE runs/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function destroy( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.remove',
			function ( $request ) {
				( new PayrollRunService() )->remove( (int) $request->get_param( 'id' ) );
				return ApiResponse::success( array(), __( 'Draft pay run removed.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}

	/**
	 * A lifecycle call.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $key     Access key.
	 * @param callable        $call    `fn( PayrollRunService, int $id, WP_REST_Request ): array`.
	 * @param string          $message Success message.
	 * @return mixed
	 */
	private function move( WP_REST_Request $request, string $key, callable $call, string $message ) {
		return $this->guard(
			$request,
			$key,
			fn( $request ) => ApiResponse::success( array( 'run' => $call( new PayrollRunService(), (int) $request->get_param( 'id' ), $request ) ), $message )->send()
		);
	}
}
