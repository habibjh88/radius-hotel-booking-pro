<?php
/**
 * The six access role templates.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

defined( 'ABSPATH' ) || exit;

/**
 * Pre-filled roles the hotel edits to suit itself (feature 13.14). Levels are
 * partial: a key a role does not set follows the global defaults. `*` sets
 * every key the role does not name.
 */
final class RoleTemplates {

	/**
	 * Hook the seeder onto Pro's installer.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rtbp_pro_installed', array( self::class, 'schedule_seed' ) );
	}

	/**
	 * Seed once translations can load (the names are translated).
	 *
	 * @return void
	 */
	public static function schedule_seed(): void {
		if ( did_action( 'init' ) ) {
			self::seed();
		} else {
			add_action( 'init', array( self::class, 'seed' ), 20 );
		}
	}

	/**
	 * Insert every template whose slug is missing. Never touches a role that
	 * exists (the hotel may have edited it).
	 *
	 * @return int Roles inserted.
	 */
	public static function seed(): int {
		$repository = new AccessRoleRepository();
		$inserted   = 0;
		foreach ( self::all() as $slug => $template ) {
			if ( $repository->findBySlug( $slug ) ) {
				continue;
			}
			$repository->create(
				$template + array(
					'slug'           => $slug,
					'is_template'    => 1,
					'profile_fields' => array(),
				)
			);
			++$inserted;
		}
		return $inserted;
	}

	/**
	 * The templates.
	 *
	 * @return array<string, array>
	 */
	public static function all(): array {
		$front_desk = array(
			'page.reports_sales' => 'locked',
			'page.exports'       => 'locked',
			'page.settings'      => 'locked',
		);

		return array(
			'receptionist'       => array(
				'name'        => __( 'Receptionist', 'radius-hotel-booking-pro' ),
				'description' => __( 'Takes bookings, checks guests in and out, records payments.', 'radius-hotel-booking-pro' ),
				'levels'      => $front_desk,
			),
			'night_receptionist' => array(
				'name'        => __( 'Night receptionist', 'radius-hotel-booking-pro' ),
				'description' => __( 'The front desk at night: no cancellations, refunds or bans.', 'radius-hotel-booking-pro' ),
				'levels'      => $front_desk + array(
					'bookings.cancel'        => 'locked',
					'bookings.line_remove'   => 'locked',
					'payments.change_status' => 'locked',
					'guests.ban'             => 'locked',
					'page.reports_rooms'     => 'locked',
				),
			),
			'supervisor'         => array(
				'name'        => __( 'Supervisor', 'radius-hotel-booking-pro' ),
				'description' => __( 'Runs the shift: may cancel and change bookings, sees the sales report.', 'radius-hotel-booking-pro' ),
				'levels'      => array(
					'page.reports_sales'   => 'open',
					'bookings.cancel'      => 'open',
					'bookings.line_edit'   => 'open',
					'bookings.line_remove' => 'open',
					'bookings.note_remove' => 'open',
					'guests.ban'           => 'open',
					'reports.export'       => 'open',
					'page.rates'           => 'open',
					'availability.manage'  => 'passcode',
					'rooms.manage'         => 'passcode',
				),
			),
			'housekeeping'       => array(
				'name'        => __( 'Housekeeping', 'radius-hotel-booking-pro' ),
				'description' => __( 'Sees rooms and availability; no bookings, payments or guests.', 'radius-hotel-booking-pro' ),
				'levels'      => array(
					'*'                 => 'locked',
					'page.dashboard'    => 'open',
					'page.rooms'        => 'open',
					'page.availability' => 'open',
				),
			),
			'accountant'         => array(
				'name'        => __( 'Accountant', 'radius-hotel-booking-pro' ),
				'description' => __( 'Payments, invoices, reports and exports; does not take bookings.', 'radius-hotel-booking-pro' ),
				'levels'      => array(
					'page.reports_sales'     => 'open',
					'reports.export'         => 'open',
					'page.exports'           => 'open',
					'exports.generate'       => 'open',
					'exports.download'       => 'open',
					'payments.change_status' => 'passcode',
					'payments.void'          => 'passcode',
					'bookings.create'        => 'locked',
					'bookings.check_in'      => 'locked',
					'bookings.check_out'     => 'locked',
				),
			),
			'manager'            => array(
				'name'        => __( 'Manager', 'radius-hotel-booking-pro' ),
				'description' => __( 'Everything, with a PIN for permissions and voiding payments.', 'radius-hotel-booking-pro' ),
				'levels'      => array(
					'*'                      => 'open',
					'payments.void'          => 'passcode',
					'access.manage'          => 'passcode',
					'access.reset_overrides' => 'passcode',
					'activity.archive'       => 'passcode',
				),
			),
		);
	}
}
