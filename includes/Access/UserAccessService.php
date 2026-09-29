<?php
/**
 * Per-person access: role assignment, overrides, the effective map.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Access\Access;
use RadiusTheme\RadiusHotelBooking\Access\AccessRegistry;
use RadiusTheme\RadiusHotelBooking\Core\Permissions\Capabilities;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Resolution on the free `rtbp_access_level` filter (M13, ADR-004), key by
 * key: the user's override, then the access role's level for the key, then
 * the role's `*`, then the free level. Key by key, so an override replaces one key and never the whole map (the
 * legacy bug). The free plugin applies the administrator rule afterwards.
 */
class UserAccessService {

	const META_OVERRIDES = 'rtbp_pro_access_overrides';

	/**
	 * Roles loaded this request, by id (false = missing).
	 *
	 * @var array<int, AccessRole|false>
	 */
	private static array $roles = array();

	/**
	 * `rtbp_access_level` filter.
	 *
	 * @param string  $level The free level.
	 * @param string  $key   Access key.
	 * @param WP_User $user  User.
	 * @return string
	 */
	public static function filter_level( $level, $key, $user ): string {
		if ( ! $user instanceof WP_User ) {
			return (string) $level;
		}
		return self::resolve( (string) $level, (string) $key, $user->ID )['level'];
	}

	/**
	 * A user's level for a key and where it comes from.
	 *
	 * @param string $base    The level without Pro (built-in role or registry default).
	 * @param string $key     Access key.
	 * @param int    $user_id User id.
	 * @return array{level: string, source: string}
	 */
	public static function resolve( string $base, string $key, int $user_id ): array {
		$overrides = self::overrides( $user_id );
		if ( isset( $overrides[ $key ] ) ) {
			return array(
				'level'  => $overrides[ $key ],
				'source' => 'override',
			);
		}

		$role = self::role_of( $user_id );
		if ( ! $role && (int) get_user_meta( $user_id, AccessRoleRepository::META_ROLE, true ) ) {
			// The user has a role that cannot be loaded (table dropped, DB
			// error): fail closed rather than fall back to the open defaults
			// (review #8).
			return array(
				'level'  => Access::LOCKED,
				'source' => 'role',
			);
		}
		$levels = $role ? (array) $role->levels : array();
		$level  = $levels[ $key ] ?? ( $levels['*'] ?? null );
		if ( in_array( $level, Access::levels(), true ) ) {
			return array(
				'level'  => $level,
				'source' => 'role',
			);
		}

		return array(
			'level'  => $base,
			'source' => 'default',
		);
	}

	/**
	 * Dashboard users (built-in plugin roles and administrators).
	 *
	 * @return array[]
	 */
	public function users(): array {
		$users = get_users(
			array(
				'role__in' => array_merge( Capabilities::manageableRoles(), array( 'administrator' ) ),
				'orderby'  => 'display_name',
			)
		);

		return array_map(
			function ( WP_User $user ) {
				$role = self::role_of( $user->ID );
				return array(
					'id'          => $user->ID,
					'name'        => $user->display_name,
					'email'       => $user->user_email,
					'wp_roles'    => array_values( $user->roles ),
					'access_role' => $role ? array(
						'id'   => (int) $role->id,
						'name' => (string) $role->name,
					) : null,
					'overrides'   => count( self::overrides( $user->ID ) ),
					'has_pin'     => PasscodeService::has_pin( $user->ID ),
				);
			},
			$users
		);
	}

