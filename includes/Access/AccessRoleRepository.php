<?php
/**
 * Access role data access.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Abstracts\BaseRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Queries on `pro_access_roles`, plus the user meta that assigns a role.
 */
class AccessRoleRepository extends BaseRepository {

	const META_ROLE = 'rtbp_pro_access_role';

	/**
	 * Model class.
	 *
	 * @var string
	 */
	protected string $model = AccessRole::class;

	/**
	 * Every role, templates first, then by name.
	 *
	 * @return AccessRole[]
	 */
	public function ordered(): array {
		$rows = AccessRole::query()->orderBy( 'is_template', 'DESC' )->orderBy( 'name' )->get();
		return array_map( static fn( $row ) => AccessRole::hydrate( $row ), $rows );
	}

	/**
	 * A role by slug.
	 *
	 * @param string $slug Slug.
	 * @return AccessRole|null
	 */
	public function findBySlug( string $slug ): ?AccessRole {
		$row = AccessRole::query()->where( 'slug', $slug )->first();
		return $row ? AccessRole::hydrate( $row ) : null;
	}

	/**
	 * Number of users per role id.
	 *
	 * @return array<int, int>
	 */
	public function userCounts(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- small aggregate over user meta.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS role_id, COUNT(*) AS users FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value",
				self::META_ROLE
			)
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->role_id ] = (int) $row->users;
		}
		return $counts;
	}

	/**
	 * Unassign a role from every user holding it.
	 *
	 * @param int $role_id Role id.
	 * @return int[] The affected user ids.
	 */
	public function unassignAll( int $role_id ): array {
		$users = get_users(
			array(
				'meta_key'   => self::META_ROLE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) $role_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
			)
		);
		foreach ( $users as $user_id ) {
			delete_user_meta( (int) $user_id, self::META_ROLE );
		}
		return array_map( 'intval', $users );
	}
}
