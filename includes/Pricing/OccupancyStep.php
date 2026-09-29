<?php
/**
 * The occupancy pricing step.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

use RadiusTheme\RadiusHotelBooking\Services\Availability\OccupancyCalculator;

defined( 'ABSPATH' ) || exit;

/**
 * Step 5 of the free PriceResolver (booking-engine §6, position 50, feature
 * 7.13): the room type's occupancy on the unit's local date (the free
 * OccupancyCalculator). Rules are taken most specific first (room type +
 * rate plan > room type > rate plan > all), then highest threshold first;
 * the first whose threshold is reached applies. Nothing applies at 0 %
 * (legacy: no rule before anything is booked).
 */
final class OccupancyStep {

	/**
	 * The step's position in the pricing order.
	 */
	const POSITION = 50;

	/**
	 * Active occupancy rules, for this request (null = not loaded).
	 *
	 * @var object[]|null
	 */
	private static ?array $rules = null;

	/**
	 * The calculator for this request (it caches per room type and date).
	 *
	 * @var OccupancyCalculator|null
	 */
	private static ?OccupancyCalculator $calculator = null;

	/**
	 * `rtbp_price_steps`: add the occupancy step.
	 *
	 * @param array $steps Name => step.
	 * @return array
	 */
	public static function register( $steps ): array {
		$steps              = (array) $steps;
		$steps['occupancy'] = array(
			'position' => self::POSITION,
			'apply'    => array( self::class, 'apply' ),
		);
		return $steps;
	}

	/**
	 * Apply the occupancy rule that is reached, if any.
	 *
	 * @param float $before  Price so far.
	 * @param array $context Pricing context.
	 * @return array|null
	 */
	public static function apply( float $before, array $context ): ?array {
		if ( null === self::$rules ) {
			self::$rules = ( new PricingRuleRepository() )->activeOfKinds( array( PricingRule::OCCUPANCY ) );
		}
		if ( ! self::$rules || ! class_exists( OccupancyCalculator::class ) ) {
			return null;
		}

		$room_type_id = (int) $context['room_type_id'];
		$rate_plan_id = (int) $context['rate_plan_id'];
		$candidates   = array();
		foreach ( self::$rules as $rule ) {
			$rooms = SeasonalStep::ids( $rule->room_type_ids );
			$plans = SeasonalStep::ids( $rule->rate_plan_ids );
			if ( ( $rooms && ! in_array( $room_type_id, $rooms, true ) ) || ( $plans && ! in_array( $rate_plan_id, $plans, true ) ) ) {
				continue;
			}
			$candidates[] = array( array( $rooms ? 1 : 0, $plans ? 1 : 0, (float) $rule->threshold, (int) $rule->id ), $rule );
		}
		if ( ! $candidates ) {
			return null;
		}

		self::$calculator = self::$calculator ?? new OccupancyCalculator();
		$occupancy        = self::$calculator->percent( $room_type_id, (string) $context['date'] );
		if ( $occupancy <= 0 ) {
			return null;
		}

		usort( $candidates, static fn( $a, $b ) => $b[0] <=> $a[0] );
		foreach ( $candidates as $candidate ) {
			$rule = $candidate[1];
			if ( (float) $rule->threshold > $occupancy ) {
				continue;
			}
			$summary = PricingTemplateService::describe( (string) $rule->mode, (string) $rule->amount_type, (float) $rule->amount );
			return array(
				'after'   => PricingTemplateService::apply( $before, (string) $rule->mode, (string) $rule->amount_type, (float) $rule->amount ),
				/* translators: 1: rule name, 2: the adjustment, e.g. "+15 %". */
				'label'   => sprintf( __( '%1$s (%2$s)', 'radius-hotel-booking-pro' ), $rule->name, $summary ),
				'rule_id' => (int) $rule->id,
			);
		}
		return null;
	}

	/**
	 * Forget the cached rules and occupancy (after a write, in tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$rules      = null;
		self::$calculator = null;
	}
}
