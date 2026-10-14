<?php
/**
 * Pay schedule model (M15 T3a).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * A pay schedule (table `pro_pay_schedules`, 15.9).
 *
 * @property int    $id
 * @property string $name
 * @property string $pay_interval
 * @property string $period_end
 * @property string $next_pay_day
 */
class PaySchedule extends BaseModel {

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_pay_schedules';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'name',
		'pay_interval',
		'period_end',
		'next_pay_day',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id' => 'int',
	);

	/**
	 * The period to pay next on a day.
	 *
	 * @param string $today Y-m-d.
	 * @return array{index:int, start:string, end:string, pay_date:string}
	 */
	public function nextPeriod( string $today ): array {
		return PayPeriods::next( (string) $this->pay_interval, (string) $this->period_end, (string) $this->next_pay_day, $today );
	}

	/**
	 * Period `k` (0 = the anchor).
	 *
	 * @param int $k Index.
	 * @return array{index:int, start:string, end:string, pay_date:string}
	 */
	public function period( int $k ): array {
		return PayPeriods::period( (string) $this->pay_interval, (string) $this->period_end, (string) $this->next_pay_day, $k );
	}
}
