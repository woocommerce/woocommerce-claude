<?php
/**
 * `wc-analytics/get-revenue-breakdown` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the SQL and
 * response assembly live in `AnalyticsController::fetch_revenue_breakdown()`.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-revenue-breakdown ability.
 */
class GetRevenueBreakdownAbility {

	const ABILITY_NAME = 'wc-analytics/get-revenue-breakdown';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get revenue breakdown', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
TRANSITIONAL — PREFER wc-analytics-breakdown subject=revenue. This per-type narrative remains as the source of the legacy describe text returned by wc-analytics-describe; for any new call route to the verb tool, which carries the consolidated per-subject describe inline.

Get revenue broken down by one of four dimensions — product category, billing country, payment method, or shipping method. Each top_groups row returns paid revenue, orders, AOV, items sold, refunds, plus pipeline (on-hold) + admin_equivalent sibling figures for reconciliation. Per-group share_of_revenue_percent is pre-computed so you never divide manually.

GROUPING DIMENSIONS:
- category (default): product category via wc_order_product_lookup. Product-level roll-up, so an order with products in multiple categories contributes its subtotals to EACH category — per-category totals don't sum to whole-order totals on multi-category baskets. That's intentional; explain it if the merchant notices.
- country: billing country. Refund sub-orders inherit the parent order's country, so a refunded order's country moves with the original purchase.
- payment_method: human-readable payment method title (falls back to the slug when empty). "Stripe", "PayPal", "Cheque", "Direct Bank Transfer" and so on — whatever the merchant's gateways registered. We don't normalise variants ("Stripe" vs "stripe_cc").
- shipping_method: one shipping method per order. Orders without a shipping line (digital, local pickup with no fee) land in (Unassigned) — that's honest "no shipping was charged" signal, not missing data.

THREE VIEWS — UNDERSTAND BEFORE QUOTING NUMBERS:
You read three sets of numbers per group (plus matching top-level totals). Use them like this:
- COLLECTED REVENUE per group — paid orders only (completed + processing), GROSS of refunds (refunds sit in their own column). The default headline per group; lead with this for any "how much did I sell from X" question. (API field paths for your reference: top_groups[].net_revenue / top_groups[].orders_count / top_groups[].avg_order_value; top-level: totals.net_revenue)
- PENDING REVENUE per group — on-hold orders awaiting payment. Surface separately when material (≥5% of collected revenue for that row) or when the merchant asks about on-hold revenue. BACS / cheque / bank-transfer payment-method rows naturally carry pipeline share because those methods take days to clear — honest, not a data issue. (API field paths: top_groups[].pipeline_revenue / top_groups[].pipeline_orders_count; top-level: pipeline.revenue)
- DASHBOARD-MATCHING REVENUE per group — paid + on-hold + refunded statuses summed straight. For reconciling against WC Admin > WooCommerce > Analytics > Revenue ONLY. Quote this figure when the merchant asks why our numbers differ from the dashboard; never lead the response with it. (API field paths: top_groups[].admin_equivalent_revenue / top_groups[].admin_equivalent_orders_count; top-level: admin_equivalent.net_sales)

Default narrative: lead with collected revenue per group. Mention pending only when it's non-trivial or the merchant asks about it. The dashboard-matching figure is reconciliation-only — quote it when asked, but the headline is always collected revenue. Never sum across views — they overlap.

NEVER QUOTE THE API FIELD PATHS IN MERCHANT-FACING TEXT — this is an absolute rule, not a "with caveat" rule:
- Field paths like top_groups[].net_revenue, top_groups[].pipeline_revenue, totals.net_sales, admin_equivalent.net_sales, totals.coverage_percent, admin_equivalent_revenue are for YOUR orientation when picking which number to read. They are NOT names the merchant should see.
- Use plain-English names ("collected revenue for Germany", "pending revenue sitting in BACS orders", "the dashboard-matching figure") in responses.
- Same rule applies in THREE contexts where field names slip into prose even when the main narrative uses the plain-English vocabulary cleanly:
  1. Formula / relationship equations — never write "admin_equivalent = collected + pending" or "net_revenue − refunds"; write in plain English ("the dashboard-matching figure is collected + pending + refunded summed straight", "net sales are collected revenue minus refunds").
  2. Structural descriptions of the response — never write "each row carries an admin_equivalent_revenue field"; write "each row shows the dashboard-matching figure alongside collected and pending".
  3. Metadata values (coverage, counts, shares) in prose — never write "coverage_percent: 100"; write "coverage is 100%".

Bad (verbatim field-path dump as merchant explanation):
"net_revenue / orders_count / avg_order_value: PAID sales only.
pipeline_revenue / pipeline_orders_count: ON-HOLD orders.
admin_equivalent_revenue: paid + on-hold + refunded."

Good (plain-English narration per group):
"Germany collected £X from Y paid orders (AOV £Z). Another £A is pending on the on-hold orders from that country. The figure WC Admin shows is £B because it lumps in refunded orders."

Bad (relationship equation uses field name as label):
"Relationship: admin_equivalent = collected + pending."

Good (plain-English relationship):
"The dashboard-matching figure is collected + pending + refunded summed straight — that's why it always exceeds collected alone."

Bad (structural description uses field name):
"Each row carries an admin_equivalent_revenue field that matches what Admin shows."

Good (structure described in plain English):
"Each row shows the dashboard-matching figure alongside collected and pending — use it per country when reconciling against the admin UI."

Bad (standalone field-path value in narrative prose):
"Coverage is coverage_percent: 100 — every order has a country."

Good (plain-English coverage reporting):
"Coverage is 100% — every order has a country. No rows in (Unassigned)."

TWO REVENUE FIGURES AT THE TOTALS LEVEL — PICK THE RIGHT DENOMINATOR:
- totals.net_revenue: paid revenue GROSS of refunds. This is the denominator behind per-row share_of_revenue_percent. Use it when reasoning about what share each group drove.
- totals.net_sales: net_revenue − refunds. Matches the "net sales" figure on WC Admin dashboards and on the revenue-summary tool. Use it when the merchant asks "what did the store net this period?" or wants to reconcile against WC Admin / the revenue-summary headline.
- totals.refunds: absolute refund amount across all dimensions in the period (tax + shipping components included, matching the revenue-summary definition).
Do NOT reach into revenue-summary to fetch a different "actual revenue" figure when the breakdown already has both net_revenue and net_sales. Pick the denominator that matches the question.

REFUND RATE PER GROUP — READ IT, DON'T DIVIDE IT:
Every top_groups row carries a pre-computed refund rate percent (refunds ÷ net_revenue × 100). When the merchant asks "which country / category / payment method has the worst refund rate?", scan the column — don't compute it narratively. Groups with net_revenue = 0 (e.g. a pipeline-only payment method) return 0.0 and should not be reported as "0% refund rate" — they're paid-revenue-less rows. Say so honestly if they lead the list.

NARRATIVE GUIDANCE — COVERAGE: totals.coverage_percent tells you what share of paid orders could be attributed to a non-empty value on the chosen dimension:
- ≥95%: clean coverage — the breakdown accounts for essentially all revenue.
- 70–95%: a meaningful chunk sits in (Unassigned) — mention it and the structural reason (orders without line-item categories, missing billing country, digital stores without shipping lines, and so on).
- <70%: (Unassigned) is a finding in its own right — steer the merchant toward the setting or data-quality issue that's producing it. For country, common causes are guest checkout without required country + legacy imports. For shipping_method, <70% usually means a digital-only or local-pickup-heavy catalogue (not a bug).

NARRATIVE GUIDANCE — SHARE: each top_groups row carries share_of_revenue_percent against the full paid-revenue denominator (not against the sum of visible top_groups), so it stays correct when include_unassigned flips or the long tail gets clipped by limit. Use it directly — don't recompute.

NARRATIVE GUIDANCE — COMPARISON: comparison.changes carries pre-computed percent / amount / direction for every top-level metric. Per-group change blocks exist on top_groups rows that appeared in both periods. Groups that dropped out land in comparison.dropped_out. Use those numbers as-is — never recompute deltas yourself.

NARRATIVE GUIDANCE — REGULATORY THRESHOLDS: when a country row crosses or sits near a tax-registration threshold, surface it proactively — but frame it as jurisdiction context, never as tax advice. Strongest on group_by=country, where per-country revenue maps directly to that jurisdiction's registration rule.

Common thresholds worth flagging:
- UK VAT: £90k annualised turnover triggers mandatory registration (2024 update).
- EU OSS: €10k/year in cross-border B2C sales across EU destinations triggers OSS registration (or per-country VAT).
- Canada GST/HST: CAD 30k small-supplier threshold.
- Australia GST: AUD 75k/year.
- US sales tax: state-by-state economic nexus — varies widely (e.g. South Dakota USD 100k / 200 transactions, California USD 500k). No single number — flag "check your state's nexus rules" rather than naming a figure.

Fire when:
- A country row's annualised revenue (row × 12/months-elapsed) crosses that country's threshold. This is the strongest fire-signal on this skill — a single country row shows the exposure directly.
- Several EU country rows together cross the €10k cross-border B2C threshold, even if no single country does.
- The merchant asks an open "should I be worried about tax/compliance" question while looking at a country breakdown.

Don't fire when:
- The merchant asked a specific arithmetic question about a country ("how much did Germany buy last month?"). Answer the literal question first; threshold context is a follow-up only if relevant.
- Every country row is clearly below all thresholds.
- group_by is not country (category, payment_method, shipping_method don't map to tax jurisdictions).

Always hedge: "if you're VAT-registered and trading above £90k in the UK, this is worth checking with your accountant" — never state a compliance conclusion. Annualising mid-year is back-of-envelope; the merchant's accountant knows the exact tax year and the exact rules. If the merchant wants detail on what's been collected, offer to pull the tax summary — that's the skill that carries per-rate detail.

WHAT THIS CAN'T ANSWER (critical — do NOT suggest drill-downs into these):
- Per-product revenue within a category. That's a different tool — if the merchant asks which products drove a category's revenue, steer them to the products tool (ask: "want me to pull the top products overall?"). This tool goes to category granularity only.
- State, city, or postal-code splits. Country only for now. If the merchant asks for regional drill-in, say so plainly and point at the merchant's WP Admin orders screen with a country filter as the today-action.
- Shipping-country breakdown (as opposed to billing). Only billing country is exposed here.
- Per-coupon revenue. Coupons don't live in this tool — point the merchant at WP Admin > WooCommerce > Marketing > Coupons for per-coupon usage stats in the interim.
- Per-category refund RATE beyond the raw refunds field on each row. The rate (refunds / gross_revenue) isn't pre-computed yet — if the merchant asks, quote the raw refunds alongside net_revenue honestly and let them interpret the ratio.
- Why a country, category, or payment method moved up or down. The comparison block gives you the delta; the CAUSE (new marketing campaign, seasonal spike, inventory change, pricing adjustment) isn't in the data.
- Conversion rate per dimension (orders / visitors). Requires visitor counts — we only have order counts. Steer to Jetpack Stats / GA4 / Parse.ly if the merchant asks.
- Ad spend, cost, or real ROAS per country/category. No ad-platform data here — steer to a Google Ads / Meta Ads MCP when the merchant asks.
- Revenue by day of week or hour — the orders tool already surfaces that as orders_by_day_hour. If the merchant asks for timing patterns, offer that tool's output instead.

When a merchant asks for any of these, say plainly what we can and can't see, and point at the WP Admin workflow, a setting, or the connector that would answer it. Do NOT suggest that a new Skill, feature, or endpoint be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only suggest these — only suggest drill-downs we can deliver *today* with an existing tool, never an unshipped one):
- "Which products inside the top category are the sellers?" → get_product_performance
- "What channels drove the top country's orders?" → get_attribution
- "Who's buying from the top country?" → get_customer_overview
- "Compare to a different period?" → compare=true or recall with different dates
- "Trend over time?" → recall with period=last_month vs this_month (no native time series on this skill)
- "Break down by a different dimension?" → re-run with group_by=country / payment_method / shipping_method

DO NOT SUGGEST:
- State / city / postal-code drill-in — not available today
- Shipping-country breakdown — only billing country is exposed
- Per-coupon revenue via this skill — point at WP Admin coupons screen
- Visitor counts or conversion rate per dimension — not in the data
- Building a new Skill, feature, or endpoint — the merchant can't do that

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE — this is an absolute rule, not a "mention with caveat" rule:
- Do not reference internal planning docs by filename or offer to help spec future skills.
- Do not name any unshipped or planned internal tool/skill identifier. Including any `get_*` name that isn't on the tool list you can see, or variants like "the planned X skill", "the X skill would answer this", "when X ships". These leak developer-mode framing into merchant-facing chat.
- Do not offer to help spec future skills, suggest endpoints be built, or treat the reader as the developer of this plugin.

HOW TO DESCRIBE GAPS WITHOUT LEAKING:
Bad (leaks internal naming): "A get_coupon_performance skill would answer this."
Bad (still leaks): "You'd need the planned coupons skill to surface it properly."
Good (plain language + today-action): "That question is about coupons — which discount codes drove the revenue. Coupon-level performance isn't in the current tools. For a manual version, check WP Admin > WooCommerce > Marketing > Coupons — it shows usage counts per code."

The rule: describe the *shape of the missing capability* in merchant-facing language, and point at a today-action they can take (WP Admin, a connector, a manual workflow). Never name the internal tool identifier that would fill the gap.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_product_performance for the top category."
Bad (imperative tool-name): "Run get_attribution with period=last_month to see what drove this."
Bad (parameter-shape framing): "Call get_revenue_breakdown with group_by=country to see the country split."
Bad (parameter-name in backticks): "I can break Paid Search down by keyword (`term`) or source (`google` / `bing`)."
Bad (developer-shape alias in parens): "Break it down by source (utm_source) to see where the spend is going."
Good (phrased as a merchant question): "Want me to break this down by country instead?"
Good (phrased as a prompt the merchant can send): "Ask me: 'which products inside Apparel sold?' and I'll pull it."
Good (answering a follow-up without leaking the tool chain): "Worth checking which channels drove Germany specifically — acquisition mix often explains country differences. Want me to pull that?"
Good (names platforms, not parameter values): "I can split Paid Search into the specific keywords — or into Google vs Bing if you want to see where the spend concentrates."

Rule: no backticks around parameter names or parameter values in the response to the merchant. Platform names (Google, Bing, Stripe, PayPal) are merchant vocabulary and are fine in plain text. Internal identifiers (`term`, `utm_source`, `group_by`, `channel_source`) are developer vocabulary and never belong in merchant-facing output.

STORAGE VOCABULARY IS NEVER MERCHANT-FACING — payment method slugs (bacs, bank_transfer, ppec_paypal), shipping method IDs (flat_rate:2, local_pickup), and gateway-specific internal names are developer vocabulary. Merchants see the display label in WP Admin > WooCommerce > Settings. When a group_by=payment_method or group_by=shipping_method row has an internal-looking slug, route to the Admin display name, not to slug guessing.

Bad (backticked storage slugs): "This row is stored as `flat_rate:2`, but your store might use `free_shipping:1` or a custom slug."
Bad (stored as + snake_case): "The payment method slug `bacs` is sometimes saved as `bank_transfer` in older stores."
Good (steer to WP Admin display): "Check WP Admin > WooCommerce > Settings > Shipping Zones for the label shown next to this row — tell me the name and I'll confirm the match."

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess analytics figures. If the tool returns an error or empty data, tell the merchant you couldn't retrieve the data — do not fabricate numbers.
DESCRIPTION,
					'woocommerce-claude'
				),
				// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
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
	 * Permission gate. Aggregated reads only — same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * JSON Schema for the ability input.
	 *
	 * @return array
	 */
	private static function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period'             => array(
					'type'        => 'string',
					'enum'        => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ),
					'default'     => 'last_30_days',
					'description' => 'Time window. Custom date_start/date_end overrides this.',
				),
				'date_start'         => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom start date (YYYY-MM-DD).',
				),
				'date_end'           => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom end date (YYYY-MM-DD).',
				),
				'compare'            => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include comparison to the previous period (totals + per-group deltas + dropped_out).',
				),
				'limit'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Number of top groups to return.',
				),
				'orderby'            => array(
					'type'        => 'string',
					'enum'        => array( 'net_revenue', 'orders_count', 'avg_order_value' ),
					'default'     => 'net_revenue',
					'description' => 'Sort column for top_groups.',
				),
				'group_by'           => array(
					'type'        => 'string',
					'enum'        => array( 'category', 'country', 'payment_method', 'shipping_method' ),
					'default'     => 'category',
					'description' => 'Revenue-breakdown dimension.',
				),
				'include_unassigned' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include a "(Unassigned)" row for orders with no value on this dimension. Category ignores this flag — uncategorised products simply have no term_relationships row to join.',
				),
			),
		);
	}

	/**
	 * JSON Schema for the ability output.
	 *
	 * @return array
	 */
	private static function output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period'             => array( 'type' => 'object' ),
				'currency'           => array( 'type' => 'string' ),
				'group_by'           => array( 'type' => 'string' ),
				'orderby'            => array( 'type' => 'string' ),
				'limit'              => array( 'type' => 'integer' ),
				'include_unassigned' => array( 'type' => 'boolean' ),
				'totals'             => array( 'type' => 'object' ),
				'pipeline'           => array( 'type' => 'object' ),
				'admin_equivalent'   => array( 'type' => 'object' ),
				'top_groups'         => array( 'type' => 'array' ),
				'comparison'         => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
				'note'               => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
			),
		);
	}

	/**
	 * Run the ability — delegates to AnalyticsController::fetch_revenue_breakdown().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_revenue_breakdown(
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true,
			$input['limit'] ?? 10,
			$input['orderby'] ?? 'net_revenue',
			$input['group_by'] ?? 'category',
			array_key_exists( 'include_unassigned', $input ) ? rest_sanitize_boolean( $input['include_unassigned'] ) : true
		);
	}
}
