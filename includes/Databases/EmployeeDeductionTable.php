<?php
/**
 * The employee deductions and garnishments table (M15 T4d).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_employee_deductions` (12.15): recurring
 * **deductions** taken before tax (health insurance, other — an employee
 * share and an optional employer share) and **garnishments** taken after tax
 * (a court order — an amount requested per period up to a percentage of
 * disposable pay; other — a total to recover, with what remains). The legacy
 * kept deductions in user meta and garnishments in their own table.
 */
class EmployeeDeductionTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_employee_deductions',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'employee_id' );
				$table->string( 'kind', 20 );
				$table->string( 'type', 30 );
				$table->string( 'name', 191 );
				$table->string( 'authority', 191 )->nullable();
				$table->tinyInteger( 'remittance_day' )->nullable();
				$table->string( 'calculation', 20 )->default( 'per_payslip' );
				$table->date( 'start_date' )->nullable();
				$table->date( 'end_date' )->nullable();
				$table->string( 'amount_type', 10 )->default( 'amount' );
				$table->decimal( 'amount', 14, 2 )->default( 0 );
				$table->decimal( 'percent', 6, 3 )->default( 0 );
				$table->string( 'employer_amount_type', 10 )->default( 'none' );
				$table->decimal( 'employer_amount', 14, 2 )->default( 0 );
				$table->decimal( 'employer_percent', 6, 3 )->default( 0 );
				$table->decimal( 'total_amount', 14, 2 )->default( 0 );
				$table->decimal( 'remaining_amount', 14, 2 )->default( 0 );
				$table->decimal( 'max_percent', 6, 3 )->default( 0 );
				$table->string( 'status', 20 )->default( 'active' );
				$table->timestamps();

				$table->index( array( 'employee_id', 'status' ), 'employee_status' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_employee_deductions' );
	}
}
