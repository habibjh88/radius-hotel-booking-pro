<?php
/**
 * Pricing template data access.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_pricing_templates`.
 */
class PricingTemplateRepository extends BaseRepository {

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = PricingTemplate::class;

	/**
	 * Every template, by name.
	 *
	 * @return PricingTemplate[]
	 */
	public function ordered(): array {
		$rows = PricingTemplate::query()->orderBy( 'name' )->orderBy( 'id' )->get();
		return array_map( static fn( $row ) => PricingTemplate::hydrate( $row ), $rows );
	}

	/**
	 * How many rules use each template.
	 *
	 * @return array<int, int> Template id => rules.
	 */
	public function ruleCounts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one grouped count.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT template_id, COUNT(*) AS n FROM %i WHERE template_id IS NOT NULL GROUP BY template_id', rtbp_table_prefix() . 'pro_pricing_rules' ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->template_id ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Lock a template row until the transaction ends.
	 *
	 * @param int $id Template id.
	 * @return bool Whether it exists.
	 */
	public function lock( int $id ): bool {
		// lockRow() is in the free plugin from M06 (main 99d8d22); without it, no lock.
		if ( ! method_exists( $this, 'lockRow' ) ) {
			return null !== $this->find( $id );
		}
		return $this->lockRow( 'pro_pricing_templates', $id );
	}
}
