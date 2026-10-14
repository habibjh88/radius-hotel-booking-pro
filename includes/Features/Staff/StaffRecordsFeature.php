<?php
/**
 * Staff records (M12).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Features\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Features\Staff;

use RadiusTheme\RadiusHotelBookingPro\Features\AbstractFeature;
use RadiusTheme\RadiusHotelBookingPro\Staff\ContractController;
use RadiusTheme\RadiusHotelBookingPro\Staff\DocumentController;
use RadiusTheme\RadiusHotelBookingPro\Staff\DocumentService;
use RadiusTheme\RadiusHotelBookingPro\Staff\Employee;
use RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeController;
use RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeRepository;
use RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeService;

defined( 'ABSPATH' ) || exit;

/**
 * Feature `staff_records`: an employee file per staff member (12.1–12.11,
 * 12.16, 12.17; the pay fields 12.12–12.15 wait for D1). Switched off, the
 * screens and routes are gone **and so is the sign-in block**: deactivated
 * employees could sign in again, so switching it off is a manager's choice.
 * The files are kept.
 */
final class StaffRecordsFeature extends AbstractFeature {

	/**
	 * Free symbols this feature needs.
	 *
	 * @return string[]
	 */
	public function requires_free(): array {
		return array(
			'rtbp_activity',
			'rtbp_table',
			'rtbp_table_prefix',
			'RadiusTheme\\RadiusHotelBooking\\Abstracts\\BaseRepository',
			'RadiusTheme\\RadiusHotelBooking\\Core\\Database\\Transaction',
			'RadiusTheme\\RadiusHotelBooking\\Exceptions\\DomainException',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Dates',
			'RadiusTheme\\RadiusHotelBooking\\Support\\Sequence',
			'RadiusTheme\\RadiusHotelBooking\\Storage\\ProtectedFiles',
		);
	}

	/**
	 * Hook in (only while switched on).
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'rtbp_register_addon_routes', array( EmployeeController::class, 'routes' ) );
		add_action( 'rtbp_register_addon_routes', array( ContractController::class, 'routes' ) );
		add_action( 'rtbp_register_addon_routes', array( DocumentController::class, 'routes' ) );
		// Documents are protected files (ADR-009): `staff.edit` to download, and each download is logged.
		add_filter( 'rtbp_file_kinds', array( DocumentService::class, 'fileKinds' ) );
		add_filter( 'rtbp_file_access', array( DocumentService::class, 'fileAccess' ), 10, 3 );
		add_action( 'rtbp_file_download', array( DocumentService::class, 'downloaded' ) );
		// Notes on an employee (12.17) through the free notes (M09): general / caution / warning.
		add_filter( 'rtbp_note_types', array( $this, 'noteTypes' ) );
		add_filter( 'rtbp_access_keys', array( $this, 'keys' ) );
		add_filter( 'rtbp_access_groups', array( $this, 'groups' ) );
		add_filter( 'rtbp_activity_actions', array( $this, 'actions' ) );
		// DoB, address, social security / tax numbers and the emergency contact: logged as "changed" only.
		add_filter( 'rtbp_activity_secret_pattern', array( EmployeeService::class, 'secretPattern' ) );
		// After WordPress checked the password (priority 20), before the result is used.
		add_filter( 'authenticate', array( EmployeeService::class, 'signInBlock' ), 99 );
		// REST checks application passwords without `authenticate`: none for a deactivated employee.
		add_filter( 'wp_is_application_passwords_available_for_user', array( EmployeeService::class, 'appPasswordsAvailable' ), 10, 2 );
	}

	/**
	 * `rtbp_access_keys`: the staff keys. Locked for the staff role, open
	 * for managers (the manager defaults open every key).
	 *
	 * @param array $keys Key => definition.
	 * @return array
	 */
	public function keys( $keys ): array {
		$keys  = (array) $keys;
		$defs  = array(
			'page.staff'       => array( 'pages', __( 'Staff', 'radius-hotel-booking-pro' ), 'employees' ),
			'staff.create'     => array( 'staff', __( 'Add an employee', 'radius-hotel-booking-pro' ), 'add-new-staff' ),
			'staff.edit'       => array( 'staff', __( 'Edit an employee file', 'radius-hotel-booking-pro' ), 'edit-staff' ),
			'staff.deactivate' => array( 'staff', __( 'Deactivate or reactivate an employee', 'radius-hotel-booking-pro' ), '' ),
			'staff.view_ids'   => array( 'staff', __( 'See social security and tax numbers', 'radius-hotel-booking-pro' ), '' ),
			'staff.contracts'  => array( 'staff', __( 'Manage employment contracts', 'radius-hotel-booking-pro' ), '' ),
			'staff.note_add'   => array( 'staff', __( 'Add an employee note', 'radius-hotel-booking-pro' ), 'add-staff-note' ),
			'staff.note_edit'  => array( 'staff', __( 'Edit an employee note', 'radius-hotel-booking-pro' ), 'edit-staff-note' ),
			'staff.note_remove' => array( 'staff', __( 'Remove an employee note', 'radius-hotel-booking-pro' ), 'remove-staff-note' ),
		);
		foreach ( $defs as $key => list( $group, $label, $legacy ) ) {
			$keys[ $key ] = array_filter(
				array(
					'default' => 'locked',
					'group'   => $group,
					'label'   => $label,
					'legacy'  => $legacy,
				)
			);
		}
		return $keys;
	}

