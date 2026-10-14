<?php
/**
 * Payroll and HR (M15).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Payroll;

use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;
use RadiusTheme\RadiusHotelBookingPro\Payroll\Decimal;
use RadiusTheme\RadiusHotelBookingPro\Payroll\DeductionController;
use RadiusTheme\RadiusHotelBookingPro\Payroll\EmployeePayController;
use RadiusTheme\RadiusHotelBookingPro\Payroll\EmployeePayService;
use RadiusTheme\RadiusHotelBookingPro\Payroll\MyPayrollController;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayrollAccess;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayrollMigration;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayrollReports;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayrollRunController;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayrollRunService;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayrollSettings;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayScheduleController;
use RadiusTheme\RadiusHotelBookingPro\Payroll\PayslipService;
use RadiusTheme\RadiusHotelBookingPro\Payroll\Statutory;
use RadiusTheme\RadiusHotelBookingPro\Payroll\StatutoryController;
use RadiusTheme\RadiusHotelBookingPro\Payroll\TimeOffController;
use RadiusTheme\RadiusHotelBookingPro\Payroll\WorkShiftController;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `payroll`: pay schedules and shifts, each employee's pay,
 * deductions and garnishments, pay runs and payslips, taxes and social
 * contributions to remit, time off, payroll reports and the staff's own
 * *My payroll* page. Taxes and contributions come from the statutory
 * calculator (Payroll\Statutory): the hotel's rates by default, or a
 * country add-on's. Switched off, the screens and routes are gone; the data
 * is kept.
 */
final class PayrollFeature extends AbstractFeature {

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_activity',
			'rtbp_setting',
			'rtbp_table_prefix',
			'RadiusTheme\\RadiusHotelBooking\\Abstracts\\BaseRepository',
			'RadiusTheme\\RadiusHotelBooking\\Core\\Database\\Transaction',
			'RadiusTheme\\RadiusHotelBooking\\Exceptions\\DomainException',
			'RadiusTheme\\RadiusHotelBooking\\Settings\\SettingsSchema',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Dates',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Money',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		// Data from the Residence TATA add-on, where payroll began (once).
		PayrollMigration::run();

		PayrollSettings::register();
		PayrollAccess::register();
		EmployeePayService::register();
		PayrollRunService::register();
		PayslipService::register();
		PayrollReports::register();
		foreach ( array( PayScheduleController::class, WorkShiftController::class, EmployeePayController::class, DeductionController::class, PayrollRunController::class, StatutoryController::class, TimeOffController::class, MyPayrollController::class ) as $controller ) {
			add_action( 'rtbp_register_addon_routes', array( $controller, 'routes' ) );
		}
		add_filter( 'rtbp_site_checks', array( $this, 'checks' ) );
		add_filter( 'rtbp_site_checks', array( PayrollMigration::class, 'checks' ) );
		add_filter( 'rtbp_admin_localize_params', array( $this, 'params' ) );

		/**
		 * Pro's payroll is on: country add-ons plug in here (their calculator,
		 * their settings, imports into payroll).
		 */
		do_action( 'rtbp_pro_payroll_booted' );
	}

	/**
	 * `rtbp_admin_localize_params`: which statutory calculator is in use
	 * (Settings → Payroll shows the built-in rates only for the built-in one).
	 *
	 * @param array $params Params.
	 * @return array
	 */
	public function params( $params ): array {
		$params     = (array) $params;
		$calculator = Statutory::calculator();
		$params['payroll_calculator'] = array(
			'key'      => $calculator->key(),
			'label'    => $calculator->label(),
			'built_in' => Statutory::builtIn(),
		);
		return $params;
	}

	/**
	 * `rtbp_site_checks`: payroll needs bcmath for exact amounts.
	 *
	 * @param array $checks Failing checks so far.
	 * @return array
	 */
	public function checks( $checks ): array {
		$checks = (array) $checks;
		if ( ! Decimal::available() ) {
			$checks[] = array(
				'id'           => 'pro_bcmath',
				'message'      => __( 'Payroll needs the PHP bcmath extension, which this server does not have. Ask your host to enable it.', 'radius-hotel-booking-pro' ),
				'action_label' => '',
				'action_url'   => '',
			);
		}
		return $checks;
	}
}
