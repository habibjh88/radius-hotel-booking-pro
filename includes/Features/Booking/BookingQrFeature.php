<?php
/**
 * Booking QR codes.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Booking
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Booking;

use RadiusTheme\RadiusHotelBookingPro\BookingQr\BookingQr;
use RadiusTheme\RadiusHotelBookingPro\BookingQr\QrImage;
use RadiusTheme\RadiusHotelBookingPro\BookingQr\QrSlots;
use RadiusTheme\RadiusHotelBookingPro\BookingQr\ScanController;
use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `booking_qr`: each booking gets a QR code on the guest's e-mails,
 * booking page, invoice and receipts. At arrival, staff scan it with a
 * phone (the link opens the booking in the dashboard), a handheld scanner
 * or the dashboard's *Scan a booking* button. Switched off, the codes and
 * links are gone; codes already sent answer 404 until it is back on.
 */
final class BookingQrFeature extends AbstractFeature {

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'RadiusTheme\\RadiusHotelBooking\\Models\\Booking',
			'RadiusTheme\\RadiusHotelBooking\\Services\\Payments\\GuestBookingView',
			'RadiusTheme\\RadiusHotelBooking\\Core\\Api\\ApiResponse',
			'RadiusTheme\\RadiusHotelBooking\\Exceptions\\DomainException',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		// The scan API works without the image library: typed references and scanners still resolve.
		add_action( 'rtbp_register_addon_routes', array( ScanController::class, 'routes' ) );
		BookingQr::init();
		if ( ! QrImage::available() ) {
			add_action( 'admin_notices', array( $this, 'missingLibrary' ) );
			return;
		}
		QrSlots::init();
	}

	/**
	 * Admin notice: the QR library is not installed.
	 *
	 * @return void
	 */
	public function missingLibrary(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Radius Hotel Booking Pro: booking QR codes are switched on, but the QR library is missing. Run `composer scope` in the plugin folder, or reinstall the plugin.', 'radius-hotel-booking-pro' ) . '</p></div>';
	}
}
