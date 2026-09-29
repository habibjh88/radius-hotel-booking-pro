<?php
/**
 * Pricing rule data access.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_pricing_rules`.
 */
class PricingRuleRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = PricingRule::class;

	/**
	 * The rules of one kind, for the screens: highest priority first, then
	 * the earliest dates, then newest.
	 *
	 * @param string $kind Kind.
	 * @return PricingRule[]
	 */
	public function ofKind( string $kind ): array {
		$rows = PricingRule::query()->where( 'kind', '=', $kind )->orderBy( 'priority', 'DESC' )->orderBy( 'date_from' )->orderBy( 'id', 'DESC' )->get();
		return array_map( static fn( $row ) => PricingRule::hydrate( $row ), $rows );
	}

	/**
	 * The active seasonal rules covering a local date, with their template's
	 * adjustment (raw rows; one query).
	 *
	 * @param string $date Local date `Y-m-d`.
	 * @return object[]
	 */
	public function seasonalOn( string $date ): array {
		global $wpdb;
		$prefix = rtbp_table_prefix();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- per-request cached by SeasonalStep.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT r.id, r.name, r.room_type_ids, r.rate_plan_ids, r.priority, t.name AS template_name, t.mode, t.amount_type, t.amount
				 FROM %i r JOIN %i t ON t.id = r.template_id
				 WHERE r.kind = %s AND r.is_active = 1 AND r.date_from <= %s AND r.date_to >= %s',
				$prefix . 'pro_pricing_rules',
				$prefix . 'pro_pricing_templates',
				PricingRule::SEASONAL,
				$date,
				$date
			)
		);
	}

	/**
	 * The active rules of some kinds with their own adjustment (raw rows;
	 * one query). Used by the occupancy and booking-window steps.
	 *
	 * @param string[] $kinds Kinds.
	 * @return object[]
	 */
	public function activeOfKinds( array $kinds ): array {
		global $wpdb;
		$kinds = array_values( array_filter( array_map( 'sanitize_key', $kinds ) ) );
		if ( ! $kinds ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is only "%s,%s,…"; per-request cached by the steps.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, kind, name, mode, amount_type, amount, room_type_ids, rate_plan_ids, threshold FROM %i WHERE is_active = 1 AND kind IN ( $placeholders )",
				array_merge( array( rtbp_table_prefix() . 'pro_pricing_rules' ), $kinds )
			)
		);
		// phpcs:enable
		return (array) $rows;
	}
}
