<?php
/**
 * The time off table (M15 T8a).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_time_off` (15.18–15.20): one request for time
 * off by an employee (Pro `pro_employees.id`) — the type (legacy list), the
 * days (calendar days, both ends included), why, and how it was decided.
 * Balances are sums over the approved requests of a year; nothing else is
 * stored.
 */
class TimeOffTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_time_off',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'employee_id' );
				$table->string( 'type', 20 );
				$table->date( 'start_date' );
				$table->date( 'end_date' );
				$table->smallInteger( 'days' );
				$table->text( 'reason' )->nullable();
				$table->string( 'status', 20 )->default( 'pending' );
				$table->unsignedBigInteger( 'requested_by' )->nullable();
				$table->dateTime( 'requested_at' )->nullable();
				$table->unsignedBigInteger( 'reviewed_by' )->nullable();
				$table->dateTime( 'reviewed_at' )->nullable();
				$table->string( 'review_note', 255 )->nullable();
				$table->timestamps();

				$table->index( array( 'employee_id', 'status', 'start_date' ), 'employee_status' );
				$table->index( array( 'status', 'start_date' ), 'status_start' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_time_off' );
	}
}
