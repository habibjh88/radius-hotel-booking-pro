<?php
/**
 * The activity log table (M14).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_activity_log`: append-only, hash-chained
 * (ADR-011). `repeat_count` and `last_seen_gmt` group repeated page views and
 * are outside the hash; every other column is covered by it.
 */
class ActivityLogTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_activity_log',
			function ( Blueprint $table ) {
				$table->id();
				$table->unsignedBigInteger( 'user_id' )->default( 0 );
				$table->string( 'user_name', 100 )->default( '' );
				$table->string( 'user_role', 60 )->default( '' );
				$table->string( 'action', 64 );
				$table->string( 'kind', 16 );
				$table->string( 'subject_type', 40 )->default( '' );
				$table->string( 'subject_id', 64 )->default( '' );
				$table->string( 'subject_label', 191 )->default( '' );
				$table->text( 'description' )->nullable();
				$table->longText( 'changes' )->nullable();
				$table->longText( 'meta' )->nullable();
				$table->string( 'ip', 45 )->default( '' );
				$table->string( 'user_agent', 255 )->default( '' );
				$table->string( 'device', 100 )->default( '' );
				$table->unsignedInteger( 'repeat_count' )->default( 1 );
				$table->dateTime( 'created_at' );
				$table->dateTime( 'created_at_gmt' );
				$table->dateTime( 'last_seen_gmt' );
				$table->char( 'prev_hash', 64 )->default( '' );
				$table->char( 'hash', 64 );

				// Short names: generated ones exceed MySQL's 64-character limit.
				$table->index( 'created_at_gmt', 'created_gmt' );
				$table->index( array( 'user_id', 'created_at_gmt' ), 'user_time' );
				$table->index( array( 'action', 'created_at_gmt' ), 'action_time' );
				$table->index( array( 'subject_type', 'subject_id' ), 'subject' );
				$table->index( array( 'kind', 'created_at_gmt' ), 'kind_time' );
			}
		);
	}

	/**
	 * Never dropped by a rollback: the log must not be erasable from a
	 * maintenance command (M14 review #2). Remove it by hand if ever needed.
	 *
	 * @return void
	 */
	public function down(): void {}
}
