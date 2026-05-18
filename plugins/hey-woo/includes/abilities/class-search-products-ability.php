<?php
/**
 * `hey-woo/search-products` ability — enriched product listing.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Abilities;

use WooCommerce\CommerceAbilities\Abilities\Store\SearchProductsAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the search-products tool ability.
 */
class SearchProductsAbility {
	use SearchProductsAbilityTrait;

	const ABILITY_NAME = 'hey-woo/search-products';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Search products', 'hey-woo' ),
				'description'         => __( 'Search the product catalogue with enriched AI metadata, completeness scores, and structured knowledge.', 'hey-woo' ),
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
