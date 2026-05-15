<?php
/**
 * `woocommerce-claude/get-store-profile` ability — store profile as a tool.
 *
 * Companion to the `store://profile` MCP resource. The resource form is
 * the right shape for explicit-include clients (clients that pre-load
 * resources into context); this tool form is for clients that reach for
 * tools by default (most notably when a prompt instructs Claude to "call
 * get_store_profile"). Same underlying data source — StoreController's
 * knowledge provider — wrapped as both affordances so the path that lines
 * up with the client doesn't depend on whether the client auto-reads
 * resources.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\StoreController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-store-profile tool ability.
 */
class GetStoreProfileAbility {

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
	 * Permission gate — same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input = null ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Run the ability — delegates to StoreController::get_profile().
	 *
	 * @param array $input Validated ability input (unused — takes no args).
	 * @return array
	 */
	public static function execute( $input = null ) {
		unset( $input );

		$response = StoreController::get_profile();
		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}
}
