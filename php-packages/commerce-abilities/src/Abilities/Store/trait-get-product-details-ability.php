<?php
/**
 * Shared implementation for get-product-details abilities.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities\Store;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Shared callbacks and schemas for single-product tool wrappers.
 */
trait GetProductDetailsAbilityTrait {

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
	 * Run the ability.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error Response payload or an error.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		return StoreKnowledge::get_product( absint( $input['product_id'] ?? 0 ) );
	}
}
