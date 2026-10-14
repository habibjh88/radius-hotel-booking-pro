<?php
/**
 * The work shift days table (M15 T3b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_work_shift_days`: one row per shift and ISO
 * weekday (1 = Monday … 7 = Sunday) — a day off, or the start and end time
 * (an end at or before the start runs past midnight).
 */
class WorkShiftDayTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_work_shift_days',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'shift_id' );
				$table->tinyInteger( 'weekday' );
				$table->boolean( 'is_day_off' )->default( 0 );
				$table->time( 'start_time' )->nullable();
				$table->time( 'end_time' )->nullable();

				$table->unique( array( 'shift_id', 'weekday' ), 'shift_weekday' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_work_shift_days' );
	}
}
