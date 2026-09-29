<?php
/**
 * Pricing rules: create, change, delete.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

use RadiusTheme\RadiusHotelBooking\ActivityLog\ChangeDiff;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Support\Dates;
use RadiusTheme\RadiusHotelBooking\Support\Money;

defined( 'ABSPATH' ) || exit;

/**
 * Pricing rules by kind (features 7.12–7.15). Rules only affect new prices:
 * bookings keep theirs. Every kind has a scope (room types, rate plans;
 * empty = all) and an active switch.
 *
 * - seasonal: a template applied to a date range (inclusive), with a priority.
 * - occupancy: once the room type's occupancy on the night reaches
 *   `threshold` %, increase or decrease the price (own adjustment).
 * - early_bird: booked at least `threshold` days before arrival → a discount.
 * - last_minute: booked at most `threshold` days before arrival → a discount.
 */
class PricingRuleService {

	/**
	 * Rules.
	 *
	 * @var PricingRuleRepository
	 */
	private PricingRuleRepository $rules;

	/**
	 * Templates.
	 *
	 * @var PricingTemplateRepository
	 */
	private PricingTemplateRepository $templates;

	/**
	 * Constructor.
	 *
	 * @param PricingRuleRepository|null     $rules     Rules.
	 * @param PricingTemplateRepository|null $templates Templates.
	 */
	public function __construct( ?PricingRuleRepository $rules = null, ?PricingTemplateRepository $templates = null ) {
		$this->rules     = $rules ?? new PricingRuleRepository();
		$this->templates = $templates ?? new PricingTemplateRepository();
	}

	/**
	 * The kinds this version manages.
	 *
	 * @return string[]
	 */
	public static function kinds(): array {
		return array( PricingRule::SEASONAL, PricingRule::OCCUPANCY, PricingRule::EARLY_BIRD, PricingRule::LAST_MINUTE );
	}

	/**
	 * The rules of a kind for the API.
	 *
	 * @param string $kind Kind.
	 * @return array[]
	 */
	public function all( string $kind ): array {
		$this->assertKind( $kind );
		$templates = $this->templateMap();
		return array_map( fn( PricingRule $rule ) => self::shape( $rule, $templates ), $this->rules->ofKind( $kind ) );
	}

