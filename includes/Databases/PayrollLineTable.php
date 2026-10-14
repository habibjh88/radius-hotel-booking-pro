<?php
/**
 * The payroll lines table (M15 T5b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_payroll_lines` (15.8): one employee of a run —
 * what was entered (`inputs`: entries, custom items, hours), the settings it
 * was calculated with (`settings`: pay profile, additional pay, deductions,
 * the year's use, the work-accident rate), the whole breakdown
 * (`LineCalculator`), the main figures as columns, and how it was paid.
 */
class PayrollLineTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_payroll_lines',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'run_id' );
				$table->unsignedBigInteger( 'employee_id' );
				$table->string( 'employee_name', 191 );
				$table->string( 'employee_no', 30 )->nullable();
				$table->longText( 'inputs' )->nullable();
				$table->longText( 'settings' )->nullable();
				$table->longText( 'breakdown' )->nullable();
				$table->decimal( 'gross', 14, 2 )->default( 0 );
				$table->decimal( 'employee_tax', 14, 2 )->default( 0 );
				$table->decimal( 'employer_tax', 14, 2 )->default( 0 );
				$table->decimal( 'net', 14, 2 )->default( 0 );
				$table->decimal( 'employer_cost', 14, 2 )->default( 0 );
				$table->string( 'payment_method', 60 )->nullable();
				$table->string( 'payment_ref', 100 )->nullable();
				$table->timestamps();

				$table->unique( array( 'run_id', 'employee_id' ), 'run_employee' );
				$table->index( 'employee_id', 'employee' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_payroll_lines' );
	}
}