	/**
	 * One user's access: role, overrides and the effective map with sources.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public function profile( int $user_id ): array {
		$user = $this->user( $user_id );
		$role = self::role_of( $user_id );

		$effective = array();
		foreach ( AccessRegistry::keys() as $key => $definition ) {
			$chain = self::resolve( self::base_level( $user, $key ), $key, $user_id );
			$level = Access::level( $key, $user );

			$source = $chain['source'];
			if ( 'default' === $source ) {
				$source = null !== self::wp_role_level( $user, $key ) ? 'wp_role' : 'default';
			}
			// Applied by the free plugin after Pro: the administrator rule, or
			// no dashboard access at all.
			if ( $level !== $chain['level'] ) {
				$source = user_can( $user, 'manage_options' ) ? 'admin' : 'no_dashboard';
			}

			$effective[] = array(
				'key'    => $key,
				'level'  => $level,
				'source' => $source,
			);
		}

		return array(
			'user'           => array(
				'id'       => $user->ID,
				'name'     => $user->display_name,
				'email'    => $user->user_email,
				'wp_roles' => array_values( $user->roles ),
				'is_admin' => user_can( $user, 'manage_options' ),
			),
			'access_role'    => $role ? (int) $role->id : null,
			'overrides'      => (object) self::overrides( $user_id ),
			'has_pin'        => PasscodeService::has_pin( $user_id ),
			'effective'      => $effective,
			'profile_fields' => array(
				'overrides' => (object) ProfileFields::overrides( $user_id ),
				'effective' => ProfileFields::resolve( $user_id ),
			),
		);
	}

	/**
	 * Assign a role and/or change overrides, key by key.
	 *
	 * @param int   $user_id User id.
	 * @param array $input   `access_role` (id or null), `overrides` (key => level, or null to
	 *                       remove), `profile_fields` (field => allow | restrict, or null).
	 * @return array The new profile.
	 * @throws DomainException On an unknown role or an invalid level.
	 */
	public function update( int $user_id, array $input ): array {
		$user   = $this->user( $user_id );
		$before = array();
		$after  = array();
		$writes = array();

		// 1. Validate everything; nothing is written if any part is invalid
		// (review #4).
		if ( array_key_exists( 'access_role', $input ) ) {
			$old = self::role_of( $user_id );
			$new = null;
			if ( null !== $input['access_role'] && '' !== $input['access_role'] ) {
				$new = ( new AccessRoleRepository() )->find( (int) $input['access_role'] );
				if ( ! $new instanceof AccessRole ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
					throw DomainException::invalid( array( 'access_role' => __( 'This access role does not exist.', 'radius-hotel-booking-pro' ) ) );
				}
			}
			if ( ( $old ? (int) $old->id : null ) !== ( $new ? (int) $new->id : null ) ) {
				$before['access_role']              = $old ? (string) $old->slug : 'none';
				$after['access_role']               = $new ? (string) $new->slug : 'none';
				$writes[ AccessRoleRepository::META_ROLE ] = $new ? (int) $new->id : null;
			}
		}

		if ( array_key_exists( 'overrides', $input ) ) {
			$changes = (array) $input['overrides'];
			$current = self::overrides( $user_id );
			$removed = array_keys( array_filter( $changes, 'is_null' ) );
			$next    = array_diff_key(
				array_merge( $current, AccessRoleService::sanitizeLevels( array_filter( $changes, static fn( $v ) => null !== $v ), 'overrides' ) ),
				array_flip( $removed )
			);
			unset( $next['*'] ); // Overrides are per key only.

			$diff = AccessRoleService::diff( $current, $next );
			if ( $diff['before'] ) {
				$before                        += $diff['before'];
				$after                         += $diff['after'];
				$writes[ self::META_OVERRIDES ] = $next ? $next : null;
			}
		}

		if ( array_key_exists( 'profile_fields', $input ) ) {
			$current = ProfileFields::overrides( $user_id );
			$next    = ProfileFields::merge( $current, (array) $input['profile_fields'] );
			$diff    = AccessRoleService::diff( $current, $next );
			foreach ( $diff['before'] as $field => $value ) {
				$before[ 'profile.' . $field ] = $value;
				$after[ 'profile.' . $field ]  = $diff['after'][ $field ];
			}
			if ( $diff['before'] ) {
				$writes[ ProfileFields::META_OVERRIDES ] = $next ? $next : null;
			}
		}

		// 2. Write.
		foreach ( $writes as $meta_key => $value ) {
			if ( null === $value ) {
				delete_user_meta( $user_id, $meta_key );
			} else {
				update_user_meta( $user_id, $meta_key, $value );
			}
		}

		if ( $before ) {
			Access::flush();
			self::$roles = array();
			self::emit(
				'permission.changed',
				array(
					'user_id' => $user_id,
					'before'  => $before,
					'after'   => $after,
				),
				/* translators: %s: staff member name. */
				sprintf( __( 'Changed the permissions of %s', 'radius-hotel-booking-pro' ), $user->display_name ),
				'user'
			);
		}

		return $this->profile( $user_id );
	}