	/**
	 * One rule, or 404.
	 *
	 * @param int $id Id.
	 * @return PricingRule
	 * @throws DomainException When missing.
	 */
	public function get( int $id ): PricingRule {
		$rule = $this->rules->find( $id );
		if ( ! $rule instanceof PricingRule ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::notFound( __( 'This pricing rule does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $rule;
	}

	/**
	 * Create a rule.
	 *
	 * @param array $input Fields incl. `kind`.
	 * @return array The rule for the API.
	 * @throws DomainException When the insert did not happen.
	 */
	public function create( array $input ): array {
		$kind = sanitize_key( (string) ( $input['kind'] ?? '' ) );
		$this->assertKind( $kind );
		$data = array( 'kind' => $kind ) + $this->validate( $kind, $input, null );
		$rule = $this->rules->create( $data );
		if ( ! $rule instanceof PricingRule || ! $rule->id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw new DomainException( 'rule_not_saved', __( 'The rule could not be saved. Please try again.', 'radius-hotel-booking-pro' ), 500 );
		}
		self::flushSteps();

		rtbp_activity(
			'pricing.rule_create',
			$rule,
			array(
				'after'       => self::audited( $rule->toArray() ),
				/* translators: %s: rule name. */
				'description' => sprintf( __( 'Added the pricing rule %s', 'radius-hotel-booking-pro' ), $rule->name ),
			)
		);

		return self::shape( $rule, $this->templateMap() );
	}

	/**
	 * Change a rule (only the fields given; the kind never changes).
	 *
	 * @param int   $id    Id.
	 * @param array $input Fields.
	 * @return array The rule for the API.
	 */
	public function update( int $id, array $input ): array {
		$rule   = $this->get( $id );
		$before = self::audited( $rule->toArray() );
		$this->rules->update( $id, $this->validate( (string) $rule->kind, $input, $rule ) );
		self::flushSteps();
		$rule = $this->get( $id );

		$diff = ChangeDiff::between( $before, self::audited( $rule->toArray() ) );
		if ( $diff['after'] ) {
			rtbp_activity(
				'pricing.rule_update',
				$rule,
				$diff + array(
					/* translators: %s: rule name. */
					'description' => sprintf( __( 'Changed the pricing rule %s', 'radius-hotel-booking-pro' ), $rule->name ),
				)
			);
		}

		return self::shape( $rule, $this->templateMap() );
	}

	/**
	 * Delete a rule.
	 *
	 * @param int $id Id.
	 * @return void
	 */
	public function delete( int $id ): void {
		$rule = $this->get( $id );
		$this->rules->delete( $id );
		self::flushSteps();

		rtbp_activity(
			'pricing.rule_delete',
			$rule,
			array(
				'before'      => self::audited( $rule->toArray() ),
				/* translators: %s: rule name. */
				'description' => sprintf( __( 'Deleted the pricing rule %s', 'radius-hotel-booking-pro' ), $rule->name ),
			)
		);
	}

	/**
	 * Forget every step's per-request rule cache (after a write).
	 *
	 * @return void
	 */
	public static function flushSteps(): void {
		SeasonalStep::flush();
		OccupancyStep::flush();
		BookingWindowStep::flush();
	}

	/**
	 * Validate and clean a rule's fields for its kind.
	 *
	 * @param string           $kind  Kind.
	 * @param array            $input Input.
	 * @param PricingRule|null $rule  The rule being changed, or null on create.
	 * @return array Clean columns.
	 * @throws DomainException With field errors.
	 */
	private function validate( string $kind, array $input, ?PricingRule $rule ): array {
		$current = $rule ? $rule->toArray() : array();
		$pick    = static fn( $key, $default ) => array_key_exists( $key, $input ) ? $input[ $key ] : ( $current[ $key ] ?? $default );
		$errors  = array();
		$data    = array();

		// Scope: empty = all; every id must be a live room type / rate plan.
		foreach ( array(
			'room_type_ids' => 'room_types',
			'rate_plan_ids' => 'rate_plans',
		) as $field => $table ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $pick( $field, array() ) ) ) ) );
			if ( $ids && count( $ids ) !== $this->liveCount( $table, $ids ) ) {
				$errors[ $field ] = 'room_type_ids' === $field
					? __( 'One of the room types no longer exists. Reload the page.', 'radius-hotel-booking-pro' )
					: __( 'One of the rate plans no longer exists. Reload the page.', 'radius-hotel-booking-pro' );
			}
			$data[ $field ] = $ids;
		}

		$priority = filter_var( $pick( 'priority', 10 ), FILTER_VALIDATE_INT );
		if ( false === $priority || $priority < -1000 || $priority > 1000 ) {
			$errors['priority'] = __( 'A whole number from -1000 to 1000.', 'radius-hotel-booking-pro' );
		}
		$data['priority']  = false === $priority ? 10 : $priority;
		$data['is_active'] = rest_sanitize_boolean( $pick( 'is_active', true ) ) ? 1 : 0;

		if ( PricingRule::SEASONAL === $kind ) {
			$template_id = absint( $pick( 'template_id', 0 ) );
			$template    = $template_id ? $this->templates->find( $template_id ) : null;
			if ( ! $template ) {
				$errors['template_id'] = __( 'Choose a pricing template.', 'radius-hotel-booking-pro' );
			}
			$from = (string) $pick( 'date_from', '' );
			$to   = (string) $pick( 'date_to', '' );
			if ( ! Dates::is_date( $from ) ) {
				$errors['date_from'] = __( 'Choose the first date.', 'radius-hotel-booking-pro' );
			}
			if ( ! Dates::is_date( $to ) ) {
				$errors['date_to'] = __( 'Choose the last date.', 'radius-hotel-booking-pro' );
			} elseif ( Dates::is_date( $from ) && $to < $from ) {
				$errors['date_to'] = __( 'The last date must not be before the first.', 'radius-hotel-booking-pro' );
			}
			$name = sanitize_text_field( (string) $pick( 'name', '' ) );
			$data += array(
				'template_id' => $template_id,
				'date_from'   => $from,
				'date_to'     => $to,
				// Default name: the template's.
				'name'        => mb_substr( '' !== $name ? $name : (string) ( $template->name ?? '' ), 0, 120 ),
			);
		}

