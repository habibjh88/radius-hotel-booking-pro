<?php
/**
 * Plugin Name:       Radius Hotel Booking Pro
 * Description:       Pro features for Radius Hotel Booking: activity log, advanced staff access, advanced pricing, iCal sync, PDF documents, advanced reports and exports, staff records and self-service. Each feature can be switched on or off.
 * Plugin URI:        https://radiustheme.com
 * Version:           1.0.0
 * Author:            RadiusTheme
 * Author URI:        https://radiustheme.com
 * Text Domain:       radius-hotel-booking-pro
 * Domain Path:       /languages
 * Requires PHP:      8.0
 * Requires at least: 6.5
 * Requires Plugins:  radius-hotel-booking
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package RadiusTheme\RadiusHotelBookingPro
 */

defined( 'ABSPATH' ) || exit;

/*
 * Defined at load time on purpose: the free plugin's rtbp_addon_active() reads
 * this constant, and it is only ever used there to decide whether to show the
 * upgrade notice (ADR-016). Features are never gated in the free plugin.
 */
define( 'RADIUS_HOTEL_BOOKING_PRO_VERSION', '1.0.0' );
// Bump whenever a Pro table changes; Databases\Installer re-runs the Pro tables.
define( 'RADIUS_HOTEL_BOOKING_PRO_DB_VERSION', '1.3.0' );
define( 'RADIUS_HOTEL_BOOKING_PRO_FILE', __FILE__ );
define( 'RADIUS_HOTEL_BOOKING_PRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'RADIUS_HOTEL_BOOKING_PRO_URL', plugin_dir_url( __FILE__ ) );
define( 'RADIUS_HOTEL_BOOKING_PRO_SLUG', 'radius-hotel-booking-pro' );

if ( ! file_exists( RADIUS_HOTEL_BOOKING_PRO_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Radius Hotel Booking Pro is missing its vendor folder. Run `composer install` in the plugin directory.', 'radius-hotel-booking-pro' ) . '</p></div>';
		}
	);
	return;
}

require_once RADIUS_HOTEL_BOOKING_PRO_DIR . 'vendor/autoload.php';
require_once RADIUS_HOTEL_BOOKING_PRO_DIR . 'includes/functions.php';

RadiusTheme\RadiusHotelBookingPro\Plugin::instance();
