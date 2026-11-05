<?php
/**
 * QR code images for the booking QR code.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\BookingQr
 */

namespace RadiusTheme\RadiusHotelBookingPro\BookingQr;

use RadiusTheme\RadiusHotelBookingPro\Documents\PdfRenderer;
use RadiusTheme\RadiusHotelBookingPro\Vendor\chillerlan\QRCode\Common\EccLevel;
use RadiusTheme\RadiusHotelBookingPro\Vendor\chillerlan\QRCode\Output\QROutputInterface;
use RadiusTheme\RadiusHotelBookingPro\Vendor\chillerlan\QRCode\QRCode;
use RadiusTheme\RadiusHotelBookingPro\Vendor\chillerlan\QRCode\QROptions;

defined( 'ABSPATH' ) || exit;

/**
 * Draws a QR code with chillerlan/php-qrcode, which ships prefixed in
 * `vendor-prefixed/` next to dompdf (`composer scope`). PNG needs PHP's GD
 * extension; without it pages fall back to SVG and e-mails get no image
 * (mail clients do not show SVG).
 */
final class QrImage {

	/**
	 * Whether the library is installed (`composer scope` was run, or the
	 * plugin came from a release zip).
	 *
	 * @return bool
	 */
	public static function available(): bool {
		if ( ! PdfRenderer::available() ) {
			return false;
		}
		require_once PdfRenderer::library();
		return class_exists( QRCode::class );
	}

	/**
	 * Whether PNG images can be drawn (GD).
	 *
	 * @return bool
	 */
	public static function canPng(): bool {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagepng' );
	}

	/**
	 * A PNG of the code, '' when it cannot be drawn.
	 *
	 * @param string $data What the code holds.
	 * @return string PNG bytes.
	 */
	public static function png( string $data ): string {
		if ( ! self::canPng() ) {
			return '';
		}
		return self::render( $data, QROutputInterface::GDIMAGE_PNG );
	}

	/**
	 * The code as a `data:` URI for an `<img>` (pages and PDFs): PNG when GD
	 * is there, SVG otherwise. '' when it cannot be drawn.
	 *
	 * @param string $data What the code holds.
	 * @return string
	 */
	public static function dataUri( string $data ): string {
		$png = self::png( $data );
		if ( '' !== $png ) {
			return 'data:image/png;base64,' . base64_encode( $png ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- an image data URI.
		}
		$svg = self::render( $data, QROutputInterface::MARKUP_SVG );
		return '' === $svg ? '' : 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- an image data URI.
	}

	/**
	 * Draw the code.
	 *
	 * @param string $data   What the code holds.
	 * @param string $output Output type.
	 * @return string
	 */
	private static function render( string $data, string $output ): string {
		if ( '' === $data || ! self::available() ) {
			return '';
		}
		try {
			$options = new QROptions(
				array(
					'outputType'    => $output,
					'outputBase64'  => false,
					// M survives a creased printout or a cracked phone screen.
					'eccLevel'      => EccLevel::M,
					'scale'         => 8,
					'quietzoneSize' => 4,
					'addQuietzone'  => true,
				)
			);
			return (string) ( new QRCode( $options ) )->render( $data );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the page or e-mail goes out without the code; say why.
			error_log( '[radius-hotel-booking-pro] The booking QR code could not be drawn: ' . $e->getMessage() );
			return '';
		}
	}
}
