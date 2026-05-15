<?php
/**
 * REST API controller for AI Readiness Scoring.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\API;

use WooCommerce\Claude\Scoring\ScoringEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the AI readiness score and improvement recommendations over REST.
 */
class ReadinessController {

	const NAMESPACE = 'woocommerce-claude/v1';

	/**
	 * Register the readiness REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/readiness/score',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_score' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/readiness/recommendations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_recommendations' ),
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
	 * Return the overall store readiness score.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_score() {
		$engine = new ScoringEngine();
		return rest_ensure_response( $engine->get_store_score() );
	}

	/**
	 * Return prioritised recommendations for improving the readiness score.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_recommendations() {
		$engine = new ScoringEngine();
		return rest_ensure_response(
			array(
				'recommendations' => $engine->get_recommendations(),
			)
		);
	}
}
