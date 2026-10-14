<?php
/**
 * Payroll access keys and activity actions (M15).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Payroll
 */

namespace RadiusTheme\RadiusHotelBookingPro\Payroll;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the payroll keys on the free access registry (group *Payroll* in
 * the permission matrix) and the payroll actions on the activity catalogue.
 * Every key is locked by default (the legacy *Payroll* section and its 13
 * actions were); the manager defaults open them, administrators always hold
 * them. *My payroll* (a staff member's own payslips and time off, M15 T9) is
 * open, as in the legacy system. The keys match M18's legacy access map, so
 * the import carries each person's payroll permissions over.
 */
final class PayrollAccess {

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rtbp_access_keys', array( self::class, 'keys' ) );
		add_filter( 'rtbp_access_groups', array( self::class, 'groups' ) );
		add_filter( 'rtbp_activity_actions', array( self::class, 'actions' ) );
	}

	/**
	 * Key => [ group, label, legacy key, default ].
	 *
	 * @return array<string, array{0:string, 1:string, 2:string, 3:string}>
	 */
	private static function definitions(): array {
		return array(
			'page.payroll'          => array( 'pages', __( 'Payroll', 'radius-hotel-booking-pro' ), 'section/payroll', 'locked' ),
			'page.my_payroll'       => array( 'pages', __( 'My payslips and time off', 'radius-hotel-booking-pro' ), 'section/my-payroll', 'open' ),
			'payroll.run'           => array( 'payroll', __( 'Run payroll', 'radius-hotel-booking-pro' ), 'run-payroll', 'locked' ),
			'payroll.edit_line'     => array( 'payroll', __( "Edit an employee's payroll line", 'radius-hotel-booking-pro' ), 'edit-payroll-item', 'locked' ),
			'payroll.finalize'      => array( 'payroll', __( 'Finalise a payroll run', 'radius-hotel-booking-pro' ), 'finalize-payroll', 'locked' ),
			'payroll.settle'        => array( 'payroll', __( 'Settle a payroll run', 'radius-hotel-booking-pro' ), 'settle-payroll', 'locked' ),
			'payroll.void'          => array( 'payroll', __( 'Void a payroll run', 'radius-hotel-booking-pro' ), 'void-payroll', 'locked' ),
			'payroll.revert'        => array( 'payroll', __( 'Revert a payroll run to draft', 'radius-hotel-booking-pro' ), 'revert-payroll', 'locked' ),
			'payroll.remove'        => array( 'payroll', __( 'Remove a draft payroll run', 'radius-hotel-booking-pro' ), 'remove-payroll-run', 'locked' ),
			'payroll.schedules'     => array( 'payroll', __( 'Manage pay schedules', 'radius-hotel-booking-pro' ), 'manage-pay-schedules', 'locked' ),
			'payroll.pay_profile'   => array( 'payroll', __( "Change an employee's pay", 'radius-hotel-booking-pro' ), '', 'locked' ),
			'payroll.shifts'        => array( 'payroll', __( 'Manage work shifts', 'radius-hotel-booking-pro' ), 'manage-work-shifts', 'locked' ),
			'payroll.statutory_pay' => array( 'payroll', __( 'Record a statutory payment', 'radius-hotel-booking-pro' ), 'record-statutory-payment', 'locked' ),
			'payroll.tax_settings'  => array( 'payroll', __( 'Change payroll settings', 'radius-hotel-booking-pro' ), 'edit-tax-info', 'locked' ),
			'timeoff.approve'       => array( 'payroll', __( 'Approve or decline time off', 'radius-hotel-booking-pro' ), 'approve-time-off', 'locked' ),
		);
	}

	/**
	 * `rtbp_access_keys`.
	 *
	 * @param array $keys Key => definition.
	 * @return array
	 */
	public static function keys( $keys ): array {
		$keys = (array) $keys;
		foreach ( self::definitions() as $key => list( $group, $label, $legacy, $default ) ) {
			$keys[ $key ] = array(
				'default' => $default,
				'group'   => $group,
				'label'   => $label,
				'legacy'  => $legacy,
			);
		}
		return $keys;
	}

	/**
	 * `rtbp_access_groups`: the Payroll group of the permission matrix.
	 *
	 * @param array $groups Group => label.
	 * @return array
	 */
	public static function groups( $groups ): array {
		$groups            = (array) $groups;
		$groups['payroll'] = __( 'Payroll and time off', 'radius-hotel-booking-pro' );
		return $groups;
	}

	/**
	 * `rtbp_activity_actions`: what payroll writes to the activity log.
	 *
	 * @param array $actions Key => definition.
	 * @return array
	 */
	public static function actions( $actions ): array {
		$actions = (array) $actions;
		$labels  = array(
			'payroll.run'              => __( 'Ran payroll', 'radius-hotel-booking-pro' ),
			'payroll.edit_line'        => __( "Changed an employee's payroll line", 'radius-hotel-booking-pro' ),
			'payroll.finalize'         => __( 'Finalised a payroll run', 'radius-hotel-booking-pro' ),
			'payroll.settle'           => __( 'Settled a payroll run', 'radius-hotel-booking-pro' ),
			'payroll.void'             => __( 'Voided a payroll run', 'radius-hotel-booking-pro' ),
			'payroll.revert'           => __( 'Reverted a payroll run to draft', 'radius-hotel-booking-pro' ),
			'payroll.remove'           => __( 'Removed a draft payroll run', 'radius-hotel-booking-pro' ),
			'payroll.schedules'        => __( 'Changed a pay schedule', 'radius-hotel-booking-pro' ),
			'payroll.pay_profile'      => __( "Changed an employee's pay", 'radius-hotel-booking-pro' ),
			'payroll.shifts'           => __( 'Changed a work shift', 'radius-hotel-booking-pro' ),
			'payroll.statutory_pay'    => __( 'Recorded a statutory payment', 'radius-hotel-booking-pro' ),
			'payroll.tax_settings'     => __( 'Changed payroll settings', 'radius-hotel-booking-pro' ),
			'payroll.payslip_download' => __( 'Downloaded a payslip', 'radius-hotel-booking-pro' ),
			'timeoff.request'          => __( 'Requested time off', 'radius-hotel-booking-pro' ),
			'timeoff.approve'          => __( 'Approved time off', 'radius-hotel-booking-pro' ),
			'timeoff.decline'          => __( 'Declined time off', 'radius-hotel-booking-pro' ),
			'self.payslip_download'    => __( 'Downloaded own payslip', 'radius-hotel-booking-pro' ),
			'self.timeoff_request'     => __( 'Requested own time off', 'radius-hotel-booking-pro' ),
			'self.timeoff_cancel'      => __( 'Cancelled own time off request', 'radius-hotel-booking-pro' ),
		);
		foreach ( $labels as $key => $label ) {
			$actions[ $key ] = array(
				'kind'  => in_array( $key, array( 'payroll.payslip_download', 'self.payslip_download' ), true ) ? 'view' : 'data',
				'label' => $label,
			);
		}
		return $actions;
	}
}
