<?php
/**
 * The log archives table (M14).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_log_archives`: one row per archive (T4): the JSON
 * file (`file_token`, verifiable, with every hash), its CSV twin (`csv_token`),
 * and the hash of the last archived row (`last_hash`, the anchor after a purge).
 */
class LogArchivesTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_log_archives',
			function ( Blueprint $table ) {
				$table->id();
				$table->dateTime( 'period_start' );
				$table->dateTime( 'period_end' );
				$table->unsignedInteger( 'row_count' )->default( 0 );
				$table->unsignedBigInteger( 'first_id' )->default( 0 );
				$table->unsignedBigInteger( 'last_id' )->default( 0 );
				$table->char( 'file_token', 32 )->default( '' );
				$table->char( 'csv_token', 32 )->default( '' );
				$table->char( 'file_sha256', 64 )->default( '' );
				$table->char( 'last_hash', 64 )->default( '' );
				$table->boolean( 'purged' )->default( 0 );
				$table->unsignedBigInteger( 'created_by' )->default( 0 );
				$table->timestamps();

				$table->index( 'period_end', 'period_end' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_log_archives' );
	}
}
