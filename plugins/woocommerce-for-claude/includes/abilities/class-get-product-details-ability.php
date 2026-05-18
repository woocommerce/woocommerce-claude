<?php
/**
 * `woocommerce-claude/get-product-details` ability — enriched single-product view.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\CommerceAbilities\Abilities\Store\GetProductDetailsAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-product-details tool ability.
 */
class GetProductDetailsAbility {
	use GetProductDetailsAbilityTrait;

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
}
