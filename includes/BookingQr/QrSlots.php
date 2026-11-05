<?php
/**
 * Where the booking QR code is shown.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\BookingQr
 */

namespace RadiusTheme\RadiusHotelBookingPro\BookingQr;

defined( 'ABSPATH' ) || exit;

/**
 * The code, through the free plugin's hooks:
 * - the guest e-mails *booking received*, *booking approved* and *payment
 *   received* (`rtbp_be_before_send_email_{id}` adds it to the data,
 *   `rtbp_email_after_content` prints it — a PNG link, mail clients drop
 *   data URIs);
 * - the guest's booking page (`rtbp_booking_confirmation_after_status`);
 * - the invoice and the receipt, printed or PDF (`rtbp_document_before_footer`).
 *
 * Only while the booking can still arrive (BookingQr::ACTIVE).
 */
final class QrSlots {

	/**
	 * The e-mails that carry the code.
	 */
	const EMAILS = array( 'guest_booking_received', 'guest_booking_approve', 'guest_payment_received' );

	/**
	 * Key in the e-mail data.
	 */
	const DATA_KEY = 'rtbp_pro_booking_qr';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function init(): void {
		foreach ( self::EMAILS as $id ) {
			add_filter( 'rtbp_be_before_send_email_' . $id, array( self::class, 'emailData' ) );
		}
		add_action( 'rtbp_email_after_content', array( self::class, 'email' ), 10, 2 );
		add_action( 'rtbp_booking_confirmation_after_status', array( self::class, 'page' ) );
		add_action( 'rtbp_document_before_footer', array( self::class, 'document' ), 10, 2 );
	}

	/**
	 * Add the code's image to a guest e-mail.
	 *
	 * @param array $email_data E-mail data.
	 * @return array
	 */
	public static function emailData( $email_data ): array {
		$email_data = (array) $email_data;
		if ( ! QrImage::canPng() ) {
			return $email_data;
		}
		$booking = BookingQr::byReference( (string) ( $email_data['merge_tags']['booking_reference'] ?? '' ) );
		if ( ! BookingQr::shows( $booking ) ) {
			return $email_data;
		}
		$email_data['data']                   = (array) ( $email_data['data'] ?? array() );
		$email_data['data'][ self::DATA_KEY ] = array(
			'image'     => BookingQr::imageUrl( $booking ),
			'reference' => (string) $booking->reference,
		);
		return $email_data;
	}

	/**
	 * `rtbp_email_after_content`: print the code.
	 *
	 * @param object $email The e-mail.
	 * @param array  $data  Its data.
	 * @return void
	 */
	public static function email( $email, $data ): void {
		$qr = is_array( $data ) ? ( $data[ self::DATA_KEY ] ?? null ) : null;
		if ( empty( $qr['image'] ) ) {
			return;
		}
		?>
		<table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-collapse: collapse; margin: 24px 0 8px; border: 1px solid #e5e7eb; border-radius: 8px;">
			<tr><td align="center" style="padding: 16px 12px;">
				<p style="margin: 0 0 4px; font-size: 16px; font-weight: bold; color: #111827;"><?php esc_html_e( 'Your booking QR code', 'radius-hotel-booking-pro' ); ?></p>
				<p style="margin: 0 0 12px; font-size: 14px; color: #374151;"><?php esc_html_e( 'Show this code at reception when you arrive: we will find your booking at once.', 'radius-hotel-booking-pro' ); ?></p>
				<img src="<?php echo esc_url( $qr['image'] ); ?>" width="180" height="180" alt="<?php echo esc_attr( sprintf( /* translators: %s: booking reference. */ __( 'QR code for booking %s', 'radius-hotel-booking-pro' ), $qr['reference'] ) ); ?>" style="display: block; width: 180px; height: 180px; border: 0;" />
				<p style="margin: 8px 0 0; font-size: 13px; color: #6b7280; font-family: monospace;"><?php echo esc_html( $qr['reference'] ); ?></p>
			</td></tr>
		</table>
		<?php
	}

	/**
	 * `rtbp_booking_confirmation_after_status`: a card on the guest's booking page.
	 *
	 * @param array $view `GuestBookingView::data()`.
	 * @return void
	 */
	public static function page( $view ): void {
		$booking = BookingQr::byReference( (string) ( $view['reference'] ?? '' ) );
		$src     = BookingQr::shows( $booking ) ? QrImage::dataUri( BookingQr::scanUrl( $booking ) ) : '';
		if ( '' === $src ) {
			return;
		}
		?>
		<div class="card" style="text-align: center;">
			<h2><?php esc_html_e( 'Your booking QR code', 'radius-hotel-booking-pro' ); ?></h2>
			<p class="muted" style="margin: 0 0 12px;"><?php esc_html_e( 'Show this code at reception when you arrive: we will find your booking at once.', 'radius-hotel-booking-pro' ); ?></p>
			<img src="<?php echo esc_attr( $src ); ?>" width="200" height="200" alt="<?php echo esc_attr( sprintf( /* translators: %s: booking reference. */ __( 'QR code for booking %s', 'radius-hotel-booking-pro' ), $booking->reference ) ); ?>" style="display: block; width: 200px; height: 200px; margin: 0 auto;" />
		</div>
		<?php
	}

	/**
	 * `rtbp_document_before_footer`: the code on the invoice and the receipt.
	 *
	 * @param string $type invoice|receipt.
	 * @param array  $doc  Document data.
	 * @return void
	 */
	public static function document( $type, $doc ): void {
		$booking = BookingQr::byReference( (string) ( $doc['booking']['reference'] ?? '' ) );
		if ( ! empty( $doc['voided'] ) || ! BookingQr::shows( $booking ) ) {
			return;
		}
		$src = QrImage::dataUri( BookingQr::scanUrl( $booking ) );
		if ( '' === $src ) {
			return;
		}
		// A table, not flex: dompdf lays out the PDF.
		?>
		<table style="margin-top: 24px; width: auto; border-collapse: collapse;">
			<tr>
				<td style="width: 110px; padding: 0; vertical-align: middle;">
					<img src="<?php echo esc_attr( $src ); ?>" width="104" height="104" alt="" style="display: block; width: 104px; height: 104px;" />
				</td>
				<td style="padding: 0 0 0 12px; vertical-align: middle;">
					<p class="box-title"><?php esc_html_e( 'Booking QR code', 'radius-hotel-booking-pro' ); ?></p>
					<div><?php echo esc_html( (string) $booking->reference ); ?></div>
					<div class="muted"><?php esc_html_e( 'Show this code at reception when you arrive.', 'radius-hotel-booking-pro' ); ?></div>
				</td>
			</tr>
		</table>
		<?php
	}
}
