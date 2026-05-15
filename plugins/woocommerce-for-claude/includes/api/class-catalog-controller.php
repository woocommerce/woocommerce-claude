<?php
/**
 * REST API controller for Catalog Knowledge.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\API;

use WooCommerce\Claude\Knowledge\KnowledgeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes catalog-level knowledge (categories, taxonomies, schema) over REST.
 */
class CatalogController {

	const NAMESPACE = 'woocommerce-claude/v1';

	/**
	 * Register the catalog REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/catalog/schema',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_schema' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission gate — restrict to users who can manage WooCommerce.
	 *
	 * @return bool
	 */
	public static function check_permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Return the catalog schema knowledge payload.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_schema() {
		$registry = KnowledgeRegistry::instance();
		return rest_ensure_response( $registry->get_knowledge( 'catalog' ) );
	}
}
