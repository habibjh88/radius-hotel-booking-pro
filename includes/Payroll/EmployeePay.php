<?php
/**
 * Employee pay profile model (M15 T4b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * One employee's pay profile (table `pro_employee_pay`).
 *
 * @property int      $id
 * @property int      $employee_id
 * @property int|null $pay_schedule_id
 * @property int|null $work_shift_id
 * @property string   $pay_type
 * @property string   $amount
 * @property string   $amount_period
 */
class EmployeePay extends BaseModel {

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_employee_pay';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'employee_id',
		'pay_schedule_id',
		'work_shift_id',
		'pay_type',
		'amount',
		'amount_period',
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
