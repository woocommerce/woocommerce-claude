<?php
/**
 * Shared REST controller for product knowledge routes.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\API;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes product knowledge and per-product readiness scores.
 */
abstract class AbstractProductsController {

	/**
	 * REST namespace. Override in the consuming plugin.
	 */
	const NAMESPACE = '';

	/**
	 * Register the product REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			static::NAMESPACE,
			'/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( static::class, 'get_products' ),
				'permission_callback' => array( static::class, 'check_permission' ),
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0;
						},
					),
					'per_page' => array(
						'default'           => 20,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param ) && $param > 0 && $param <= 100;
						},
					),
					'category' => array(
						'default' => '',
					),
					'search'   => array(
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			static::NAMESPACE,
			'/products/(?P<product_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( static::class, 'get_product' ),
				'permission_callback' => array( static::class, 'check_permission' ),
				'args'                => array(
					'product_id' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);

		register_rest_route(
			static::NAMESPACE,
			'/products/(?P<product_id>\d+)/score',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( static::class, 'get_product_score' ),
				'permission_callback' => array( static::class, 'check_permission' ),
				'args'                => array(
					'product_id' => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
				),
			)
		);
	}

	/**
	 * Permission gate — restrict to users who can manage WooCommerce.
	 *
	 * @return bool
	 */
	public static function check_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Return a paginated, optionally filtered list of products.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response
	 */
	public static function get_products( $request ) {
		return rest_ensure_response(
			StoreKnowledge::get_products(
				array(
					'page'     => $request->get_param( 'page' ),
					'per_page' => $request->get_param( 'per_page' ),
					'category' => $request->get_param( 'category' ),
					'search'   => $request->get_param( 'search' ),
				)
			)
		);
	}

	/**
	 * Return a single product's knowledge payload.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_product( $request ) {
		$data = StoreKnowledge::get_product( absint( $request->get_param( 'product_id' ) ) );
		return is_wp_error( $data ) ? $data : rest_ensure_response( $data );
	}

	/**
	 * Return the readiness score for a single product.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_product_score( $request ) {
		$score = StoreKnowledge::get_product_score( absint( $request->get_param( 'product_id' ) ) );
		return is_wp_error( $score ) ? $score : rest_ensure_response( $score );
	}
}
