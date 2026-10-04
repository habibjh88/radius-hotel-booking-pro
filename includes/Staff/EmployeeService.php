<?php
/**
 * Employee files (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Core\Database\Transaction;
use RadiusTheme\RadiusHotelBooking\Core\Permissions\Capabilities;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Support\Dates;
use RadiusTheme\RadiusHotelBooking\Support\Sequence;
use RadiusTheme\RadiusHotelBookingPro\Access\PasscodeService;
use RadiusTheme\RadiusHotelBookingPro\Access\UserAccessService;
use WP_Session_Tokens;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * A staff member is a WordPress user (role `rtbp_staff`) with an employee
 * file. `create()` writes both in one transaction; `update()` changes the
 * file and keeps the user's name and e-mail in step, logging each change;
 * the personal values (date of birth, address, statutory numbers, emergency
 * contact) reach the log only as "changed" — the feature adds them to the
 * emitter's secret pattern; `deactivate()` blocks
 * sign-in (the `authenticate` filter, see signInBlock()) and ends every
 * session, keeping the history. Deactivating is not banning.
 */
class EmployeeService {

	/**
	 * Fields whose values the activity log never records (only "changed"):
	 * added to `rtbp_activity_secret_pattern` by StaffRecordsFeature.
	 */
	public const MASKED = array( 'dob', 'address', 'cnps_no', 'tax_no', 'emergency' );

	/**
	 * Employees.
	 *
	 * @var EmployeeRepository
	 */
	private EmployeeRepository $employees;

	/**
	 * Constructor.
	 *
	 * @param EmployeeRepository|null $employees Employees.
	 */
	public function __construct( ?EmployeeRepository $employees = null ) {
		$this->employees = $employees ?? new EmployeeRepository();
	}

