<?php
/**
 * The activity log's secret key.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\ActivityLog
 */

namespace RadiusTheme\RadiusHotelBookingPro\ActivityLog;

use RadiusTheme\RadiusHotelBooking\Storage\ProtectedFiles;

defined( 'ABSPATH' ) || exit;

/**
 * The key of the log's HMAC chain (M14 review #2). Kept outside the database,
 * so someone who can only edit tables cannot recompute the chain:
 *
 * 1. the `RTBP_PRO_LOG_KEY` constant (wp-config.php), when defined;
 * 2. otherwise a random key in a file in the protected storage folder,
 *    created on first use.
 *
 * Not the WordPress salts: resetting them would break the whole chain. Losing
 * the key makes the existing chain unverifiable, so back it up with the site.
 */
final class LogKey {

	const FILE = '.activity-log-key';

	/**
	 * Resolved key, per request.
	 *
	 * @var string|null
	 */
	private static ?string $key = null;

	/**
	 * The key (created on first use).
	 *
	 * @return string
	 */
	public static function get(): string {
		if ( null !== self::$key ) {
			return self::$key;
		}
		if ( defined( 'RTBP_PRO_LOG_KEY' ) && '' !== (string) RTBP_PRO_LOG_KEY ) {
			self::$key = (string) RTBP_PRO_LOG_KEY;
			return self::$key;
		}

		$path = self::path();
		if ( is_readable( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own key file.
			$key = trim( (string) file_get_contents( $path ) );
			if ( strlen( $key ) >= 32 ) {
				self::$key = $key;
				return self::$key;
			}
		}

		$key = bin2hex( random_bytes( 32 ) );
		if ( class_exists( ProtectedFiles::class ) ) {
			ProtectedFiles::ensure_protected_dir( dirname( $path ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- our own key file, created once.
		if ( false === @file_put_contents( $path, $key, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported below.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the log cannot be keyed; say so.
			error_log( '[radius-hotel-booking-pro] The activity log key file could not be written: ' . $path . '. Define RTBP_PRO_LOG_KEY in wp-config.php.' );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_permissions_chmod -- owner-only key file.
			@chmod( $path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort.
		}
		self::$key = $key;
		return self::$key;
	}

	/**
	 * Where the key file lives.
	 *
	 * @return string
	 */
	public static function path(): string {
		$base = class_exists( ProtectedFiles::class ) ? ProtectedFiles::base_dir() : WP_CONTENT_DIR . '/uploads/radius-hotel-booking';
		return trailingslashit( $base ) . self::FILE;
	}

	/**
	 * Forget the cached key (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$key = null;
	}
}
