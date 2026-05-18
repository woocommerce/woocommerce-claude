<?php
/**
 * `woocommerce-claude/get-readiness-score` ability — AI readiness score.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\CommerceAbilities\Abilities\Store\GetReadinessScoreAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-readiness-score tool ability.
 */
class GetReadinessScoreAbility {
	use GetReadinessScoreAbilityTrait;

	const ABILITY_NAME = 'woocommerce-claude/get-readiness-score';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get readiness score', 'woocommerce-claude' ),
				'description'         => __( "Get the store's AI readiness score (0-100) with breakdown by factor: product completeness, schema coverage, policy completeness, and content quality.", 'woocommerce-claude' ),
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
