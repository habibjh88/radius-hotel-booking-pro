<?php
/**
 * Personal PINs, the fallback PIN, lockout and passcode grants.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Access\AccessRegistry;
use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The passcode level (M13, features 13.6–13.9):
 *
 * - A personal PIN (4–8 digits) per user, stored only as `wp_hash_password()`.
 * - A fallback PIN for users without one (hashed, can be switched off).
 * - `unlock()`: a correct PIN returns a random token, valid for the passcode
 *   validity, for every key (`global` scope) or for one key (`per_key`). Only a
 *   hash of the token is stored.
 * - Five wrong PINs lock the user out of unlocking for 15 minutes.
 * - Changing a PIN bumps the user's grant generation, which voids all their
 *   grants at once.
 *
 * The client sends the token in the `X-RTBP-Passcode` header; check() answers
 * the free plugin's `rtbp_access_passcode_check` filter.
 */
final class PasscodeService {

	const META_PIN        = 'rtbp_pro_pin_hash';
	const META_FAILURES   = 'rtbp_pro_pin_failures';
	const META_LOCKED     = 'rtbp_pro_pin_locked_until';
	const META_GENERATION = 'rtbp_pro_pin_generation';

	const FALLBACK_GENERATION = 'rtbp_pro_access_fallback_generation';

	const MAX_FAILURES = 5;
	const LOCKOUT      = 15 * MINUTE_IN_SECONDS;
	const HEADER       = 'x-rtbp-passcode';

	/**
	 * Whether a value is a valid PIN: 4 to 8 digits.
	 *
	 * @param mixed $pin Value.
	 * @return bool
	 */
	public static function is_valid_pin( $pin ): bool {
		return is_string( $pin ) && 1 === preg_match( '/^\d{4,8}$/', $pin );
	}

	/**
	 * Whether a user has a personal PIN.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function has_pin( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_PIN, true );
	}

	/**
	 * Whether the user has a PIN they can enter: their own, or the fallback
	 * PIN while it is switched on.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	public static function can_enter_pin( int $user_id ): bool {
		return self::has_pin( $user_id ) || ( AccessSettings::get( 'fallbackEnabled', true ) && self::has_fallback() );
	}

	/**
	 * Whether a fallback PIN is set.
	 *
	 * @return bool
	 */
	public static function has_fallback(): bool {
		return '' !== (string) get_option( AccessSettings::FALLBACK_OPTION, '' );
	}

	/**
	 * Set or clear a user's PIN. Voids the user's grants and lockout.
	 *
	 * @param int         $user_id User id.
	 * @param string|null $pin     New PIN, or null to remove it.
	 * @return true|WP_Error
	 */
	public static function set_pin( int $user_id, ?string $pin ) {
		if ( null !== $pin && ! self::is_valid_pin( $pin ) ) {
			return new WP_Error( 'pin_format', __( 'A PIN is 4 to 8 digits.', 'radius-hotel-booking-pro' ) );
		}

		if ( null === $pin ) {
			delete_user_meta( $user_id, self::META_PIN );
		} else {
			update_user_meta( $user_id, self::META_PIN, wp_hash_password( $pin ) );
		}

		self::revoke_grants( $user_id );
		delete_user_meta( $user_id, self::META_FAILURES );
		delete_user_meta( $user_id, self::META_LOCKED );

		/**
		 * Fires when a user's PIN is set, changed or removed. Never carries the PIN.
		 *
		 * @param int  $user_id User id.
		 * @param bool $removed Whether the PIN was removed.
		 */
		do_action( 'rtbp_pro_pin_changed', $user_id, null === $pin );

		self::activity(
			'security.pin_changed',
			array(
				'user_id'     => $user_id,
				'after'       => array( 'pin' => null === $pin ? 'removed' : 'changed' ),
				'description' => null === $pin
					? __( 'Removed a staff PIN', 'radius-hotel-booking-pro' )
					: __( 'Set a staff PIN', 'radius-hotel-booking-pro' ),
			)
		);

		return true;
	}

