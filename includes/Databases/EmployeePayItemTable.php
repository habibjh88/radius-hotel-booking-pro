<?php
/**
 * The employee additional pay table (M15 T4c).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_employee_pay_items` (12.14): the additional pay
 * types an employee can receive (one row per employee and type, `PayItemTypes`)
 * and their settings (JSON: an overtime rate, yearly hours, the vacation pay
 * method and percentage). The amounts themselves are entered on each pay run.
 */
class EmployeePayItemTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_employee_pay_items',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'employee_id' );
				$table->string( 'type', 30 );
				$table->text( 'settings' )->nullable();
				$table->timestamps();

				$table->unique( array( 'employee_id', 'type' ), 'employee_type' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_employee_pay_items' );
	}
}
