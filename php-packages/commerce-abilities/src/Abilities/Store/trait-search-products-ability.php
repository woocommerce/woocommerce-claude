<?php
/**
 * Shared implementation for search-products abilities.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities\Store;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Shared callbacks and schemas for product search tool wrappers.
 */
trait SearchProductsAbilityTrait {

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
	 * Run the ability.
	 *
	 * @param array $input Validated ability input.
	 * @return array|null Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return StoreKnowledge::get_products(
			array(
				'search'   => $input['query'] ?? null,
				'category' => $input['category'] ?? null,
				'page'     => $input['page'] ?? 1,
				'per_page' => $input['per_page'] ?? 20,
			),
			StoreKnowledge::consumer_from_ability( static::ABILITY_NAME )
		);
	}
}
