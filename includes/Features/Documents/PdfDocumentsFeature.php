<?php
/**
 * PDF invoices and receipts (M05 T7).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Documents
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Documents;

use RadiusTheme\RadiusHotelBookingPro\Documents\PdfAttachments;
use RadiusTheme\RadiusHotelBookingPro\Documents\PdfRenderer;
use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `pdf_documents`: the `pdf` format of the free document registry
 * (*Download PDF* on the booking and its payments, and the guest's links), and
 * the PDF attached to the *booking received* and *payment received* e-mails.
 * Switched off, everything falls back to the free HTML print view.
 */
final class PdfDocumentsFeature extends AbstractFeature {

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'RadiusTheme\\RadiusHotelBooking\\Documents\\DocumentService',
			'RadiusTheme\\RadiusHotelBooking\\Models\\Booking',
			'RadiusTheme\\RadiusHotelBooking\\Models\\Payment',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( ! PdfRenderer::available() ) {
			// A development copy without `composer scope`: say so, offer nothing.
			add_action( 'admin_notices', array( $this, 'missingLibrary' ) );
			return;
		}
		add_filter( 'rtbp_document_renderers', array( PdfRenderer::class, 'register' ) );
		add_filter( 'rtbp_be_before_send_email_guest_booking_received', array( PdfAttachments::class, 'invoice' ) );
		add_filter( 'rtbp_be_before_send_email_guest_payment_received', array( PdfAttachments::class, 'receipt' ) );
	}

	/**
	 * Admin notice: the PDF library is not installed.
	 *
	 * @return void
	 */
	public function missingLibrary(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'Radius Hotel Booking Pro: PDF invoices are switched on, but the PDF library is missing. Run `composer scope` in the plugin folder, or reinstall the plugin.', 'radius-hotel-booking-pro' ) . '</p></div>';
	}
}
