<?php
/**
 * Archive-and-remove endpoint (M11 T3b).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Export
 */

namespace RadiusTheme\RadiusHotelBookingPro\Export;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `POST exports/archive { from, to, mode, format, reason }` — needs
 * `exports.generate` **and** `exports.archive_remove` (locked by default; a
 * role may set it to the passcode level).
 */
class ArchiveController {

	use GuardsRequests;

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router Free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->post( 'exports/archive', array( self::class, 'archive' ) );
	}

	/**
	 * POST exports/archive.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function archive( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			array( 'exports.generate', 'exports.archive_remove' ),
			function ( $request ) {
				$input  = array_map( 'sanitize_text_field', array_filter( (array) $request->get_params(), 'is_scalar' ) );
				$result = ( new ArchiveService() )->run( $input );
				return ApiResponse::success( $result )->send();
			}
		);
	}
}
