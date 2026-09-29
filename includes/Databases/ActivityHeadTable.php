<?php
/**
 * The activity log's head record (M14 review).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_activity_head`: one row (id 1) holding the end
 * of the chain (last id, last hash), the number of live rows, the anchor left
 * by purges, and an HMAC over those fields. Writers lock it (`SELECT … FOR
 * UPDATE`), so the chain is written one row at a time and can be checked from
 * both ends.
 */
class ActivityHeadTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_activity_head',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'last_id' )->default( 0 );
				$table->char( 'last_hash', 64 )->default( '' );
				$table->unsignedBigInteger( 'row_count' )->default( 0 );
				$table->char( 'anchor', 64 )->default( '' );
				$table->char( 'mac', 64 )->default( '' );
			}
		);
	}

	/**
	 * Never dropped by a rollback: the log's integrity depends on it.
	 *
	 * @return void
	 */
	public function down(): void {}
}
