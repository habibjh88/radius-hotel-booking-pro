<?php
/**
 * Pro admin assets.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Assets
 */

namespace RadiusTheme\RadiusHotelBookingPro\Assets;

use RadiusTheme\RadiusHotelBookingPro\Features\FeatureRegistry;
use RadiusTheme\RadiusHotelBookingPro\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the Pro admin bundle into the free plugin's admin app.
 *
 * The free plugin fires `rtbp_admin_enqueue_scripts` right after it enqueues
 * its own app, so the Pro bundle loads after `window.rtbp` exists and extends
 * the app through the `rtbp.*` JS filters. On a free version without that
 * hook, nothing is enqueued and the Pro settings tab simply does not appear.
 */
final class Assets {

	/**
	 * Script handle.
	 */
	const HANDLE = 'radius-hotel-booking-pro-admin';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rtbp_admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	/**
	 * Enqueue the admin bundle and its data.
	 *
	 * @param string $free_handle The free admin app's script handle.
	 * @return void
	 */
	public function enqueue_admin( $free_handle ): void {
		$asset_file = RADIUS_HOTEL_BOOKING_PRO_DIR . 'build/admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return; // Not built yet: run `npm run build` in the Pro plugin.
		}

		$asset = require $asset_file;
		$deps  = array_merge( (array) ( $asset['dependencies'] ?? array() ), array( (string) $free_handle ) );

		wp_enqueue_script(
			self::HANDLE,
			RADIUS_HOTEL_BOOKING_PRO_URL . 'build/admin.js',
			array_unique( $deps ),
			$asset['version'] ?? RADIUS_HOTEL_BOOKING_PRO_VERSION,
			true
		);

		wp_set_script_translations( self::HANDLE, 'radius-hotel-booking-pro', RADIUS_HOTEL_BOOKING_PRO_DIR . 'languages' );

		$features = Plugin::instance()->features();

		wp_add_inline_script(
			self::HANDLE,
			'window.rtbpPro = ' . wp_json_encode(
				array(
					'version'  => RADIUS_HOTEL_BOOKING_PRO_VERSION,
					'features' => $features ? $features->describe() : array(),
					'groups'   => FeatureRegistry::groups(),
				)
			) . ';',
			'before'
		);
	}
}
