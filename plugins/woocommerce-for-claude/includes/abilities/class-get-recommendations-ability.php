<?php
/**
 * `woocommerce-claude/get-recommendations` ability — prioritised readiness fixes.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\CommerceAbilities\Abilities\Store\GetRecommendationsAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-recommendations tool ability.
 */
class GetRecommendationsAbility {
	use GetRecommendationsAbilityTrait;

	const ABILITY_NAME = 'woocommerce-claude/get-recommendations';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get recommendations', 'woocommerce-claude' ),
				'description'         => __( "Get prioritised recommendations for improving the store's AI readiness. Each recommendation includes priority, impact, and a description of what to fix.", 'woocommerce-claude' ),
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => AbilitiesBootstrap::empty_input_schema(),
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
