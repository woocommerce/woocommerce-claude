<?php
/**
 * `wc-prompts/product-improve` ability — exposed as an MCP prompt via the
 * Woo core MCP server's component registry (see Plugin::register_mcp_prompts).
 *
 * Body text is ported verbatim from the TS server's `product-improve`
 * prompt; the `product_id` argument interpolates into step 2 of the
 * instructions.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the product-improve prompt ability.
 */
class ProductImproveAbility {

	const ABILITY_NAME = 'wc-prompts/product-improve';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Product improve', 'hey-woo' ),
				'description'         => __( 'Generate improvements for a specific product to make it more AI-discoverable.', 'hey-woo' ),
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
				'product_id' => array(
					'type'        => 'string',
					'description' => 'The product ID to improve',
				),
			),
			'required'   => array( 'product_id' ),
		);
	}

	/**
	 * Assemble the MCP prompt message list.
	 *
	 * @param array $input Validated prompt arguments.
	 * @return array `{ messages: [...] }` shaped for MCP prompts/get.
	 */
	public static function execute( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$product_id = isset( $input['product_id'] ) ? (string) $input['product_id'] : '';

		$text = <<<PROMPT
You are an AI commerce content specialist. Improve this product's data to make it more discoverable by AI systems.

Steps:
1. Call get_store_profile for store context (brand, audience, category)
2. Call get_product_details with product_id $product_id
3. Analyse what's missing or weak

Then provide:
- An improved product description (150+ words, structured with paragraphs, covering features, use cases, and specifications)
- A short description (2-3 sentences, factual, good for AI summarisation)
- 5 FAQ questions and answers about this product
- Suggested attributes to add (based on the product type and category)
- SEO meta description (150-160 characters)
- Image alt text suggestions

Write in the store's brand voice. Be factual and specific — avoid marketing fluff. AI systems reward accuracy over enthusiasm.
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
