<?php
/**
 * The seasonal pricing step.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

defined( 'ABSPATH' ) || exit;

/**
 * Step 4 of the free PriceResolver (booking-engine §6, position 40): the one
 * seasonal rule covering the unit's local date wins by higher `priority`,
 * then the more specific scope (room type + rate plan > room type > rate
 * plan > all), then the newest; its template adjusts the price (never below 0).
 */
final class SeasonalStep {

	/**
	 * The step's position in the pricing order.
	 */
	const POSITION = 40;

	/**
	 * Active seasonal rules per local date, for this request.
	 *
	 * @var array<string, object[]>
	 */
	private static array $cache = array();

	/**
	 * `rtbp_price_steps`: add the seasonal step.
	 *
	 * @param array $steps Name => step.
	 * @return array
	 */
	public static function register( $steps ): array {
		$steps             = (array) $steps;
		$steps['seasonal'] = array(
			'position' => self::POSITION,
			'apply'    => array( self::class, 'apply' ),
		);
		return $steps;
	}

	/**
	 * Apply the winning seasonal rule to a unit's price.
	 *
	 * @param float $before  Price so far.
	 * @param array $context Pricing context (room_type_id, rate_plan_id, date, …).
	 * @return array|null `{ after, label, rule_id }`, or null when no rule covers the date.
	 */
	public static function apply( float $before, array $context ): ?array {
		$rule = self::winner( (int) $context['room_type_id'], (int) $context['rate_plan_id'], (string) $context['date'] );
		if ( ! $rule ) {
			return null;
		}
		$summary = PricingTemplateService::describe( (string) $rule->mode, (string) $rule->amount_type, (float) $rule->amount );
		return array(
			'after'   => PricingTemplateService::apply( $before, (string) $rule->mode, (string) $rule->amount_type, (float) $rule->amount ),
			/* translators: 1: rule or template name, 2: the adjustment, e.g. "+20 %". */
			'label'   => sprintf( __( '%1$s (%2$s)', 'radius-hotel-booking-pro' ), '' !== (string) $rule->name ? $rule->name : $rule->template_name, $summary ),
			'rule_id' => (int) $rule->id,
		);
	}

	/**
	 * The seasonal rule that applies, or null.
	 *
	 * @param int    $room_type_id Room type id.
	 * @param int    $rate_plan_id Rate plan id.
	 * @param string $date         Local date `Y-m-d`.
	 * @return object|null
	 */
	public static function winner( int $room_type_id, int $rate_plan_id, string $date ): ?object {
		if ( ! isset( self::$cache[ $date ] ) ) {
			self::$cache[ $date ] = ( new PricingRuleRepository() )->seasonalOn( $date );
		}

		$matching = array();
		foreach ( self::$cache[ $date ] as $rule ) {
			$rooms = self::ids( $rule->room_type_ids );
			$plans = self::ids( $rule->rate_plan_ids );
			if ( ( $rooms && ! in_array( $room_type_id, $rooms, true ) ) || ( $plans && ! in_array( $rate_plan_id, $plans, true ) ) ) {
				continue;
			}
			$matching[] = array( array( (int) $rule->priority, $rooms ? 1 : 0, $plans ? 1 : 0, (int) $rule->id ), $rule );
		}
		if ( ! $matching ) {
			return null;
		}
		// Highest first on every key: priority, room scope, plan scope, newest.
		usort( $matching, static fn( $a, $b ) => $b[0] <=> $a[0] );
		return $matching[0][1];
	}

	/**
	 * Forget the cached rules (after a rule or template changed).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$cache = array();
	}

	/**
	 * A stored id list as ints. The free model's `json` cast stores arrays
	 * serialized (maybe_serialize), so read it the way the model does.
	 *
	 * @param mixed $stored Serialized, JSON or array.
	 * @return int[]
	 */
	public static function ids( $stored ): array {
		$list = is_array( $stored ) ? $stored : maybe_unserialize( (string) $stored );
		if ( is_string( $list ) ) {
			$list = json_decode( $list, true );
		}
		return array_values( array_filter( array_map( 'intval', is_array( $list ) ? $list : array() ) ) );
	}
}
