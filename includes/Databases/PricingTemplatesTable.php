<?php
/**
 * Pricing templates table (M07, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

use RadiusTheme\RadiusHotelBooking\Abstracts\Migration;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Blueprint;
use RadiusTheme\RadiusHotelBooking\Core\Database\Schema\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * `…pro_pricing_templates`: reusable adjustments (feature 7.11) — *increase
 * 20 %*, *decrease 5 000*, *set 25 000*. Seasonal rules point to one.
 */
class PricingTemplatesTable extends Migration {

	/**
	 * Create or upgrade the table (dbDelta: safe to run again).
	 *
	 * @return void
	 */
	public function up(): void {
		Schema::create(
			$this->tablePrefix . 'pro_pricing_templates',
			function ( Blueprint $table ) {
				$table->id();
				$table->string( 'name', 120 );
				$table->string( 'mode', 20 )->default( 'increase' );
				$table->string( 'amount_type', 20 )->default( 'percent' );
				$table->decimal( 'amount', 12, 2 )->default( 0 );
				$table->timestamps();
			}
		);
	}

	/**
	 * Drop the table.
	 *
	 * @return void
	 */
	public function down(): void {
		Schema::drop( $this->tablePrefix . 'pro_pricing_templates' );
	}
}
