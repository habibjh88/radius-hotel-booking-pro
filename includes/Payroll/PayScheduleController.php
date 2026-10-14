<?php
/**
 * Pay schedules API (M15 T3a).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBooking\Support\Dates;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET payroll/schedules` (`page.payroll`), `POST payroll/schedules`,
 * `PUT|DELETE payroll/schedules/{id}` (`payroll.schedules`). Registered
 * only when Pro's GuardsRequests exists (Plugin::boot()).
 */
class PayScheduleController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'payroll/schedules', array( self::class, 'index' ) );
		$router->post( 'payroll/schedules', array( self::class, 'store' ) );
		$router->put( 'payroll/schedules/(?P<id>\d+)', array( self::class, 'update' ) );
		$router->delete( 'payroll/schedules/(?P<id>\d+)', array( self::class, 'destroy' ) );
	}

	/**
	 * GET payroll/schedules: the schedules and the interval list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.payroll',
			fn() => ApiResponse::success(
				array(
					'schedules' => ( new PayScheduleService() )->list(),
					'intervals' => self::intervals(),
					'today'     => Dates::today(),
				)
			)->send()
		);
	}

	/**
	 * POST payroll/schedules.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.schedules',
			fn( $request ) => ApiResponse::created(
				array( 'schedule' => PayScheduleService::shape( ( new PayScheduleService() )->create( self::fields( $request ) ), Dates::today() ) ),
				__( 'Pay schedule added.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * PUT payroll/schedules/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.schedules',
			fn( $request ) => ApiResponse::success(
				array( 'schedule' => PayScheduleService::shape( ( new PayScheduleService() )->update( (int) $request->get_param( 'id' ), self::fields( $request ) ), Dates::today() ) ),
				__( 'Pay schedule saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * DELETE payroll/schedules/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function destroy( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'payroll.schedules',
			function ( $request ) {
				( new PayScheduleService() )->delete( (int) $request->get_param( 'id' ) );
				return ApiResponse::success( array(), __( 'Pay schedule deleted.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}

	/**
	 * The intervals for the form.
	 *
	 * @return array[]
	 */
	private static function intervals(): array {
		$out = array();
		foreach ( PayPeriods::intervals() as $key => list( $label, $delay ) ) {
			$out[] = array(
				'value'     => $key,
				'label'     => $label,
				'max_delay' => $delay,
			);
		}
		return $out;
	}

	/**
	 * The fields sent.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function fields( WP_REST_Request $request ): array {
		$out = array();
		foreach ( array( 'name', 'pay_interval', 'period_end', 'next_pay_day' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$out[ $key ] = (string) $request->get_param( $key );
			}
		}
		return $out;
	}
}
