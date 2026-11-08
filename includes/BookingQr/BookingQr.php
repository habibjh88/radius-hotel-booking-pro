<?php
/**
 * The booking QR code: what it holds, and where a scan leads.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\BookingQr
 */

namespace RadiusTheme\RadiusHotelBookingPro\BookingQr;

use RadiusTheme\RadiusHotelBooking\Access\Access;
use RadiusTheme\RadiusHotelBooking\Core\Permissions\Capabilities;
use RadiusTheme\RadiusHotelBooking\Models\Booking;
use RadiusTheme\RadiusHotelBooking\Services\Payments\GuestBookingView;

defined( 'ABSPATH' ) || exit;

/**
 * Every booking's code holds one link, `/?rtbp_scan={public token}` (the
 * same 128-bit token as the guest's booking page, so it opens nothing the
 * guest's e-mail does not already open):
 *
 * - staff who may open bookings land on the booking in the dashboard
 *   (the `[rtbp_dashboard]` page when there is one, else wp-admin);
 * - anyone else (the guest) lands on the guest's booking page.
 *
 * The dashboard's *Scan a booking* dialog reads the same link, a bare token
 * or a booking reference (ScanController). `/?rtbp_qr={token}` serves the
 * code as a PNG for e-mails, which cannot carry a data URI.
 */
final class BookingQr {

	/**
	 * Query var of the scan link.
	 */
	const SCAN_VAR = 'rtbp_scan';

	/**
	 * Query var of the PNG.
	 */
	const IMAGE_VAR = 'rtbp_qr';

	/**
	 * Statuses that still lead to an arrival: the code is shown only then.
	 */
	const ACTIVE = array( 'pending', 'confirmed', 'checked_in' );

	/**
	 * Hook the two links.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'query_vars', array( self::class, 'queryVars' ) );
		// Before the free plugin's guest page (also priority 1): nothing else answers these vars.
		add_action( 'template_redirect', array( self::class, 'maybeAnswer' ), 0 );
	}

	/**
	 * `query_vars`.
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public static function queryVars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = self::SCAN_VAR;
		$vars[] = self::IMAGE_VAR;
		return $vars;
	}

	/**
	 * The link the code holds.
	 *
	 * @param Booking $booking Booking.
	 * @return string '' when the booking has no public token (some imported ones).
	 */
	public static function scanUrl( Booking $booking ): string {
		$token = (string) $booking->public_token;
		return '' === $token ? '' : add_query_arg( self::SCAN_VAR, $token, home_url( '/' ) );
	}

	/**
	 * The PNG's address, for e-mails.
	 *
	 * @param Booking $booking Booking.
	 * @return string
	 */
	public static function imageUrl( Booking $booking ): string {
		$token = (string) $booking->public_token;
		return '' === $token ? '' : add_query_arg( self::IMAGE_VAR, $token, home_url( '/' ) );
	}

	/**
	 * Whether a booking shows its code: it has a token and can still arrive.
	 *
	 * @param Booking|null $booking Booking.
	 * @return bool
	 */
	public static function shows( ?Booking $booking ): bool {
		return $booking && '' !== (string) $booking->public_token && in_array( (string) $booking->status, self::ACTIVE, true );
	}

	/**
	 * A booking by its reference (e-mails and documents carry only that).
	 *
	 * @param string $reference Reference, e.g. RT-2026-000005.
	 * @return Booking|null
	 */
	public static function byReference( string $reference ): ?Booking {
		$reference = strtoupper( trim( $reference ) );
		if ( '' === $reference ) {
			return null;
		}
		$rows = Booking::query()->where( 'reference', '=', $reference )->limit( 1 )->get();
		// A booking renumbered since (free DB 1.0.22+, `former_reference`): the guest's e-mail has the old one.
		if ( ! $rows && defined( 'RADIUS_HOTEL_BOOKING_DB_VERSION' ) && version_compare( RADIUS_HOTEL_BOOKING_DB_VERSION, '1.0.22', '>=' ) ) {
			$rows = Booking::query()->where( 'former_reference', '=', $reference )->limit( 1 )->get();
		}
		return $rows ? Booking::hydrate( $rows[0] ) : null;
	}

	/**
	 * Whether the signed-in user may open bookings in the dashboard.
	 *
	 * @return bool
	 */
	public static function staffCanOpen(): bool {
		return is_user_logged_in()
			&& current_user_can( Capabilities::VIEW_DASHBOARD )
			&& ( ! class_exists( Access::class ) || Access::can( 'page.bookings' ) );
	}

	/**
	 * The booking in the staff dashboard: the front-end `[rtbp_dashboard]`
	 * page when the hotel has one (a phone opens the bare app), else wp-admin.
	 *
	 * @param int $booking_id Booking id.
	 * @return string
	 */
	public static function staffUrl( int $booking_id ): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one lookup per scan.
		$page_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM %i WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1", $wpdb->posts, '%' . $wpdb->esc_like( '[rtbp_dashboard' ) . '%' ) );
		$base    = $page_id ? (string) get_permalink( $page_id ) : admin_url( 'admin.php?page=radius-hotel-booking' );

		/**
		 * Where a staff scan opens a booking.
		 *
		 * @param string $url        Dashboard URL with the `#/bookings/{id}` route.
		 * @param int    $booking_id Booking id.
		 */
		return (string) apply_filters( 'rtbp_pro_scan_staff_url', $base . '#/bookings/' . $booking_id, $booking_id );
	}

	/**
	 * Answer `?rtbp_scan=` and `?rtbp_qr=`.
	 *
	 * @return void
	 */
	public static function maybeAnswer(): void {
		$scan  = (string) get_query_var( self::SCAN_VAR );
		$image = (string) get_query_var( self::IMAGE_VAR );
		if ( '' === $scan && '' === $image ) {
			return;
		}
		$booking = GuestBookingView::byToken( sanitize_key( '' !== $scan ? $scan : $image ) );
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( '' !== $image ) {
			self::sendImage( $booking );
		}

		nocache_headers();
		if ( ! $booking ) {
			status_header( 404 );
			wp_die(
				esc_html__( 'We could not find this booking. Check the code, or contact the hotel.', 'radius-hotel-booking-pro' ),
				esc_html__( 'Booking not found', 'radius-hotel-booking-pro' ),
				array( 'response' => 404 )
			);
		}
		$to = self::staffCanOpen() ? self::staffUrl( (int) $booking->id ) : GuestBookingView::pageUrl( $booking );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- our own dashboard or booking page; a filter may point the dashboard elsewhere.
		wp_redirect( $to, 302, 'Radius Hotel Booking' );
		exit;
	}

	/**
	 * Send the PNG and stop.
	 *
	 * @param Booking|null $booking Booking.
	 * @return never
	 */
	private static function sendImage( ?Booking $booking ) {
		$png = self::shows( $booking ) ? QrImage::png( self::scanUrl( $booking ) ) : '';
		if ( '' === $png ) {
			nocache_headers();
			status_header( 404 );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: image/png' );
		header( 'Content-Length: ' . strlen( $png ) );
		// The image of a token never changes; mail proxies may keep it.
		header( 'Cache-Control: public, max-age=2592000' );
		echo $png; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PNG bytes.
		exit;
	}
}
