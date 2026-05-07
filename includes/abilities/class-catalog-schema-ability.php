<?php
/**
 * `wc-knowledge/catalog-schema` ability — exposed as the `store://catalog-schema` MCP resource.
 *
 * Resource-type ability. Wired into the Woo core MCP server by Plugin's
 * mcp_adapter_init hook; not a tool.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\Knowledge\KnowledgeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the catalog-schema resource ability.
 */
class CatalogSchemaAbility {

	const ABILITY_NAME = 'wc-knowledge/catalog-schema';
	const RESOURCE_URI = 'store://catalog-schema';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Catalog schema', 'woocommerce-claude' ),
				'description'         => __( 'Catalog schema — category tree, attribute terms, and product type distribution. Aggregated via the plugin\'s knowledge providers.', 'woocommerce-claude' ),
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
	 * `store://catalog-schema` resource: the /catalog/schema REST payload,
	 * JSON-encoded as a single text content item.
	 *
	 * @param array $input Ability input (unused — resources take no args).
	 * @return array
	 */
	public static function execute( $input = null ) {
		unset( $input );

		$registry = KnowledgeRegistry::instance();
		$payload  = $registry->get_knowledge( 'catalog' );

		return array(
			array(
				'uri'      => self::RESOURCE_URI,
				'mimeType' => 'application/json',
				'text'     => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			),
		);
	}
}
