<?php
/**
 * `hey-woo/get-store-profile` ability — store profile as a tool.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Abilities;

use WooCommerce\CommerceAbilities\Abilities\Store\GetStoreProfileAbilityTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-store-profile tool ability.
 */
class GetStoreProfileAbility {
	use GetStoreProfileAbilityTrait;

	const ABILITY_NAME = 'hey-woo/get-store-profile';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get store profile', 'hey-woo' ),
				'description'         => __( "Get the store's identity, configuration, payment methods, shipping zones, and features. Use this first to understand the store context.", 'hey-woo' ),
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
		return HEY_WOO_VERSION;
	}
}
