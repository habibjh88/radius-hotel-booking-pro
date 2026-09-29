<?php
/**
 * Profile-field control (M13 T6, features 13.11, 17.6).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Which fields a member of staff may change on their own profile: `allow` or
 * `restrict` per field. Resolved per person, field by field: the person's
 * override, then their access role, then Settings → Passcodes (profile-field
 * defaults), then the code default below. Administrators may
 * change every field.
 *
 * M16 (staff self-service) enforces it with allowed().
 */
final class ProfileFields {

	const ALLOW    = 'allow';
	const RESTRICT = 'restrict';

	const META_OVERRIDES = 'rtbp_pro_profile_field_overrides';

	/**
	 * Every field: key => [ translated label, code default ]. The defaults
	 * match the legacy system.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function fields(): array {
		$fields = array(
			'username'          => array( __( 'Username', 'radius-hotel-booking-pro' ), self::RESTRICT ),
			'first_name'        => array( __( 'First name', 'radius-hotel-booking-pro' ), self::ALLOW ),
			'last_name'         => array( __( 'Last name', 'radius-hotel-booking-pro' ), self::ALLOW ),
			'dob'               => array( __( 'Date of birth', 'radius-hotel-booking-pro' ), self::RESTRICT ),
			'address'           => array( __( 'Address', 'radius-hotel-booking-pro' ), self::RESTRICT ),
			'marital_status'    => array( __( 'Marital status', 'radius-hotel-booking-pro' ), self::RESTRICT ),
			'num_children'      => array( __( 'Number of children', 'radius-hotel-booking-pro' ), self::RESTRICT ),
			'phone'             => array( __( 'Phone', 'radius-hotel-booking-pro' ), self::RESTRICT ),
			'email'             => array( __( 'E-mail', 'radius-hotel-booking-pro' ), self::RESTRICT ),
			'emergency_contact' => array( __( 'Emergency contact', 'radius-hotel-booking-pro' ), self::RESTRICT ),
			'passcode'          => array( __( 'Personal PIN', 'radius-hotel-booking-pro' ), self::ALLOW ),
			'password'          => array( __( 'Password', 'radius-hotel-booking-pro' ), self::ALLOW ),
		);

		/**
		 * Filters the profile fields staff may be allowed to change.
		 *
		 * @param array $fields Key => [ label, 'allow'|'restrict' ].
		 */
		return (array) apply_filters( 'rtbp_pro_profile_fields', $fields );
	}

	/**
	 * The fields for the API: key, label, code default.
	 *
	 * @return array[]
	 */
	public static function describe(): array {
		$out = array();
		foreach ( self::fields() as $key => $field ) {
			$out[] = array(
				'key'     => $key,
				'label'   => (string) $field[0],
				'default' => self::RESTRICT === ( $field[1] ?? '' ) ? self::RESTRICT : self::ALLOW,
			);
		}
		return $out;
	}

	/**
	 * The global defaults: code defaults, then Settings → Passcodes.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		$defaults = array();
		foreach ( self::describe() as $field ) {
			$defaults[ $field['key'] ] = $field['default'];
		}
		$saved = AccessSettings::get( 'profileFields', array() );
		return array_merge( $defaults, array_intersect_key( self::clean( (array) $saved ), $defaults ) );
	}

	/**
	 * A person's value per field and where it comes from.
	 *
	 * @param int $user_id User id.
	 * @return array[] `[ { key, value, source } ]`, source: override | role | settings | default | admin.
	 */
	public static function resolve( int $user_id ): array {
		$overrides = self::overrides( $user_id );
		$role      = UserAccessService::role_of( $user_id );
		$role_map  = $role ? self::clean( (array) $role->profile_fields ) : array();
		$saved     = self::clean( (array) AccessSettings::get( 'profileFields', array() ) );
		$is_admin  = user_can( $user_id, 'manage_options' );

		$out = array();
		foreach ( self::describe() as $field ) {
			$key = $field['key'];
			if ( $is_admin ) {
				$value  = self::ALLOW;
				$source = 'admin';
			} elseif ( isset( $overrides[ $key ] ) ) {
				$value  = $overrides[ $key ];
				$source = 'override';
			} elseif ( isset( $role_map[ $key ] ) ) {
				$value  = $role_map[ $key ];
				$source = 'role';
			} elseif ( isset( $saved[ $key ] ) ) {
				$value  = $saved[ $key ];
				$source = 'settings';
			} else {
				$value  = $field['default'];
				$source = 'default';
			}
			$out[] = array(
				'key'    => $key,
				'value'  => $value,
				'source' => $source,
			);
		}
		return $out;
	}

	/**
	 * Whether a person may change a field of their own profile.
	 *
	 * @param int    $user_id User id.
	 * @param string $field   Field key.
	 * @return bool
	 */
	public static function allowed( int $user_id, string $field ): bool {
		foreach ( self::resolve( $user_id ) as $row ) {
			if ( $row['key'] === $field ) {
				return self::ALLOW === $row['value'];
			}
		}
		return false; // Unknown field: not editable.
	}

	/**
	 * A person's overrides (valid values only).
	 *
	 * @param int $user_id User id.
	 * @return array<string, string>
	 */
	public static function overrides( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_OVERRIDES, true );
		return is_array( $raw ) ? self::clean( $raw ) : array();
	}

	/**
	 * Apply override changes (`null` removes one) to a map, validating them.
	 * Writes nothing.
	 *
	 * @param array $current Current overrides.
	 * @param array $changes Field => allow | restrict | null.
	 * @return array<string, string> The new overrides.
	 * @throws DomainException On an invalid value.
	 */
	public static function merge( array $current, array $changes ): array {
		$next = $current;
		foreach ( $changes as $key => $value ) {
			if ( null === $value ) {
				unset( $next[ $key ] );
			}
		}
		return array_merge( $next, self::validate( array_filter( $changes, static fn( $v ) => null !== $v ), 'profile_fields' ) );
	}

	/**
	 * Clean a field => value map, throwing on an invalid value.
	 *
	 * @param mixed  $map   Raw map.
	 * @param string $field Field name for errors.
	 * @return array<string, string>
	 * @throws DomainException On an invalid value.
	 */
	public static function validate( $map, string $field ): array {
		if ( ! is_array( $map ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::invalid( array( $field => __( 'This value is not valid.', 'radius-hotel-booking-pro' ) ) );
		}
		foreach ( $map as $key => $value ) {
			if ( isset( self::fields()[ $key ] ) && ! in_array( $value, array( self::ALLOW, self::RESTRICT ), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
				throw DomainException::invalid( array( $field . '.' . $key => __( 'Choose Allowed or Restricted.', 'radius-hotel-booking-pro' ) ) );
			}
		}
		return self::clean( $map );
	}

	/**
	 * Settings sanitiser for `pro_access.profileFields`.
	 *
	 * @param mixed $value Value.
	 * @return array|WP_Error
	 */
	public static function sanitize_setting( $value ) {
		if ( ! is_array( $value ) ) {
			return new WP_Error( 'rtbp_invalid_setting', __( 'This value is not valid.', 'radius-hotel-booking-pro' ) );
		}
		return self::clean( $value );
	}

	/**
	 * Known fields and valid values only.
	 *
	 * @param array $map Raw map.
	 * @return array<string, string>
	 */
	private static function clean( array $map ): array {
		$fields = self::fields();
		$clean  = array();
		foreach ( $map as $key => $value ) {
			if ( isset( $fields[ $key ] ) && in_array( $value, array( self::ALLOW, self::RESTRICT ), true ) ) {
				$clean[ $key ] = $value;
			}
		}
		return $clean;
	}
}
