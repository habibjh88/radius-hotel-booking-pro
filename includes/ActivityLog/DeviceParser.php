<?php
/**
 * A small user-agent parser.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\ActivityLog;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a user agent into "Device · OS · Browser" for the log (feature 14.1).
 * Enough to tell the front-desk PC from a phone; not a full UA database.
 */
final class DeviceParser {

	/**
	 * Parse a user agent.
	 *
	 * @param string $agent User agent.
	 * @return string E.g. "iPhone · iOS · Safari", '' when unknown.
	 */
	public static function describe( string $agent ): string {
		if ( '' === trim( $agent ) ) {
			return '';
		}
		return implode( ' · ', array_filter( array( self::device( $agent ), self::os( $agent ), self::browser( $agent ) ) ) );
	}

	/**
	 * The device class.
	 *
	 * @param string $a User agent.
	 * @return string
	 */
	private static function device( string $a ): string {
		if ( stripos( $a, 'iPhone' ) !== false ) {
			return 'iPhone';
		}
		if ( stripos( $a, 'iPad' ) !== false || ( stripos( $a, 'Android' ) !== false && stripos( $a, 'Mobile' ) === false ) || stripos( $a, 'Tablet' ) !== false ) {
			return __( 'Tablet', 'radius-hotel-booking-pro' );
		}
		if ( preg_match( '/Mobile|Android|IEMobile|Opera Mini/i', $a ) ) {
			return __( 'Phone', 'radius-hotel-booking-pro' );
		}
		if ( preg_match( '/bot|crawl|spider|curl|wget|WordPress\//i', $a ) ) {
			return __( 'Script', 'radius-hotel-booking-pro' );
		}
		return __( 'Computer', 'radius-hotel-booking-pro' );
	}

	/**
	 * The operating system.
	 *
	 * @param string $a User agent.
	 * @return string
	 */
	private static function os( string $a ): string {
		$map = array(
			'/iPhone|iPad|iPod/i' => 'iOS',
			'/Android/i'          => 'Android',
			'/Windows/i'          => 'Windows',
			'/Mac OS X|Macintosh/' => 'macOS',
			'/CrOS/'              => 'ChromeOS',
			'/Linux/i'            => 'Linux',
		);
		foreach ( $map as $pattern => $name ) {
			if ( preg_match( $pattern, $a ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * The browser (order matters: Edge and Opera also say Chrome).
	 *
	 * @param string $a User agent.
	 * @return string
	 */
	private static function browser( string $a ): string {
		$map = array(
			'/Edg(e|A|iOS)?\//' => 'Edge',
			'/OPR\/|Opera/'     => 'Opera',
			'/SamsungBrowser/'  => 'Samsung Internet',
			'/Firefox|FxiOS/'   => 'Firefox',
			'/Chrome|CriOS/'    => 'Chrome',
			'/Safari/'          => 'Safari',
		);
		foreach ( $map as $pattern => $name ) {
			if ( preg_match( $pattern, $a ) ) {
				return $name;
			}
		}
		return '';
	}
}
