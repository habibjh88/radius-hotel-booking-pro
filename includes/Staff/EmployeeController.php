<?php
/**
 * Employees API (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET employees?q=&active=&page=&per_page=` and `GET employees/{id}` (`page.staff`);
 * - `POST employees` (`staff.create`): the user and the file, plus access role and PIN
 *   (these two also need `access.manage`);
 * - `PUT employees/{id}` (`staff.edit`): only the fields sent;
 * - `POST employees/{id}/deactivate` · `/activate` `{ reason? }` (`staff.deactivate`);
 * - `PUT employees/{id}/account` `{ password?, pin?, remove_pin?, access_role? }` (role
 *   and PIN also need `access.manage`; never your own role; never an administrator's
 *   account unless you are one) and
 *   `POST employees/{id}/password-link` (`staff.edit`);
 * - `GET employees/options` (`page.staff`): access roles and the lists for the forms;
 * - `GET employees/{id}/identifiers` (`staff.view_ids`, logged): the only way to the
 *   full CNPS and tax numbers.
 */
class EmployeeController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'employees', array( self::class, 'index' ) );
		$router->get( 'employees/options', array( self::class, 'options' ) );
		$router->put( 'employees/(?P<id>\d+)/account', array( self::class, 'account' ) ); // phpcs:ignore
		$router->post( 'employees/(?P<id>\d+)/password-link', array( self::class, 'password_link' ) ); // phpcs:ignore
		$router->post( 'employees', array( self::class, 'store' ) );
		$router->get( 'employees/(?P<id>\d+)/identifiers', array( self::class, 'identifiers' ) ); // phpcs:ignore
		$router->get( 'employees/(?P<id>\d+)', array( self::class, 'show' ) ); // phpcs:ignore
		$router->put( 'employees/(?P<id>\d+)', array( self::class, 'update' ) ); // phpcs:ignore
		$router->post( 'employees/(?P<id>\d+)/deactivate', array( self::class, 'deactivate' ) ); // phpcs:ignore
		$router->post( 'employees/(?P<id>\d+)/activate', array( self::class, 'activate' ) ); // phpcs:ignore
	}

	/**
	 * GET employees.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.staff',
			function ( $request ) {
				$page = max( 1, absint( $request->get_param( 'page' ) ) );
				$per  = absint( $request->get_param( 'per_page' ) );
				$data = ( new EmployeeService() )->page(
					(string) $request->get_param( 'q' ),
					sanitize_key( (string) $request->get_param( 'active' ) ),
					$page,
					$per ? $per : 20
				);
				return ApiResponse::success(
					array( 'employees' => array_map( array( EmployeeResource::class, 'summary' ), $data['items'] ) ),
					null,
					array(
						'total' => $data['total'],
						'page'  => $page,
					)
				)->send();
			}
		);
	}

	/**
	 * GET employees/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function show( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.staff',
			fn( $request ) => ApiResponse::success( array( 'employee' => EmployeeResource::detail( ( new EmployeeService() )->get( (int) $request->get_param( 'id' ) ) ) ) )->send()
		);
	}

	/**
	 * GET employees/{id}/identifiers.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function identifiers( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.view_ids',
			fn( $request ) => ApiResponse::success( ( new EmployeeService() )->identifiers( (int) $request->get_param( 'id' ) ) )->send()
		);
	}

	/**
	 * POST employees.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::withAccessManage( 'staff.create', $request ),
			fn( $request ) => ApiResponse::created(
				array( 'employee' => EmployeeResource::detail( ( new EmployeeService() )->create( (array) $request->get_json_params() ) ) ),
				__( 'Employee added.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * PUT employees/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.edit',
			fn( $request ) => ApiResponse::success(
				array( 'employee' => EmployeeResource::detail( ( new EmployeeService() )->update( (int) $request->get_param( 'id' ), self::fields( $request ) ) ) ),
				__( 'Employee saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * POST employees/{id}/deactivate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function deactivate( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.deactivate',
			fn( $request ) => ApiResponse::success(
				array( 'employee' => EmployeeResource::detail( ( new EmployeeService() )->deactivate( (int) $request->get_param( 'id' ), (string) ( $request->get_json_params()['reason'] ?? '' ) ) ) ),
				__( 'Employee deactivated: they can no longer sign in.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * POST employees/{id}/activate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function activate( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.deactivate',
			fn( $request ) => ApiResponse::success(
				array( 'employee' => EmployeeResource::detail( ( new EmployeeService() )->activate( (int) $request->get_param( 'id' ), (string) ( $request->get_json_params()['reason'] ?? '' ) ) ) ),
				__( 'Employee reactivated.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * GET employees/options.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function options( WP_REST_Request $request ) {
		return $this->guard( $request, 'page.staff', fn() => ApiResponse::success( EmployeeService::options() )->send() );
	}

	/**
	 * PUT employees/{id}/account.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function account( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::withAccessManage( 'staff.edit', $request ),
			fn( $request ) => ApiResponse::success(
				array( 'employee' => EmployeeResource::detail( ( new EmployeeService() )->account( (int) $request->get_param( 'id' ), (array) $request->get_json_params() ) ) ),
				__( 'Account saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * POST employees/{id}/password-link.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function password_link( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.edit',
			function ( $request ) {
				( new EmployeeService() )->sendPasswordLink( (int) $request->get_param( 'id' ) );
				return ApiResponse::success( array(), __( 'Password link sent.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}

	/**
	 * The keys a request needs: assigning an access role or a PIN also needs
	 * M13's `access.manage` (checked here so a passcode-level key asks for the
	 * PIN like any other).
	 *
	 * @param string          $key     The route's own key.
	 * @param WP_REST_Request $request Request.
	 * @return string[]
	 */
	private static function withAccessManage( string $key, WP_REST_Request $request ): array {
		$body  = (array) $request->get_json_params();
		$keys  = array( $key );
		// On the account, sending `access_role: null` removes the role; on create, an empty one means none.
		$role  = 'staff.edit' === $key ? array_key_exists( 'access_role', $body ) : ! empty( $body['access_role'] );
		$pin   = isset( $body['pin'] ) && '' !== trim( (string) $body['pin'] );
		$touch = $role || $pin || ! empty( $body['remove_pin'] );
		if ( $touch ) {
			$keys[] = 'access.manage';
		}
		return $keys;
	}

	/**
	 * The file fields of an update body (only those present; the account
	 * fields are T3a's).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function fields( WP_REST_Request $request ): array {
		return array_intersect_key(
			(array) $request->get_json_params(),
			array_flip( array( 'first_name', 'last_name', 'dob', 'address', 'marital_status', 'num_children', 'phone', 'email', 'emergency', 'cnps_no', 'tax_no', 'department', 'job_title', 'shift' ) )
		);
	}
}
