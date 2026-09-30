<?php
/**
 * The employees table (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…pro_employees`: the employee file of a staff member (a WordPress user
 * with the role `rtbp_staff`, one row per user). `emergency` is JSON
 * `{ full_name, address, phone, relationship }`. The pay fields of 12.12–
 * 12.15 are not here: they wait for D1 (payroll in scope?).
 */
class EmployeesTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_employees',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'user_id' );
				$table->string( 'employee_no', 20 );
				$table->string( 'first_name', 100 )->default( '' );
				$table->string( 'last_name', 100 )->default( '' );
				$table->date( 'dob' )->nullable();
				$table->text( 'address' )->nullable();
				$table->string( 'marital_status', 20 )->default( '' );
				$table->unsignedInteger( 'num_children' )->default( 0 );
				$table->string( 'phone', 40 )->default( '' );
				$table->string( 'phone_e164', 20 )->nullable();
				$table->string( 'email', 191 )->default( '' );
				$table->text( 'emergency' )->nullable();
				$table->string( 'cnps_no', 40 )->default( '' );
				$table->string( 'tax_no', 40 )->default( '' );
				$table->string( 'department', 100 )->default( '' );
				$table->string( 'job_title', 100 )->default( '' );
				$table->string( 'shift', 60 )->default( '' );
				$table->boolean( 'is_active' )->default( 1 );
				$table->dateTime( 'deactivated_at' )->nullable();
				$table->unsignedBigInteger( 'avatar_id' )->nullable();
				$table->timestamps();

				$table->unique( 'user_id', 'user_id' );
				$table->unique( 'employee_no', 'employee_no' );
				$table->index( 'is_active', 'is_active' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_employees' );
	}
}
