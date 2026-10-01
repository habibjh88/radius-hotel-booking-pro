<?php
/**
 * My profile (M16).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\SelfService
 */

namespace RadiusTheme\RadiusHotelBookingPro\SelfService;

use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBookingPro\Access\ProfileFields;
use RadiusTheme\RadiusHotelBookingPro\Staff\Employee;
use RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeRepository;
use RadiusTheme\RadiusHotelBookingPro\Staff\EmployeeService;

defined( 'ABSPATH' ) || exit;

/**
 * A member of staff reads and changes their own profile (16.1, 16.2, 16.5,
 * 16.6), limited to the fields the hotel allows them (M13's profile-field
 * map, `ProfileFields`).
 *
 * - With an employee file (M12): name, date of birth, address, marital
 *   status, children, phone, e-mail and emergency contact — saved through
 *   `EmployeeService::update()` (its validation, e-mail check and WordPress
 *   user sync), logged `self.profile_update`.
 * - Without one (an administrator, say): first and last name and e-mail of
 *   the WordPress user.
 *
 * A restricted field sent with a **changed** value is refused — 422 with the
 * field's error, nothing saved, `security.denied` logged — instead of being
 * silently kept as the legacy system did. Sent unchanged (the screen posts
 * the whole form), it is ignored. The username is never changed here.
 */
class ProfileService {

	/**
	 * Profile field => employee file column.
	 */
	public const FILE_FIELDS = array(
		'first_name'        => 'first_name',
		'last_name'         => 'last_name',
		'dob'               => 'dob',
		'address'           => 'address',
		'marital_status'    => 'marital_status',
		'num_children'      => 'num_children',
		'phone'             => 'phone',
		'email'             => 'email',
		'emergency_contact' => 'emergency',
	);

	/**
	 * Profile fields of a user without an employee file.
	 */
	public const USER_FIELDS = array( 'first_name', 'last_name', 'email' );

	/**
	 * The profile: values, each field with whether it may be changed, the
	 * lists for the form.
	 *
	 * @param int $user_id User id.
	 * @return array
	 */
	public function get( int $user_id ): array {
		$user     = get_userdata( $user_id );
		$employee = $this->employee( $user_id );
		$labels   = ProfileFields::fields();
		$resolved = array_column( ProfileFields::resolve( $user_id ), null, 'key' );

		$fields = array();
		foreach ( $this->keys( $employee ) as $key ) {
			$fields[] = array(
				'key'      => $key,
				'label'    => (string) ( $labels[ $key ][0] ?? $key ),
				'editable' => ProfileFields::ALLOW === ( $resolved[ $key ]['value'] ?? ProfileFields::RESTRICT ),
			);
		}
		$options = EmployeeService::options();

		return array(
			'username' => $user ? (string) $user->user_login : '',
			'has_file' => null !== $employee,
			// Read-only facts of the file, for the header.
			'employee' => $employee ? array(
				'employee_no' => (string) $employee->employee_no,
				'department'  => (string) $employee->department,
				'job_title'   => (string) $employee->job_title,
			) : null,
			'values'   => $this->values( $user_id, $employee ),
			'fields'   => $fields,
			'options'  => array(
				'marital'       => $options['marital'],
				'relationships' => $options['relationships'],
			),
		);
	}

