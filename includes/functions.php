<?php
/**
 * Global helpers for Pro.
 *
 * @package RadiusTheme\RadiusHotelBookingPro
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rtbp_pro_feature_active' ) ) {
	/**
	 * Whether a Pro feature is running in this request: switched on, built,
	 * its requirements on, and the free plugin recent enough.
	 *
	 * Use this in Pro and client add-on code. The free plugin must never call
	 * it (ADR-014): free code is never gated on Pro.
	 *
	 * @param string $key Feature key, e.g. 'activity_log'.
	 * @return bool
	 */
	function rtbp_pro_feature_active( string $key ): bool {
		$features = \RadiusTheme\RadiusHotelBookingPro\Plugin::instance()->features();
		return $features ? $features->is_active( $key ) : false;
	}
}