	/**
	 * Remove the overrides of one user, or of everyone (feature 13.12).
	 *
	 * @param int|null $user_id User id, or null for everyone.
	 * @return int Users whose overrides were removed.
	 */
	public function resetOverrides( ?int $user_id ): int {
		if ( null !== $user_id ) {
			$this->user( $user_id );
			$users = self::overrides( $user_id ) ? array( $user_id ) : array();
		} else {
			$users = array_map(
				'intval',
				get_users(
					array(
						'meta_key' => self::META_OVERRIDES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'fields'   => 'ID',
					)
				)
			);
		}

		foreach ( $users as $id ) {
			delete_user_meta( $id, self::META_OVERRIDES );
		}

		Access::flush();
		self::emit(
			'permission.overrides_reset',
			array(
				'user_id' => $user_id,
				'users'   => $users,
			),
			null === $user_id
				? __( 'Reset every personal permission override', 'radius-hotel-booking-pro' )
				: __( 'Reset personal permission overrides', 'radius-hotel-booking-pro' ),
			'reset'
		);

		return count( $users );
	}

	/**
	 * A user's overrides (valid levels only).
	 *
	 * @param int $user_id User id.
	 * @return array<string, string>
	 */
	public static function overrides( int $user_id ): array {
		$raw = get_user_meta( $user_id, self::META_OVERRIDES, true );
		return is_array( $raw ) ? array_filter( $raw, static fn( $level ) => in_array( $level, Access::levels(), true ) ) : array();
	}

	/**
	 * A user's access role, cached for the request.
	 *
	 * @param int $user_id User id.
	 * @return AccessRole|null
	 */
	public static function role_of( int $user_id ): ?AccessRole {
		$role_id = (int) get_user_meta( $user_id, AccessRoleRepository::META_ROLE, true );
		if ( ! $role_id ) {
			return null;
		}
		if ( ! array_key_exists( $role_id, self::$roles ) ) {
			$role                    = ( new AccessRoleRepository() )->find( $role_id );
			self::$roles[ $role_id ] = $role instanceof AccessRole ? $role : false;
		}
		return false === self::$roles[ $role_id ] ? null : self::$roles[ $role_id ];
	}

	/**
	 * Forget cached roles (after a role changes).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$roles = array();
	}

	/**
	 * The free level without Pro: built-in role levels, then the registry.
	 *
	 * @param WP_User $user User.
	 * @param string  $key  Access key.
	 * @return string
	 */
	private static function base_level( WP_User $user, string $key ): string {
		return self::wp_role_level( $user, $key ) ?? ( AccessRegistry::get( $key )['default'] ?? Access::LOCKED );
	}

	/**
	 * The most permissive level the user's built-in plugin roles set, or null.
	 *
	 * @param WP_User $user User.
	 * @param string  $key  Access key.
	 * @return string|null
	 */
	private static function wp_role_level( WP_User $user, string $key ): ?string {
		$best = null;
		foreach ( array_intersect( (array) $user->roles, Capabilities::manageableRoles() ) as $role ) {
			$level = Access::role_level( $role, $key );
			if ( null !== $level && ( null === $best || array_search( $level, Access::levels(), true ) < array_search( $best, Access::levels(), true ) ) ) {
				$best = $level;
			}
		}
		return $best;
	}

	/**
	 * A user, or 404.
	 *
	 * @param int $user_id User id.
	 * @return WP_User
	 * @throws DomainException When missing.
	 */
	private function user( int $user_id ): WP_User {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::notFound( __( 'This staff member does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $user;
	}

	/**
	 * Fire `rtbp_access_changed` and the activity event.
	 *
	 * @param string $action      Activity action.
	 * @param array  $context     Context.
	 * @param string $description Description.
	 * @param string $scope       `user` or `reset`.
	 * @return void
	 */
	private static function emit( string $action, array $context, string $description, string $scope ): void {
		/** This action is documented in the free plugin's includes/Access/AccessAudit.php */
		do_action( 'rtbp_access_changed', $scope, $context, get_current_user_id() );

		if ( function_exists( 'rtbp_activity' ) ) {
			rtbp_activity( $action, null, $context + array( 'description' => $description ) );
		}
	}
}
