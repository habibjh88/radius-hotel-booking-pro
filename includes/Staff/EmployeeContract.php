<?php
/**
 * Employment contract model.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * One employment contract of an employee (table `pro_employee_contracts`,
 * M12 12.9). An employee has a history of contracts, at most one active.
 *
 * @property int         $id
 * @property int         $employee_id
 * @property string      $status
 * @property string      $job_type
 * @property string      $job_title
 * @property string      $department
 * @property string|null $start_date
 * @property string|null $end_date
 * @property string      $file_token
 * @property string|null $notes
 */
class EmployeeContract extends BaseModel {

	/**
	 * Statuses.
	 */
	const STATUSES = array( 'active', 'terminated' );

	/**
	 * Job types (legacy list, underscores).
	 */
	const JOB_TYPES = array( 'full_time', 'part_time', 'contract', 'internship', 'other' );

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_employee_contracts';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'employee_id',
		'status',
		'job_type',
		'job_title',
		'department',
		'start_date',
		'end_date',
		'file_token',
		'notes',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id'          => 'int',
		'employee_id' => 'int',
	);
}
