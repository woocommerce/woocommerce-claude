<?php
/**
 * Shared REST controller for catalogue knowledge routes.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\API;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes catalogue-level knowledge over a plugin-owned namespace.
 */
abstract class AbstractCatalogController {

	/**
	 * REST namespace. Override in the consuming plugin.
	 */
	const NAMESPACE = '';

	/**
	 * Register the catalogue REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			static::NAMESPACE,
			'/catalog/schema',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( static::class, 'get_schema' ),
				'permission_callback' => array( static::class, 'check_permission' ),
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
	 * Return the catalogue schema knowledge payload.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_schema() {
		return rest_ensure_response( StoreKnowledge::get_catalog_schema() );
	}
}
