<?php
/**
 * Employee model.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * A staff member's employee file (table `pro_employees`, M12).
 *
 * @property int         $id
 * @property int         $user_id
 * @property string      $employee_no
 * @property string      $first_name
 * @property string      $last_name
 * @property string|null $dob
 * @property string|null $address
 * @property string      $marital_status
 * @property int         $num_children
 * @property string      $phone
 * @property string|null $phone_e164
 * @property string      $email
 * @property string|null $emergency
 * @property string      $cnps_no
 * @property string      $tax_no
 * @property string      $department
 * @property string      $job_title
 * @property string      $shift
 * @property bool        $is_active
 * @property string|null $deactivated_at
 * @property int|null    $avatar_id
 */
class Employee extends BaseModel {

	/**
	 * Marital statuses (legacy list; `widowed` for the legacy `widow|widower`).
	 */
	const MARITAL = array( 'single', 'married', 'divorced', 'separated', 'widowed' );

	/**
	 * Emergency contact relationships (legacy list).
	 */
	const RELATIONSHIPS = array( 'father', 'mother', 'spouse', 'brother', 'sister', 'friend', 'relative', 'guardian', 'other' );

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_employees';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'user_id',
		'employee_no',
		'first_name',
		'last_name',
		'dob',
		'address',
		'marital_status',
		'num_children',
		'phone',
		'phone_e164',
		'email',
		'emergency',
		'cnps_no',
		'tax_no',
		'department',
		'job_title',
		'shift',
		'is_active',
		'deactivated_at',
		'avatar_id',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id'           => 'int',
		'user_id'      => 'int',
		'num_children' => 'int',
		'is_active'    => 'bool',
		'avatar_id'    => 'int',
	);

	/**
	 * "First Last", or the employee number.
	 *
	 * @return string
	 */
	public function fullName(): string {
		$name = trim( $this->first_name . ' ' . $this->last_name );
		return '' === $name ? (string) $this->employee_no : $name;
	}

	/**
	 * The emergency contact.
	 *
	 * @return array{full_name: string, address: string, phone: string, relationship: string}
	 */
	public function emergencyContact(): array {
		$data = json_decode( (string) $this->emergency, true );
		$data = is_array( $data ) ? $data : array();
		return array(
			'full_name'    => (string) ( $data['full_name'] ?? '' ),
			'address'      => (string) ( $data['address'] ?? '' ),
			'phone'        => (string) ( $data['phone'] ?? '' ),
			'relationship' => (string) ( $data['relationship'] ?? '' ),
		);
	}
}
