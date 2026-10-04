<?php
/**
 * Staff self-service (M16).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\SelfService
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\SelfService;

use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;
use RadiusTheme\RadiusHotelBookingPro\SelfService\CredentialsController;
use RadiusTheme\RadiusHotelBookingPro\SelfService\ProfileController;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `staff_self_service` (needs `staff_records`): each member of staff
 * manages their own profile within the fields the hotel allows (16.1–16.6),
 * their password and their PIN (16.3, 16.4). Switched off, the *My profile*
 * routes and screen are gone; the employee
 * files are unchanged.
 */
final class SelfServiceFeature extends AbstractFeature {

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_activity',
			'RadiusTheme\\RadiusHotelBooking\\Core\\Api\\ApiResponse',
			'RadiusTheme\\RadiusHotelBooking\\Exceptions\\DomainException',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'rtbp_register_addon_routes', array( ProfileController::class, 'routes' ) );
		add_action( 'rtbp_register_addon_routes', array( CredentialsController::class, 'routes' ) );
		add_filter( 'rtbp_activity_actions', array( $this, 'actions' ) );
	}

	/**
	 * `rtbp_activity_actions`: the self-service actions.
	 *
	 * @param array $actions Key => definition.
	 * @return array
	 */
	public function actions( $actions ): array {
		$actions                        = (array) $actions;
		$actions['self.profile_update'] = array(
			'kind'  => 'data',
			'label' => __( 'Updated their own profile', 'radius-hotel-booking-pro' ),
		);
		$actions['self.password_change'] = array(
			'kind'  => 'security',
			'label' => __( 'Changed their own password', 'radius-hotel-booking-pro' ),
		);
		$actions['self.password_failed'] = array(
			'kind'  => 'security',
			'label' => __( 'Entered a wrong current password', 'radius-hotel-booking-pro' ),
		);
		$actions['self.pin_change']      = array(
			'kind'  => 'security',
			'label' => __( 'Changed their own PIN', 'radius-hotel-booking-pro' ),
		);
		return $actions;
	}
}