	/**
	 * `rtbp_note_types`: notes on an employee file, read with `page.staff`
	 * and written with the `staff.note_*` keys; logged as `staff.note_*`
	 * against the employee.
	 *
	 * @param array $types Type => definition.
	 * @return array
	 */
	public function noteTypes( $types ): array {
		$types             = (array) $types;
		$types['employee'] = array(
			'read'     => 'page.staff',
			'add'      => 'staff.note_add',
			'edit'     => 'staff.note_edit',
			'remove'   => 'staff.note_remove',
			'activity' => 'staff.note',
			'find'     => static function ( int $id ): ?array {
				$employee = ( new EmployeeRepository() )->find( $id );
				return $employee instanceof Employee ? array(
					'type'  => 'employee',
					'id'    => (int) $employee->id,
					'label' => $employee->fullName(),
				) : null;
			},
		);
		return $types;
	}

	/**
	 * `rtbp_access_groups`: the Staff group of the permission matrix.
	 *
	 * @param array $groups Group => label.
	 * @return array
	 */
	public function groups( $groups ): array {
		$groups          = (array) $groups;
		$groups['staff'] = __( 'Staff records', 'radius-hotel-booking-pro' );
		return $groups;
	}

	/**
	 * `rtbp_activity_actions`: the staff actions.
	 *
	 * @param array $actions Key => definition.
	 * @return array
	 */
	public function actions( $actions ): array {
		$actions = (array) $actions;
		$labels  = array(
			'staff.create'     => __( 'Added an employee', 'radius-hotel-booking-pro' ),
			'staff.edit'       => __( 'Changed an employee file', 'radius-hotel-booking-pro' ),
			'staff.deactivate' => __( 'Deactivated an employee', 'radius-hotel-booking-pro' ),
			'staff.activate'   => __( 'Reactivated an employee', 'radius-hotel-booking-pro' ),
			'staff.account'    => __( 'Changed an employee account', 'radius-hotel-booking-pro' ),
			'staff.view_ids'   => __( 'Viewed the social security and tax numbers of an employee', 'radius-hotel-booking-pro' ),
			'staff.contract_create' => __( 'Added an employment contract', 'radius-hotel-booking-pro' ),
			'staff.contract_edit'   => __( 'Changed an employment contract', 'radius-hotel-booking-pro' ),
			'staff.contract_end'    => __( 'Ended an employment contract', 'radius-hotel-booking-pro' ),
			'staff.contract_delete' => __( 'Deleted an employment contract', 'radius-hotel-booking-pro' ),
			'staff.document_upload' => __( 'Added a document to an employee file', 'radius-hotel-booking-pro' ),
			'staff.document_delete' => __( 'Deleted a document from an employee file', 'radius-hotel-booking-pro' ),
			'staff.document_download' => __( 'Opened a document in an employee file', 'radius-hotel-booking-pro' ),
			'staff.note_add'        => __( 'Added an employee note', 'radius-hotel-booking-pro' ),
			'staff.note_edit'       => __( 'Edited an employee note', 'radius-hotel-booking-pro' ),
			'staff.note_remove'     => __( 'Removed an employee note', 'radius-hotel-booking-pro' ),
		);
		foreach ( $labels as $key => $label ) {
			$actions[ $key ] = array(
				'kind'  => in_array( $key, array( 'staff.view_ids', 'staff.document_download' ), true ) ? 'view' : 'data',
				'label' => $label,
			);
		}
		return $actions;
	}
}
