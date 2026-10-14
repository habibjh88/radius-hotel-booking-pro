<?php
/**
 * The payroll statutory lines table (M15 T6).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_payroll_statutory` (15.12): what one line owes
 * each authority — the statutory lines (employee and employer share), the
 * deductions' shares and the garnishments — written with the line. A month's
 * contributions are the sums over the lines of **settled** runs paid that
 * month; a voided run's lines simply stop counting.
 */
class PayrollStatutoryTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_payroll_statutory',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'line_id' );
				$table->unsignedBigInteger( 'run_id' );
				$table->unsignedBigInteger( 'employee_id' );
				$table->smallInteger( 'year' );
				$table->tinyInteger( 'month' );
				$table->string( 'authority', 191 );
				$table->string( 'code', 60 );
				$table->string( 'label', 191 );
				$table->string( 'kind', 20 );
				$table->decimal( 'base', 14, 2 )->default( 0 );
				$table->decimal( 'employee_amount', 14, 2 )->default( 0 );
				$table->decimal( 'employer_amount', 14, 2 )->default( 0 );

				$table->index( array( 'year', 'month', 'authority', 'code' ), 'period_code' );
				$table->index( 'run_id', 'run' );
				$table->index( 'line_id', 'line' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_payroll_statutory' );
	}
}
