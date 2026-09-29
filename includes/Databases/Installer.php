<?php
/**
 * Pro schema installer.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Databases
 */

namespace RadiusTheme\RadiusHotelBookingPro\Databases;

defined( 'ABSPATH' ) || exit;

/**
 * Pro's tables, whatever features are switched on (ADR-017).
 *
 * They are listed on the free `rtbp_migration_classes` filter, so the free
 * installer and `wp radius-hotel-booking artisan migrate` create them too. The
 * free installer only re-runs when the **free** DB version changes, so Pro
 * keeps its own gate: when `RADIUS_HOTEL_BOOKING_PRO_DB_VERSION` differs from
 * the stored one, every Pro table's `up()` runs again (dbDelta, idempotent),
 * then `rtbp_pro_installed` fires (seeding hooks in there).
 */
final class Installer {

	const OPTION = 'rtbp_pro_db_version';

	const LOCK = 'rtbp_pro_upgrading';

	/**
	 * Pro table classes, in dependency order.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return array(
			AccessRolesTable::class,
		);
	}

	/**
	 * Hook in. Runs from Plugin::boot(), after the free plugin loaded.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'rtbp_migration_classes', array( self::class, 'register' ) );
		\RadiusTheme\RadiusHotelBookingPro\Access\RoleTemplates::init();
		self::maybe_upgrade();
	}

	/**
	 * `rtbp_migration_classes`: append Pro's tables.
	 *
	 * @param array $classes Migration classes.
	 * @return array
	 */
	public static function register( $classes ): array {
		return array_merge( (array) $classes, self::tables() );
	}

	/**
	 * Create or upgrade Pro's tables when the Pro DB version changed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) === RADIUS_HOTEL_BOOKING_PRO_DB_VERSION || get_transient( self::LOCK ) ) {
			return;
		}
		if ( ! class_exists( 'RadiusTheme\\RadiusHotelBooking\\Core\\Database\\Schema\\Schema' ) ) {
			return;
		}

		set_transient( self::LOCK, 1, MINUTE_IN_SECONDS );
		try {
			if ( ! function_exists( 'dbDelta' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			foreach ( self::tables() as $class ) {
				( new $class() )->up();
			}
			update_option( self::OPTION, RADIUS_HOTEL_BOOKING_PRO_DB_VERSION );

			/**
			 * Fires after Pro's tables were created or upgraded.
			 */
			do_action( 'rtbp_pro_installed' );
		} finally {
			delete_transient( self::LOCK );
		}
	}
}
