<?php
/**
 * Access roles: create, change, delete.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Access\Access;
use RadiusTheme\RadiusHotelBooking\Access\AccessRegistry;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;

defined( 'ABSPATH' ) || exit;

/**
 * Named access roles (features 13.13, 13.14). Changing a role changes every
 * person holding it. Templates can be edited but not deleted.
 */
class AccessRoleService {

	/**
	 * Repository.
	 *
	 * @var AccessRoleRepository
	 */
	private AccessRoleRepository $roles;

	/**
	 * Constructor.
	 *
	 * @param AccessRoleRepository|null $roles Repository.
	 */
	public function __construct( ?AccessRoleRepository $roles = null ) {
		$this->roles = $roles ?? new AccessRoleRepository();
	}

	/**
	 * Every role with its user count.
	 *
	 * @return array[]
	 */
	public function all(): array {
		$counts = $this->roles->userCounts();
		return array_map(
			static fn( AccessRole $role ) => self::shape( $role, $counts[ $role->id ] ?? 0 ),
			$this->roles->ordered()
		);
	}

	/**
	 * One role.
	 *
	 * @param int $id Role id.
	 * @return array
	 */
	public function get( int $id ): array {
		return self::shape( $this->find( $id ), $this->roles->userCounts()[ $id ] ?? 0 );
	}

	/**
	 * Create a role.
	 *
	 * @param array $input name, description, levels.
	 * @return array
	 */
	public function create( array $input ): array {
		$data         = $this->validate( $input, true );
		$data['slug'] = $this->uniqueSlug( $data['name'] );
		$role         = $this->roles->create( $data + array( 'is_template' => 0 ) );

		Access::flush();
		UserAccessService::flush();
		self::emit(
			'permission.role_created',
			$role,
			array(),
			array( 'levels' => $role->levels ),
			/* translators: %s: role name. */
			sprintf( __( 'Created the access role %s', 'radius-hotel-booking-pro' ), $role->name )
		);

		return self::shape( $role, 0 );
	}

	/**
	 * Change a role (only the fields given).
	 *
	 * @param int   $id    Role id.
	 * @param array $input name, description, levels.
	 * @return array
	 */
	public function update( int $id, array $input ): array {
		$role   = $this->find( $id );
		$before = self::audited( $role );
		$data   = $this->validate( $input, false );

		$this->roles->update( $id, $data );
		$role = $this->find( $id );

		Access::flush();
		UserAccessService::flush();
		$diff = self::diff( $before, self::audited( $role ) );
		self::emit(
			'permission.role_updated',
			$role,
			$diff['before'],
			$diff['after'],
			/* translators: %s: role name. */
			sprintf( __( 'Changed the access role %s', 'radius-hotel-booking-pro' ), $role->name )
		);

		return self::shape( $role, $this->roles->userCounts()[ $id ] ?? 0 );
	}

	/**
	 * Delete a role; its holders fall back to the defaults.
	 *
	 * @param int $id Role id.
	 * @return int[] The users who held it.
	 * @throws DomainException For a template role.
	 */
	public function delete( int $id ): array {
		$role = $this->find( $id );
		if ( $role->is_template ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::conflict( 'template_role', __( 'A template role can be changed but not deleted.', 'radius-hotel-booking-pro' ) );
		}

		$users = $this->roles->unassignAll( $id );
		$this->roles->delete( $id );

		Access::flush();
		UserAccessService::flush();
		self::emit(
			'permission.role_deleted',
			$role,
			array( 'levels' => $role->levels ),
			array(),
			/* translators: %s: role name. */
			sprintf( __( 'Deleted the access role %s', 'radius-hotel-booking-pro' ), $role->name ),
			array( 'users' => $users )
		);

		return $users;
	}

