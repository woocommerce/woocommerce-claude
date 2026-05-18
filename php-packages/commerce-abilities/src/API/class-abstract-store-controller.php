<?php
/**
 * Shared REST controller for store knowledge routes.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\API;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes store-level knowledge over a plugin-owned namespace.
 */
abstract class AbstractStoreController {

	/**
	 * REST namespace. Override in the consuming plugin.
	 */
	const NAMESPACE = '';

	/**
	 * Product/plugin version. Override in the consuming plugin.
	 */
	const VERSION = '';

	/**
	 * Register the store REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			static::NAMESPACE,
			'/store/profile',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( static::class, 'get_profile' ),
				'permission_callback' => array( static::class, 'check_permission' ),
			)
		);

		register_rest_route(
			static::NAMESPACE,
			'/store/policies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( static::class, 'get_policies' ),
				'permission_callback' => array( static::class, 'check_permission' ),
			)
		);

		register_rest_route(
			static::NAMESPACE,
			'/store/providers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( static::class, 'get_providers' ),
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
	 * Return the store profile knowledge payload.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_profile() {
		return rest_ensure_response( StoreKnowledge::get_profile( static::VERSION ) );
	}

	/**
	 * Return the store policies knowledge payload.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_policies() {
		return rest_ensure_response( StoreKnowledge::get_policies() );
	}

	/**
	 * Return registered knowledge providers and their availability.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_providers() {
		return rest_ensure_response( StoreKnowledge::get_providers_status() );
	}
}
