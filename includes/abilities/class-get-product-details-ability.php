<?php
/**
 * `woocommerce-claude/get-product-details` ability — enriched single-product view.
 *
 * Thin wrapper over ProductsController::get_product(). Adds completeness
 * scores + structured relationships on top of WC core MCP's basic
 * `woocommerce-products-get` tool; the overlap is deliberate.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\ProductsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-product-details tool ability.
 */
class GetProductDetailsAbility {

	const ABILITY_NAME = 'woocommerce-claude/get-product-details';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get product details', 'woocommerce-claude' ),
				'description'         => __( 'Get full details for a specific product including description, attributes, images, relationships, and completeness score.', 'woocommerce-claude' ),
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
				'product_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'The WooCommerce product ID.',
				),
			),
			'required'   => array( 'product_id' ),
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
	 * Run the ability — delegates to ProductsController::get_product().
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error Response payload or an error.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$request = new \WP_REST_Request( 'GET' );
		$request->set_param( 'product_id', absint( $input['product_id'] ?? 0 ) );

		$response = ProductsController::get_product( $request );
		if ( $response instanceof \WP_REST_Response ) {
			return $response->get_data();
		}
		return $response;
	}
}
