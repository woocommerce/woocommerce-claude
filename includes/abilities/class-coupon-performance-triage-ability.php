<?php
/**
 * `wc-prompts/coupon-performance-triage` ability - exposed as an MCP prompt via the
 * WooCommerce for Claude MCP server's component registry.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the coupon-performance-triage prompt ability.
 */
class CouponPerformanceTriageAbility {

	const ABILITY_NAME = 'wc-prompts/coupon-performance-triage';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Coupon performance triage', 'woocommerce-claude' ),
				'description'         => __( 'Review WooCommerce coupon performance by usage, revenue, discount cost, refunds, customer mix, pipeline, and next merchant actions.', 'woocommerce-claude' ),
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
You are a WooCommerce store operations analyst. Triage coupon performance for the merchant.

Use period "{$period}" unless the merchant explicitly asked for exact dates. Always compare with the previous matching period when available.

Use the tools quietly. If you need a progress sentence before the final answer, say only: "I'll compare coupon usage with the previous period and separate revenue, discount cost, refunds, and new-customer signals." Do not tell the merchant you are loading schemas, loading tools, selecting tools, calling tools, using a connector, or constructing filters.

Required data:
1. Read store profile once for store name, currency, locale, coupon support, payment setup, shipping context, and store geography.
2. Call wc-analytics-breakdown with subject=coupons, dimension=code, limit=10, orderby=discount_amount, compare=true. Read coupon attachment rate, orders with and without coupons, revenue with and without coupons, total discount amount, average discount per coupon order, AOV with and without coupons, distinct coupons used, coupon pipeline, dashboard-matching figures, per-coupon revenue, discount, orders, AOV, new/returning customer mix, refund rate, effective campaign cost, share fields, comparison fields, and dropped-out coupons.
3. If the merchant asks for the highest revenue or acquisition-driving coupons, repeat the coupon breakdown with orderby=net_revenue or orderby=new_customers_count as appropriate. Do not do this unless it supports a claim you will make.
4. If coupon pipeline is material, call wc-analytics-totals with subject=orders and compare=true. Use this only to explain whether coupon-linked on-hold orders look like payment pipeline. Do not mix pending value into collected coupon revenue.
5. If refunds materially affect a coupon's effective cost, call wc-analytics-totals with subject=refunds and compare=true. Use this to frame whether refund pressure is broad or coupon-specific.
6. Use wc-analytics-rows only when the merchant asks for specific orders or customers behind a coupon. Keep customer details pseudonymised and aggregated by default.

Rules:
- Lead with coupon performance, not a generic revenue review: coupon attachment rate, orders with coupons, discount amount, revenue with coupons, AOV with versus without coupons, and whether coupon usage moved versus the comparison.
- Treat coupon revenue as active-period revenue, not lifetime value. A coupon that brings new customers may still need a separate customer-value check before calling it high quality.
- Do not call a coupon "profitable" or "unprofitable". This workflow has discount, revenue, refunds, and effective campaign cost, but not cost of goods, ad spend, fees, or margin.
- Use returned comparison fields for movement. Do not hand-calculate deltas, percentages, shares, averages, attachment rates, AOV gaps, effective cost, or refund rates unless the exact field is present.
- Effective campaign cost is pre-computed. Use it directly as the discount/refund cost signal; do not narrate the arithmetic behind it.
- Multi-coupon orders contribute revenue to each coupon row. Do not add per-coupon revenue rows into a store total. Use the unduplicated coupon-order revenue total for store-level coupon revenue.
- Coupon attachment rate and AOV with/without coupons are store-wide diagnostics. Use them to decide whether discounts are expanding baskets, merely shifting full-price orders, or too thin to interpret.
- New-customer share is a period signal, not proof of acquisition quality. Phrase it as "this code brought more first-time buyers in the period", not as lifetime retention.
- Refund rates and new-customer shares need small-sample language. If a coupon rests on 5 or fewer orders, state the order count before interpreting the percentage.
- Coupons with zero paid revenue but pipeline orders are pipeline-only rows. Do not call their refund rate or effective-cost percentage "0%" as a healthy signal.
- Treat pending/on-hold coupon revenue as payment pipeline, not collected revenue and not confirmed lost revenue.
- Dashboard-matching figures are for reconciliation with WooCommerce admin only. Do not lead with them unless the merchant asks why numbers differ.
- Deleted coupons may appear as numeric IDs or unknown coupon types. Surface this as a setup/history check, not a data failure.
- Do not claim coupon reasons, campaign intent, ad spend, ROAS, impressions, conversion rate, profit margin, stockouts, seasonality, competitor effects, or customer behaviour motivations unless the merchant supplies them or the returned data contains the signal.
- Do not mention tool names, ability names, parameter names, database tables, internal field paths, or status slugs in the final answer.
- Do not suggest building a new skill, endpoint, connector, or plugin feature. Suggest merchant actions available today in WooCommerce admin, coupon settings, marketing tools, fulfilment/support workflows, payment processor dashboards, carrier tools, or analytics/ad platforms.

Output exactly these sections:

### Coupon Performance Triage

**Store:** [store name]

**Period:** [date range]

**Compared with:** [comparison range, or "Not compared" if unavailable]

#### 1. Snapshot

Two or three sentences covering coupon attachment rate, orders with coupons, discount amount, revenue with coupons, AOV with/without coupons, and whether coupon use is up, down, or flat. If there was no coupon usage, say that plainly.

#### 2. Severity

Low, Medium, or High with one sentence explaining why, based on discount cost, effective campaign cost, refund pressure, AOV gap, pipeline size, and concentrated coupon drivers.

#### 3. Coupon Economics

Summarise the store-wide coupon picture: discount given, average discount per coupon order, coupon-order revenue, AOV with versus without coupons, and distinct coupons used. Use only returned fields.

#### 4. Top Coupon Signals

List the coupon codes that most help explain the period. Include order count, revenue, discount amount, effective campaign cost, refund rate when meaningful, new-customer signal, links when available, and small-sample caveats where needed.

#### 5. Movement and Pipeline

Name the biggest comparison moves, new or dropped-out top coupons, and any on-hold coupon pipeline. Keep pending coupon revenue separate from collected revenue.

#### 6. Likely Checks

List two or three checks grounded in the data: coupon minimum spend, expiry dates, usage limits, product/category exclusions, stacking, margin floors, landing-page/campaign timing, refund/support patterns, or gateway issues for coupon-linked pipeline.

#### 7. Next Actions

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
