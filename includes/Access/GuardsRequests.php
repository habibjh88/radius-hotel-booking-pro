<?php
/**
 * Runs a Pro endpoint behind the free middleware.
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Access
 */

namespace RadiusTheme\RadiusHotelBookingPro\Access;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBooking\Core\Api\Middleware\AccessMiddleware;
use RadiusTheme\RadiusHotelBooking\Core\Api\Middleware\AuthMiddleware;
use RadiusTheme\RadiusHotelBooking\Core\Api\Middleware\PermissionMiddleware;
use RadiusTheme\RadiusHotelBooking\Core\Permissions\Capabilities;
use Throwable;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Signed in, dashboard access, nonce and referer, then the access key(s);
 * any exception becomes the API envelope (a DomainException keeps its code).
 */
trait GuardsRequests {

	/**
	 * Run a handler behind the middleware.
	 *
	 * @param WP_REST_Request      $request Request.
	 * @param string|string[]|null $keys    Access key(s), all required; null = any dashboard user.
	 * @param callable             $handler Returns a response.
	 * @return mixed
	 */
	protected function guard( WP_REST_Request $request, $keys, callable $handler ) {
		$stack = array( new AuthMiddleware(), new PermissionMiddleware( Capabilities::VIEW_DASHBOARD ) );
		if ( null !== $keys ) {
			$stack[] = new AccessMiddleware( array( '*' => (array) $keys ) );
		}

		$next = static function ( $request ) use ( $handler ) {
			try {
				return $handler( $request );
			} catch ( Throwable $e ) {
				return ApiResponse::fromThrowable( $e )->send();
			}
		};
		foreach ( array_reverse( $stack ) as $middleware ) {
			$next = static fn( $request ) => $middleware->handle( $request, $next );
		}

		return $next( $request );
	}
}
