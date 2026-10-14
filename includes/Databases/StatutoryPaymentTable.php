<?php
/**
 * The statutory payments table (M15 T6).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_statutory_payments` (15.13): a payment made to
 * an authority for one contribution of one month (`year`, `month`,
 * `authority`, `code`). A payment entered by mistake is voided with a reason,
 * never deleted.
 */
class StatutoryPaymentTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_statutory_payments',
			function ( Blueprint $table ) {
				$table->id();
				$table->smallInteger( 'year' );
				$table->tinyInteger( 'month' );
				$table->string( 'authority', 191 );
				$table->string( 'code', 60 );
				$table->decimal( 'amount', 14, 2 );
				$table->date( 'paid_on' );
				$table->string( 'method', 60 )->nullable();
				$table->string( 'reference', 150 )->nullable();
				$table->unsignedBigInteger( 'created_by' )->nullable();
				$table->dateTime( 'voided_at' )->nullable();
				$table->unsignedBigInteger( 'voided_by' )->nullable();
				$table->string( 'void_reason', 255 )->nullable();
				$table->timestamps();

				$table->index( array( 'year', 'month', 'authority', 'code' ), 'period_code' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_statutory_payments' );
	}
}
