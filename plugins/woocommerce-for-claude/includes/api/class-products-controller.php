<?php
/**
 * REST API controller for Enriched Product Knowledge.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\API;

use WooCommerce\Claude\Knowledge\KnowledgeRegistry;
use WooCommerce\Claude\Scoring\ScoringEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes product knowledge and per-product readiness scores over REST.
 */
class ProductsController {

	const NAMESPACE = 'woocommerce-claude/v1';

	/**
	 * Register the product REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_products' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
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
			self::NAMESPACE,
			'/products/(?P<product_id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_product' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
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
			self::NAMESPACE,
			'/products/(?P<product_id>\d+)/score',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_product_score' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
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
		$registry = KnowledgeRegistry::instance();
		$data     = $registry->get_knowledge(
			'products',
			array(
				'page'     => $request->get_param( 'page' ),
				'per_page' => $request->get_param( 'per_page' ),
				'category' => $request->get_param( 'category' ),
				'search'   => $request->get_param( 'search' ),
			)
		);

		return rest_ensure_response( $data );
	}

	/**
	 * Return a single product's knowledge payload.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_product( $request ) {
		$registry = KnowledgeRegistry::instance();
		$data     = $registry->get_knowledge(
			'products',
			array( 'product_id' => absint( $request->get_param( 'product_id' ) ) )
		);

		if ( isset( $data['error'] ) ) {
			return new \WP_Error( 'product_not_found', $data['error'], array( 'status' => 404 ) );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Return the readiness score for a single product.
	 *
	 * @param \WP_REST_Request $request Incoming REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function get_product_score( $request ) {
		$product_id = absint( $request->get_param( 'product_id' ) );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', 'Product not found', array( 'status' => 404 ) );
		}

		$engine = new ScoringEngine();
		$score  = $engine->get_product_score( $product );

		return rest_ensure_response( $score );
	}
}
