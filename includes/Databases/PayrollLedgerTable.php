<?php
/**
 * The payroll ledger table (M15 T5b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_payroll_ledger`: what a line used up — hours of
 * paid / sick / unpaid time off, vacation pay kept, each deduction and
 * garnishment taken. Balances are sums over the entries of runs that count
 * (finalized, settled); voiding or reverting a run stops its entries
 * counting, so nothing is ever written back by hand.
 */
class PayrollLedgerTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_payroll_ledger',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'line_id' );
				$table->unsignedBigInteger( 'run_id' );
				$table->unsignedBigInteger( 'employee_id' );
				$table->string( 'code', 30 );
				$table->unsignedBigInteger( 'ref_id' )->nullable();
				$table->smallInteger( 'year' );
				$table->tinyInteger( 'month' );
				$table->decimal( 'quantity', 10, 2 )->default( 0 );
				$table->decimal( 'amount', 14, 2 )->default( 0 );
				$table->decimal( 'employer_amount', 14, 2 )->default( 0 );

				$table->index( array( 'employee_id', 'code', 'year' ), 'employee_code_year' );
				$table->index( array( 'ref_id', 'code' ), 'ref_code' );
				$table->index( 'run_id', 'run' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_payroll_ledger' );
	}
}
