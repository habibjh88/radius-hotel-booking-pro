<?php
/**
 * Employee documents (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Access\Access;
use RadiusTheme\RadiusHotelBooking\Exceptions\DomainException;
use RadiusTheme\RadiusHotelBooking\Storage\ProtectedFiles;

defined( 'ABSPATH' ) || exit;

/**
 * Documents in an employee file (12.16): contracts, ID copies, diplomas.
 * Each file is a protected file (ADR-009, kind `employee_document`): never
 * publicly reachable, downloaded only through `GET files/{token}` by someone
 * with `staff.edit`. Uploads, deletions and downloads are logged.
 */
class DocumentService {

	/**
	 * Largest upload, in bytes (the server's own limit applies when lower).
	 */
	const MAX_BYTES = 10485760;

	/**
	 * Accepted files: extension => MIME type.
	 */
	const TYPES = array(
		'pdf'      => 'application/pdf',
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
		'doc'      => 'application/msword',
		'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'odt'      => 'application/vnd.oasis.opendocument.text',
	);

	/**
	 * Documents.
	 *
	 * @var EmployeeDocumentRepository
	 */
	private EmployeeDocumentRepository $documents;

	/**
	 * Employees.
	 *
	 * @var EmployeeService
	 */
	private EmployeeService $employees;

	/**
	 * Constructor.
	 *
	 * @param EmployeeDocumentRepository|null $documents Documents.
	 * @param EmployeeService|null            $employees Employees.
	 */
	public function __construct( ?EmployeeDocumentRepository $documents = null, ?EmployeeService $employees = null ) {
		$this->documents = $documents ?? new EmployeeDocumentRepository();
		$this->employees = $employees ?? new EmployeeService();
	}

	/**
	 * An employee's documents, newest first.
	 *
	 * @param int $employee_id Employee id.
	 * @return EmployeeDocument[]
	 */
	public function forEmployee( int $employee_id ): array {
		$this->employees->get( $employee_id );
		return $this->documents->forEmployee( $employee_id );
	}

