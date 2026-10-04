<?php
/**
 * My password and my PIN (M16).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\SelfService
 */

namespace RadiusTheme\RadiusHotelBookingPro\SelfService;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBookingPro\Access\PasscodeService;
use RadiusTheme\RadiusHotelBookingPro\Access\ProfileFields;
use WP_Error;
use WP_Session_Tokens;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * A member of staff changes their own password (16.3) and PIN (16.4).
 *
 * - **Password**: the current password is required; at least 8 characters
 *   and not the current one. Every session of the user is ended, then this
 *   browser is signed in again — so the other browsers are signed out — and
 *   the new REST nonce is returned (the old one belonged to the old session).
 * - **PIN**: needs *Advanced staff access*; the current PIN (their own, not
 *   the fallback PIN) or the current password is required; 4–8 digits.
 *   `PasscodeService::set_pin()` voids every passcode grant and the lockout.
 *
 * A wrong current password or PIN counts: five in a row lock the check for
 * 15 minutes (the PIN shares unlock()'s counter), so a session left open
 * cannot be used to guess either. Both need the field to be allowed in the
 * profile-field map (`password`, `passcode`).
 */
class CredentialsService {

	const META_PW_FAILURES = 'rtbp_pro_pw_failures';
	const META_PW_LOCKED   = 'rtbp_pro_pw_locked_until';

	const MIN_PASSWORD = 8;

	/**
	 * What the user may do, for the profile screen.
	 *
	 * @param int $user_id User id.
	 * @return array `{ password: { editable }, pin: { available, editable, has_pin } }`.
	 */
	public static function summary( int $user_id ): array {
		return array(
			'password' => array( 'editable' => ProfileFields::allowed( $user_id, 'password' ) ),
			'pin'      => array(
				'available' => self::pins_on(),
				'editable'  => ProfileFields::allowed( $user_id, 'passcode' ),
				'has_pin'   => self::pins_on() && PasscodeService::has_pin( $user_id ),
			),
		);
	}

	/**
	 * Change one's own password; sign out every other session.
	 *
	 * @param int   $user_id User id (the signed-in user).
	 * @param array $input   `{ current_password, new_password }`.
	 * @return array `{ nonce }` — the REST nonce of the new session.
	 * @throws DomainException 403 restricted, 422 invalid, 429 locked out.
	 */
	public function change_password( int $user_id, array $input ): array {
		$user = $this->user( $user_id );
		$this->require_field( $user_id, 'password', 'self.password_change' );

		$current = (string) ( $input['current_password'] ?? '' );
		$new     = (string) ( $input['new_password'] ?? '' );
		$errors  = array();
		if ( '' === $current ) {
			$errors['current_password'] = __( 'Enter your current password.', 'radius-hotel-booking-pro' );
		}
		if ( strlen( $new ) < self::MIN_PASSWORD ) {
			/* translators: %d: minimum length. */
			$errors['new_password'] = sprintf( __( 'Use at least %d characters.', 'radius-hotel-booking-pro' ), self::MIN_PASSWORD );
		} elseif ( $new === $current ) {
			$errors['new_password'] = __( 'Choose a password different from the current one.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		$this->check_password( $user, $current );

		wp_set_password( $new, $user_id );
		$nonce = $this->renew_session( $user_id );

		rtbp_activity(
			'self.password_change',
			null,
			array(
				'user_id'     => $user_id,
				'after'       => array( 'password' => 'changed' ),
				'description' => __( 'Changed their own password; other sessions signed out', 'radius-hotel-booking-pro' ),
			)
		);

		return array( 'nonce' => $nonce );
	}

	/**
	 * Set one's own PIN.
	 *
	 * @param int   $user_id User id (the signed-in user).
	 * @param array $input   `{ pin, current_pin }` or `{ pin, current_password }`.
	 * @return array As summary().
	 * @throws DomainException 409 feature off, 403 restricted, 422 invalid, 429 locked out.
	 */
	public function change_pin( int $user_id, array $input ): array {
		$user = $this->user( $user_id );
		if ( ! self::pins_on() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict( 'feature_off', __( 'PINs need the Advanced staff access feature (Settings → Pro features).', 'radius-hotel-booking-pro' ) );
		}
		$this->require_field( $user_id, 'passcode', 'self.pin_change' );

		$pin         = trim( (string) ( $input['pin'] ?? '' ) );
		$current_pin = trim( (string) ( $input['current_pin'] ?? '' ) );
		$password    = (string) ( $input['current_password'] ?? '' );
		$errors      = array();
		if ( ! PasscodeService::is_valid_pin( $pin ) ) {
			$errors['pin'] = __( 'Enter a PIN of 4 to 8 digits.', 'radius-hotel-booking-pro' );
		}
		if ( '' === $current_pin && '' === $password ) {
			$errors[ PasscodeService::has_pin( $user_id ) ? 'current_pin' : 'current_password' ] = PasscodeService::has_pin( $user_id )
				? __( 'Enter your current PIN or your password.', 'radius-hotel-booking-pro' )
				: __( 'Enter your password.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}

		if ( '' !== $current_pin ) {
			$this->throw_on_error( PasscodeService::verify_own_pin( $user_id, $current_pin ), 'current_pin' );
		} else {
			$this->check_password( $user, $password );
		}

		$this->throw_on_error(
			PasscodeService::set_pin(
				$user_id,
				$pin,
				array(
					'action'      => 'self.pin_change',
					'description' => __( 'Changed their own PIN', 'radius-hotel-booking-pro' ),
				)
			),
			'pin'
		);

		return self::summary( $user_id );
	}

	/**
	 * Whether personal PINs are on (Advanced staff access).
	 *
	 * @return bool
	 */
	private static function pins_on(): bool {
		return function_exists( 'rtbp_pro_feature_active' ) && rtbp_pro_feature_active( 'advanced_access' );
	}

	/**
	 * The signed-in user.
	 *
	 * @param int $user_id User id.
	 * @return WP_User
	 * @throws DomainException 401.
	 */
	private function user( int $user_id ): WP_User {
		$user = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof WP_User ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw new DomainException( 'not_signed_in', __( 'Please sign in again.', 'radius-hotel-booking-pro' ), 401 );
		}
		return $user;
	}

	/**
	 * Refuse when the profile-field map does not let the user change the field.
	 *
	 * @param int    $user_id User id.
	 * @param string $field   `password` or `passcode`.
	 * @param string $action  The action refused, for the log.
	 * @return void
	 * @throws DomainException 403 `field_restricted`.
	 */
	private function require_field( int $user_id, string $field, string $action ): void {
		if ( ProfileFields::allowed( $user_id, $field ) ) {
			return;
		}
		rtbp_activity(
			'security.denied',
			null,
			array(
				'key'         => $action,
				'fields'      => array( $field ),
				/* translators: %s: field key. */
				'description' => sprintf( __( 'Tried to change a restricted profile field: %s', 'radius-hotel-booking-pro' ), $field ),
			)
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
		throw new DomainException( 'field_restricted', __( 'You are not allowed to change this. Ask a manager.', 'radius-hotel-booking-pro' ), 403 );
	}

	/**
	 * Check the current password; five wrong in a row lock it for 15 minutes.
	 * Runs under the user's PIN-check lock, so parallel guesses are counted.
	 *
	 * @param WP_User $user     User.
	 * @param string  $password Password entered.
	 * @return void
	 * @throws DomainException 422 wrong, 429 locked out, 409 busy.
	 */
	private function check_password( WP_User $user, string $password ): void {
		$user_id = (int) $user->ID;
		$result  = PasscodeService::locked(
			$user_id,
			static function () use ( $user_id, $password ) {
				$until = (int) get_user_meta( $user_id, self::META_PW_LOCKED, true );
				if ( $until > time() ) {
					$minutes = (int) ceil( ( $until - time() ) / MINUTE_IN_SECONDS );
					return new WP_Error(
						'password_locked',
						sprintf(
							/* translators: %d: minutes left. */
							_n( 'Too many wrong passwords. Try again in %d minute.', 'Too many wrong passwords. Try again in %d minutes.', $minutes, 'radius-hotel-booking-pro' ),
							$minutes
						)
					);
				}

				// The hash read fresh (the lock cleared the meta cache, not the user's).
				clean_user_cache( $user_id );
				$fresh = get_userdata( $user_id );
				if ( $fresh && wp_check_password( $password, $fresh->user_pass, $user_id ) ) {
					delete_user_meta( $user_id, self::META_PW_FAILURES );
					return true;
				}

				$failures = (int) get_user_meta( $user_id, self::META_PW_FAILURES, true ) + 1;
				$locked   = $failures >= PasscodeService::MAX_FAILURES;
				if ( $locked ) {
					update_user_meta( $user_id, self::META_PW_LOCKED, time() + PasscodeService::LOCKOUT );
					delete_user_meta( $user_id, self::META_PW_FAILURES );
				} else {
					update_user_meta( $user_id, self::META_PW_FAILURES, $failures );
				}
				rtbp_activity(
					'self.password_failed',
					null,
					array(
						'failures'    => $failures,
						'locked'      => $locked,
						'description' => __( 'Entered a wrong current password in their profile', 'radius-hotel-booking-pro' ),
					)
				);

				return $locked
					? new WP_Error(
						'password_locked',
						sprintf(
							/* translators: %d: minutes. */
							__( 'Too many wrong passwords. Try again in %d minutes.', 'radius-hotel-booking-pro' ),
							PasscodeService::LOCKOUT / MINUTE_IN_SECONDS
						)
					)
					: new WP_Error( 'password_invalid', __( 'Your current password is not right.', 'radius-hotel-booking-pro' ) );
			}
		);
		$this->throw_on_error( $result, 'current_password' );
	}

	/**
	 * Turn a check's WP_Error into the API error: a wrong value is a field
	 * error, a lockout 429, a busy lock 409.
	 *
	 * @param true|WP_Error $result Result.
	 * @param string        $field  Field the error belongs to.
	 * @return void
	 * @throws DomainException On an error.
	 */
	private function throw_on_error( $result, string $field ): void {
		if ( ! is_wp_error( $result ) ) {
			return;
		}
		$code    = (string) $result->get_error_code();
		$message = $result->get_error_message();
		if ( in_array( $code, array( 'pin_locked', 'password_locked' ), true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw new DomainException( $code, $message, 429 );
		}
		if ( 'pin_busy' === $code ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict( $code, $message );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
		throw new DomainException( $code, $message, 422, array( $field => $message ) );
	}

	/**
	 * End every session of the user and sign this browser in again (the old
	 * auth cookies stopped working with the password anyway). Returns the
	 * REST nonce of the new session.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function renew_session( int $user_id ): string {
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();

		if ( get_current_user_id() !== $user_id ) {
			return '';
		}

		// Keep "Remember me" as it was, the way wp_update_user() does.
		$cookie   = wp_parse_auth_cookie( '', 'logged_in' );
		$life     = (int) apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $user_id, false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
		$remember = is_array( $cookie ) && ( (int) $cookie['expiration'] - time() ) > $life;

		// The nonce is tied to the session token, which only exists in the
		// cookie being set: make this request see the new cookie.
		$capture = static function ( $logged_in_cookie ) {
			$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
		};
		add_action( 'set_logged_in_cookie', $capture );
		wp_set_auth_cookie( $user_id, $remember, is_ssl() );
		remove_action( 'set_logged_in_cookie', $capture );

		wp_set_current_user( $user_id );
		return wp_create_nonce( 'wp_rest' );
	}
}