	/**
	 * Set or clear the fallback PIN.
	 *
	 * @param string|null $pin New PIN, or null to remove it.
	 * @return true|WP_Error
	 */
	public static function set_fallback( ?string $pin ) {
		if ( null !== $pin && ! self::is_valid_pin( $pin ) ) {
			return new WP_Error( 'pin_format', __( 'A PIN is 4 to 8 digits.', 'radius-hotel-booking-pro' ) );
		}

		if ( null === $pin ) {
			delete_option( AccessSettings::FALLBACK_OPTION );
		} else {
			update_option( AccessSettings::FALLBACK_OPTION, wp_hash_password( $pin ), false );
		}
		// Void every grant made with the old fallback PIN.
		update_option( self::FALLBACK_GENERATION, (string) ( (int) get_option( self::FALLBACK_GENERATION, 0 ) + 1 ), false );

		self::activity(
			'security.pin_changed',
			array(
				'after'       => array( 'fallback_pin' => null === $pin ? 'removed' : 'changed' ),
				'description' => null === $pin
					? __( 'Removed the fallback PIN', 'radius-hotel-booking-pro' )
					: __( 'Set the fallback PIN', 'radius-hotel-booking-pro' ),
			)
		);

		return true;
	}

	/**
	 * Unix time until which the user may not unlock, or 0.
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function locked_until( int $user_id ): int {
		$until = (int) get_user_meta( $user_id, self::META_LOCKED, true );
		return $until > time() ? $until : 0;
	}

	/**
	 * Check a PIN and issue a grant.
	 *
	 * PIN checks of one user run one at a time (a per-user lock), so the
	 * failure counter cannot be raced by parallel requests (M13 review #2).
	 *
	 * @param int             $user_id User id.
	 * @param string          $pin     PIN entered.
	 * @param string|string[] $keys    Access key(s) being unlocked; may be empty with the global scope.
	 * @return array|WP_Error `{ token, expires_at, scope }` or an error with a code:
	 *                        `pin_locked`, `pin_not_set`, `pin_invalid`, `invalid_key`, `pin_busy`.
	 */
	public static function unlock( int $user_id, string $pin, $keys ) {
		$global = AccessSettings::global_scope();
		$keys   = array_values( array_unique( array_filter( array_map( 'strval', (array) $keys ) ) ) );

		if ( ! $global ) {
			$unknown = array_filter( $keys, static fn( $key ) => ! AccessRegistry::has( $key ) );
			if ( ! $keys || $unknown ) {
				return new WP_Error( 'invalid_key', __( 'Choose what to unlock.', 'radius-hotel-booking-pro' ) );
			}
		}

		if ( ! self::acquire( $user_id ) ) {
			return new WP_Error( 'pin_busy', __( 'Another PIN is being checked. Try again in a moment.', 'radius-hotel-booking-pro' ), array( 'retry_after' => 2 ) );
		}

		try {
			// This request loaded the user's meta before it waited for the lock;
			// read the failure count and lockout fresh (review #2).
			wp_cache_delete( $user_id, 'user_meta' );

			$until = self::locked_until( $user_id );
			if ( $until ) {
				$minutes = (int) ceil( ( $until - time() ) / MINUTE_IN_SECONDS );
				return new WP_Error(
					'pin_locked',
					sprintf(
						/* translators: %d: minutes left. */
						_n( 'Too many wrong PINs. Try again in %d minute.', 'Too many wrong PINs. Try again in %d minutes.', $minutes, 'radius-hotel-booking-pro' ),
						$minutes
					),
					array( 'retry_after' => $until - time() )
				);
			}

			$hash     = (string) get_user_meta( $user_id, self::META_PIN, true );
			$fallback = '';
			if ( '' === $hash && AccessSettings::get( 'fallbackEnabled', true ) ) {
				$hash     = (string) get_option( AccessSettings::FALLBACK_OPTION, '' );
				$fallback = self::fallback_generation();
			}
			if ( '' === $hash ) {
				return new WP_Error( 'pin_not_set', __( 'You do not have a PIN yet. Ask a manager to set one for you.', 'radius-hotel-booking-pro' ) );
			}

			if ( ! self::is_valid_pin( $pin ) || ! wp_check_password( $pin, $hash ) ) {
				return self::fail( $user_id, implode( ',', $keys ) );
			}

			delete_user_meta( $user_id, self::META_FAILURES );

			$token = wp_generate_password( 40, false, false );
			$ttl   = AccessSettings::validity_seconds();
			// A grant made with the fallback PIN remembers its generation, so
			// changing or switching off the fallback voids it (review #7).
			$value = self::token_hash( $token ) . '|' . $fallback;
			foreach ( $global ? array( 'global' ) : $keys as $scope ) {
				set_transient( self::grant_name( $user_id, $scope ), $value, $ttl );
			}

			self::activity(
				'security.passcode_ok',
				array(
					'keys'        => $keys,
					'scope'       => $global ? 'global' : 'per_key',
					'fallback'    => '' !== $fallback,
					'description' => __( 'Confirmed with a PIN', 'radius-hotel-booking-pro' ),
				)
			);

			return array(
				'token'      => $token,
				'expires_at' => gmdate( 'c', time() + $ttl ),
				'scope'      => $global ? 'global' : 'per_key',
				'keys'       => $keys,
			);
		} finally {
			self::release( $user_id );
		}
	}