	/**
	 * Store an uploaded file in an employee file.
	 *
	 * @param int   $employee_id Employee id.
	 * @param array $file        The upload (`name`, `tmp_name`, `size`, `error`), from $_FILES.
	 * @param array $input       `title` (the file name when empty), `category`.
	 * @return EmployeeDocument
	 * @throws DomainException 404, 422.
	 * @throws \Throwable When the row cannot be written (the stored file is removed first).
	 */
	public function upload( int $employee_id, array $file, array $input ): EmployeeDocument {
		$employee = $this->employees->get( $employee_id );
		// The original name is kept for display (ProtectedFiles makes it safe); the type check uses a clean one.
		$original = sanitize_text_field( wp_basename( (string) ( $file['name'] ?? '' ) ) );
		$name     = sanitize_file_name( $original );
		$tmp      = (string) ( $file['tmp_name'] ?? '' );
		$errors   = array();

		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			$errors['file'] = self::tooLarge();
		} elseif ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_file( $tmp ) ) {
			$errors['file'] = __( 'Choose a file to upload.', 'radius-hotel-booking-pro' );
		} else {
			$size = (int) filesize( $tmp );
			if ( $size <= 0 ) {
				$errors['file'] = __( 'This file is empty.', 'radius-hotel-booking-pro' );
			} elseif ( $size > self::maxBytes() ) {
				$errors['file'] = self::tooLarge();
			} else {
				// The real type, from the content where WordPress can read it.
				$type = wp_check_filetype_and_ext( $tmp, $name, self::TYPES );
				if ( empty( $type['ext'] ) || empty( $type['type'] ) ) {
					$errors['file'] = __( 'Upload a PDF, an image (JPG, PNG, WebP) or a Word or OpenDocument file.', 'radius-hotel-booking-pro' );
				} elseif ( ! empty( $type['proper_filename'] ) ) {
					// WordPress corrected the extension to match the content.
					$original = (string) $type['proper_filename'];
				}
			}
		}

		$category = sanitize_key( (string) ( $input['category'] ?? 'other' ) );
		if ( '' === $category ) {
			$category = 'other';
		}
		if ( ! in_array( $category, EmployeeDocument::CATEGORIES, true ) ) {
			$errors['category'] = __( 'Choose from the list.', 'radius-hotel-booking-pro' );
		}
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = (string) pathinfo( $original, PATHINFO_FILENAME );
		}
		if ( mb_strlen( $title ) > 191 ) {
			$errors['title'] = __( 'This is too long.', 'radius-hotel-booking-pro' );
		}
		if ( $errors ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::invalid( $errors );
		}

		$stored = ProtectedFiles::put_file( EmployeeDocument::FILE_KIND, $tmp, '' !== $original ? $original : $name, true, (string) $type['type'] );
		try {
			$document = $this->documents->create(
				array(
					'employee_id' => (int) $employee->id,
					'title'       => $title,
					'category'    => $category,
					'file_token'  => (string) $stored->token,
					'mime'        => (string) $stored->mime,
					'size'        => (int) $stored->size,
					'uploaded_by' => get_current_user_id() ? get_current_user_id() : null,
				)
			);
		} catch ( \Throwable $e ) {
			ProtectedFiles::delete( (string) $stored->token );
			throw $e;
		}

		rtbp_activity(
			'staff.document_upload',
			self::subject( $employee ),
			array(
				'before'      => array(
					'document' => null,
					'category' => null,
				),
				'after'       => array(
					'document' => $title,
					'category' => $category,
				),
				/* translators: 1: document title, 2: employee name. */
				'description' => sprintf( __( 'Added the document "%1$s" to the file of %2$s', 'radius-hotel-booking-pro' ), $title, $employee->fullName() ),
			)
		);
		return $document instanceof EmployeeDocument ? $document : EmployeeDocument::hydrate( (array) $document );
	}

	/**
	 * Delete a document and its file.
	 *
	 * @param int    $employee_id Employee id.
	 * @param int    $id          Document id.
	 * @param string $reason      Why (logged).
	 * @return void
	 * @throws DomainException 404.
	 */
	public function delete( int $employee_id, int $id, string $reason = '' ): void {
		$employee = $this->employees->get( $employee_id );
		$document = $this->get( $employee_id, $id );
		$reason   = sanitize_textarea_field( $reason );

		$this->documents->delete( (int) $document->id );
		ProtectedFiles::delete( (string) $document->file_token );

		rtbp_activity(
			'staff.document_delete',
			self::subject( $employee ),
			array(
				'before'      => array(
					'document' => (string) $document->title,
					'category' => (string) $document->category,
				),
				'after'       => array(
					'document' => null,
					'category' => null,
				),
				'description' => '' === $reason
					/* translators: 1: document title, 2: employee name. */
					? sprintf( __( 'Deleted the document "%1$s" from the file of %2$s', 'radius-hotel-booking-pro' ), $document->title, $employee->fullName() )
					/* translators: 1: document title, 2: employee name, 3: reason. */
					: sprintf( __( 'Deleted the document "%1$s" from the file of %2$s: %3$s', 'radius-hotel-booking-pro' ), $document->title, $employee->fullName(), $reason ),
			)
		);
	}

	/**
	 * One document of an employee, or 404.
	 *
	 * @param int $employee_id Employee id.
	 * @param int $id          Document id.
	 * @return EmployeeDocument
	 * @throws DomainException 404.
	 */
	public function get( int $employee_id, int $id ): EmployeeDocument {
		$document = $id > 0 ? $this->documents->find( $id ) : null;
		if ( ! $document instanceof EmployeeDocument || (int) $document->employee_id !== $employee_id ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- sent as JSON; React escapes it.
			throw DomainException::notFound( __( 'This document does not exist.', 'radius-hotel-booking-pro' ) );
		}
		return $document;
	}

	/**
	 * `rtbp_file_kinds`: employee documents.
	 *
	 * @param array $kinds Kind => config.
	 * @return array
	 */
	public static function fileKinds( $kinds ): array {
		$kinds                                = (array) $kinds;
		$kinds[ EmployeeDocument::FILE_KIND ] = array( 'capability' => 'rtbp_view_dashboard' );
		return $kinds;
	}

	/**
	 * `rtbp_file_access`: an employee document needs `staff.edit`, like the
	 * Documents tab, for the current user's own request.
	 *
	 * @param bool   $allowed Decision so far.
	 * @param object $file    StoredFile.
	 * @param int    $user_id User.
	 * @return bool
	 */
	public static function fileAccess( $allowed, $file, $user_id ): bool {
		if ( EmployeeDocument::FILE_KIND !== (string) ( $file->kind ?? '' ) ) {
			return (bool) $allowed;
		}
		return (bool) $allowed
			&& get_current_user_id() === (int) $user_id
			&& class_exists( Access::class )
			&& Access::can( 'staff.edit' );
	}

	/**
	 * `rtbp_file_download`: record who opened an employee document.
	 *
	 * @param object $file StoredFile.
	 * @return void
	 */
	public static function downloaded( $file ): void {
		if ( EmployeeDocument::FILE_KIND !== (string) ( $file->kind ?? '' ) ) {
			return;
		}
		$document = ( new EmployeeDocumentRepository() )->forToken( (string) $file->token );
		$employee = $document ? ( new EmployeeRepository() )->find( (int) $document->employee_id ) : null;
		if ( ! $document || ! $employee instanceof Employee ) {
			return;
		}
		rtbp_activity(
			'staff.document_download',
			self::subject( $employee ),
			array(
				/* translators: 1: document title, 2: employee name. */
				'description' => sprintf( __( 'Opened the document "%1$s" in the file of %2$s', 'radius-hotel-booking-pro' ), $document->title, $employee->fullName() ),
			)
		);
	}

	/**
	 * The largest accepted upload, in bytes.
	 *
	 * @return int
	 */
	public static function maxBytes(): int {
		$server = (int) wp_max_upload_size();
		return $server > 0 ? min( self::MAX_BYTES, $server ) : self::MAX_BYTES;
	}

	/**
	 * Categories with their labels.
	 *
	 * @return array<string, string>
	 */
	public static function categories(): array {
		return array(
			'contract' => __( 'Contract', 'radius-hotel-booking-pro' ),
			'identity' => __( 'Identity document', 'radius-hotel-booking-pro' ),
			'diploma'  => __( 'Diploma or certificate', 'radius-hotel-booking-pro' ),
			'medical'  => __( 'Medical', 'radius-hotel-booking-pro' ),
			'other'    => __( 'Other', 'radius-hotel-booking-pro' ),
		);
	}

	/**
	 * "Too large" with the limit.
	 *
	 * @return string
	 */
	private static function tooLarge(): string {
		/* translators: %s: size such as "10 MB". */
		return sprintf( __( 'This file is too large. The limit is %s.', 'radius-hotel-booking-pro' ), size_format( self::maxBytes() ) );
	}

	/**
	 * The employee as the log subject.
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
}