	/**
	 * Clean a key => level map: known keys (or `*`) and valid levels only.
	 *
	 * @param mixed  $levels Raw map.
	 * @param string $field  Field name for errors.
	 * @return array
	 * @throws DomainException On an invalid level.
	 */
	public static function sanitizeLevels( $levels, string $field = 'levels' ): array {
		if ( ! is_array( $levels ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::invalid( array( $field => __( 'This value is not valid.', 'radius-hotel-booking-pro' ) ) );
		}

		$clean = array();
		foreach ( $levels as $key => $level ) {
			$key = (string) $key;
			if ( '*' !== $key && ! AccessRegistry::has( $key ) ) {
				continue; // A key from an add-on that is off: dropped.
			}
			if ( ! in_array( $level, Access::levels(), true ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
				throw DomainException::invalid( array( $field . '.' . $key => __( 'Choose Open, Passcode or Locked.', 'radius-hotel-booking-pro' ) ) );
			}
			$clean[ $key ] = $level;
		}
		return $clean;
	}

	/**
	 * Per-key before/after of two maps (`unset` = not set).
	 *
	 * @param array $before Key => level.
	 * @param array $after  Key => level.
	 * @return array{before: array, after: array}
	 */
	public static function diff( array $before, array $after ): array {
		$out = array(
			'before' => array(),
			'after'  => array(),
		);
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $key ) {
			$old = $before[ $key ] ?? 'unset';
			$new = $after[ $key ] ?? 'unset';
			if ( $old !== $new ) {
				$out['before'][ $key ] = $old;
				$out['after'][ $key ]  = $new;
			}
		}
		return $out;
	}

	/**
	 * A role's levels and profile fields as one audited map (profile fields
	 * prefixed `profile.`).
	 *
	 * @param AccessRole $role Role.
	 * @return array
	 */
	private static function audited( AccessRole $role ): array {
		$map = (array) $role->levels;
		foreach ( (array) $role->profile_fields as $field => $value ) {
			$map[ 'profile.' . $field ] = $value;
		}
		return $map;
	}

	/**
	 * The API shape of a role.
	 *
	 * @param AccessRole $role  Role.
	 * @param int        $users Users holding it.
	 * @return array
	 */
	public static function shape( AccessRole $role, int $users ): array {
		return array(
			'id'             => (int) $role->id,
			'slug'           => (string) $role->slug,
			'name'           => (string) $role->name,
			'description'    => (string) $role->description,
			'levels'         => (object) ( (array) $role->levels ),
			'profile_fields' => (object) ( (array) $role->profile_fields ),
			'is_template'    => (bool) $role->is_template,
			'users'          => $users,
		);
	}

	/**
	 * A role, or 404.
	 *
	 * @param int $id Role id.
	 * @return AccessRole
	 * @throws DomainException When missing.
	 */
	private function find( int $id ): AccessRole {
		$role = $this->roles->find( $id );
		if ( ! $role instanceof AccessRole ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::notFound( __( 'This access role does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $role;
	}

	/**
	 * Validate name, description and levels.
	 *
	 * @param array $input  Input.
	 * @param bool  $create Whether the name is required.
	 * @return array Clean columns.
	 * @throws DomainException On invalid input.
	 */
	private function validate( array $input, bool $create ): array {
		$data   = array();
		$errors = array();

		if ( $create || array_key_exists( 'name', $input ) ) {
			$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
			if ( '' === $name || mb_strlen( $name ) > 100 ) {
				$errors['name'] = __( 'Give the role a name of up to 100 characters.', 'radius-hotel-booking-pro' );
			}
			$data['name'] = $name;
		}
		if ( array_key_exists( 'description', $input ) ) {
			$data['description'] = mb_substr( sanitize_textarea_field( (string) $input['description'] ), 0, 500 );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it (phpcs.xml).
			throw DomainException::invalid( $errors );
		}
		if ( $create || array_key_exists( 'levels', $input ) ) {
			$data['levels'] = self::sanitizeLevels( $input['levels'] ?? array() );
		}
		if ( $create || array_key_exists( 'profile_fields', $input ) ) {
			$data['profile_fields'] = ProfileFields::validate( $input['profile_fields'] ?? array(), 'profile_fields' );
		}

		return $data;
	}

	/**
	 * A slug from the name, unique in the table.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private function uniqueSlug( string $name ): string {
		$base = substr( sanitize_key( str_replace( array( ' ', '-' ), '_', remove_accents( $name ) ) ), 0, 50 );
		$base = '' !== $base ? $base : 'role';
		$slug = $base;
		for ( $i = 2; $this->roles->findBySlug( $slug ); $i++ ) {
			$slug = $base . '_' . $i;
		}
		return $slug;
	}

	/**
	 * Fire `rtbp_access_changed` and the activity event.
	 *
	 * @param string     $action      Activity action.
	 * @param AccessRole $role        Role.
	 * @param array      $before      Before values.
	 * @param array      $after       After values.
	 * @param string     $description Description.
	 * @param array      $extra       Extra context.
	 * @return void
	 */
	private static function emit( string $action, AccessRole $role, array $before, array $after, string $description, array $extra = array() ): void {
		/** This action is documented in the free plugin's includes/Access/AccessAudit.php */
		do_action(
			'rtbp_access_changed',
			'role',
			array(
				'role'   => (int) $role->id,
				'before' => $before,
				'after'  => $after,
			),
			get_current_user_id()
		);

		if ( function_exists( 'rtbp_activity' ) ) {
			rtbp_activity(
				$action,
				null,
				$extra + array(
					'role_id'     => (int) $role->id,
					'before'      => $before,
					'after'       => $after,
					'description' => $description,
				)
			);
		}
	}
}
