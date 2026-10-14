<?php
/**
 * The work shifts table (M15 T3b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_work_shifts` (15.10): a named shift (*Jour*,
 * *Nuit*, *Jour de repos*); its hours are one row per weekday in
 * `pro_work_shift_days`.
 */
class WorkShiftTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_work_shifts',
			function ( Blueprint $table ) {
				$table->id();
				$table->string( 'name', 100 );
				$table->text( 'description' )->nullable();
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
		Schema::drop( $this->tablePrefix . 'pro_work_shifts' );
	}
}
