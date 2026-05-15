<?php
/**
 * `wc-prompts/refund-triage` ability - exposed as an MCP prompt via the
 * WooCommerce for Claude MCP server's component registry.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the refund-triage prompt ability.
 */
class RefundTriageAbility {

	const ABILITY_NAME = 'wc-prompts/refund-triage';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Refund triage', 'woocommerce-claude' ),
				'description'         => __( 'Triage WooCommerce refunds by size, timing, product, country, and next merchant actions.', 'woocommerce-claude' ),
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
You are a WooCommerce store operations analyst. Triage refunds for the merchant.

Use period "{$period}" unless the merchant explicitly asked for exact dates. Always compare with the previous period when available.

Refund analytics use refund-issued dates, not original order dates. A refund issued in the selected period belongs to this triage even when the original order was placed earlier.

Use the tools quietly. If you need a progress sentence before the final answer, say only: "I'll check the last 30 days and separate refund size, timing, and product drivers." Do not tell the merchant you are loading schemas, loading tools, selecting tools, calling tools, using a connector, or constructing filters.

Required data:
1. Read store profile once for store name, currency, locale, shipping context, and payment setup context.
2. Call wc-analytics-totals with subject=refunds and compare=true. Read refund amount, refund count, orders refunded, refund rate, paid-gross denominator, average and median days to refund, partial/full split, timing buckets, and comparison fields.
3. Call wc-analytics-breakdown with subject=refunds, dimension=product, limit=8, compare=true.
4. Call wc-analytics-breakdown with subject=refunds, dimension=country, limit=6, include_unassigned=true, compare=true.
5. If a product driver needs sales-volume context, call wc-analytics-breakdown with subject=products, dimension=product, limit=8, compare=true. Use this only to support a claim you will make.

Rules:
- Treat refunds as a diagnostic, not a verdict. Product, country, timing, and rate data show where to inspect; they do not prove why customers returned items.
- Use returned comparison fields for movement. Do not hand-calculate deltas, rates, percentages, shares, or averages unless the exact field is present.
- Read refund_rate_percent directly. If it is null, say the rate is undefined because refunds are visible but the same-window paid-sales denominator is zero.
- In the final answer, translate denominator language into merchant terms such as "same-window paid sales". Do not use the word "denominator".
- Use median days to refund when describing the typical refund timing. The average can be pulled by long-tail refunds.
- Use timing buckets directly. Same-day-heavy patterns suggest duplicate/wrong-item/payment-confusion checks; 1-7 days suggests initial-impression or shipping checks; 8-30 days suggests delivery, fit, quality, or expectation checks; 31+ days suggests long-tail quality, delayed disputes, or processor-side investigation. Phrase these as checks, not causes.
- Distinguish full and partial refunds. Full refunds can point to order-level cancellation or dissatisfaction; partial refunds can point to item-level, shipping, fit, damage, or goodwill adjustments. Do not state a cause unless the data actually shows it.
- Top refunded products and countries carry pre-computed refund rate and share fields. Read them; do not recompute them.
- If a product or country has refunds but no same-window paid gross revenue, call the rate undefined for that row; do not call it 0%.
- Small samples need small-sample language. If a product or country finding rests on 5 or fewer refunds, state the count before interpreting the percentage and frame it as a signal to watch.
- Per-product refund amounts can sum to more than headline refunds when one refund touches multiple line items. Do not add product rows into a headline total.
- Do not claim refund reasons, chargebacks, fraud, carrier failure, product defects, sizing problems, or customer identity unless the data or merchant supplied it.
- If the merchant asks for refund records or refund reasons, explain the current aggregate view and point them to WP Admin > WooCommerce > Orders with the refunded status/filter to read individual refund notes. Do not invent or aggregate refund reasons.
- Do not mention tool names, ability names, parameter names, database tables, internal field paths, or status slugs in the final answer.
- Do not suggest building a new skill, endpoint, connector, or plugin feature. Suggest merchant actions available today in WooCommerce admin, product pages, support workflows, fulfilment, carrier tools, payment processor dashboards, or analytics/ad platforms.

Output exactly these sections:

### Refund Triage

**Store:** [store name]

**Period:** [date range]

**Compared with:** [comparison range, or "Not compared" if unavailable]

#### 1. Snapshot

Two or three sentences covering refund amount, refund count, orders refunded, refund rate, and whether refunds are up, down, or flat versus the comparison. Say plainly if there is no refund issue in the period.

#### 2. Severity

Low, Medium, or High with one sentence explaining why, based on refund rate, refund value, movement, count, timing concentration, and driver concentration.

#### 3. Refund Timing

Summarise median days to refund, average days if useful, partial/full split, and the timing buckets. Translate timing into checks, not conclusions.

#### 4. Top Drivers

List the most important refunded products and countries. Include refund count, refund amount, refund rate when defined, and small-sample caveats where needed.

#### 5. Likely Checks

List two or three checks grounded in the data: product description/imagery accuracy, sizing or compatibility guidance, packaging/damage reports, shipping delays, fulfilment substitutions, support macros, payment processor disputes, or country-specific delivery expectations.

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
