<?php
/**
 * Pricing template model.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * A reusable price adjustment (table `pro_pricing_templates`, feature 7.11).
 *
 * @property int    $id
 * @property string $name
 * @property string $mode        increase | decrease | set
 * @property string $amount_type percent | fixed
 * @property string $amount
 */
class PricingTemplate extends BaseModel {

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_pricing_templates';

	/**
	 * Mass-assignable columns.
	 *
	 * @var array
	 */
	protected array $fillable = array( 'name', 'mode', 'amount_type', 'amount' );

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id' => 'int',
	);
}
