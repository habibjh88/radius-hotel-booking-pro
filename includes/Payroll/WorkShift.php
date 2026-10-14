<?php
/**
 * Work shift model (M15 T3b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * A work shift (table `pro_work_shifts`, 15.10); its days are in
 * `pro_work_shift_days` (WorkShiftRepository::days()).
 *
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 */
class WorkShift extends BaseModel {

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_work_shifts';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'name',
		'description',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id' => 'int',
	);
}
