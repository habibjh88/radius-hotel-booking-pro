<?php
/**
 * The pay schedules table (M15 T3a).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_pay_schedules` (15.9): a named pay interval and
 * one **anchor** pay period — its last day (`period_end`) and its pay day
 * (`next_pay_day`). Every other period follows from them (`PayPeriods`), so
 * nothing has to move the schedule forward after a run.
 */
class PayScheduleTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_pay_schedules',
			function ( Blueprint $table ) {
				$table->id();
				$table->string( 'name', 100 );
				$table->string( 'pay_interval', 20 )->default( 'monthly' );
				$table->date( 'period_end' );
				$table->date( 'next_pay_day' );
				$table->timestamps();

				$table->unique( 'name', 'name' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_pay_schedules' );
	}
}
