<?php
/**
 * The scan API: what a scanned code points at.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\BookingQr
 */

namespace RadiusTheme\RadiusHotelBookingPro\BookingQr;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Models\Booking;
use RadiusTheme\RadiusHotelBooking\Services\Payments\GuestBookingView;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `GET scan?code=` (`page.bookings`): the booking a scanned code points at,
 * `{ id, reference }`. The code may be the QR link (`…?rtbp_scan=`), the
 * guest page link (`…?rtbp_booking=`), a bare token, or a booking reference
 * typed by hand. Anything else goes through `rtbp_pro_scan_booking_id`
 * (e.g. the client add-on mapping the old system's codes), then 404.
 */
class ScanController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'scan', array( self::class, 'resolve' ) );
	}

	/**
	 * GET scan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function resolve( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'page.bookings',
			function ( $request ) {
				$code    = trim( sanitize_text_field( (string) $request->get_param( 'code' ) ) );
				$booking = '' === $code ? null : self::find( $code );
				if ( ! $booking ) {
					// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
					throw new DomainException(
						'scan_not_found',
						__( 'No booking matches this code.', 'radius-hotel-booking-pro' ),
						404
					);
					// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				return ApiResponse::success(
					array(
						'id'        => (int) $booking->id,
						'reference' => (string) $booking->reference,
					)
				)->send();
			}
		);
	}

	/**
	 * The booking a code points at.
	 *
	 * @param string $code Scanned or typed text.
	 * @return Booking|null
	 */
	public static function find( string $code ): ?Booking {
		$token = '';
		if ( preg_match( '/[?&](?:' . BookingQr::SCAN_VAR . '|' . GuestBookingView::QUERY_VAR . ')=([a-f0-9]{32})\b/i', $code, $m ) ) {
			$token = $m[1];
		} elseif ( preg_match( '/^[a-f0-9]{32}$/i', $code ) ) {
			$token = $code;
		}
		$booking = '' !== $token ? GuestBookingView::byToken( strtolower( $token ) ) : BookingQr::byReference( $code );
		if ( $booking ) {
			return $booking;
		}

		/**
		 * The booking id of a code this plugin does not know (0 = none).
		 *
		 * @param int    $booking_id Booking id.
		 * @param string $code       Scanned or typed text.
		 */
		$id = (int) apply_filters( 'rtbp_pro_scan_booking_id', 0, $code );
		return $id > 0 ? GuestBookingView::booking( $id ) : null;
	}
}
