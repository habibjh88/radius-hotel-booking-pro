<?php
/**
 * Employees API (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use RadiusTheme\RadiusHotelBookingPro\Payroll\EmployeePayService;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * - `GET employees?q=&active=&page=&per_page=` and `GET employees/{id}` (`page.staff`);
 * - `POST employees` (`staff.create`): the user and the file, plus access role and PIN
 *   (these two also need `access.manage`);
 * - `PUT employees/{id}` (`staff.edit`): only the fields sent;
 * - on both, `work_shift_id` (Payroll on) sets the payroll work shift and also
 *   needs `payroll.pay_profile`;
 * - `POST employees/{id}/deactivate` · `/activate` `{ reason? }` (`staff.deactivate`);
 * - `PUT employees/{id}/account` `{ password?, pin?, remove_pin?, access_role? }` (role
 *   and PIN also need `access.manage`; never your own role; never an administrator's
 *   account unless you are one) and
 *   `POST employees/{id}/password-link` (`staff.edit`);
 * - `GET employees/options` (`page.staff`): access roles and the lists for the forms;
 * - `GET employees/{id}/identifiers` (`staff.view_ids`, logged): the only way to the
 *   full social security and tax numbers.
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
		$router->get( 'employees/accounts', array( self::class, 'accounts' ) );
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
					$per ? $per : 20,
					array(
						'department' => (string) $request->get_param( 'department' ),
						'job_title'  => (string) $request->get_param( 'job_title' ),
						'sort'       => (string) $request->get_param( 'sort' ),
						'dir'        => (string) $request->get_param( 'dir' ),
					)
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
			self::withShift( self::withAccessManage( 'staff.create', $request ), $request, false ),
			function ( $request ) {
				$body    = (array) $request->get_json_params();
				$user_id = absint( $body['user_id'] ?? 0 );
				$shift   = self::shiftOf( $request );
				// Checked first: a wrong shift must not leave a file half made.
				if ( null !== $shift ) {
					EmployeePayService::assertShift( $shift );
				}
				// An existing sign-in gets a file (R1-T3); otherwise a new user is made with it.
				$employee = $user_id
					? ( new EmployeeService() )->linkAccount( $user_id, array_diff_key( $body, array_flip( array( 'user_id', 'username', 'password', 'access_role', 'pin', 'work_shift_id' ) ) ) )
					: ( new EmployeeService() )->create( array_diff_key( $body, array( 'work_shift_id' => 0 ) ) );
				if ( $shift ) {
					( new EmployeePayService() )->setShift( (int) $employee->id, $shift );
				}
				return ApiResponse::created(
					array( 'employee' => EmployeeResource::detail( $employee ) ),
					__( 'Employee added.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * GET employees/accounts: sign-ins that can get a file (R1-T3).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function accounts( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.create',
			fn( $request ) => ApiResponse::success( array( 'accounts' => ( new EmployeeService() )->accounts( sanitize_text_field( (string) $request->get_param( 'q' ) ) ) ) )->send()
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
			self::withShift( array( 'staff.edit' ), $request, true ),
			function ( $request ) {
				$id    = (int) $request->get_param( 'id' );
				$shift = self::shiftOf( $request );
				if ( null !== $shift ) {
					EmployeePayService::assertShift( $shift );
				}
				$employee = ( new EmployeeService() )->update( $id, self::fields( $request ) );
				if ( null !== $shift ) {
					( new EmployeePayService() )->setShift( $id, $shift );
				}
				return ApiResponse::success(
					array( 'employee' => EmployeeResource::detail( $employee ) ),
					__( 'Employee saved.', 'radius-hotel-booking-pro' )
				)->send();
			}
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
	 * The payroll work shift sent (`work_shift_id`; '' or 0 is none), or null
	 * when none was sent or Payroll is off (M15 owns work shifts).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|null
	 */
	private static function shiftOf( WP_REST_Request $request ): ?int {
		$body = (array) $request->get_json_params();
		if ( ! array_key_exists( 'work_shift_id', $body ) || ! class_exists( EmployeePayService::class )
			|| ! function_exists( 'rtbp_pro_feature_active' ) || ! rtbp_pro_feature_active( 'payroll' ) ) {
			return null;
		}
		return absint( $body['work_shift_id'] );
	}

	/**
	 * The access keys, plus `payroll.pay_profile` when a work shift is set.
	 *
	 * @param string[]        $keys   Keys so far.
	 * @param WP_REST_Request $request Request.
	 * @param bool            $clear  Whether sending "none" counts (an update; on create none is nothing).
	 * @return string[]
	 */
	private static function withShift( array $keys, WP_REST_Request $request, bool $clear ): array {
		$shift = self::shiftOf( $request );
		if ( null !== $shift && ( $shift || $clear ) ) {
			$keys[] = 'payroll.pay_profile';
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
			array_flip( array( 'first_name', 'last_name', 'dob', 'address', 'marital_status', 'num_children', 'phone', 'email', 'emergency', 'cnps_no', 'tax_no', 'department', 'job_title' ) )
		);
	}
}
