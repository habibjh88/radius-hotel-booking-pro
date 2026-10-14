<?php
/**
 * Payroll line model (M15 T5b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * One employee of a run (table `pro_payroll_lines`); `inputs`, `settings`
 * and `breakdown` are JSON.
 *
 * @property int         $id
 * @property int         $run_id
 * @property int         $employee_id
 * @property string      $employee_name
 * @property string|null $employee_no
 * @property string|null $inputs
 * @property string|null $settings
 * @property string|null $breakdown
 * @property string      $gross
 * @property string      $net
 * @property string|null $payment_method
 * @property string|null $payment_ref
 */
class PayrollLine extends BaseModel {

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_payroll_lines';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'run_id',
		'employee_id',
		'employee_name',
		'employee_no',
		'inputs',
		'settings',
		'breakdown',
		'gross',
		'employee_tax',
		'employer_tax',
		'net',
		'employer_cost',
		'payment_method',
		'payment_ref',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id'          => 'int',
		'run_id'      => 'int',
		'employee_id' => 'int',
	);

	/**
	 * A JSON column decoded.
	 *
	 * @param string $column inputs|settings|breakdown.
	 * @return array
	 */
	public function json( string $column ): array {
		$value = json_decode( (string) $this->{$column}, true );
		return is_array( $value ) ? $value : array();
	}
}
