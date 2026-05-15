<?php
/**
 * `woocommerce-claude/get-readiness-score` ability — AI readiness score.
 *
 * Thin wrapper over ReadinessController::get_score() (which uses
 * ScoringEngine::get_store_score()).
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\ReadinessController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-readiness-score tool ability.
 */
class GetReadinessScoreAbility {

	const ABILITY_NAME = 'woocommerce-claude/get-readiness-score';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get readiness score', 'woocommerce-claude' ),
				'description'         => __( "Get the store's AI readiness score (0-100) with breakdown by factor: product completeness, schema coverage, policy completeness, and content quality. Requires the WooCommerce for Claude plugin.", 'woocommerce-claude' ),
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
	 * Run the ability — delegates to ReadinessController::get_score().
	 *
	 * @param array $input Validated ability input (unused — takes no args).
	 * @return array
	 */
	public static function execute( $input = null ) {
		unset( $input );

		$response = ReadinessController::get_score();
		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}
}
