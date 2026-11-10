<?php
/**
 * Cash register (R3-T5 – R3-T8).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\CashRegister
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\CashRegister;

use RadiusTheme\RadiusHotelBookingPro\CashRegister\CashRegisterController;
use RadiusTheme\RadiusHotelBookingPro\CashRegister\CashRegisterService;
use RadiusTheme\RadiusHotelBookingPro\CashRegister\CashRegisterSettings;
use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `cash_register`: each staff member opens their register at the
 * start of a shift, sees what they took in per payment method, and closes it
 * with a cash count (Front desk → Cash register). Managers see every
 * register. Optionally no payment may be taken with the register closed.
 */
final class CashRegisterFeature extends AbstractFeature {

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_table',
			'rtbp_table_prefix',
			'RadiusTheme\\RadiusHotelBooking\\Core\\Database\\Transaction',
			'RadiusTheme\\RadiusHotelBooking\\Exceptions\\DomainException',
			'RadiusTheme\\RadiusHotelBooking\\Settings\\PaymentSettings',
			'RadiusTheme\\RadiusHotelBooking\\Settings\\SettingsSchema',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Money',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		CashRegisterSettings::register();
		add_action( 'rtbp_register_addon_routes', array( CashRegisterController::class, 'routes' ) );
		add_filter( 'rtbp_access_keys', array( $this, 'keys' ) );
		add_filter( 'rtbp_access_groups', array( $this, 'groups' ) );
		add_filter( 'rtbp_activity_actions', array( $this, 'actions' ) );
		// Free fires it for payments staff take now (an older free never does: nothing is refused).
		add_action( 'rtbp_payment_validated', array( CashRegisterService::class, 'requireOpen' ) );
		// Saved with the payment settings' key.
		add_filter( 'rtbp_settings_access_key', array( $this, 'settings_key' ), 10, 2 );
	}

	/**
	 * `rtbp_access_keys`: both locked unless granted. A role saved before this
	 * feature never names these keys, so an open default would hand the
	 * register to every staff member, maintenance included. The Receptionist
	 * templates open `register.use` (RoleTemplates).
	 *
	 * @param array $keys Keys.
	 * @return array
	 */
	public function keys( $keys ): array {
		$keys                    = (array) $keys;
		$keys['register.use']    = array(
			'default' => 'locked',
			'group'   => 'register',
			'label'   => __( 'Use their own cash register', 'radius-hotel-booking-pro' ),
		);
		$keys['register.manage'] = array(
			'default' => 'locked',
			'group'   => 'register',
			'label'   => __( 'See every cash register and its difference', 'radius-hotel-booking-pro' ),
		);
		return $keys;
	}

	/**
	 * `rtbp_access_groups`.
	 *
	 * @param array $groups Group => label.
	 * @return array
	 */
	public function groups( $groups ): array {
		$groups             = (array) $groups;
		$groups['register'] = __( 'Cash register', 'radius-hotel-booking-pro' );
		return $groups;
	}

	/**
	 * `rtbp_activity_actions`.
	 *
	 * @param array $actions Key => definition.
	 * @return array
	 */
	public function actions( $actions ): array {
		$actions                   = (array) $actions;
		$actions['register.open']  = array(
			'kind'  => 'data',
			'label' => __( 'Opened the cash register', 'radius-hotel-booking-pro' ),
		);
		$actions['register.close'] = array(
			'kind'  => 'data',
			'label' => __( 'Closed the cash register', 'radius-hotel-booking-pro' ),
		);
		return $actions;
	}

	/**
	 * `rtbp_settings_access_key`: the section is saved with `settings.payments`.
	 *
	 * @param string $key     Access key.
	 * @param string $section Section.
	 * @return string
	 */
	public function settings_key( $key, $section ): string {
		return CashRegisterSettings::SECTION === $section ? 'settings.payments' : (string) $key;
	}
}
