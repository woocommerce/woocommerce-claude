<?php
/**
 * `wc-prompts/weekly-store-review` ability - exposed as an MCP prompt via the
 * WooCommerce for Claude MCP server's component registry.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the weekly-store-review prompt ability.
 */
class WeeklyStoreReviewAbility {

	const ABILITY_NAME = 'wc-prompts/weekly-store-review';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Weekly store review', 'woocommerce-claude' ),
				'description'         => __( 'Produce a weekly WooCommerce performance review covering revenue, orders, customers, products, channels, refunds, and next actions.', 'woocommerce-claude' ),
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
	 * Permission gate - same capability as WC Admin.
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
				'period' => array(
					'type'        => 'string',
					'default'     => 'last_7_days',
					'description' => 'Analytics period to review. Use last_7_days by default unless the merchant explicitly asks for a different range.',
					'enum'        => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ),
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
		$input  = is_array( $input ) ? $input : array();
		$period = isset( $input['period'] ) && is_string( $input['period'] ) ? sanitize_key( $input['period'] ) : 'last_7_days';

		$text = <<<PROMPT
You are a WooCommerce store operations analyst. Produce a merchant-friendly weekly store review.

Use period "{$period}" unless the merchant explicitly asked for exact dates. Always compare with the previous period.

Use the tools quietly. Do not tell the merchant you are loading schemas, selecting tools, making parallel calls, or pulling data through a named connector.

Required data:
1. Read store profile once for store name, currency, locale, payment methods, and shipping context.
2. Call wc-analytics-totals with subject=revenue and compare=true.
3. Call wc-analytics-totals with subject=orders and compare=true.
4. Call wc-analytics-totals with subject=customers and compare=true.
5. Call wc-analytics-totals with subject=refunds and compare=true. Do not skip this.
6. Call wc-analytics-breakdown with subject=products, dimension=product, limit=5, compare=true.
7. Call wc-analytics-breakdown with subject=attribution, dimension=channel, limit=6, include_unassigned=true, compare=true.

Rules:
- Only report numbers returned by tools. Do not invent targets, forecasts, margins, conversion rates, sessions, ad spend, ROAS, or customer identities.
- Use returned comparison fields for movement. Do not hand-calculate deltas or percentages unless the exact field is present in the response.
- Keep customer details pseudonymised.
- Treat small samples carefully. If a percentage is driven by 5 or fewer events, say the sample is small before interpreting it.
- Do not describe failed or on-hold order value as lost revenue. It is checkout risk or payment pipeline unless the data says it is unrecoverable.
- Attribution is revenue source context, not ROAS. If the merchant asks about ROAS, say ad cost is needed from a connected ad platform.
- Do not mention tool names, ability names, parameter names, database tables, or internal slugs in the final answer.

Output exactly these sections:

### Weekly Store Review

**Store:** [store name]

**Period:** [date range]

**Compared with:** [comparison range]

#### 1. Headline

Two or three sentences covering collected revenue, orders, average order value, customer count, and refund rate. Lead with the most important movement.

#### 2. What changed

Three bullets maximum. Focus on material changes in revenue, order volume, AOV, customer mix, pipeline/on-hold orders, or refunds.

#### 3. Products

Name the top products and meaningful movement. Flag concentration risk if one product dominates the week.

#### 4. Channels

Summarise the leading channels and any notable mix shift. If unassigned/direct traffic is large, explain that tracking may need attention without overstating the cause.

#### 5. Watch List

List up to three issues worth checking, including refunds or low refund risk.

#### 6. Next Actions

Give three concrete merchant-actionable steps. Do not replace this section with a follow-up question.
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
