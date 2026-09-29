<?php
/**
 * Pricing rules (M07).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Pricing;

use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;
use RadiusTheme\RadiusHotelBookingPro\Pricing\BookingWindowStep;
use RadiusTheme\RadiusHotelBookingPro\Pricing\OccupancyStep;
use RadiusTheme\RadiusHotelBookingPro\Pricing\PricingController;
use RadiusTheme\RadiusHotelBookingPro\Pricing\SeasonalStep;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `pricing_rules`: pricing templates and seasonal rules (T5a), then
 * occupancy and booking-window rules (T6a), inserted into the free
 * PriceResolver through `rtbp_price_steps`. Switched off, prices follow the
 * free steps only; the rules and templates stay.
 */
final class PricingRulesFeature extends AbstractFeature {

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_activity',
			'rtbp_table',
			'rtbp_table_prefix',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Pricing\\PriceResolver',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Availability\\OccupancyCalculator',
			'RadiusTheme\\RadiusHotelBooking\\ActivityLog\\ChangeDiff',
			'RadiusTheme\\RadiusHotelBooking\\Core\\Database\\Transaction',
			'RadiusTheme\\RadiusHotelBooking\\Exceptions\\DomainException',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Dates',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Money',
			'RadiusTheme\\RadiusHotelBooking\\Abstracts\\BaseRepository',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		add_filter( 'rtbp_price_steps', array( SeasonalStep::class, 'register' ) );
		add_filter( 'rtbp_price_steps', array( OccupancyStep::class, 'register' ) );
		add_filter( 'rtbp_price_steps', array( BookingWindowStep::class, 'register' ) );
		add_action( 'rtbp_register_addon_routes', array( PricingController::class, 'routes' ) );
		add_filter( 'rtbp_activity_actions', array( $this, 'actions' ) );
	}

	/**
	 * `rtbp_activity_actions`: the pricing actions.
	 *
	 * @param array $actions Key => definition.
	 * @return array
	 */
	public function actions( $actions ): array {
		$actions = (array) $actions;
		$labels  = array(
			'pricing.template_create' => __( 'Added a pricing template', 'radius-hotel-booking-pro' ),
			'pricing.template_update' => __( 'Changed a pricing template', 'radius-hotel-booking-pro' ),
			'pricing.template_delete' => __( 'Deleted a pricing template', 'radius-hotel-booking-pro' ),
			'pricing.rule_create'     => __( 'Added a pricing rule', 'radius-hotel-booking-pro' ),
			'pricing.rule_update'     => __( 'Changed a pricing rule', 'radius-hotel-booking-pro' ),
			'pricing.rule_delete'     => __( 'Deleted a pricing rule', 'radius-hotel-booking-pro' ),
		);
		foreach ( $labels as $key => $label ) {
			$actions[ $key ] = array(
				'kind'  => 'data',
				'label' => $label,
			);
		}
		return $actions;
	}
}
