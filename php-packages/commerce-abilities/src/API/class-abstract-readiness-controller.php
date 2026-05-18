<?php
/**
 * Shared REST controller for readiness routes.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\API;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes AI readiness score and improvement recommendations.
 */
abstract class AbstractReadinessController {

	/**
	 * REST namespace. Override in the consuming plugin.
	 */
	const NAMESPACE = '';

	/**
	 * Register the readiness REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			static::NAMESPACE,
			'/readiness/score',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( static::class, 'get_score' ),
				'permission_callback' => array( static::class, 'check_permission' ),
			)
		);

		register_rest_route(
			static::NAMESPACE,
			'/readiness/recommendations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( static::class, 'get_recommendations' ),
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
	 * Return the overall store readiness score.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_score() {
		return rest_ensure_response( StoreKnowledge::get_readiness_score() );
	}

	/**
	 * Return prioritised recommendations for improving the readiness score.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_recommendations() {
		return rest_ensure_response( StoreKnowledge::get_recommendations() );
	}
}
