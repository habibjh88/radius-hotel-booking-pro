<?php
/**
 * The cash register sessions table (R3-T5).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_cash_sessions`: one shift at a staff member's
 * cash register, from Open to Close. The takings are not copied in while it
 * is open: they are the free payments that user recorded between
 * `opened_at` and `closed_at` (site time, like `payments.created_at`). At
 * Close, `expected` (method => amount, cash including the opening float),
 * `counted` (method => amount) and the cash `difference` are frozen.
 */
class CashSessionsTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_cash_sessions',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'user_id' );
				$table->string( 'status', 10 )->default( 'open' );
				$table->dateTime( 'opened_at' );
				$table->decimal( 'opening_float', 14, 2 )->default( 0 );
				$table->dateTime( 'closed_at' )->nullable();
				$table->unsignedBigInteger( 'closed_by' )->nullable();
				$table->text( 'expected' )->nullable();
				$table->text( 'counted' )->nullable();
				$table->decimal( 'difference', 14, 2 )->nullable();
				$table->text( 'note' )->nullable();
				$table->timestamps();

				$table->index( array( 'user_id', 'status' ), 'user_status' );
				$table->index( array( 'status', 'opened_at' ), 'status_opened' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_cash_sessions' );
	}
}
