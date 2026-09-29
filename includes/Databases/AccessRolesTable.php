<?php
/**
 * The access roles table (M13).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…radius_hotel_booking_pro_access_roles`: named access roles (features
 * 13.13–13.15). `levels` is a partial key => level map, `*` = every other key.
 * `profile_fields` is filled by T6.
 */
class AccessRolesTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_access_roles',
			function ( Blueprint $table ) {
				$table->id();
				$table->string( 'slug', 60 );
				$table->string( 'name', 100 );
				$table->text( 'description' )->nullable();
				$table->longText( 'levels' )->nullable();
				$table->longText( 'profile_fields' )->nullable();
				$table->boolean( 'is_template' )->default( 0 );
				$table->timestamps();

				$table->unique( 'slug' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_access_roles' );
	}
}