	/**
	 * `rtbp_access_passcode_check` handler: true when the request carries a
	 * valid grant for the key.
	 *
	 * @param true|WP_Error|null   $result  An earlier answer.
	 * @param string               $key     Access key.
	 * @param WP_REST_Request|null $request Request; null from Access::can().
	 * @return true|WP_Error|null
	 */
	public static function check( $result, $key, $request ) {
		if ( null !== $result ) {
			return $result;
		}

		// An administrator with no PIN to enter is let through: they are never
		// locked out, and they must be able to set the first PINs. Once they
		// have a PIN (or a fallback PIN is on), they are asked like anyone else.
		$user_id = get_current_user_id();
		if ( user_can( $user_id, 'manage_options' ) && ! self::can_enter_pin( $user_id ) ) {
			return true;
		}

		$token = $request instanceof WP_REST_Request
			? (string) $request->get_header( self::HEADER )
			: sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_RTBP_PASSCODE'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- defaulted; read-only token compared by hash.

		if ( '' !== $token && self::grant_valid( $user_id, (string) $key, $token ) ) {
			return true;
		}

		return new WP_Error( 'passcode_required', __( 'Enter your PIN to continue.', 'radius-hotel-booking-pro' ) );
	}

	/**
	 * Whether a token is a live grant for a key.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Access key.
	 * @param string $token   Token.
	 * @return bool
	 */
	public static function grant_valid( int $user_id, string $key, string $token ): bool {
		if ( ! $user_id ) {
			return false;
		}
		$scope  = AccessSettings::global_scope() ? 'global' : $key;
		$stored = get_transient( self::grant_name( $user_id, $scope ) );
		if ( ! is_string( $stored ) ) {
			return false;
		}

		list( $hash, $fallback ) = array_pad( explode( '|', $stored, 2 ), 2, '' );
		if ( ! hash_equals( $hash, self::token_hash( $token ) ) ) {
			return false;
		}
		// Made with the fallback PIN: only while that same fallback is on.
		return '' === $fallback
			|| ( AccessSettings::get( 'fallbackEnabled', true ) && self::has_fallback() && $fallback === self::fallback_generation() );
	}

