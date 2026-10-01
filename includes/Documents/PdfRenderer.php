<?php
/**
 * The PDF renderer for invoices and receipts (M05 T7, ADR-005).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Documents
 */

namespace RadiusTheme\RadiusHotelBookingPro\Documents;

use RadiusTheme\RadiusHotelBooking\Documents\DocumentService;
use RadiusTheme\RadiusHotelBookingPro\Vendor\Dompdf\Dompdf;
use RadiusTheme\RadiusHotelBookingPro\Vendor\Dompdf\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the free plugin's own document HTML (`DocumentService::html()`,
 * the theme-overridable templates) to PDF with dompdf, so the PDF and the
 * print view can never differ. Registered as the `pdf` format of
 * `rtbp_document_renderers`.
 *
 * The dompdf library ships in `vendor-prefixed/`, under the
 * `RadiusTheme\RadiusHotelBookingPro\Vendor` namespace (built by
 * `composer scope`), and is loaded only when a PDF is made. It never fetches
 * anything: remote files, PHP and JavaScript are off, and the logo is
 * embedded as a data URI.
 */
final class PdfRenderer {

	/**
	 * Stands in for the logo while the template runs (`.invalid` never resolves).
	 */
	const LOGO_PLACEHOLDER = 'https://rtbp-pro-logo.invalid/logo';

	/**
	 * The prefixed libraries' autoloader.
	 *
	 * @return string
	 */
	public static function library(): string {
		return RADIUS_HOTEL_BOOKING_PRO_DIR . 'vendor-prefixed/vendor/autoload.php';
	}

	/**
	 * Whether the PDF library is installed (`composer scope` was run, or the
	 * plugin came from a release zip).
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return is_readable( self::library() );
	}

	/**
	 * `rtbp_document_renderers`: add the `pdf` format.
	 *
	 * @param array $renderers Format => renderer.
	 * @return array
	 */
	public static function register( $renderers ): array {
		$renderers        = (array) $renderers;
		$renderers['pdf'] = array( self::class, 'render' );
		return $renderers;
	}

	/**
	 * Render a document to PDF.
	 *
	 * @param string $type invoice|receipt.
	 * @param array  $data Document data (from DocumentService).
	 * @return array `{ body, content_type, filename }`.
	 */
	public static function render( string $type, array $data ): array {
		require_once self::library();

		// The templates escape the logo with esc_url(), which drops data: URIs
		// (and wp_allowed_protocols() is fixed once WordPress has loaded): they
		// get a placeholder, replaced by the embedded image afterwards.
		$logo             = self::inlineImage( (string) ( $data['logo_url'] ?? '' ) );
		$data['pdf']      = true;
		$data['logo_url'] = '' === $logo ? '' : self::LOGO_PLACEHOLDER;

		$html         = DocumentService::html( $type, $data );
		$html['body'] = str_replace( self::LOGO_PLACEHOLDER, $logo, (string) $html['body'] );

		$pdf = new Dompdf(
			new Options(
				array(
					'defaultFont'         => 'DejaVu Sans',
					// The templates' @media print rules: a plain white page.
					'defaultMediaType'    => 'print',
					'defaultPaperSize'    => 'a4',
					'isRemoteEnabled'     => false,
					'isPhpEnabled'        => false,
					'isJavascriptEnabled' => false,
					'fontCache'           => self::fontCache(),
					'tempDir'             => get_temp_dir(),
				)
			)
		);
		$pdf->loadHtml( (string) $html['body'], 'UTF-8' );
		$pdf->render();

		return array(
			'body'         => (string) $pdf->output(),
			'content_type' => 'application/pdf',
			'filename'     => sanitize_file_name( (string) ( $data['number'] ?? $type ) . '.pdf' ),
		);
	}

	/**
	 * An uploaded image as a data URI ('' when it is not a local upload).
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private static function inlineImage( string $url ): string {
		$uploads = wp_get_upload_dir();
		$base    = set_url_scheme( (string) $uploads['baseurl'] );
		$url     = set_url_scheme( $url );
		if ( '' === $url || 0 !== strpos( $url, $base . '/' ) ) {
			return '';
		}
		$path = wp_normalize_path( $uploads['basedir'] . substr( $url, strlen( $base ) ) );
		$type = wp_check_filetype( $path );
		if ( false !== strpos( $path, '..' ) || ! in_array( $type['type'], array( 'image/png', 'image/jpeg', 'image/gif' ), true ) || ! is_readable( $path ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload.
		$bytes = file_get_contents( $path );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- embedding the logo in the PDF.
		return false === $bytes ? '' : 'data:' . $type['type'] . ';base64,' . base64_encode( $bytes );
	}

	/**
	 * Where dompdf keeps font metrics it computes (writable; not the plugin folder).
	 *
	 * @return string
	 */
	private static function fontCache(): string {
		$dir = wp_get_upload_dir()['basedir'] . '/rtbp-pro-fonts';
		return wp_mkdir_p( $dir ) && wp_is_writable( $dir ) ? $dir : get_temp_dir();
	}
}
