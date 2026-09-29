<?php
/**
 * Pricing rule model.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseModel;

defined( 'ABSPATH' ) || exit;

/**
 * A pricing rule (table `pro_pricing_rules`, features 7.12–7.15). Nullable
 * columns (template, adjustment, dates, threshold) are not cast: a scalar
 * cast would read NULL as 0.
 *
 * @property int         $id
 * @property string      $kind
 * @property string      $name
 * @property string|null $template_id
 * @property string|null $mode
 * @property string|null $amount_type
 * @property string|null $amount
 * @property array       $room_type_ids
 * @property array       $rate_plan_ids
 * @property string|null $date_from
 * @property string|null $date_to
 * @property string|null $threshold
 * @property int         $priority
 * @property bool        $is_active
 */
class PricingRule extends BaseModel {

	const SEASONAL    = 'seasonal';
	const OCCUPANCY   = 'occupancy';
	const EARLY_BIRD  = 'early_bird';
	const LAST_MINUTE = 'last_minute';

	/**
	 * Table, without the WordPress prefix.
	 *
	 * @var string
	 */
	protected string $table = 'radius_hotel_booking_pro_pricing_rules';

	/**
	 * Mass-assignable columns.
	 *
	 * @var array
	 */
	protected array $fillable = array(
		'kind',
		'name',
		'template_id',
		'mode',
		'amount_type',
		'amount',
		'room_type_ids',
		'rate_plan_ids',
		'date_from',
		'date_to',
		'threshold',
		'priority',
		'is_active',
	);

	/**
	 * Casts.
	 *
	 * @var array
	 */
	protected array $casts = array(
		'id'            => 'int',
		'room_type_ids' => 'json',
		'rate_plan_ids' => 'json',
		'priority'      => 'int',
		'is_active'     => 'bool',
	);
}
