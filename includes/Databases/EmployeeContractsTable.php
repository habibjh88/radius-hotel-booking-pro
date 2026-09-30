<?php
/**
 * The employee contracts table (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…pro_employee_contracts`: an employee's contract history (12.9), one row
 * per contract; `file_token` points to a protected file.
 */
class EmployeeContractsTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_employee_contracts',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'employee_id' );
				$table->string( 'status', 20 )->default( 'active' );
				$table->string( 'job_type', 20 )->default( 'full_time' );
				$table->string( 'job_title', 100 )->default( '' );
				$table->string( 'department', 100 )->default( '' );
				$table->date( 'start_date' )->nullable();
				$table->date( 'end_date' )->nullable();
				$table->string( 'file_token', 64 )->default( '' );
				$table->text( 'notes' )->nullable();
				$table->timestamps();

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
		Schema::drop( $this->tablePrefix . 'pro_employee_contracts' );
	}
}
