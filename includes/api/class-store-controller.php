<?php
/**
 * REST API controller for Store Knowledge.
 *
 * @package HeyWoo
 */

namespace HeyWoo\API;

use HeyWoo\Knowledge\KnowledgeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes store-level knowledge (profile, policies, providers) over REST.
 */
class StoreController {

	const NAMESPACE = 'hey-woo/v1';

	/**
	 * Register the store REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/store/profile',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_profile' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/store/policies',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_policies' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/store/providers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_providers' ),
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
	 * Return the store profile knowledge payload.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_profile() {
		$registry = KnowledgeRegistry::instance();
		$data     = $registry->get_knowledge( 'store-profile' );

		return rest_ensure_response(
			array(
				'version' => HEY_WOO_VERSION,
				'data'    => $data,
			)
		);
	}

	/**
	 * Return the store policies knowledge payload.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_policies() {
		$registry = KnowledgeRegistry::instance();
		return rest_ensure_response( $registry->get_knowledge( 'policies' ) );
	}

	/**
	 * Return the registered knowledge providers and their availability.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_providers() {
		$registry = KnowledgeRegistry::instance();
		return rest_ensure_response( $registry->get_providers_status() );
	}
}