	/**
	 * One employee, or 404.
	 *
	 * @param int $id Id.
	 * @return Employee
	 * @throws DomainException 404.
	 */
	public function get( int $id ): Employee {
		$employee = $id > 0 ? $this->employees->find( $id ) : null;
		if ( ! $employee instanceof Employee ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::notFound( __( 'This employee does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $employee;
	}

	/**
	 * A page of employees (12.1).
	 *
	 * @param string $q      Search.
	 * @param string $active active|inactive|''.
	 * @param int    $page   Page.
	 * @param int    $per    Per page (1–100).
	 * @return array{items: array[], total: int} Rows with `user_login`.
	 */
	public function page( string $q, string $active, int $page = 1, int $per = 20 ): array {
		return $this->employees->page( sanitize_text_field( $q ), $active, max( 1, $page ), min( 100, max( 1, $per ) ) );
	}

	/**
	 * Add a staff member (12.2): the user (role `rtbp_staff`) and the employee
	 * file, then, when given and Pro's advanced access is on, the access role
	 * and the PIN.
	 *
	 * @param array $input `username`, `password` (optional: a random one, to reset
	 *                     later), `email`, the file fields (see validate()),
	 *                     `access_role` (id), `pin`.
	 * @param array $options `phone_optional` (bool): an empty phone is accepted —
	 *                       the legacy import only (M18); the screens always ask for one.
	 * @return Employee
	 * @throws DomainException 422.
	 * @throws \Throwable Whatever the write threw, after the user cache was cleaned.
	 */
	public function create( array $input, array $options = array() ): Employee {
		$errors   = array();
		$username = sanitize_user( (string) ( $input['username'] ?? '' ), true );
		if ( '' === $username || mb_strlen( $username ) > 60 ) {
			$errors['username'] = __( 'Choose a user name (letters, numbers, . _ - @).', 'radius-hotel-booking-pro' );
		} elseif ( username_exists( $username ) ) {
			$errors['username'] = __( 'This user name is taken.', 'radius-hotel-booking-pro' );
		}
		$password = (string) ( $input['password'] ?? '' );
		if ( '' !== $password && strlen( $password ) < 8 ) {
			$errors['password'] = __( 'Use at least 8 characters.', 'radius-hotel-booking-pro' );
		}
		$pin = isset( $input['pin'] ) ? trim( (string) $input['pin'] ) : '';
		if ( '' !== $pin && class_exists( PasscodeService::class ) && ! PasscodeService::is_valid_pin( $pin ) ) {
			$errors['pin'] = __( 'Enter a PIN of 4 to 8 digits.', 'radius-hotel-booking-pro' );
		}
		try {
			$data = $this->validate( $input, null, ! empty( $options['phone_optional'] ) );
		} catch ( DomainException $e ) {
			$errors = array_merge( $errors, $e->getFieldErrors() );
			$data   = array();
		}
		if ( ( ! empty( $input['access_role'] ) || '' !== $pin ) && self::advancedAccess() ) {
			self::requireAccessManage();
		}
		if ( ! $errors && '' !== $data['email'] && email_exists( $data['email'] ) ) {
			$errors['email'] = __( 'Another account already uses this e-mail.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}

		$created = 0;
		try {
			$id = $this->insert( $username, $password, $data, $created, $input, $pin );
		} catch ( \Throwable $e ) {
			// The transaction undid the user; the object cache (possibly persistent) must forget it too.
			if ( $created ) {
				clean_user_cache( $created );
			}
			throw $e;
		}

		return $this->get( $id );
	}

	/**
	 * Give an **existing** WordPress user an employee file (M18: on
	 * production the legacy staff accounts are already in the site, with
	 * their passwords; the import must not create new ones). The user is
	 * not changed, except that it gains the `rtbp_staff` role when it has no
	 * dashboard access yet. No access role or PIN here.
	 *
	 * @param int   $user_id User id.
	 * @param array $input   The file fields (see validate()); `email` defaults to the user's.
	 * @param array $options As create().
	 * @return Employee
	 * @throws DomainException 404 (no such user), 409 (the user already has a file), 422.
	 */
	public function attach( int $user_id, array $input, array $options = array() ): Employee {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::notFound( __( 'This user does not exist.', 'radius-hotel-booking-pro' ) );
		}
		if ( $this->employees->forUser( $user_id ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict( 'employee_exists', __( 'This user already has an employee file.', 'radius-hotel-booking-pro' ) );
		}
		$input += array( 'email' => (string) $user->user_email );
		$data   = $this->validate( $input, null, ! empty( $options['phone_optional'] ) );
		if ( '' !== $data['email'] ) {
			$owner = email_exists( $data['email'] );
			if ( $owner && (int) $owner !== $user_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
				throw DomainException::invalid( array( 'email' => __( 'Another account already uses this e-mail.', 'radius-hotel-booking-pro' ) ) );
			}
		}

		$id = Transaction::run(
			function () use ( $user, $user_id, $data ) {
				$employee = $this->employees->create(
					array_merge(
						$data,
						array(
							'user_id'     => $user_id,
							'employee_no' => self::number( Sequence::next( 'employee' ) ),
							'is_active'   => true,
						)
					)
				);
				if ( ! $employee->id ) {
					throw new \RuntimeException( 'The employee file could not be saved.' );
				}
				if ( ! user_can( $user_id, Capabilities::VIEW_DASHBOARD ) ) {
					$user->add_role( 'rtbp_staff' );
				}
				rtbp_activity(
					'staff.create',
					self::subject( $employee ),
					array(
						'after'       => array_filter( self::logged( $data, array() ), static fn( $value ) => '' !== $value ),
						/* translators: 1: employee name, 2: user name. */
						'description' => sprintf( __( 'Added the employee file of %1$s (existing user %2$s)', 'radius-hotel-booking-pro' ), $employee->fullName(), $user->user_login ),
					)
				);
				return (int) $employee->id;
			}
		);
		return $this->get( $id );
	}

	/**
	 * Write the user, the employee file, the access role and the PIN in one
	 * transaction.
	 *
	 * @param string $username Username.
	 * @param string $password Password ('' = random).
	 * @param array  $data     File columns.
	 * @param int    $created  Set to the new user id as soon as it exists.
	 * @param array  $input    The request (its `access_role`).
	 * @param string $pin      PIN ('' for none).
	 * @return int Employee id.
	 * @throws DomainException 422 when WordPress refuses the user.
	 * @throws \RuntimeException When the file cannot be written.
	 */
	private function insert( string $username, string $password, array $data, int &$created, array $input = array(), string $pin = '' ): int {
		return Transaction::run(
			function () use ( $username, $password, $data, &$created, $input, $pin ) {
				$user_id = wp_insert_user(
					array(
						'user_login'   => $username,
						'user_pass'    => '' !== $password ? $password : wp_generate_password( 24 ),
						'user_email'   => $data['email'],
						'first_name'   => $data['first_name'],
						'last_name'    => $data['last_name'],
						'display_name' => trim( $data['first_name'] . ' ' . $data['last_name'] ),
						'role'         => 'rtbp_staff',
					)
				);
				if ( is_wp_error( $user_id ) ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
					throw DomainException::invalid( array( 'username' => $user_id->get_error_message() ) );
				}
				$created  = (int) $user_id;
				$employee = $this->employees->create(
					array_merge(
						$data,
						array(
							'user_id'     => (int) $user_id,
							'employee_no' => self::number( Sequence::next( 'employee' ) ),
							'is_active'   => true,
						)
					)
				);
				if ( ! $employee->id ) {
					throw new \RuntimeException( 'The employee file could not be saved.' );
				}
				// Inside the transaction: a role deleted meanwhile undoes the whole creation.
				$this->applyAccess( (int) $user_id, $input, $pin );
				rtbp_activity(
					'staff.create',
					self::subject( $employee ),
					array(
						// Only the fields filled in; the emitter masks the personal ones.
						'after'       => array_filter( self::logged( $data, array() ), static fn( $value ) => '' !== $value ),
						/* translators: 1: employee name, 2: user name. */
						'description' => sprintf( __( 'Added the employee %1$s (user %2$s)', 'radius-hotel-booking-pro' ), $employee->fullName(), $username ),
					)
				);
				return (int) $employee->id;
			}
		);
	}

	/**
	 * Change an employee file (12.3, 12.6–12.8, 12.10). Only the fields given
	 * change; the user's name and e-mail follow.
	 *
	 * @param int    $id     Employee id.
	 * @param array  $input  File fields.
	 * @param string $action Activity action: `staff.edit`, or `self.profile_update`
	 *                       when the employee changes their own file (M16).
	 * @return Employee
	 * @throws DomainException 404, 422.
	 */
	public function update( int $id, array $input, string $action = 'staff.edit' ): Employee {
		$employee = $this->get( $id );
		self::guardTarget( $employee );
		$data     = $this->validate( $input, $employee );
		if ( isset( $data['email'] ) && '' !== $data['email'] ) {
			$owner = email_exists( $data['email'] );
			if ( $owner && (int) $owner !== (int) $employee->user_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
				throw DomainException::invalid( array( 'email' => __( 'Another account already uses this e-mail.', 'radius-hotel-booking-pro' ) ) );
			}
		}

		$before = self::logged( $employee->toArray(), array() );
		$after  = self::logged( array_merge( $employee->toArray(), $data ), array() );
		$diff   = self::diff( $before, $after );
		if ( ! $diff['after'] ) {
			return $employee;
		}
		// Raw values: the emitter masks the personal ones (the secret pattern).

		Transaction::run(
			function () use ( $id, $data, $diff, $employee, $action ) {
				$this->employees->lock( $id );
				$this->employees->update( $id, $data );
				$user = array( 'ID' => (int) $employee->user_id );
				foreach ( array( 'first_name', 'last_name', 'email' ) as $field ) {
					if ( array_key_exists( $field, $data ) ) {
						$user[ 'email' === $field ? 'user_email' : $field ] = $data[ $field ];
					}
				}
				if ( count( $user ) > 1 ) {
					$first                = $data['first_name'] ?? $employee->first_name;
					$last                 = $data['last_name'] ?? $employee->last_name;
					$user['display_name'] = trim( $first . ' ' . $last );
					$result               = wp_update_user( $user );
					if ( is_wp_error( $result ) ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
						throw DomainException::invalid( array( 'email' => $result->get_error_message() ) );
					}
				}
				rtbp_activity(
					$action,
					self::subject( $employee ),
					array_merge(
						$diff,
						array(
							'description' => 'self.profile_update' === $action
								/* translators: %s: employee name. */
								? sprintf( __( '%s updated their own profile', 'radius-hotel-booking-pro' ), $employee->fullName() )
								/* translators: %s: employee name. */
								: sprintf( __( 'Changed the file of %s', 'radius-hotel-booking-pro' ), $employee->fullName() ),
						)
					)
				);
			}
		);
		return $this->get( $id );
	}

	/**
	 * The full statutory numbers of an employee (12.10), for a viewer with
	 * `staff.view_ids` (the controller checks). Every reveal is logged.
	 *
	 * @param int $id Employee id.
	 * @return array `{ cnps_no, tax_no }`.
	 */
	public function identifiers( int $id ): array {
		$employee = $this->get( $id );
		if ( '' !== (string) $employee->cnps_no || '' !== (string) $employee->tax_no ) {
			rtbp_activity(
				'staff.view_ids',
				self::subject( $employee ),
				array(
					/* translators: %s: employee name. */
					'description' => sprintf( __( 'Viewed the CNPS and tax numbers of %s', 'radius-hotel-booking-pro' ), $employee->fullName() ),
				)
			);
		}
		return array(
			'cnps_no' => (string) $employee->cnps_no,
			'tax_no'  => (string) $employee->tax_no,
		);
	}

	/**
	 * Switch an employee off (12.4): no more sign-in, every session ended.
	 * Their bookings and history keep them as author.
	 *
	 * @param int    $id     Employee id.
	 * @param string $reason Why (optional).
	 * @return Employee
	 */
	public function deactivate( int $id, string $reason = '' ): Employee {
		return $this->setActive( $id, false, $reason );
	}

	/**
	 * Switch an employee back on.
	 *
	 * @param int    $id     Employee id.
	 * @param string $reason Why (optional).
	 * @return Employee
	 */
	public function activate( int $id, string $reason = '' ): Employee {
		return $this->setActive( $id, true, $reason );
	}

	/**
	 * Change the account of an employee (12.5): password, PIN, access role.
	 * The username cannot be changed (WordPress keeps it fixed).
	 *
	 * @param int   $id    Employee id.
	 * @param array $input `password` (8+ characters), `pin` (4–8 digits, or '' / null
	 *                     with `remove_pin`), `remove_pin` (bool), `access_role` (id,
	 *                     or null / 0 for none).
	 * @return Employee
	 * @throws DomainException 404, 422, 409 when advanced access is off.
	 */
	public function account( int $id, array $input ): Employee {
		$employee = $this->get( $id );
		$user_id  = (int) $employee->user_id;
		self::guardTarget( $employee );
		$errors   = array();
		$password = array_key_exists( 'password', $input ) ? (string) $input['password'] : '';
		if ( '' !== $password && strlen( $password ) < 8 ) {
			$errors['password'] = __( 'Use at least 8 characters.', 'radius-hotel-booking-pro' );
		}
		$pin = isset( $input['pin'] ) ? trim( (string) $input['pin'] ) : '';
		if ( '' !== $pin && ! PasscodeService::is_valid_pin( $pin ) ) {
			$errors['pin'] = __( 'Enter a PIN of 4 to 8 digits.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		$wants_access = '' !== $pin || ! empty( $input['remove_pin'] ) || array_key_exists( 'access_role', $input );
		if ( $wants_access && ! self::advancedAccess() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict( 'feature_off', __( 'PINs and access roles need the Advanced staff access feature (Settings → Pro features).', 'radius-hotel-booking-pro' ) );
		}
		if ( $wants_access ) {
			// Roles and PINs are M13's `access.manage`, not `staff.edit`.
			self::requireAccessManage();
			if ( array_key_exists( 'access_role', $input ) && get_current_user_id() === $user_id ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
				throw new DomainException( 'own_role', __( 'You cannot change your own access role.', 'radius-hotel-booking-pro' ), 403 );
			}
		}

		if ( '' !== $password ) {
			wp_set_password( $password, $user_id );
			rtbp_activity(
				'staff.account',
				self::subject( $employee ),
				array(
					'after'       => array( 'password' => 'changed' ),
					/* translators: %s: employee name. */
					'description' => sprintf( __( 'Set a new password for %s', 'radius-hotel-booking-pro' ), $employee->fullName() ),
				)
			);
		}
		if ( array_key_exists( 'access_role', $input ) ) {
			$role = (int) $input['access_role'];
			( new UserAccessService() )->update( $user_id, array( 'access_role' => $role ? $role : null ) );
		}
		if ( '' !== $pin ) {
			PasscodeService::set_pin( $user_id, $pin );
		} elseif ( ! empty( $input['remove_pin'] ) && PasscodeService::has_pin( $user_id ) ) {
			PasscodeService::set_pin( $user_id, null );
		}
		return $this->get( $id );
	}

	/**
	 * E-mail the employee a link to choose their password (WordPress's own
	 * reset e-mail).
	 *
	 * @param int $id Employee id.
	 * @return void
	 * @throws DomainException 404, 422 without an e-mail, 503 when the e-mail fails.
	 */
	public function sendPasswordLink( int $id ): void {
		$employee = $this->get( $id );
		self::guardTarget( $employee );
		$user     = get_userdata( (int) $employee->user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( array( 'email' => __( 'Add an e-mail address first: the link is sent there.', 'radius-hotel-booking-pro' ) ) );
		}
		$sent = retrieve_password( $user->user_login );
		if ( is_wp_error( $sent ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw new DomainException( 'mail_failed', __( 'The e-mail could not be sent. Check the site\'s e-mail settings, or set a password here.', 'radius-hotel-booking-pro' ), 503 );
		}
		rtbp_activity(
			'staff.account',
			self::subject( $employee ),
			array(
				'after'       => array( 'password_link' => 'sent' ),
				/* translators: %s: employee name. */
				'description' => sprintf( __( 'Sent %s a link to set their password', 'radius-hotel-booking-pro' ), $employee->fullName() ),
			)
		);
	}

	/**
	 * What the staff forms offer: access roles (when advanced access runs)
	 * and the lists.
	 *
	 * @return array
	 */
	public static function options(): array {
		$roles = array();
		if ( self::advancedAccess() && class_exists( '\\RadiusTheme\\RadiusHotelBookingPro\\Access\\AccessRoleRepository' ) ) {
			foreach ( ( new \RadiusTheme\RadiusHotelBookingPro\Access\AccessRoleRepository() )->ordered() as $role ) {
				$roles[] = array(
					'id'   => (int) $role->id,
					'name' => (string) $role->name,
				);
			}
		}
		return array(
			'access_roles'    => $roles,
			'advanced_access' => self::advancedAccess(),
			'marital'         => array(
				'single'    => __( 'Single', 'radius-hotel-booking-pro' ),
				'married'   => __( 'Married', 'radius-hotel-booking-pro' ),
				'divorced'  => __( 'Divorced', 'radius-hotel-booking-pro' ),
				'separated' => __( 'Separated', 'radius-hotel-booking-pro' ),
				'widowed'   => __( 'Widowed', 'radius-hotel-booking-pro' ),
			),
			'relationships'   => array(
				'father'   => __( 'Father', 'radius-hotel-booking-pro' ),
				'mother'   => __( 'Mother', 'radius-hotel-booking-pro' ),
				'spouse'   => __( 'Spouse', 'radius-hotel-booking-pro' ),
				'brother'  => __( 'Brother', 'radius-hotel-booking-pro' ),
				'sister'   => __( 'Sister', 'radius-hotel-booking-pro' ),
				'friend'   => __( 'Friend', 'radius-hotel-booking-pro' ),
				'relative' => __( 'Relative', 'radius-hotel-booking-pro' ),
				'guardian' => __( 'Guardian', 'radius-hotel-booking-pro' ),
				'other'    => __( 'Other', 'radius-hotel-booking-pro' ),
			),
		);
	}

	/**
	 * Whether Pro's advanced access (PINs, access roles) is running.
	 *
	 * @return bool
	 */
	private static function advancedAccess(): bool {
		return function_exists( 'rtbp_pro_feature_active' ) && rtbp_pro_feature_active( 'advanced_access' ) && class_exists( PasscodeService::class ) && class_exists( UserAccessService::class );
	}

	/**
	 * The `authenticate` filter: a deactivated employee cannot sign in.
	 *
	 * @param WP_User|\WP_Error|null $user The user so far.
	 * @return WP_User|\WP_Error|null
	 */
	public static function signInBlock( $user ) {
		// An administrator is never locked out by the plugin.
		if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) && ( new EmployeeRepository() )->isInactiveUser( (int) $user->ID ) ) {
			return new \WP_Error( 'rtbp_inactive', __( 'This account is deactivated. Ask a manager.', 'radius-hotel-booking-pro' ) );
		}
		return $user;
	}

	/**
	 * `wp_is_application_passwords_available_for_user`: none for a
	 * deactivated employee (REST checks application passwords without the
	 * `authenticate` filter).
	 *
	 * @param bool          $available Decision so far.
	 * @param WP_User|mixed $user      User.
	 * @return bool
	 */
	public static function appPasswordsAvailable( $available, $user ): bool {
		if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) && ( new EmployeeRepository() )->isInactiveUser( (int) $user->ID ) ) {
			return false;
		}
		return (bool) $available;
	}

	/**
	 * Refuse to act on the file of an administrator unless the current user
	 * is one too: a password, e-mail or deactivation there would be a site
	 * takeover or lock the owner out.
	 *
	 * @param Employee $employee Employee.
	 * @return void
	 * @throws DomainException 403.
	 */
	private static function guardTarget( Employee $employee ): void {
		if ( user_can( (int) $employee->user_id, 'manage_options' ) && ! current_user_can( 'manage_options' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw new DomainException( 'admin_file', __( 'Only an administrator can change an administrator\'s account.', 'radius-hotel-booking-pro' ), 403 );
		}
	}

	/**
	 * Assigning access roles and PINs is M13's `access.manage`.
	 *
	 * @return void
	 * @throws DomainException 403.
	 */
	private static function requireAccessManage(): void {
		if ( ! class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Access\\Access' ) || ! \RadiusTheme\RadiusHotelBooking\Access\Access::can( 'access.manage' ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw new DomainException( 'access_manage_required', __( 'Changing access roles and PINs needs the "Manage permissions" permission.', 'radius-hotel-booking-pro' ), 403 );
		}
	}

	/**
	 * Validated file columns. On update only the fields present are checked.
	 *
	 * @param array         $input    Input.
	 * @param Employee|null $employee The file being changed.
	 * @param bool          $phone_optional Accept an empty phone (the legacy import, M18).
	 * @return array Columns.
	 * @throws DomainException 422.
	 */
	private function validate( array $input, ?Employee $employee, bool $phone_optional = false ): array {
		$has    = static fn( $key ) => null === $employee || array_key_exists( $key, $input );
		$errors = array();
		$data   = array();

		foreach ( array( 'first_name', 'last_name', 'department', 'job_title', 'shift' ) as $field ) {
			if ( $has( $field ) ) {
				$data[ $field ] = sanitize_text_field( (string) ( $input[ $field ] ?? '' ) );
				if ( mb_strlen( $data[ $field ] ) > ( 'shift' === $field ? 60 : 100 ) ) {
					$errors[ $field ] = __( 'This is too long.', 'radius-hotel-booking-pro' );
				}
			}
		}
		$first = $data['first_name'] ?? ( $employee ? $employee->first_name : '' );
		$last  = $data['last_name'] ?? ( $employee ? $employee->last_name : '' );
		if ( '' === trim( $first ) || '' === trim( $last ) ) {
			$errors['first_name'] = __( 'Enter the first and last name.', 'radius-hotel-booking-pro' );
		}

		if ( $has( 'dob' ) ) {
			$dob = trim( (string) ( $input['dob'] ?? '' ) );
			if ( '' === $dob ) {
				$data['dob'] = null;
			} elseif ( ! Dates::is_date( $dob ) || $dob > Dates::today() || $dob < '1900-01-01' ) {
				$errors['dob'] = __( 'Enter a valid date of birth.', 'radius-hotel-booking-pro' );
			} else {
				$data['dob'] = $dob;
			}
		}
		if ( $has( 'address' ) ) {
			$data['address'] = sanitize_textarea_field( (string) ( $input['address'] ?? '' ) );
		}
		if ( $has( 'marital_status' ) ) {
			$status = sanitize_key( (string) ( $input['marital_status'] ?? '' ) );
			if ( '' !== $status && ! in_array( $status, Employee::MARITAL, true ) ) {
				$errors['marital_status'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
			}
			$data['marital_status'] = $status;
		}
		if ( $has( 'num_children' ) ) {
			$children = $input['num_children'] ?? 0;
			if ( '' === $children || null === $children ) {
				$children = 0;
			}
			if ( ! is_numeric( $children ) || (int) $children < 0 || (int) $children > 30 ) {
				$errors['num_children'] = __( 'Enter a number from 0 to 30.', 'radius-hotel-booking-pro' );
			}
			$data['num_children'] = max( 0, (int) $children );
		}

		// Contact (12.7): the phone is required.
		if ( $has( 'phone' ) ) {
			$phone              = sanitize_text_field( (string) ( $input['phone'] ?? '' ) );
			$data['phone']      = $phone;
			$data['phone_e164'] = class_exists( '\\RadiusTheme\\RadiusHotelBooking\\Support\\Phone' ) ? \RadiusTheme\RadiusHotelBooking\Support\Phone::toE164( $phone ) : null;
			if ( ( '' === $phone && ! $phone_optional ) || ( '' !== $phone && strlen( preg_replace( '/\D+/', '', $phone ) ) < 8 ) ) {
				$errors['phone'] = __( 'Enter the phone number.', 'radius-hotel-booking-pro' );
			}
		}
		if ( $has( 'email' ) ) {
			$email = trim( (string) ( $input['email'] ?? '' ) );
			if ( '' !== $email && ! is_email( sanitize_email( $email ) ) ) {
				$errors['email'] = __( 'Enter a valid e-mail address.', 'radius-hotel-booking-pro' );
			}
			$data['email'] = '' === $email ? '' : sanitize_email( $email );
		}

		// Emergency contact (12.8).
		if ( $has( 'emergency' ) ) {
			$emergency    = (array) ( $input['emergency'] ?? array() );
			$relationship = sanitize_key( (string) ( $emergency['relationship'] ?? '' ) );
			if ( '' !== $relationship && ! in_array( $relationship, Employee::RELATIONSHIPS, true ) ) {
				$errors['emergency.relationship'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
			}
			$clean = array(
				'full_name'    => sanitize_text_field( (string) ( $emergency['full_name'] ?? '' ) ),
				'address'      => sanitize_textarea_field( (string) ( $emergency['address'] ?? '' ) ),
				'phone'        => sanitize_text_field( (string) ( $emergency['phone'] ?? '' ) ),
				'relationship' => $relationship,
			);
			$data['emergency'] = array_filter( $clean, static fn( $value ) => '' !== $value ) ? wp_json_encode( $clean ) : null;
		}

		// Statutory numbers (12.10).
		foreach ( array( 'cnps_no', 'tax_no' ) as $field ) {
			if ( $has( $field ) ) {
				$data[ $field ] = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( (string) ( $input[ $field ] ?? '' ) ) ) );
				if ( strlen( $data[ $field ] ) > 40 ) {
					$errors[ $field ] = __( 'This is too long.', 'radius-hotel-booking-pro' );
				}
			}
		}

		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}
		return $data;
	}

	/**
	 * Switch on or off.
	 *
	 * @param int    $id     Employee id.
	 * @param bool   $active New state.
	 * @param string $reason Why.
	 * @return Employee
	 * @throws DomainException 404, 409 already in that state.
	 */
	private function setActive( int $id, bool $active, string $reason ): Employee {
		$employee = $this->get( $id );
		self::guardTarget( $employee );
		if ( (bool) $employee->is_active === $active ) {
			// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict(
				'no_change',
				$active ? __( 'This employee is already active.', 'radius-hotel-booking-pro' ) : __( 'This employee is already deactivated.', 'radius-hotel-booking-pro' )
			);
			// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( ! $active && (int) $employee->user_id === get_current_user_id() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::conflict( 'self', __( 'You cannot deactivate your own account.', 'radius-hotel-booking-pro' ) );
		}
		$reason = sanitize_text_field( $reason );

		Transaction::run(
			function () use ( $id, $active, $employee, $reason ) {
				$this->employees->lock( $id );
				$this->employees->update(
					$id,
					array(
						'is_active'      => $active,
						'deactivated_at' => $active ? null : Dates::to_gmt_db( Dates::now() ),
					)
				);
				rtbp_activity(
					$active ? 'staff.activate' : 'staff.deactivate',
					self::subject( $employee ),
					array(
						'before'      => array( 'active' => ! $active ),
						'after'       => array_filter(
							array(
								'active' => $active,
								'reason' => $reason,
							),
							static fn( $value ) => '' !== $value
						),
						'description' => $active
							/* translators: %s: employee name. */
							? sprintf( __( 'Reactivated %s', 'radius-hotel-booking-pro' ), $employee->fullName() )
							/* translators: %s: employee name. */
							: sprintf( __( 'Deactivated %s: they can no longer sign in', 'radius-hotel-booking-pro' ), $employee->fullName() ),
					)
				);
			}
		);

		if ( ! $active ) {
			// End every session now, and any PIN unlock still running.
			WP_Session_Tokens::get_instance( (int) $employee->user_id )->destroy_all();
			// Application passwords skip the `authenticate` filter on REST: remove them.
			if ( class_exists( \WP_Application_Passwords::class ) ) {
				\WP_Application_Passwords::delete_all_application_passwords( (int) $employee->user_id );
			}
			if ( class_exists( PasscodeService::class ) && method_exists( PasscodeService::class, 'revoke_grants' ) ) {
				PasscodeService::revoke_grants( (int) $employee->user_id );
			}
		}
		return $this->get( $id );
	}

	/**
	 * The access role and PIN of a new employee, when Pro's advanced access
	 * (which owns both) is running.
	 *
	 * @param int    $user_id User id.
	 * @param array  $input   Input.
	 * @param string $pin     PIN.
	 * @return void
	 */
	private function applyAccess( int $user_id, array $input, string $pin ): void {
		if ( ! self::advancedAccess() ) {
			return;
		}
		if ( ! empty( $input['access_role'] ) && class_exists( UserAccessService::class ) ) {
			( new UserAccessService() )->update( $user_id, array( 'access_role' => (int) $input['access_role'] ) );
		}
		if ( '' !== $pin && class_exists( PasscodeService::class ) ) {
			PasscodeService::set_pin( $user_id, $pin );
		}
	}

	/**
	 * The fields an activity entry records, from columns.
	 *
	 * @param array $values Columns.
	 * @param array $extra  More fields.
	 * @return array
	 */
	private static function logged( array $values, array $extra ): array {
		$fields = array( 'first_name', 'last_name', 'dob', 'address', 'marital_status', 'num_children', 'phone', 'email', 'emergency', 'cnps_no', 'tax_no', 'department', 'job_title', 'shift' );
		$out    = array();
		foreach ( $fields as $field ) {
			$value         = $values[ $field ] ?? null;
			$out[ $field ] = null === $value ? '' : (string) $value;
		}
		return array_merge( $out, $extra );
	}

	/**
	 * The changed fields, raw: `rtbp_activity()` masks the personal ones
	 * (`MASKED`, added to the secret pattern by the feature).
	 *
	 * @param array $before Before.
	 * @param array $after  After.
	 * @return array{before: array, after: array}
	 */
	private static function diff( array $before, array $after ): array {
		$out = array(
			'before' => array(),
			'after'  => array(),
		);
		foreach ( $after as $field => $value ) {
			if ( (string) ( $before[ $field ] ?? '' ) === (string) $value ) {
				continue;
			}
			$out['before'][ $field ] = $before[ $field ] ?? '';
			$out['after'][ $field ]  = $value;
		}
		return $out;
	}

	/**
	 * `rtbp_activity_secret_pattern`: the personal fields of an employee file
	 * are never logged, only "changed" (12.6, 12.8, 12.10).
	 *
	 * @param string $pattern The emitter's pattern.
	 * @return string
	 */
	public static function secretPattern( $pattern ): string {
		$fields = implode( '|', self::MASKED );
		$extra  = '(^|[._])(' . $fields . ')($|[._])';
		if ( preg_match( '#^/(.*)/([a-zA-Z]*)$#s', (string) $pattern, $m ) ) {
			return '/(?:' . $m[1] . ')|' . $extra . '/' . ( false === strpos( $m[2], 'i' ) ? $m[2] . 'i' : $m[2] );
		}
		return '/' . $extra . '/i';
	}

	/**
	 * The activity subject.
	 *
	 * @param Employee $employee Employee.
	 * @return array
	 */
	private static function subject( Employee $employee ): array {
		return array(
			'type'  => 'employee',
			'id'    => (int) $employee->id,
			'label' => $employee->fullName(),
		);
	}

	/**
	 * The employee number: `E0001`.
	 *
	 * @param int $number Sequence number.
	 * @return string
	 */
	private static function number( int $number ): string {
		/**
		 * A new employee's number.
		 *
		 * @param string $employee_no Default `E` + 4 digits.
		 * @param int    $number      Sequence number.
		 */
		return (string) apply_filters( 'rtbp_pro_employee_number', sprintf( 'E%04d', $number ), $number );
	}
}
