<?php
/**
 * Exact decimal arithmetic for payroll (M15).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Thin bcmath wrappers: payroll amounts are decimal strings, never floats,
 * worked at 8 decimals and rounded to the currency's precision only at the
 * end of each line (`money()`, half away from zero): the calculator's
 * decimals (0 for CFA francs), else the site currency's, at most 2.
 */
final class Decimal {

	/**
	 * Working scale.
	 */
	public const SCALE = 8;

	/**
	 * Whether bcmath is loaded (payroll cannot run without it).
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return extension_loaded( 'bcmath' );
	}

	/**
	 * A clean decimal string from user or stored input.
	 *
	 * @param mixed $value Value.
	 * @return string
	 * @throws InvalidArgumentException When not a number.
	 */
	public static function of( $value ): string {
		if ( is_int( $value ) ) {
			return (string) $value;
		}
		if ( is_float( $value ) ) {
			$value = sprintf( '%.' . self::SCALE . 'F', $value );
		}
		$value = trim( (string) $value );
		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $value ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a programming error, never shown as HTML.
			throw new InvalidArgumentException( 'Not a decimal number: ' . $value );
		}
		return $value;
	}

	/**
	 * A + B.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return string
	 */
	public static function add( string $a, string $b ): string {
		return bcadd( $a, $b, self::SCALE );
	}

	/**
	 * A − B.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return string
	 */
	public static function sub( string $a, string $b ): string {
		return bcsub( $a, $b, self::SCALE );
	}

	/**
	 * A × B.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return string
	 */
	public static function mul( string $a, string $b ): string {
		return bcmul( $a, $b, self::SCALE );
	}

	/**
	 * A ÷ B.
	 *
	 * @param string $a A.
	 * @param string $b B (not zero).
	 * @return string
	 */
	public static function div( string $a, string $b ): string {
		return bcdiv( $a, $b, self::SCALE );
	}

	/**
	 * Compare: −1, 0 or 1.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return int
	 */
	public static function cmp( string $a, string $b ): int {
		return bccomp( $a, $b, self::SCALE );
	}

	/**
	 * The smaller of A and B.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return string
	 */
	public static function min( string $a, string $b ): string {
		return self::cmp( $a, $b ) <= 0 ? $a : $b;
	}

	/**
	 * The larger of A and B.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return string
	 */
	public static function max( string $a, string $b ): string {
		return self::cmp( $a, $b ) >= 0 ? $a : $b;
	}

	/**
	 * Rounded to the currency's decimals, half away from zero (with 0
	 * decimals: `2.5` → `3`, `-2.5` → `-3`).
	 *
	 * @param string   $a      Amount.
	 * @param int|null $places Decimals (default: the site currency's).
	 * @return string
	 */
	public static function money( string $a, ?int $places = null ): string {
		$places = null === $places ? self::places() : max( 0, min( 2, $places ) );
		$half   = '0.' . str_repeat( '0', $places ) . '5';
		$half   = 0 === $places ? '0.5' : $half;
		// bcadd truncates toward zero at the scale.
		$out = bcadd( $a, self::cmp( $a, '0' ) < 0 ? '-' . $half : $half, $places );
		return 0 === self::cmp( $out, '0' ) ? bcadd( '0', '0', $places ) : $out;
	}

	/**
	 * The site currency's decimals (Settings → General).
	 *
	 * @return int
	 */
	public static function places(): int {
		$fixed = class_exists( Statutory::class ) ? Statutory::calculator()->places() : null;
		$site  = function_exists( 'rtbp_setting' ) ? (int) rtbp_setting( 'general', 'decimals', 2 ) : 2;
		// Payroll columns are decimal(14,2): never more than 2.
		return max( 0, min( 2, null === $fixed ? $site : $fixed ) );
	}

	/**
	 * A ratio given as `num/den` or a plain decimal.
	 *
	 * @param string $factor Factor.
	 * @return array{0:string,1:string} Numerator, denominator.
	 */
	public static function ratio( string $factor ): array {
		if ( false !== strpos( $factor, '/' ) ) {
			list( $num, $den ) = explode( '/', $factor, 2 );
			return array( trim( $num ), trim( $den ) );
		}
		return array( $factor, '1' );
	}
}
