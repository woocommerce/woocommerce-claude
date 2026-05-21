<?php
/**
 * `woocommerce-claude/get-store-profile` ability — store profile as a tool.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\CommerceAbilities\Abilities\Store\GetStoreProfileAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-store-profile tool ability.
 */
class GetStoreProfileAbility {
	use GetStoreProfileAbilityTrait;

	const ABILITY_NAME = 'woocommerce-claude/get-store-profile';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get store profile', 'woocommerce-claude' ),
				'description'         => __( "Get the store's identity, configuration, payment methods, shipping zones, and features. This goes beyond WooCommerce core MCP by providing structured store knowledge for AI reasoning. Use this first to understand the store context.", 'woocommerce-claude' ),
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

	/**
	 * Version value included in the store profile payload.
	 *
	 * @return string
	 */
	private static function profile_version() {
		return WOOCOMMERCE_CLAUDE_VERSION;
	}
}
