<?php
/**
 * Payroll run model (M15 T5b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * One pay run (table `pro_payroll_runs`).
 *
 * @property int    $id
 * @property int    $pay_schedule_id
 * @property string $schedule_name
 * @property string $pay_interval
 * @property int    $period_index
 * @property string $period_start
 * @property string $period_end
 * @property string $pay_date
 * @property string $status
 */
class PayrollRun extends BaseModel {

	public const DRAFT     = 'draft';
	public const FINALIZED = 'finalized';
	public const SETTLED   = 'settled';
	public const VOIDED    = 'voided';

	/**
	 * Runs whose ledger entries count towards balances.
	 */
	public const COUNTING = array( self::FINALIZED, self::SETTLED );

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_payroll_runs';

	/**
	 * Mass-assignable columns.
	 *
	 * @var string[]
	 */
	protected array $fillable = array(
		'pay_schedule_id',
		'schedule_name',
		'pay_interval',
		'period_index',
		'period_start',
		'period_end',
		'pay_date',
		'status',
		'employees',
		'gross_total',
		'employee_tax_total',
		'employer_tax_total',
		'net_total',
		'employer_cost_total',
		'created_by',
		'finalized_at',
		'finalized_by',
		'settled_at',
		'settled_by',
		'voided_at',
		'voided_by',
		'void_reason',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id'              => 'int',
		'pay_schedule_id' => 'int',
		'period_index'    => 'int',
		'employees'       => 'int',
	);

	/**
	 * "Mensuel · 1–31 Oct 2026" for logs.
	 *
	 * @return string
	 */
	public function label(): string {
		return sprintf( '%s · %s – %s', (string) $this->schedule_name, substr( (string) $this->period_start, 0, 10 ), substr( (string) $this->period_end, 0, 10 ) );
	}
}
