<?php
/**
 * `hey-woo/suggest-improvements` ability — per-product or store-wide hints.
 *
 * Branches: if product_id is provided, returns that product's full details
 * plus a focus-aware instruction string for the model. If not, falls through
 * to store-wide recommendations. Shape preserved byte-for-byte from the TS
 * server's `suggest_improvements` tool.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

use HeyWoo\API\ProductsController;
use HeyWoo\API\ReadinessController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the suggest-improvements tool ability.
 */
class SuggestImprovementsAbility {

	const ABILITY_NAME = 'hey-woo/suggest-improvements';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Suggest improvements', 'hey-woo' ),
				'description'         => __( 'Suggest specific improvements for a product or the entire store to increase AI readiness. For a specific product, provide the product_id.', 'hey-woo' ),
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => self::input_schema(),
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
					'description' => 'Optional: specific product ID to get improvements for.',
				),
				'focus'      => array(
					'type'        => 'string',
					'enum'        => array( 'description', 'images', 'seo', 'attributes', 'all' ),
					'default'     => 'all',
					'description' => 'Area to focus improvements on.',
				),
			),
		);
	}

	/**
	 * Run the ability — mirrors the TS `suggest_improvements` branching.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$focus      = isset( $input['focus'] ) && is_string( $input['focus'] ) ? $input['focus'] : 'all';

		if ( $product_id > 0 ) {
			$request = new \WP_REST_Request( 'GET' );
			$request->set_param( 'product_id', $product_id );
			$response = ProductsController::get_product( $request );
			if ( ! ( $response instanceof \WP_REST_Response ) ) {
				return $response;
			}

			$instruction = 'Analyse this product data and suggest specific improvements'
				. ( 'all' !== $focus ? ' focusing on ' . $focus : '' )
				. '. Look at completeness scores, missing fields, and content quality.';

			return array(
				'product'     => $response->get_data(),
				'instruction' => $instruction,
			);
		}

		$response = ReadinessController::get_recommendations();
		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}
}
