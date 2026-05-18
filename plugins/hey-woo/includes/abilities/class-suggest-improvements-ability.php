<?php
/**
 * `hey-woo/suggest-improvements` ability — per-product or store-wide hints.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Abilities;

use WooCommerce\CommerceAbilities\Abilities\Store\SuggestImprovementsAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the suggest-improvements tool ability.
 */
class SuggestImprovementsAbility {
	use SuggestImprovementsAbilityTrait;

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
}
