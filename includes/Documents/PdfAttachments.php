<?php
/**
 * PDF invoices and receipts attached to the guest e-mails (M05 T7, 5.7, 5.12).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Documents
 */

namespace RadiusTheme\RadiusHotelBookingPro\Documents;

use RadiusTheme\RadiusHotelBooking\Documents\DocumentService;
use RadiusTheme\RadiusHotelBooking\Models\Booking;
use RadiusTheme\RadiusHotelBooking\Models\Payment;

defined( 'ABSPATH' ) || exit;

/**
 * Hooked on the free e-mails' `rtbp_be_before_send_email_{id}` filter: the
 * *booking received* e-mail gets the invoice, *payment received* the receipt.
 * The file is written to `uploads/rtbp-emails/`, which the free EmailSender
 * deletes from after sending. A PDF that cannot be made never stops the
 * e-mail: it goes out with its links only.
 */
final class PdfAttachments {

	/**
	 * Folder under uploads (the one the free EmailSender cleans up).
	 */
	const DIR = 'rtbp-emails';

	/**
	 * `guest_booking_received`: attach the invoice.
	 *
	 * @param array $email_data E-mail data.
	 * @return array
	 */
	public static function invoice( $email_data ): array {
		$email_data = (array) $email_data;
		$reference  = (string) ( $email_data['merge_tags']['booking_reference'] ?? '' );
		$rows       = '' === $reference ? array() : Booking::query()->where( 'reference', '=', $reference )->limit( 1 )->get();
		if ( ! $rows ) {
			return $email_data;
		}
		$booking_id = (int) Booking::hydrate( $rows[0] )->id;
		return self::attach( $email_data, 'invoice', static fn() => ( new DocumentService() )->invoice( $booking_id ) );
	}

	/**
	 * `guest_payment_received`: attach the receipt.
	 *
	 * @param array $email_data E-mail data.
	 * @return array
	 */
	public static function receipt( $email_data ): array {
		$email_data = (array) $email_data;
		$number     = (string) ( $email_data['data']['receipt_no'] ?? '' );
		$rows       = '' === $number ? array() : Payment::query()->where( 'receipt_no', '=', $number )->limit( 1 )->get();
		if ( ! $rows ) {
			return $email_data;
		}
		$payment_id = (int) Payment::hydrate( $rows[0] )->id;
		return self::attach( $email_data, 'receipt', static fn() => ( new DocumentService() )->receipt( $payment_id ) );
	}

	/**
	 * Render and attach one document.
	 *
	 * @param array    $email_data E-mail data.
	 * @param string   $type       invoice|receipt.
	 * @param callable $data       Returns the document data.
	 * @return array
	 */
	private static function attach( array $email_data, string $type, callable $data ): array {
		try {
			$out  = PdfRenderer::render( $type, $data() );
			$file = self::write( $out['filename'], $out['body'] );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the e-mail still goes out; say why it has no PDF.
			error_log( '[radius-hotel-booking-pro] The ' . $type . ' PDF could not be attached: ' . $e->getMessage() );
			return $email_data;
		}
		if ( '' !== $file ) {
			$email_data['attachments'] = array_merge( (array) ( $email_data['attachments'] ?? array() ), array( $file ) );
		}
		return $email_data;
	}

	/**
	 * Write the file in a folder of its own (two e-mails of the same document
	 * at once must not delete each other's file), under a protected folder.
	 *
	 * @param string $filename File name the guest sees.
	 * @param string $body     PDF bytes.
	 * @return string Path, '' on failure.
	 */
	private static function write( string $filename, string $body ): string {
		$root = wp_get_upload_dir()['basedir'] . '/' . self::DIR;
		if ( ! wp_mkdir_p( $root ) ) {
			return '';
		}
		self::protect( $root );
		self::sweep( $root );

		$dir = $root . '/' . wp_generate_password( 16, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		$path = $dir . '/' . $filename;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a temporary attachment.
		return false === file_put_contents( $path, $body ) ? '' : $path;
	}

	/**
	 * No listing and no direct access to the attachments.
	 *
	 * @param string $root Folder.
	 * @return void
	 */
	private static function protect( string $root ): void {
		if ( ! file_exists( $root . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a guard file.
			file_put_contents( $root . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a guard file.
			file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
	}

	/**
	 * Remove the per-e-mail folders the sender emptied (older than an hour,
	 * so a mail being sent right now keeps its file).
	 *
	 * @param string $root Folder.
	 * @return void
	 */
	private static function sweep( string $root ): void {
		foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( is_string( $dir ) && filemtime( $dir ) < time() - HOUR_IN_SECONDS && array() === array_diff( (array) scandir( $dir ), array( '.', '..' ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- an empty temporary folder.
				rmdir( $dir );
			}
		}
	}
}