		if ( in_array( $kind, array( PricingRule::OCCUPANCY, PricingRule::EARLY_BIRD, PricingRule::LAST_MINUTE ), true ) ) {
			$data += $this->validateAdjusted( $kind, $pick, $errors, $current );
		}

		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::invalid( $errors );
		}
		return $data;
	}

	/**
	 * The fields of a rule with its own adjustment and a threshold
	 * (occupancy, early bird, last minute).
	 *
	 * @param string   $kind    Kind.
	 * @param callable $pick    Reads a field (input, else current, else default).
	 * @param array    $errors  Errors, added to.
	 * @param array    $current The rule's stored values (empty on create).
	 * @return array Clean columns.
	 */
	private function validateAdjusted( string $kind, callable $pick, array &$errors, array $current = array() ): array {
		$occupancy = PricingRule::OCCUPANCY === $kind;

		// Early bird and last minute are always discounts (7.14, 7.15).
		$mode = $occupancy ? sanitize_key( (string) $pick( 'mode', 'increase' ) ) : 'decrease';
		if ( ! in_array( $mode, array( 'increase', 'decrease' ), true ) ) {
			$errors['mode'] = __( 'Choose increase or decrease.', 'radius-hotel-booking-pro' );
		}
		$type = sanitize_key( (string) $pick( 'amount_type', 'percent' ) );
		if ( ! in_array( $type, PricingTemplateService::AMOUNT_TYPES, true ) ) {
			$errors['amount_type'] = __( 'Choose a percentage or a fixed amount.', 'radius-hotel-booking-pro' );
		}
		$amount = filter_var( $pick( 'amount', null ), FILTER_VALIDATE_FLOAT );
		$max    = 'percent' === $type ? ( 'decrease' === $mode ? 100 : 1000 ) : 99999999;
		if ( false === $amount || $amount <= 0 || $amount > $max ) {
			$errors['amount'] = 'percent' === $type
				/* translators: %d: the largest percentage. */
				? sprintf( __( 'A percentage above 0, up to %d.', 'radius-hotel-booking-pro' ), $max )
				: __( 'An amount above 0.', 'radius-hotel-booking-pro' );
		}

		$threshold = filter_var( $pick( 'threshold', null ), FILTER_VALIDATE_FLOAT );
		if ( $occupancy && ( false === $threshold || $threshold < 1 || $threshold > 100 ) ) {
			$errors['threshold'] = __( 'An occupancy from 1 to 100 %.', 'radius-hotel-booking-pro' );
		} elseif ( ! $occupancy ) {
			// An early bird needs at least 1 day ahead (0 would discount every booking);
			// a last-minute rule may be 0 (booked on the day of arrival).
			$min = PricingRule::EARLY_BIRD === $kind ? 1 : 0;
			if ( false === $threshold || $threshold < $min || $threshold > 730 || floor( $threshold ) !== $threshold ) {
				/* translators: %d: the smallest number of days. */
				$errors['threshold'] = sprintf( __( 'A whole number of days from %d to 730.', 'radius-hotel-booking-pro' ), $min );
			}
		}
		$threshold = false === $threshold ? 0 : ( $occupancy ? round( $threshold, 2 ) : (int) $threshold );

		// A name still equal to the automatic one follows the threshold, so the
		// label on prices never says "30+ days" for a 60-day rule.
		$name    = sanitize_text_field( (string) $pick( 'name', '' ) );
		$was_auto = isset( $current['name'], $current['threshold'] ) && $current['name'] === self::defaultName( $kind, (float) $current['threshold'] );
		if ( '' === $name || ( $was_auto && $name === $current['name'] ) ) {
			$name = self::defaultName( $kind, (float) $threshold );
		}

		return array(
			'name'        => mb_substr( $name, 0, 120 ),
			'template_id' => null,
			'mode'        => $mode,
			'amount_type' => $type,
			'amount'      => 'fixed' === $type ? Money::round( (float) $amount ) : round( (float) $amount, 2 ),
			'threshold'   => $threshold,
			'date_from'   => null,
			'date_to'     => null,
		);
	}

	/**
	 * "Occupancy 80 %+", "Early bird 30+ days", "Last minute ≤ 3 days".
	 *
	 * @param string $kind      Kind.
	 * @param float  $threshold Threshold.
	 * @return string
	 */
	public static function defaultName( string $kind, float $threshold ): string {
		$value = rtrim( rtrim( number_format( $threshold, 2, '.', '' ), '0' ), '.' );
		switch ( $kind ) {
			case PricingRule::OCCUPANCY:
				/* translators: %s: occupancy percentage. */
				return sprintf( __( 'Occupancy %s %%+', 'radius-hotel-booking-pro' ), $value );
			case PricingRule::EARLY_BIRD:
				/* translators: %s: number of days. */
				return sprintf( __( 'Early bird %s+ days', 'radius-hotel-booking-pro' ), $value );
			default:
				/* translators: %s: number of days. */
				return sprintf( __( 'Last minute ≤ %s days', 'radius-hotel-booking-pro' ), $value );
		}
	}

	/**
	 * How many of the ids are live rows of a free table (not soft-deleted).
	 *
	 * @param string $table Free table name (`room_types`, `rate_plans`).
	 * @param int[]  $ids   Ids.
	 * @return int
	 */
	private function liveCount( string $table, array $ids ): int {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is only "%d,%d,…", one per id.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE deleted_at IS NULL AND id IN ( $placeholders )",
				array_merge( array( rtbp_table( $table ) ), $ids )
			)
		);
		// phpcs:enable
		return $count;
	}

	/**
	 * Refuse a kind this version does not manage.
	 *
	 * @param string $kind Kind.
	 * @return void
	 * @throws DomainException 422.
	 */
	private function assertKind( string $kind ): void {
		if ( ! in_array( $kind, self::kinds(), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::invalid( array( 'kind' => __( 'Unknown kind of pricing rule.', 'radius-hotel-booking-pro' ) ) );
		}
	}

	/**
	 * Templates by id.
	 *
	 * @return array<int, PricingTemplate>
	 */
	private function templateMap(): array {
		$out = array();
		foreach ( $this->templates->ordered() as $template ) {
			$out[ (int) $template->id ] = $template;
		}
		return $out;
	}

	/**
	 * A rule for the API.
	 *
	 * @param PricingRule $rule      Rule.
	 * @param array       $templates Templates by id.
	 * @return array
	 */
	public static function shape( PricingRule $rule, array $templates ): array {
		$template = null !== $rule->template_id ? ( $templates[ (int) $rule->template_id ] ?? null ) : null;
		return array(
			'id'            => (int) $rule->id,
			'kind'          => (string) $rule->kind,
			'name'          => (string) $rule->name,
			'template_id'   => null === $rule->template_id ? null : (int) $rule->template_id,
			'template'      => $template ? PricingTemplateService::shape( $template, 0 ) : null,
			'room_type_ids' => array_values( array_map( 'intval', (array) $rule->room_type_ids ) ),
			'rate_plan_ids' => array_values( array_map( 'intval', (array) $rule->rate_plan_ids ) ),
			'mode'          => $rule->mode,
			'amount_type'   => $rule->amount_type,
			'amount'        => null === $rule->amount ? null : (float) $rule->amount,
			'threshold'     => null === $rule->threshold ? null : (float) $rule->threshold,
			'summary'       => null === $rule->mode ? null : PricingTemplateService::describe( (string) $rule->mode, (string) $rule->amount_type, (float) $rule->amount ),
			'date_from'     => $rule->date_from,
			'date_to'       => $rule->date_to,
			'priority'      => (int) $rule->priority,
			'is_active'     => (bool) $rule->is_active,
		);
	}

	/**
	 * The fields the activity log compares.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function audited( array $row ): array {
		$keys = array( 'name', 'template_id', 'mode', 'amount_type', 'amount', 'room_type_ids', 'rate_plan_ids', 'date_from', 'date_to', 'threshold', 'priority', 'is_active' );
		$out  = array_intersect_key( $row, array_flip( $keys ) );
		foreach ( array( 'template_id', 'priority' ) as $int ) {
			if ( isset( $out[ $int ] ) ) {
				$out[ $int ] = (int) $out[ $int ];
			}
		}
		if ( isset( $out['is_active'] ) ) {
			$out['is_active'] = (bool) $out['is_active'];
		}
		return $out;
	}
}
