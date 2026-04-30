<?php
/**
 * `wc-knowledge/store-policies` ability — exposed as the `store://policies` MCP resource.
 *
 * Resource-type ability. Wired into the Woo core MCP server by Plugin's
 * mcp_adapter_init hook; not a tool.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

use HeyWoo\Knowledge\KnowledgeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the store-policies resource ability.
 */
class StorePoliciesAbility {

	const ABILITY_NAME = 'wc-knowledge/store-policies';
	const RESOURCE_URI = 'store://policies';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Store policies', 'hey-woo' ),
				'description'         => __( 'Store policies — privacy, refunds, shipping, terms. Aggregated via the plugin\'s knowledge providers.', 'hey-woo' ),
				'category'            => AbilitiesBootstrap::CATEGORY,
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'meta'                => array(
					'uri'          => self::RESOURCE_URI,
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
	 * Return the MCP `contents` array. Shape matches the TS server's
	 * `store://policies` resource.
	 *
	 * @param array $input Ability input (unused — resources take no args).
	 * @return array
	 */
	public static function execute( $input = null ) {
		unset( $input );

		$registry = KnowledgeRegistry::instance();
		$payload  = $registry->get_knowledge( 'policies' );

		return array(
			array(
				'uri'      => self::RESOURCE_URI,
				'mimeType' => 'application/json',
				'text'     => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			),
		);
	}
}
