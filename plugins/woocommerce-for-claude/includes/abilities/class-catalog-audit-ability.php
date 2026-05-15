<?php
/**
 * `wc-prompts/catalog-audit` ability — exposed as an MCP prompt via the
 * Woo core MCP server's component registry (see Plugin::register_mcp_prompts).
 *
 * Prompts assemble a message list that the caller feeds back to the model.
 * The body text is ported verbatim from the TS server's `catalog-audit`
 * prompt; the `focus` argument interpolates into the middle line.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the catalog-audit prompt ability.
 */
class CatalogAuditAbility {

	const ABILITY_NAME = 'wc-prompts/catalog-audit';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Catalog audit', 'woocommerce-claude' ),
				'description'         => __( "Run a comprehensive AI readiness audit of the store's product catalog.", 'woocommerce-claude' ),
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => self::input_schema(),
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
	public static function permission_check( $input ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * JSON Schema for the prompt input.
	 *
	 * @return array
	 */
	private static function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'focus' => array(
					'type'        => 'string',
					'default'     => 'all',
					'description' => 'Focus area: completeness, seo, structure, or all',
				),
			),
		);
	}

	/**
	 * Assemble the MCP prompt message list.
	 *
	 * @param array $input Validated prompt arguments.
	 * @return array `{ messages: [...] }` shaped for MCP prompts/get.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$focus = isset( $input['focus'] ) && is_string( $input['focus'] ) ? $input['focus'] : 'all';

		$focus_line = ( 'all' === $focus )
			? 'Cover all areas: completeness, schema, policies, and content quality.'
			: 'Focus specifically on: ' . $focus;

		$text = <<<PROMPT
You are an AI commerce readiness analyst. Perform a thorough audit of this WooCommerce store's catalog for AI readiness.

Steps:
1. Call get_store_profile to understand the store context
2. Call get_readiness_score to get the overall score and factor breakdown
3. Call search_products to sample the catalog (get 20 products)
4. Call get_recommendations for prioritised improvements
5. Pick 2-3 products with the lowest completeness scores and call get_product_details on each

$focus_line

Produce a structured audit report with:
- Overall readiness score and grade
- Top 5 findings (what's working, what's not)
- Prioritised action plan (what to fix first, estimated effort)
- Specific product examples showing issues
- Quick wins the merchant can do today

Use clear, actionable language. Be specific — reference actual products and categories by name.
PROMPT;

		return array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}
}
