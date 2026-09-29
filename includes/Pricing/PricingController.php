<?php
/**
 * Pricing templates and rules API (M07, Pro).
 *
 * @package RadiusTheme\RadiusHotelBookingPro\Pricing
 */

namespace RadiusTheme\RadiusHotelBookingPro\Pricing;

use RadiusTheme\RadiusHotelBooking\Core\Api\ApiResponse;
use RadiusTheme\RadiusHotelBookingPro\Access\GuardsRequests;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * `pricing/templates` and `pricing/rules?kind=` CRUD; every call needs
 * `pricing.manage`.
 */
class PricingController {

	use GuardsRequests;

	const KEY = 'pricing.manage';

	/**
	 * `rtbp_register_addon_routes`.
	 *
	 * @param object $router The free router.
	 * @return void
	 */
	public static function routes( $router ): void {
		$router->get( 'pricing/templates', array( self::class, 'templates' ) );
		$router->post( 'pricing/templates', array( self::class, 'create_template' ) );
		$router->put( 'pricing/templates/(?P<id>\d+)', array( self::class, 'update_template' ) ); // phpcs:ignore
		$router->delete( 'pricing/templates/(?P<id>\d+)', array( self::class, 'delete_template' ) ); // phpcs:ignore
		$router->get( 'pricing/rules', array( self::class, 'rules' ) );
		$router->post( 'pricing/rules', array( self::class, 'create_rule' ) );
		$router->put( 'pricing/rules/(?P<id>\d+)', array( self::class, 'update_rule' ) ); // phpcs:ignore
		$router->delete( 'pricing/rules/(?P<id>\d+)', array( self::class, 'delete_rule' ) ); // phpcs:ignore
	}

	/**
	 * GET pricing/templates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function templates( WP_REST_Request $request ) {
		return $this->guard( $request, self::KEY, fn() => ApiResponse::success( array( 'templates' => ( new PricingTemplateService() )->all() ) )->send() );
	}

	/**
	 * POST pricing/templates.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_template( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $r ) => ApiResponse::created(
				array( 'template' => ( new PricingTemplateService() )->create( (array) $r->get_json_params() ) ),
				__( 'Template added.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * PUT pricing/templates/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_template( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $r ) => ApiResponse::success(
				array( 'template' => ( new PricingTemplateService() )->update( (int) $r['id'], (array) $r->get_json_params() ) ),
				__( 'Template saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * DELETE pricing/templates/{id}: refused while a rule uses it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_template( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			function ( $r ) {
				( new PricingTemplateService() )->delete( (int) $r['id'] );
				return ApiResponse::success( array(), __( 'Template deleted.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}

	/**
	 * GET pricing/rules?kind=seasonal.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function rules( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $r ) => ApiResponse::success( array( 'rules' => ( new PricingRuleService() )->all( sanitize_key( (string) $r->get_param( 'kind' ) ) ) ) )->send()
		);
	}

	/**
	 * POST pricing/rules (`kind` in the body).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function create_rule( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $r ) => ApiResponse::created(
				array( 'rule' => ( new PricingRuleService() )->create( (array) $r->get_json_params() ) ),
				__( 'Rule added.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * PUT pricing/rules/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function update_rule( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			fn( $r ) => ApiResponse::success(
				array( 'rule' => ( new PricingRuleService() )->update( (int) $r['id'], (array) $r->get_json_params() ) ),
				__( 'Rule saved.', 'radius-hotel-booking-pro' )
			)->send()
		);
	}

	/**
	 * DELETE pricing/rules/{id}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public function delete_rule( WP_REST_Request $request ) {
		return $this->guard(
			$request,
			self::KEY,
			function ( $r ) {
				( new PricingRuleService() )->delete( (int) $r['id'] );
				return ApiResponse::success( array(), __( 'Rule deleted.', 'radius-hotel-booking-pro' ) )->send();
			}
		);
	}
}
