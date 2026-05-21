<?php
/**
 * Abilities API bootstrap.
 *
 * Registers Hey Woo-owned store knowledge and readiness tools. Shared
 * `wc-analytics/*` abilities are registered by the commerce-abilities package.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps Hey Woo product, store, and readiness abilities.
 */
class AbilitiesBootstrap {

	/**
	 * Category slug for Hey Woo-owned abilities.
	 */
	const CATEGORY = 'hey-woo';

	/**
	 * Return the schema for abilities that accept no input.
	 *
	 * @return array
	 */
	public static function empty_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(),
		);
	}

	/**
	 * Register the Hey Woo ability category.
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Hey Woo', 'hey-woo' ),
				'description' => __( 'Store knowledge, catalogue, product, and readiness tools for Hey Woo.', 'hey-woo' ),
			)
		);
	}

	/**
	 * Register Hey Woo-owned tool abilities.
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		GetStoreProfileAbility::register();
		SearchProductsAbility::register();
		GetProductDetailsAbility::register();
		GetReadinessScoreAbility::register();
		GetRecommendationsAbility::register();
		SuggestImprovementsAbility::register();
	}
}
