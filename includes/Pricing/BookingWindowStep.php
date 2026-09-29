<?php
/**
 * The booking-window (early bird / last minute) pricing step.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Step 6 of the free PriceResolver (booking-engine §6, position 60,
 * features 7.14, 7.15): days between the booking date and the arrival date.
 * `early_bird` matches when days ≥ threshold, `last_minute` when days ≤
 * threshold. Only one rule applies: the most specific (room type counts 2,
 * rate plan 1), then early bird over last minute, then the highest early
 * threshold / the lowest late threshold, then the newest. Always a discount.
 *
 * Deviation from the legacy system, on purpose (booking-engine §6): the days
 * are counted to the arrival, not to each night, so a stay gets one
 * consistent discount.
 */
final class BookingWindowStep {

	/**
	 * The step's position in the pricing order.
	 */
	const POSITION = 60;

	/**
	 * Active booking-window rules, for this request (null = not loaded).
	 *
	 * @var object[]|null
	 */
	private static ?array $rules = null;

	/**
	 * `rtbp_price_steps`: add the booking-window step.
	 *
	 * @param array $steps Name => step.
	 * @return array
	 */
	public static function register( $steps ): array {
		$steps                   = (array) $steps;
		$steps['booking_window'] = array(
			'position' => self::POSITION,
			'apply'    => array( self::class, 'apply' ),
		);
		return $steps;
	}

	/**
	 * Apply the winning booking-window rule, if any.
	 *
	 * @param float $before  Price so far.
	 * @param array $context Pricing context (`arrival`, `booked_at` as `Y-m-d`).
	 * @return array|null
	 */
	public static function apply( float $before, array $context ): ?array {
		if ( null === self::$rules ) {
			self::$rules = ( new PricingRuleRepository() )->activeOfKinds( array( PricingRule::EARLY_BIRD, PricingRule::LAST_MINUTE ) );
		}
		$days = self::daysAhead( (string) ( $context['booked_at'] ?? '' ), (string) ( $context['arrival'] ?? '' ) );
		if ( ! self::$rules || null === $days || $days < 0 ) {
			return null;
		}

		$rule = self::winner( self::$rules, (int) $context['room_type_id'], (int) $context['rate_plan_id'], $days );
		if ( ! $rule ) {
			return null;
		}
		$summary = PricingTemplateService::describe( 'decrease', (string) $rule->amount_type, (float) $rule->amount );
		return array(
			'after'   => PricingTemplateService::apply( $before, 'decrease', (string) $rule->amount_type, (float) $rule->amount ),
			/* translators: 1: rule name, 2: the discount, e.g. "−10 %". */
			'label'   => sprintf( __( '%1$s (%2$s)', 'radius-hotel-booking-pro' ), $rule->name, $summary ),
			'rule_id' => (int) $rule->id,
		);
	}

	/**
	 * The rule that applies to a stay booked `$days` days ahead, or null.
	 *
	 * @param object[] $rules        Active early-bird / last-minute rules.
	 * @param int      $room_type_id Room type id.
	 * @param int      $rate_plan_id Rate plan id.
	 * @param int      $days         Days between booking and arrival.
	 * @return object|null
	 */
	public static function winner( array $rules, int $room_type_id, int $rate_plan_id, int $days ): ?object {
		$candidates = array();
		foreach ( $rules as $rule ) {
			$rooms = SeasonalStep::ids( $rule->room_type_ids );
			$plans = SeasonalStep::ids( $rule->rate_plan_ids );
			if ( ( $rooms && ! in_array( $room_type_id, $rooms, true ) ) || ( $plans && ! in_array( $rate_plan_id, $plans, true ) ) ) {
				continue;
			}
			$threshold = (int) $rule->threshold;
			$early     = PricingRule::EARLY_BIRD === $rule->kind;
			if ( $early ? $days < $threshold : $days > $threshold ) {
				continue;
			}
			// Sort key, highest wins: specificity, early over late, the
			// tighter threshold (highest early / lowest late), newest.
			$candidates[] = array(
				array( ( $rooms ? 2 : 0 ) + ( $plans ? 1 : 0 ), $early ? 1 : 0, $early ? $threshold : -$threshold, (int) $rule->id ),
				$rule,
			);
		}
		if ( ! $candidates ) {
			return null;
		}
		usort( $candidates, static fn( $a, $b ) => $b[0] <=> $a[0] );
		return $candidates[0][1];
	}

	/**
	 * Calendar days from the booking date to the arrival date.
	 *
	 * @param string $booked_at Local date `Y-m-d`.
	 * @param string $arrival   Local date `Y-m-d`.
	 * @return int|null Null when a date is invalid.
	 */
	public static function daysAhead( string $booked_at, string $arrival ): ?int {
		$utc  = new DateTimeZone( 'UTC' );
		$from = DateTimeImmutable::createFromFormat( '!Y-m-d', $booked_at, $utc );
		$to   = DateTimeImmutable::createFromFormat( '!Y-m-d', $arrival, $utc );
		if ( ! $from || ! $to ) {
			return null;
		}
		return (int) round( ( $to->getTimestamp() - $from->getTimestamp() ) / DAY_IN_SECONDS );
	}

	/**
	 * Forget the cached rules (after a write, in tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$rules = null;
	}
}
