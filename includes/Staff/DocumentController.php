<?php
/**
 * Employee documents API (M12, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Staff
 */

namespace RadiusTheme\RadiusHotelBookingPro\Staff;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBooking\Storage\ProtectedFiles;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * All `staff.edit`:
 * - `GET employees/{id}/documents`: the list, categories and upload limit;
 * - `POST employees/{id}/documents` (multipart: `file`, `title`, `category`);
 * - `DELETE employees/{id}/documents/{document}?reason=`.
 * Downloads go through the free `GET files/{token}` (each item's `url`).
 */
class DocumentController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$base = 'employees/(?P<id>\d+)/documents';
		$router->get( $base, array( self::class, 'index' ) );
		$router->post( $base, array( self::class, 'store' ) );
		$router->delete( $base . '/(?P<document>\d+)', array( self::class, 'destroy' ) );
	}

	/**
	 * GET employees/{id}/documents.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function index( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.edit',
			fn( $request ) => ApiResponse::success(
				array(
					'documents'  => array_map( array( self::class, 'shape' ), ( new DocumentService() )->forEmployee( (int) $request->get_param( 'id' ) ) ),
					'categories' => DocumentService::categories(),
					'max_bytes'  => DocumentService::maxBytes(),
				)
			)->send()
		);
	}

	/**
	 * POST employees/{id}/documents.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function store( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.edit',
			function ( $request ) {
				$files = $request->get_file_params();
				return ApiResponse::created(
					array(
						'document' => self::shape(
							( new DocumentService() )->upload(
								(int) $request->get_param( 'id' ),
								(array) ( $files['file'] ?? array() ),
								array(
									'title'    => (string) $request->get_param( 'title' ),
									'category' => (string) $request->get_param( 'category' ),
								)
							)
						),
					),
					__( 'Document added.', 'radius-hotel-booking-pro' )
				)->send();
			}
		);
	}

	/**
	 * DELETE employees/{id}/documents/{document}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function destroy( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			'staff.edit',
			function ( $request ) {
				( new DocumentService() )->delete( (int) $request->get_param( 'id' ), (int) $request->get_param( 'document' ), (string) $request->get_param( 'reason' ) );
				return ApiResponse::success( array(), __( 'Document deleted.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}

	/**
	 * A document for the API, with its download link.
	 *
	 * @param EmployeeDocument $document Document.
	 * @return array
	 */
	public static function shape( EmployeeDocument $document ): array {
		$categories = DocumentService::categories();
		$file       = ProtectedFiles::get( (string) $document->file_token );
		$uploader   = $document->uploaded_by ? get_userdata( (int) $document->uploaded_by ) : null;
		return array(
			'id'             => (int) $document->id,
			'title'          => (string) $document->title,
			'category'       => (string) $document->category,
			'category_label' => (string) ( $categories[ $document->category ] ?? $document->category ),
			'file_name'      => $file ? (string) $file->original_name : '',
			'mime'           => (string) $document->mime,
			'size'           => (int) $document->size,
			'uploaded_by'    => $uploader ? (string) $uploader->display_name : '',
			'created_at'     => substr( (string) $document->created_at, 0, 16 ),
			'url'            => ProtectedFiles::url( (string) $document->file_token ),
			'view_url'       => ProtectedFiles::url( (string) $document->file_token, true ),
		);
	}
}
