<?php
/**
 * `hey-woo/search-products` ability — enriched product listing.
 *
 * Thin wrapper over ProductsController::get_products() (which delegates to
 * the `products` knowledge provider). Adds completeness scores and structured
 * metadata on top of what WC core MCP's `woocommerce-products-list` tool
 * returns; the overlap is deliberate because the enriched data is only
 * available when this plugin is installed.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

use HeyWoo\API\ProductsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the search-products tool ability.
 */
class SearchProductsAbility {

	const ABILITY_NAME = 'hey-woo/search-products';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Search products', 'hey-woo' ),
				'description'         => __( "Search the product catalog with enriched AI metadata. Extends WooCommerce core MCP's basic product listing with completeness scores and structured knowledge when the Hey Woo plugin is installed.", 'hey-woo' ),
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission gate — same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * JSON Schema for the ability input.
	 *
	 * @return array
	 */
	private static function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'query'    => array(
					'type'        => 'string',
					'description' => 'Search query to find products by name or description.',
				),
				'category' => array(
					'type'        => 'string',
					'description' => 'Filter by category slug.',
				),
				'page'     => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => 'Page number.',
				),
				'per_page' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => 'Products per page (max 100).',
				),
			),
		);
	}

	/**
	 * JSON Schema for the ability output.
	 *
	 * @return array
	 */
	private static function output_schema() {
		return array( 'type' => 'object' );
	}

	/**
	 * Run the ability — delegates to ProductsController::get_products().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'search', $input['query'] ?? null );
		$request->set_param( 'category', $input['category'] ?? null );
		$request->set_param( 'page', $input['page'] ?? 1 );
		$request->set_param( 'per_page', $input['per_page'] ?? 20 );

		$response = ProductsController::get_products( $request );
		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}
}
