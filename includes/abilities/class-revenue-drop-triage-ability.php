<?php
/**
 * `wc-prompts/revenue-drop-triage` ability - exposed as an MCP prompt via the
 * WooCommerce for Claude MCP server's component registry.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the revenue-drop-triage prompt ability.
 */
class RevenueDropTriageAbility {

	const ABILITY_NAME = 'wc-prompts/revenue-drop-triage';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Revenue drop triage', 'woocommerce-claude' ),
				'description'         => __( 'Diagnose WooCommerce revenue drops by separating order volume, AOV, customers, refunds, products, channels, and next merchant actions.', 'woocommerce-claude' ),
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
					'default'     => 'last_30_days',
					'description' => 'Analytics period to triage. Use last_30_days by default unless the merchant explicitly asks for a different range.',
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
		$period = isset( $input['period'] ) && is_string( $input['period'] ) ? sanitize_key( $input['period'] ) : 'last_30_days';

		$text = <<<PROMPT
You are a WooCommerce store operations analyst. Triage a possible revenue drop for the merchant.

Use period "{$period}" unless the merchant explicitly asked for exact dates. Always compare with the previous matching period when available.

Use the tools quietly. If you need a progress sentence before the final answer, say only: "I'll compare the period with the previous one and separate volume, basket size, refunds, products, and channels." Do not tell the merchant you are loading schemas, loading tools, selecting tools, calling tools, using a connector, or constructing filters.

Required data:
1. Read store profile once for store name, currency, locale, payment setup, shipping context, and store geography.
2. Call wc-analytics-totals with subject=revenue and compare=true. Read collected revenue, orders count, average order value, refunds, total customers, items sold, pending revenue, dashboard-matching revenue if needed, and comparison fields.
3. Call wc-analytics-totals with subject=orders and compare=true. Read paid orders, average order value, status breakdown, pending/on-hold pipeline, failed-order count, value distribution, and comparison fields.
4. Call wc-analytics-totals with subject=customers and compare=true. Read total customers, new/returning split, repeat rate, segment revenue/AOV, pipeline customers, and comparison fields.
5. Call wc-analytics-totals with subject=refunds and compare=true. Use this to decide whether refunds explain a net-sales drop.
6. Call wc-analytics-breakdown with subject=products, dimension=product, limit=8, compare=true. Read top products, per-product change fields, dropped-out top products, catalogue coverage, stock status, pipeline revenue, and refunds.
7. Call wc-analytics-breakdown with subject=attribution, dimension=channel, limit=8, include_unassigned=true, compare=true. Read paid revenue, order count, new/returning customers, attribution coverage, direct/unassigned share, per-channel changes, dropped-out channels, and pipeline over-index fields.
8. Optional only if it supports a claim you will make: revenue by country for regional shifts, revenue by payment method for gateway/pipeline signals, or coupons if discounting/campaign cost is central.

Rules:
- Lead with collected revenue: paid orders, net of refunds. Keep pending/on-hold revenue separate and never describe it as lost revenue.
- First decide whether there is a real drop. If collected revenue is flat or up versus the comparison, say so plainly and pivot to softer underlying signals instead of forcing a decline narrative.
- Separate the levers: order volume, average order value, customer count/mix, refunds, pending pipeline, product mix, and channel mix.
- Use returned comparison fields for movement. Do not hand-calculate deltas, percentages, shares, averages, or ratios unless the exact field is present.
- Product and channel rows carry pre-computed change fields and dropped-out lists. Use those directly; do not infer movement from row order alone.
- Treat attribution as order revenue source context, not marketing performance. Do not claim ad spend, ROAS, sessions, conversion rate, impressions, click-through rate, or campaign efficiency without a connected ad/analytics source.
- If attribution coverage is low, frame channel conclusions as partial and make tracking hygiene one of the checks.
- Refunds can explain a net-sales decline when refund value or refund rate moved materially. Phrase refunds as diagnostic pressure, not proof of product defects or customer dissatisfaction.
- On-hold and failed orders are pipeline or checkout risk. They can explain why revenue has not landed yet, but they are not confirmed lost revenue.
- Product findings are sales signals, not causal proof. A top product dropping out, low stock status, or concentration shift tells the merchant where to inspect pricing, stock, merchandising, product content, fulfilment, or promotion timing.
- Customer mix shifts need plain language: fewer new customers suggests acquisition softness; fewer returning customers suggests retention or repeat-purchase softness; lower AOV suggests basket-size, discounting, or mix checks. Phrase as checks, not conclusions.
- Do not describe customer movement as churn, churn risk, or no churn. This workflow has repeat-rate and customer-mix actuals, not churn prediction.
- Small samples need small-sample language. If a driver rests on 5 or fewer orders/refunds/customers, state the count before interpreting the percentage.
- Do not invent competitor effects, seasonality, ad budget changes, stockouts, pricing changes, email-send gaps, search ranking changes, or fulfilment problems unless the merchant supplies them or the returned data contains the signal.
- Do not mention tool names, ability names, parameter names, database tables, internal field paths, or status slugs in the final answer.
- Do not suggest building a new skill, endpoint, connector, or plugin feature. Suggest merchant actions available today in WooCommerce admin, product pages, marketing tools, fulfilment/support workflows, payment processor dashboards, carrier tools, or analytics/ad platforms.

Output exactly these sections:

### Revenue Drop Triage

**Store:** [store name]

**Period:** [date range]

**Compared with:** [comparison range, or "Not compared" if unavailable]

#### 1. Snapshot

Two or three sentences covering collected revenue movement, order count, average order value, customers, and whether the headline is truly a drop. If revenue is not down, say that clearly.

#### 2. Severity

Low, Medium, or High with one sentence explaining why, based on revenue movement, order/customer movement, refund pressure, pipeline size, and concentrated product/channel drivers.

#### 3. Drop Drivers

List the main levers that moved: order volume, basket size, customer mix, refunds, and pending pipeline. Use only returned comparison fields and keep it to the two or three highest-signal drivers.

#### 4. Product and Channel Signals

Name the products and channels that most help explain the movement. Include dropped-out top products/channels when relevant, product links when available, and attribution-coverage caveats when needed.

#### 5. Likely Checks

List two or three checks grounded in the data: stock availability, product page/content changes, pricing or discount timing, campaign/tagging health, email cadence, payment gateway health, fulfilment delays, or refund/support patterns.

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
