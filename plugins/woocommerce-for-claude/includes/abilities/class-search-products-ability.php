<?php
/**
 * `woocommerce-claude/search-products` ability — enriched product listing.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\CommerceAbilities\Abilities\Store\SearchProductsAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the search-products tool ability.
 */
class SearchProductsAbility {
	use SearchProductsAbilityTrait;

	const ABILITY_NAME = 'woocommerce-claude/search-products';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Search products', 'woocommerce-claude' ),
				'description'         => __( "Search the product catalogue with enriched AI metadata. Extends WooCommerce core MCP's basic product listing with completeness scores and structured knowledge when the WooCommerce for Claude plugin is installed.", 'woocommerce-claude' ),
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
}
