<?php
/**
 * Employee API shapes.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Access\Access;
use RadiusTheme\RadiusHotelBookingPro\Access\PasscodeService;
use RadiusTheme\RadiusHotelBookingPro\Access\UserAccessService;

defined( 'ABSPATH' ) || exit;

/**
 * An employee for the API. Lists and the detail mask the statutory numbers
 * (12.10); the full ones come only from `GET employees/{id}/identifiers`
 * (`staff.view_ids`, logged). The detail, which needs `page.staff`, carries
 * the rest of the file.
 */
final class EmployeeResource {

	/**
	 * A row of the list.
	 *
	 * @param array $row Employee columns plus `user_login`.
	 * @return array
	 */
	public static function summary( array $row ): array {
		$role = function_exists( 'rtbp_pro_feature_active' ) && rtbp_pro_feature_active( 'advanced_access' ) && class_exists( UserAccessService::class )
			? UserAccessService::role_of( (int) $row['user_id'] )
			: null;
		return array(
			'id'             => (int) $row['id'],
			'user_id'        => (int) $row['user_id'],
			'user_login'     => (string) ( $row['user_login'] ?? '' ),
			'employee_no'    => (string) $row['employee_no'],
			'first_name'     => (string) $row['first_name'],
			'last_name'      => (string) $row['last_name'],
			'name'           => trim( $row['first_name'] . ' ' . $row['last_name'] ),
			'job_title'      => (string) $row['job_title'],
			'department'     => (string) $row['department'],
			'shift'          => (string) $row['shift'],
			'phone'          => (string) $row['phone'],
			'email'          => (string) $row['email'],
			'is_active'      => (bool) (int) $row['is_active'],
			'access_role'    => $role ? array(
				'id'   => (int) $role->id,
				'name' => (string) $role->name,
			) : null,
			'cnps_no_masked' => self::mask( (string) $row['cnps_no'] ),
			'tax_no_masked'  => self::mask( (string) $row['tax_no'] ),
		);
	}

	/**
	 * The detail screen's employee.
	 *
	 * @param Employee $employee Employee.
	 * @return array
	 */
	public static function detail( Employee $employee ): array {
		$user = get_userdata( (int) $employee->user_id );
		$row  = array_merge( $employee->toArray(), array( 'user_login' => $user ? $user->user_login : '' ) );
		return array_merge(
			self::summary( $row ),
			array(
				'dob'            => $employee->dob ? substr( (string) $employee->dob, 0, 10 ) : null,
				'address'        => (string) $employee->address,
				'marital_status' => (string) $employee->marital_status,
				'num_children'   => (int) $employee->num_children,
				'phone_e164'     => $employee->phone_e164,
				'emergency'      => $employee->emergencyContact(),
				'has_ids'        => '' !== (string) $employee->cnps_no || '' !== (string) $employee->tax_no,
				// Whether this viewer may reveal the full numbers (the server checks again).
				'can_view_ids'   => class_exists( Access::class ) && Access::LOCKED !== Access::level( 'staff.view_ids' ),
				'deactivated_at' => $employee->deactivated_at ? (string) $employee->deactivated_at : null,
				'has_pin'        => class_exists( PasscodeService::class ) ? PasscodeService::has_pin( (int) $employee->user_id ) : false,
				'created_at'     => substr( (string) $employee->created_at, 0, 16 ),
			)
		);
	}

	/**
	 * A number masked for lists: `••••3456`.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public static function mask( string $value ): string {
		$value = trim( $value );
		return '' === $value ? '' : '••••' . ( strlen( $value ) > 4 ? substr( $value, -4 ) : '' );
	}
}