	/**
	 * Void every grant of a user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public static function revoke_grants( int $user_id ): void {
		update_user_meta( $user_id, self::META_GENERATION, (int) get_user_meta( $user_id, self::META_GENERATION, true ) + 1 );
	}

	/**
	 * Count a wrong PIN; lock the user out at the limit.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Access key being unlocked.
	 * @return WP_Error
	 */
	private static function fail( int $user_id, string $key ): WP_Error {
		$failures = (int) get_user_meta( $user_id, self::META_FAILURES, true ) + 1;
		$locked   = $failures >= self::MAX_FAILURES;

		if ( $locked ) {
			update_user_meta( $user_id, self::META_LOCKED, time() + self::LOCKOUT );
			delete_user_meta( $user_id, self::META_FAILURES );
		} else {
			update_user_meta( $user_id, self::META_FAILURES, $failures );
		}

		/**
		 * Fires on a wrong PIN.
		 *
		 * @param int    $user_id  User id.
		 * @param string $key      Access key being unlocked.
		 * @param int    $failures Wrong PINs in a row.
		 * @param bool   $locked   Whether this one locked the user out.
		 */
		do_action( 'rtbp_passcode_failed', $user_id, $key, $failures, $locked );

		self::activity(
			'security.passcode_failed',
			array(
				'key'         => $key,
				'failures'    => $failures,
				'description' => __( 'Entered a wrong PIN', 'radius-hotel-booking-pro' ),
			)
		);

		if ( $locked ) {
			self::activity(
				'security.pin_locked',
				array(
					'minutes'     => self::LOCKOUT / MINUTE_IN_SECONDS,
					'description' => __( 'Locked out after too many wrong PINs', 'radius-hotel-booking-pro' ),
				)
			);

			return new WP_Error(
				'pin_locked',
				sprintf(
					/* translators: %d: minutes. */
					__( 'Too many wrong PINs. Try again in %d minutes.', 'radius-hotel-booking-pro' ),
					self::LOCKOUT / MINUTE_IN_SECONDS
				),
				array( 'retry_after' => self::LOCKOUT )
			);
		}

		$left = self::MAX_FAILURES - $failures;
		return new WP_Error(
			'pin_invalid',
			sprintf(
				/* translators: %d: attempts left. */
				_n( 'Wrong PIN. %d attempt left.', 'Wrong PIN. %d attempts left.', $left, 'radius-hotel-booking-pro' ),
				$left
			),
			array( 'attempts_left' => $left )
		);
	}

	/**
	 * The fallback PIN's current generation.
	 *
	 * @return string
	 */
	private static function fallback_generation(): string {
		return 'fb' . (int) get_option( self::FALLBACK_GENERATION, 0 );
	}

	/**
	 * Take the user's PIN-check lock. An atomic insert of a lock row: only
	 * one request can create it. A lock older than 30 s (a crashed request)
	 * is cleared and taken over.
	 *
	 * @param int $user_id User id.
	 * @return bool Whether the lock was taken.
	 */
	private static function acquire( int $user_id ): bool {
		global $wpdb;
		$name = self::lock_name( $user_id );

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- atomic lock; the options API cannot fail on an existing row.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );
			if ( 1 === (int) $inserted ) {
				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read the lock row itself, not a cached copy.
			$since = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
			if ( null === $since ) {
				continue; // Released meanwhile: try to take it.
			}
			if ( time() - (int) $since < 30 ) {
				return false; // Held by a live request.
			}
			// Stale (a crashed request): clear it only if nobody took it since.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- conditional delete of the stale lock row.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, (string) $since ) );
		}
		return false;
	}

	/**
	 * Release the user's PIN-check lock.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private static function release( int $user_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the lock row is never cached.
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::lock_name( $user_id ) ) );
	}

	/**
	 * Option name of a user's PIN-check lock.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private static function lock_name( int $user_id ): string {
		return 'rtbp_pro_pin_lock_' . $user_id;
	}

	/**
	 * Transient name of a grant.
	 *
	 * @param int    $user_id User id.
	 * @param string $scope   `global` or an access key.
	 * @return string
	 */
	private static function grant_name( int $user_id, string $scope ): string {
		$generation = (int) get_user_meta( $user_id, self::META_GENERATION, true );
		return 'rtbp_pro_pc_' . $user_id . '_' . $generation . '_' . md5( $scope );
	}

	/**
	 * Keyed hash of a token (only this is stored).
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function token_hash( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Emit an activity event once the free emitter exists (M14).
	 *
	 * @param string $action  Action key.
	 * @param array  $context Context.
	 * @return void
	 */
	private static function activity( string $action, array $context ): void {
		if ( function_exists( 'rtbp_activity' ) ) {
			rtbp_activity( $action, null, $context );
		}
	}
}
