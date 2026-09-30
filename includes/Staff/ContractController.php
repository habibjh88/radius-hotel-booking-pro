<?php
/**
 * Employment contracts API (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET employees/{id}/contracts` (`page.staff`): the history, newest first;
 * - `POST employees/{id}/contracts`, `PUT employees/{id}/contracts/{contract}`,
 *   `POST …/{contract}/terminate { end_date?, reason? }` and
 *   `DELETE …/{contract}?reason=` (`staff.contracts`).
 */
class ContractController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$base = 'employees/(?P<id>\d+)/contracts';
		$router->get( $base, array( self::class, 'index' ) );
		$router->post( $base, array( self::class, 'store' ) );
		$router->put( $base . '/(?P<contract>\d+)', array( self::class, 'update' ) );
		$router->post( $base . '/(?P<contract>\d+)/terminate', array( self::class, 'terminate' ) );
		$router->delete( $base . '/(?P<contract>\d+)', array( self::class, 'destroy' ) );
	}

	/**
	 * GET employees/{id}/contracts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.staff',
			fn( $request ) => ApiResponse::success(
				array(
					'contracts' => array_map( array( self::class, 'shape' ), ( new ContractService() )->forEmployee( (int) $request->get_param( 'id' ) ) ),
					'job_types' => ContractService::jobTypes(),
				)
			)->send()
		);
	}

	/**
	 * POST employees/{id}/contracts.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.contracts',
			fn( $request ) => ApiResponse::created(
				array( 'contract' => self::shape( ( new ContractService() )->create( (int) $request->get_param( 'id' ), self::fields( $request ) ) ) ),
				__( 'Contract added.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * PUT employees/{id}/contracts/{contract}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.contracts',
			fn( $request ) => ApiResponse::success(
				array( 'contract' => self::shape( ( new ContractService() )->update( (int) $request->get_param( 'id' ), (int) $request->get_param( 'contract' ), self::fields( $request ) ) ) ),
				__( 'Contract saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * POST employees/{id}/contracts/{contract}/terminate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function terminate( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.contracts',
			function ( $request ) {
				$body = (array) $request->get_json_params();
				return ApiResponse::success(
					array(
						'contract' => self::shape(
							( new ContractService() )->terminate(
								(int) $request->get_param( 'id' ),
								(int) $request->get_param( 'contract' ),
								(string) ( $body['end_date'] ?? '' ),
								(string) ( $body['reason'] ?? '' )
							)
						),
					),
					__( 'Contract ended.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * DELETE employees/{id}/contracts/{contract}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function destroy( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.contracts',
			function ( $request ) {
				( new ContractService() )->delete( (int) $request->get_param( 'id' ), (int) $request->get_param( 'contract' ), (string) $request->get_param( 'reason' ) );
				return ApiResponse::success( array(), __( 'Contract deleted.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}

	/**
	 * A contract for the API.
	 *
	 * @param EmployeeContract $contract Contract.
	 * @return array
	 */
	public static function shape( EmployeeContract $contract ): array {
		$types = ContractService::jobTypes();
		return array(
			'id'             => (int) $contract->id,
			'status'         => (string) $contract->status,
			'job_type'       => (string) $contract->job_type,
			'job_type_label' => (string) ( $types[ $contract->job_type ] ?? $contract->job_type ),
			'job_title'      => (string) $contract->job_title,
			'department'     => (string) $contract->department,
			'start_date'     => $contract->start_date ? substr( (string) $contract->start_date, 0, 10 ) : null,
			'end_date'       => $contract->end_date ? substr( (string) $contract->end_date, 0, 10 ) : null,
			'notes'          => (string) $contract->notes,
		);
	}

	/**
	 * The contract fields of the body.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function fields( WP_REST_Request $request ): array {
		return array_intersect_key(
			(array) $request->get_json_params(),
			array_flip( array( 'status', 'job_type', 'job_title', 'department', 'start_date', 'end_date', 'notes' ) )
		);
	}
}