	/**
	 * Change one's own profile.
	 *
	 * @param int   $user_id User id (the signed-in user).
	 * @param array $input   Profile fields; others are ignored.
	 * @return array The profile, as `get()`.
	 * @throws DomainException 422 `field_restricted` (nothing saved), 422 on invalid values.
	 */
	public function update( int $user_id, array $input ): array {
		$employee = $this->employee( $user_id );
		$current  = $this->values( $user_id, $employee );
		$input    = array_intersect_key( $input, array_flip( $this->keys( $employee ) ) );

		$refused = array();
		foreach ( array_keys( $input ) as $key ) {
			if ( ProfileFields::allowed( $user_id, $key ) ) {
				continue;
			}
			if ( self::same( $input[ $key ], $current[ $key ] ?? null ) ) {
				unset( $input[ $key ] ); // Sent back unchanged: nothing to refuse.
				continue;
			}
			$refused[ $key ] = __( 'You are not allowed to change this. Ask a manager.', 'radius-hotel-booking-pro' );
		}
		if ( $refused ) {
			rtbp_activity(
				'security.denied',
				null,
				array(
					'key'         => 'self.profile_update',
					'fields'      => array_keys( $refused ),
					'description' => sprintf(
						/* translators: %s: field keys. */
						__( 'Tried to change a restricted profile field: %s', 'radius-hotel-booking-pro' ),
						implode( ', ', array_keys( $refused ) )
					),
				)
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw new DomainException( 'field_restricted', __( 'Some of these fields cannot be changed from your profile.', 'radius-hotel-booking-pro' ), 422, $refused );
		}
		if ( ! $input ) {
			return $this->get( $user_id );
		}

		if ( $employee ) {
			$columns = array();
			foreach ( $input as $key => $value ) {
				$columns[ self::FILE_FIELDS[ $key ] ] = $value;
			}
			( new EmployeeService() )->update( (int) $employee->id, $columns, 'self.profile_update' );
		} else {
			$this->updateUser( $user_id, $input, $current );
		}
		return $this->get( $user_id );
	}

	/**
	 * Save the WordPress user's own fields (no employee file).
	 *
	 * @param int   $user_id User id.
	 * @param array $input   Allowed, present fields.
	 * @param array $current Current values.
	 * @return void
	 * @throws DomainException 422.
	 */
	private function updateUser( int $user_id, array $input, array $current ): void {
		$data   = array();
		$errors = array();
		foreach ( array( 'first_name', 'last_name' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$data[ $key ] = sanitize_text_field( (string) $input[ $key ] );
				if ( mb_strlen( $data[ $key ] ) > 100 ) {
					$errors[ $key ] = __( 'This is too long.', 'radius-hotel-booking-pro' );
				}
			}
		}
		$first = $data['first_name'] ?? $current['first_name'];
		$last  = $data['last_name'] ?? $current['last_name'];
		if ( '' === trim( $first ) || '' === trim( $last ) ) {
			$errors['first_name'] = __( 'Enter your first and last name.', 'radius-hotel-booking-pro' );
		}
		if ( array_key_exists( 'email', $input ) ) {
			$email = sanitize_email( trim( (string) $input['email'] ) );
			$owner = $email ? email_exists( $email ) : false;
			if ( ! is_email( $email ) ) {
				$errors['email'] = __( 'Enter a valid e-mail address.', 'radius-hotel-booking-pro' );
			} elseif ( $owner && (int) $owner !== $user_id ) {
				$errors['email'] = __( 'Another account already uses this e-mail.', 'radius-hotel-booking-pro' );
			}
			$data['email'] = $email;
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}

		$before = array();
		$after  = array();
		foreach ( $data as $key => $value ) {
			if ( (string) $current[ $key ] !== $value ) {
				$before[ $key ] = $current[ $key ];
				$after[ $key ]  = $value;
			}
		}
		if ( ! $after ) {
			return;
		}
		$user = array(
			'ID'           => $user_id,
			'display_name' => trim( $first . ' ' . $last ),
		);
		foreach ( $after as $key => $value ) {
			$user[ 'email' === $key ? 'user_email' : $key ] = $value;
		}
		$result = wp_update_user( $user );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( array( 'email' => $result->get_error_message() ) );
		}
		$name = trim( $first . ' ' . $last );
		rtbp_activity(
			'self.profile_update',
			array(
				'type'  => 'user',
				'id'    => $user_id,
				'label' => $name,
			),
			array(
				'before'      => $before,
				'after'       => $after,
				/* translators: %s: name. */
				'description' => sprintf( __( '%s updated their own profile', 'radius-hotel-booking-pro' ), $name ),
			)
		);
	}

	/**
	 * The current values, keyed by profile field.
	 *
	 * @param int           $user_id  User id.
	 * @param Employee|null $employee The file, if any.
	 * @return array
	 */
	private function values( int $user_id, ?Employee $employee ): array {
		if ( $employee ) {
			return array(
				'first_name'        => (string) $employee->first_name,
				'last_name'         => (string) $employee->last_name,
				'dob'               => $employee->dob ? substr( (string) $employee->dob, 0, 10 ) : '',
				'address'           => (string) $employee->address,
				'marital_status'    => (string) $employee->marital_status,
				'num_children'      => (int) $employee->num_children,
				'phone'             => (string) $employee->phone,
				'email'             => (string) $employee->email,
				'emergency_contact' => $employee->emergencyContact(),
			);
		}
		$user = get_userdata( $user_id );
		return array(
			'first_name' => $user ? (string) $user->first_name : '',
			'last_name'  => $user ? (string) $user->last_name : '',
			'email'      => $user ? (string) $user->user_email : '',
		);
	}

	/**
	 * The profile fields this person has.
	 *
	 * @param Employee|null $employee The file, if any.
	 * @return string[]
	 */
	private function keys( ?Employee $employee ): array {
		return $employee ? array_keys( self::FILE_FIELDS ) : self::USER_FIELDS;
	}

	/**
	 * The employee file of a user.
	 *
	 * @param int $user_id User id.
	 * @return Employee|null
	 */
	private function employee( int $user_id ): ?Employee {
		return $user_id ? ( new EmployeeRepository() )->forUser( $user_id ) : null;
	}

	/**
	 * Whether a sent value equals the stored one (trimmed; the emergency
	 * contact part by part; numbers as numbers).
	 *
	 * @param mixed $sent   Sent.
	 * @param mixed $stored Stored.
	 * @return bool
	 */
	private static function same( $sent, $stored ): bool {
		if ( is_array( $stored ) ) {
			$sent = is_array( $sent ) ? $sent : array();
			foreach ( $stored as $part => $value ) {
				if ( ! self::same( $sent[ $part ] ?? '', $value ) ) {
					return false;
				}
			}
			return true;
		}
		if ( is_int( $stored ) ) {
			return is_numeric( $sent ) ? (int) $sent === $stored : ( '' === $sent || null === $sent ) && 0 === $stored;
		}
		return is_scalar( $sent ) || null === $sent ? trim( (string) $sent ) === trim( (string) $stored ) : false;
	}
}
