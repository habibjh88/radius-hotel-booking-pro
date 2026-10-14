<?php
/**
 * Employee deduction or garnishment model (M15 T4d).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * One recurring deduction or garnishment (table `pro_employee_deductions`).
 *
 * @property int         $id
 * @property int         $employee_id
 * @property string      $kind
 * @property string      $type
 * @property string      $name
 * @property string|null $authority
 * @property int|null    $remittance_day
 * @property string      $calculation
 * @property string|null $start_date
 * @property string|null $end_date
 * @property string      $amount_type
 * @property string      $amount
 * @property string      $percent
 * @property string      $employer_amount_type
 * @property string      $employer_amount
 * @property string      $employer_percent
 * @property string      $total_amount
 * @property string      $remaining_amount
 * @property string      $max_percent
 * @property string      $status
 */
class EmployeeDeduction extends BaseModel {

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_employee_deductions';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'employee_id',
		'kind',
		'type',
		'name',
		'authority',
		'remittance_day',
		'calculation',
		'start_date',
		'end_date',
		'amount_type',
		'amount',
		'percent',
		'employer_amount_type',
		'employer_amount',
		'employer_percent',
		'total_amount',
		'remaining_amount',
		'max_percent',
		'status',
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
