<?php
/**
 * The iCal feeds table (M08, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…pro_ical_feeds` (feature 8.10): one row per feed of a room.
 *
 * - `export`: the room's own calendar, served at a URL carrying `token`
 *   (48 random hex characters; one export feed per room).
 * - `import`: a channel's calendar at `url`, read every `interval_minutes`
 *   into blocks (T6b).
 *
 * `last_synced_at_gmt` is the last time a channel read an export feed, or the
 * last import run.
 */
class IcalFeedsTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_ical_feeds',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'room_id' );
				$table->string( 'direction', 10 );
				$table->string( 'name', 100 )->default( '' );
				$table->text( 'url' )->nullable();
				$table->string( 'token', 64 )->nullable();
				$table->unsignedInteger( 'interval_minutes' )->default( 60 );
				$table->dateTime( 'last_synced_at_gmt' )->nullable();
				$table->string( 'last_status', 20 )->default( '' );
				$table->string( 'last_error', 191 )->default( '' );
				$table->unsignedBigInteger( 'created_by' )->nullable();
				$table->timestamps();

				$table->index( array( 'room_id', 'direction' ), 'room_direction' );
				$table->unique( 'token', 'token' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_ical_feeds' );
	}
}
