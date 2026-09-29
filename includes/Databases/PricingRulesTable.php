<?php
/**
 * Pricing rules table (M07, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…pro_pricing_rules`: every pricing rule, by `kind` (features 7.12–7.15).
 *
 * - `seasonal`: `template_id`, `date_from` … `date_to` (inclusive), `priority`.
 * - `occupancy`: `threshold` (%), own `mode` / `amount_type` / `amount` (T6a).
 * - `early_bird` / `last_minute`: `threshold` (days ahead), own adjustment (T6a).
 *
 * `room_type_ids` / `rate_plan_ids` are JSON id lists; empty = all.
 */
class PricingRulesTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_pricing_rules',
			function ( Blueprint $table ) {
				$table->id();
				$table->string( 'kind', 20 );
				$table->string( 'name', 120 )->default( '' );
				$table->unsignedBigInteger( 'template_id' )->nullable();
				$table->string( 'mode', 20 )->nullable();
				$table->string( 'amount_type', 20 )->nullable();
				$table->decimal( 'amount', 12, 2 )->nullable();
				$table->text( 'room_type_ids' )->nullable();
				$table->text( 'rate_plan_ids' )->nullable();
				$table->date( 'date_from' )->nullable();
				$table->date( 'date_to' )->nullable();
				$table->decimal( 'threshold', 8, 2 )->nullable();
				$table->integer( 'priority' )->default( 10 );
				$table->boolean( 'is_active' )->default( 1 );
				$table->timestamps();

				$table->index( array( 'kind', 'is_active' ), 'kind_active' );
				$table->index( 'template_id', 'template' );
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_pricing_rules' );
	}
}
