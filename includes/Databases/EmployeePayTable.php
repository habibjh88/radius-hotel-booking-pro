<?php
/**
 * The employee pay profiles table (M15 T4b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_employee_pay` (12.12, 12.13): one row per Pro
 * employee (`pro_employees.id`) — the pay schedule and work shift they are
 * on, and how they are paid: a salary per week / month / year, or an hourly
 * rate. Earlier values live in the activity log (`payroll.pay_profile`);
 * each pay run line keeps the figures it used.
 */
class EmployeePayTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_employee_pay',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'employee_id' );
				$table->unsignedBigInteger( 'pay_schedule_id' )->nullable();
				$table->unsignedBigInteger( 'work_shift_id' )->nullable();
				$table->string( 'pay_type', 20 )->default( 'salaried' );
				$table->decimal( 'amount', 14, 2 )->default( 0 );
				$table->string( 'amount_period', 10 )->default( 'month' );
				$table->timestamps();

				$table->unique( 'employee_id', 'employee' );
				$table->index( 'pay_schedule_id', 'schedule' );
				$table->index( 'work_shift_id', 'shift' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_employee_pay' );
	}
}
