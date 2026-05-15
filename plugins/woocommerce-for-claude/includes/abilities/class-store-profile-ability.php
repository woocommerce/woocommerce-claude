<?php
/**
 * `wc-knowledge/store-profile` ability — exposed as the `store://profile` MCP resource.
 *
 * Resource-type ability. Wired into the Woo core MCP server by Plugin's
 * mcp_adapter_init hook; not a tool. The MCP adapter reads `meta.uri` to
 * register the resource and calls execute() at resources/read time to get
 * the content payload.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\Knowledge\KnowledgeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the store-profile resource ability.
 */
class StoreProfileAbility {

	const ABILITY_NAME = 'wc-knowledge/store-profile';
	const RESOURCE_URI = 'store://profile';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Store profile', 'woocommerce-claude' ),
				'description'         => __( 'Store profile — identity, configuration, payment methods, shipping zones, features. Aggregated via the plugin\'s knowledge providers.', 'woocommerce-claude' ),
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => AbilitiesBootstrap::empty_input_schema(),
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
	 * Permission gate. Aggregated reads only — same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input = null ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Return the MCP `contents` array for the resource. Matches the shape the
	 * TS server's `store://profile` resource used: the REST /store/profile
	 * payload (version + data), JSON-encoded as a single text content item.
	 *
	 * @param array $input Ability input (unused — resources take no args).
	 * @return array
	 */
	public static function execute( $input = null ) {
		unset( $input );

		$registry = KnowledgeRegistry::instance();
		$payload  = array(
			'version' => WOOCOMMERCE_CLAUDE_VERSION,
			'data'    => $registry->get_knowledge( 'store-profile' ),
		);

		return array(
			array(
				'uri'      => self::RESOURCE_URI,
				'mimeType' => 'application/json',
				'text'     => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			),
		);
	}
}
