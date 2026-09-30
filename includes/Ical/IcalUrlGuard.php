<?php
/**
 * The check on a channel's calendar URL before it is fetched.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Ical
 */

namespace RadiusTheme\RadiusHotelBookingPro\Ical;

defined( 'ABSPATH' ) || exit;

/**
 * Server-side request forgery guard (the legacy SSRF idea, kept): the URL a
 * staff member pastes is fetched by the server, so it must not reach the
 * server itself or the private network.
 *
 * - `webcal://` (what channels often give) becomes `https://`;
 * - only http and https, no user or password, ports 80, 443 and 8080
 *   (WordPress's own rule, `wp_http_validate_url()`);
 * - the host must be public: a literal IP, or every address its name
 *   resolves to, outside private, loopback, link-local and reserved ranges
 *   (IPv4 and IPv6).
 *
 * The fetch then goes through `wp_safe_remote_get()`, which validates the URL
 * again and every redirect.
 */
final class IcalUrlGuard {

	/**
	 * The normalised URL, or null with the reason.
	 *
	 * @param string $url URL as pasted.
	 * @return array{0: string|null, 1: string} `[ url|null, error ]`.
	 */
	public static function check( string $url ): array {
		$url = trim( $url );
		if ( 0 === stripos( $url, 'webcal://' ) ) {
			$url = 'https://' . substr( $url, 9 );
		}
		if ( '' === $url || strlen( $url ) > 2000 ) {
			return array( null, __( 'Paste the calendar address (iCal link) the channel gives you.', 'radius-hotel-booking-pro' ) );
		}

		$parts  = wp_parse_url( $url );
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( trim( (string) ( $parts['host'] ?? '' ), '[]' ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return array( null, __( 'The address must start with https:// (or webcal://).', 'radius-hotel-booking-pro' ) );
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return array( null, __( 'The address must not contain a user name or password.', 'radius-hotel-booking-pro' ) );
		}
		if ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443, 8080 ), true ) ) {
			return array( null, __( 'The address uses a port that is not allowed.', 'radius-hotel-booking-pro' ) );
		}

		$private = __( 'This address points to a private or local network, which is not allowed.', 'radius-hotel-booking-pro' );
		if ( 'localhost' === $host || str_ends_with( $host, '.localhost' ) ) {
			return array( null, $private );
		}
		$ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : self::resolve( $host );
		if ( ! $ips ) {
			return array( null, __( 'This address could not be found. Check it and try again.', 'radius-hotel-booking-pro' ) );
		}
		foreach ( $ips as $ip ) {
			if ( ! self::public_ip( $ip ) ) {
				return array( null, $private );
			}
		}

		if ( ! wp_http_validate_url( $url ) ) {
			return array( null, $private );
		}
		return array( $url, '' );
	}

	/**
	 * Whether an address is on the public internet.
	 *
	 * @param string $ip IPv4 or IPv6.
	 * @return bool
	 */
	public static function public_ip( string $ip ): bool {
		// An IPv4-mapped IPv6 address (::ffff:127.0.0.1) is judged as IPv4.
		if ( preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m ) ) {
			$ip = $m[1];
		}
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		// Ranges PHP's flags do not cover: carrier-grade NAT, benchmarking,
		// IPv6 unique-local and link-local.
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $ip );
			foreach ( array( array( '100.64.0.0', 10 ), array( '198.18.0.0', 15 ), array( '0.0.0.0', 8 ) ) as list( $base, $bits ) ) {
				$mask = -1 << ( 32 - $bits );
				if ( ( $long & $mask ) === ( ip2long( $base ) & $mask ) ) {
					return false;
				}
			}
			return true;
		}
		$bin = inet_pton( $ip );
		return false !== $bin && ( ord( $bin[0] ) & 0xfe ) !== 0xfc && ! ( 0xfe === ord( $bin[0] ) && ( ord( $bin[1] ) & 0xc0 ) === 0x80 );
	}

	/**
	 * Every address a host name resolves to (A and AAAA).
	 *
	 * @param string $host Host.
	 * @return string[]
	 */
	private static function resolve( string $host ): array {
		$ips = array();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a failed lookup is an answer, not an error.
		$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_A | DNS_AAAA ) : false;
		foreach ( (array) $records as $record ) {
			if ( ! empty( $record['ip'] ) ) {
				$ips[] = $record['ip'];
			} elseif ( ! empty( $record['ipv6'] ) ) {
				$ips[] = $record['ipv6'];
			}
		}
		if ( ! $ips ) {
			$v4 = gethostbynamel( $host );
			$ips = $v4 ? $v4 : array();
		}
		return array_values( array_unique( $ips ) );
	}
}
