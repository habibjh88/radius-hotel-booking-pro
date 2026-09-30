<?php
/**
 * The employee documents table (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…pro_employee_documents`: files kept against an employee (12.16): an ID
 * copy, a certificate… The file itself is in protected storage (`file_token`).
 */
class EmployeeDocumentsTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_employee_documents',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'employee_id' );
				$table->string( 'title', 191 )->default( '' );
				$table->string( 'category', 40 )->default( 'other' );
				$table->string( 'file_token', 64 );
				$table->string( 'mime', 100 )->default( '' );
				$table->unsignedBigInteger( 'size' )->default( 0 );
				$table->unsignedBigInteger( 'uploaded_by' )->nullable();
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
		Schema::drop( $this->tablePrefix . 'pro_employee_documents' );
	}
}
