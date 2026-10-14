<?php
/**
 * The payroll runs table (M15 T5b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_payroll_runs` (15.1–15.7): one pay run of a pay
 * schedule for one pay period — draft, finalized, settled or voided — with
 * the schedule and period as they were, the totals of its lines, and who
 * moved it on and when. A voided run stays (15.2: the history keeps both).
 */
class PayrollRunTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_payroll_runs',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'pay_schedule_id' );
				$table->string( 'schedule_name', 100 );
				$table->string( 'pay_interval', 20 );
				$table->integer( 'period_index' );
				$table->date( 'period_start' );
				$table->date( 'period_end' );
				$table->date( 'pay_date' );
				$table->string( 'status', 20 )->default( 'draft' );
				$table->unsignedInteger( 'employees' )->default( 0 );
				$table->decimal( 'gross_total', 14, 2 )->default( 0 );
				$table->decimal( 'employee_tax_total', 14, 2 )->default( 0 );
				$table->decimal( 'employer_tax_total', 14, 2 )->default( 0 );
				$table->decimal( 'net_total', 14, 2 )->default( 0 );
				$table->decimal( 'employer_cost_total', 14, 2 )->default( 0 );
				$table->unsignedBigInteger( 'created_by' )->nullable();
				$table->dateTime( 'finalized_at' )->nullable();
				$table->unsignedBigInteger( 'finalized_by' )->nullable();
				$table->dateTime( 'settled_at' )->nullable();
				$table->unsignedBigInteger( 'settled_by' )->nullable();
				$table->dateTime( 'voided_at' )->nullable();
				$table->unsignedBigInteger( 'voided_by' )->nullable();
				$table->string( 'void_reason', 255 )->nullable();
				$table->timestamps();

				$table->index( array( 'pay_schedule_id', 'period_start', 'status' ), 'schedule_period' );
				$table->index( array( 'status', 'pay_date' ), 'status_pay' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_payroll_runs' );
	}
}
