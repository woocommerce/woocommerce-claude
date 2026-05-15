<?php
/**
 * `woocommerce-claude/get-recommendations` ability — prioritised readiness fixes.
 *
 * Thin wrapper over ReadinessController::get_recommendations().
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\ReadinessController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-recommendations tool ability.
 */
class GetRecommendationsAbility {

	const ABILITY_NAME = 'woocommerce-claude/get-recommendations';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get recommendations', 'woocommerce-claude' ),
				'description'         => __( "Get prioritised recommendations for improving the store's AI readiness. Each recommendation includes priority, impact, and a description of what to fix. Requires the WooCommerce for Claude plugin.", 'woocommerce-claude' ),
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
	 * Run the ability — delegates to ReadinessController::get_recommendations().
	 *
	 * @param array $input Validated ability input (unused — takes no args).
	 * @return array
	 */
	public static function execute( $input = null ) {
		unset( $input );

		$response = ReadinessController::get_recommendations();
		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}
}
