<?php
/**
 * `wc-analytics/get-coupon-performance` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the SQL
 * and response assembly live in
 * `AnalyticsController::fetch_coupon_performance()`.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-coupon-performance ability.
 */
class GetCouponPerformanceAbility {

	const ABILITY_NAME = 'wc-analytics/get-coupon-performance';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get coupon performance', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
TRANSITIONAL — PREFER wc-analytics-breakdown subject=coupons. This per-type narrative remains as the source of the legacy describe text returned by wc-analytics-describe; for any new call route to the verb tool, which carries the consolidated per-subject describe inline.

Get per-coupon performance — usage, discount given away, revenue driven, refund rate per coupon, plus store-wide coupon attachment rate and with-coupon vs without-coupon AOV. Each top_groups row returns paid revenue, orders, AOV, items sold, discount amount, refunds, plus pipeline (on-hold) + admin_equivalent sibling figures for reconciliation. Per-row share_of_coupon_revenue_percent, share_of_total_discount_percent, new_customer_share_percent, avg_discount_per_order, and refund_rate_percent are pre-computed so you never divide manually. Each top_groups row also includes an admin_url pointing at the coupon's WP Admin edit screen — render the coupon code as a clickable markdown link (e.g. `[SAVE15](https://example.com/wp-admin/...)`) so the merchant can jump straight to the coupon in WooCommerce.

THREE VIEWS — UNDERSTAND BEFORE QUOTING NUMBERS:
You read three sets of numbers per coupon (plus matching top-level totals / pipeline / admin_equivalent blocks). Use them like this:
- COLLECTED REVENUE per coupon — paid orders only (completed + processing), GROSS of refunds (refunds sit in their own column). The default headline per coupon; lead with this for any "how much did X drive" question. (API field paths for your reference: top_groups[].net_revenue / top_groups[].orders_count / top_groups[].avg_order_value; top-level: totals.net_revenue)
- PENDING REVENUE per coupon — on-hold orders awaiting payment that used this coupon. Surface separately when material (≥5% of collected revenue for that row) or when the merchant asks about on-hold coupon orders. Bank-transfer-heavy merchants will naturally see pipeline share on coupon orders. (API field paths: top_groups[].pipeline_revenue / top_groups[].pipeline_orders_count; top-level: pipeline.revenue)
- DASHBOARD-MATCHING REVENUE per coupon — paid + on-hold + refunded statuses summed straight. For reconciling against WC Admin > WooCommerce > Marketing > Coupons ONLY. Quote this figure when the merchant asks why our numbers differ from the dashboard; never lead the response with it. (API field paths: top_groups[].admin_equivalent_revenue / top_groups[].admin_equivalent_orders_count; top-level: admin_equivalent.revenue)

Default narrative: lead with collected revenue per coupon. Mention pending only when it's non-trivial or the merchant asks about it. The dashboard-matching figure is reconciliation-only — quote it when asked, but the headline is always collected revenue. Never sum across views — they overlap.

NEVER QUOTE THE API FIELD PATHS IN MERCHANT-FACING TEXT — this is an absolute rule, not a "with caveat" rule:
- Field paths like top_groups[].net_revenue, top_groups[].pipeline_revenue, totals.net_revenue, pipeline.revenue, admin_equivalent.revenue are for YOUR orientation when picking which number to read. They are NOT names the merchant should see.
- Use plain-English names ("collected revenue from save15", "pending revenue on save15's on-hold orders", "the dashboard-matching figure") in responses.
- Same rule applies to pre-computed fields (share_of_coupon_revenue_percent, effective_campaign_cost, new_customer_share_percent, refund_rate_percent) — narrate in English ("save15 drove 42% of your coupon-assisted revenue", "effective cost was £X") rather than pasting field paths.

Bad (verbatim field-path dump as merchant explanation):
"net_revenue / orders_count / avg_order_value: PAID sales only.
pipeline_revenue / pipeline_orders_count: ON-HOLD orders that used this coupon.
admin_equivalent_revenue: paid + on-hold + refunded."

Good (plain-English narration per coupon):
"save15 drove £X in collected revenue from Y paid orders (AOV £Z). Another £A is pending on on-hold orders using the same code. The figure WC Admin shows is £B because it lumps in refunded orders."

TWO REVENUE FIGURES AT THE TOTALS LEVEL — PICK THE RIGHT DENOMINATOR:
- totals.net_revenue: store-wide paid revenue GROSS of refunds — same definition as revenue_summary.net_revenue. Use it when reasoning about what share each coupon drove of ALL paid revenue.
- totals.net_sales: net_revenue − refunds. Matches the "net sales" figure on WC Admin dashboards and on the revenue-summary tool. Use it when the merchant asks "what did the store net this period?" or wants to reconcile.
- totals.revenue_with_coupon: paid revenue from orders that used at least one coupon. Un-duplicated — an order that used two coupons contributes its revenue once here, but twice in the per-row totals (once per coupon). Use this as the denominator when reasoning about coupon-share-of-total-revenue.
- totals.revenue_without_coupon: paid revenue from orders with no coupon attached.
- totals.refunds: absolute refund amount across all orders in the period (tax + shipping components included, matching the revenue-summary definition). NOT coupon-attributed — see the per-row refunds column for coupon-attributed refund amounts.

REFUND RATE PER COUPON — READ IT, DON'T DIVIDE IT:
Every top_groups row carries a pre-computed refund rate percent (refunds ÷ net_revenue × 100). When the merchant asks "which coupon has the worst refund rate?" or "is save15 driving returns?", scan the column — don't compute it narratively. Coupons with net_revenue = 0 (e.g. a coupon only used on on-hold orders) return 0.0 and should not be reported as "0% refund rate" — they're paid-revenue-less rows. Say so honestly if they lead the list.

SMALL-N HONESTY — flag the sample size BEFORE the rate:
When a coupon's orders_count is small (≤5 typically), the refund_rate_percent and new_customer_share_percent are interpretation-misleading even when arithmetically correct — a single refund on a 3-order coupon is 33%, true but operationally noise. Lead with the sample size. State that one event would change the rate materially. Suggest the merchant let the coupon accumulate more orders before treating the rate as a signal. Same shape applies when "every other coupon is at 0% refund rate" reads as "they're safe" — that "0%" might just mean "small N, no refunds yet." Call it out. Skip the caveat when orders_count is large enough (typically ≥10) for the rate to carry, or when the merchant explicitly asked for the literal rate regardless of confidence.

EFFECTIVE CAMPAIGN COST — READ IT, DON'T ADD IT:
Every top_groups row carries a pre-computed effective campaign cost (discount_amount + refunds — total cash outflow from offering the coupon: what you gave away PLUS what came back) and its percentage of paid revenue on coupon orders (effective_campaign_cost ÷ net_revenue × 100 — higher = less margin-efficient campaign). When the merchant asks "what did this campaign cost me?" or "is save15 margin-eating?", scan those fields — don't narrate the addition. Coupons with net_revenue = 0 return 0.0 on the percent field.

Bad (narrates the arithmetic): "save15 gave away £2,197 in discount and got £1,215 back in refunds — effective cost is £3,412."
Good (reads the pre-computed fields): "save15's effective campaign cost is £3,412 — 27% of the paid revenue it drove. That's the least margin-efficient coupon in the list."

THRESHOLDS ARE FOR YOU (CLAUDE), NOT THE MERCHANT:
Any refund-rate or effective-cost threshold you use to decide whether to flag a coupon (e.g. refund rates above ~5% warrant attention, above ~10% are a red flag) is guidance for YOU when shaping the response. When surfacing the interpretation to the merchant, state the insight as a characterisation of their numbers — not as a reference to the guidance that produced it. The merchant doesn't have access to "our guidance" and doesn't need to know it exists.

Bad (names the provenance of a threshold): "freeship at 10.8% refund rate is squarely in the 'red flag' zone per our own guidance."
Bad (same shape): "This rate merits attention per our thresholds."
Bad (attribution leak): "By the reference thresholds we use, that's a red flag."
Good (states the insight as fact): "freeship — 10.8% refund rate. That's more than double your store-wide baseline of 4.1%, and high enough that it's eating into the margin the coupon was meant to drive."
Good (skips the threshold reference entirely when not the headline): "save15 is driving a 9.8% refund rate — more than double your store baseline. Worth a look at which products those orders contain."

COUPON ATTACHMENT RATE AND WITH/WITHOUT AOV:
- totals.coupon_attachment_rate_percent: share of paid orders that used at least one coupon. Pre-computed. Use it for "what share of my orders use a coupon?" questions.
- totals.avg_order_value_with_coupon: AOV on the orders that used a coupon. Pre-computed.
- totals.avg_order_value_without_coupon: AOV on the orders that did not use a coupon. Pre-computed.
- totals.avg_discount_per_coupon_order: total_discount_amount ÷ orders_with_coupon. Pre-computed. Reads as "on a coupon order, the average discount was X".

Use these three figures as the single source of truth for "do my coupon orders spend more?" questions. Never derive them from the top_groups rows.

NEW vs RETURNING CUSTOMER ATTRIBUTION:
Each top_groups row carries new-customer and returning-customer counts — paid orders on the coupon flagged as first-time vs repeat via the returning_customer flag on wc_order_stats. A new_customer_share_percent field is pre-computed so you never divide narratively. Remember: the returning_customer flag is stamped at order-creation time, so a customer might appear in both buckets within the same period on different coupons — that's intentional and matches WC Analytics' dashboard definition.

NARRATIVE GUIDANCE — SHARE: each top_groups row carries a pre-computed share of coupon revenue percent (row's net_revenue / totals.revenue_with_coupon × 100) and share of total discount percent (row's discount_amount / totals.total_discount_amount × 100) — both against the unduplicated full-period denominators, not against the sum of visible top_groups. Use them directly — don't recompute.

NARRATIVE GUIDANCE — COMPARISON: comparison.changes carries pre-computed percent / amount / direction for every top-level metric. Per-row change blocks exist on top_groups rows that appeared in both periods. Coupons that dropped out land in comparison.dropped_out. Use those numbers as-is — never recompute deltas yourself.

WHAT THIS CAN'T ANSWER (critical — do NOT suggest drill-downs into these):
- Which coupons acquired my most valuable customers over their lifetime. That's a cohort-retention lens (first-order coupon, tracked for lifetime spend) and this skill is an active-period frame. If the merchant asks, point at the cohort view that groups customers by first-order context on the customer-value tool (steer by describing the question shape, never by naming the internal tool identifier to the merchant).
- Coupon-type roll-ups as a separate group (percent vs fixed cart vs fixed product vs free shipping). The coupon type is exposed on each top_groups row so the merchant can read it per-coupon, but the skill doesn't aggregate by type. If they ask "how are my percent vs fixed coupons doing overall?", sum the rows by type in-chat honestly, or point them at WP Admin > WooCommerce > Marketing > Coupons which segments by type.
- Why a coupon's performance moved. The comparison block gives you the delta; the CAUSE (a new campaign, a landing-page change, seasonality, a price change) isn't in the data.
- Coupon × channel cross-tabs ("how much Paid Search revenue used a coupon?"). Not in the MVP.
- Coupon × keyword / UTM term cross-tabs. Same shape.
- Per-coupon conversion rate (redemptions ÷ impressions). We don't have coupon impressions — WC only records usage, not how many times the code was seen or dropped at checkout. Steer to Jetpack Stats / GA4 / Parse.ly if the merchant asks.
- Creating / editing / deleting coupons. Read-only — WC core MCP has a separate coupon write endpoint.
- Ad spend or real ROAS per coupon. No ad-platform data — steer to a Google Ads / Meta Ads MCP when the merchant asks.

When a merchant asks for any of these, say plainly what we can and can't see, and point at the WP Admin workflow, a setting, or the connector that would answer it. Do NOT suggest that a new Skill, feature, or endpoint be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only suggest these — only suggest drill-downs we can deliver *today* with an existing tool, never an unshipped one):
- "Which orders used the top coupon?" → get_orders_summary
- "Who redeemed the top coupon?" → get_customer_overview
- "Compare to a different period?" → compare=true or recall with different dates
- "Trend over time?" → recall with period=last_month vs this_month (no native time series on this skill)
- "How did coupon-using customers' lifetime spend compare?" → get_customer_value

DO NOT SUGGEST:
- Creating or editing coupons via this skill — read-only
- Per-coupon cohort retention via this skill — steer to the customer-value cohort frame
- Coupon-type roll-ups via a group_by param — not exposed; read the coupon_type field per row
- Per-coupon conversion rate — we don't track impressions
- Building a new Skill, feature, or endpoint — the merchant can't do that

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE — this is an absolute rule, not a "mention with caveat" rule:
- Do not reference internal planning docs by filename or offer to help spec future skills.
- Do not name any unshipped or planned internal tool/skill identifier. Including any `get_*` name that isn't on the tool list you can see, or variants like "the planned X skill", "the X skill would answer this", "when X ships". These leak developer-mode framing into merchant-facing chat.
- Do not offer to help spec future skills, suggest endpoints be built, or treat the reader as the developer of this plugin.

HOW TO DESCRIBE GAPS WITHOUT LEAKING:
Bad (leaks internal naming): "A get_keyword_performance skill would answer this."
Bad (still leaks): "You'd need the planned coupon-cohort skill to surface it properly."
Good (plain language + today-action): "That question is about which coupons acquired customers who went on to spend big over their lifetime — a cohort view rather than an active-period view. The current coupon tool answers the period question. For the cohort version, the customer-value view groups acquisition cohorts by their first-order context. Want me to pull that?"

The rule: describe the *shape of the missing capability* in merchant-facing language, and point at a today-action they can take (WP Admin, a connector, a manual workflow). Never name the internal tool identifier that would fill the gap.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_orders_summary to see the coupon's orders."
Bad (imperative tool-name): "Run get_customer_overview with period=last_month to see who redeemed save10."
Bad (parameter-shape framing): "Call get_coupon_performance with compare=true to see last month's delta."
Bad (parameter-name in backticks): "I can filter by `coupon_type` or sort by `orders_count` if you want."
Bad (developer-shape alias in parens): "Break it down by usage (orders_count) to see which coupons are most redeemed."
Good (phrased as a merchant question): "Want me to compare save10's performance to last month?"
Good (phrased as a prompt the merchant can send): "Ask me: 'who redeemed save10?' and I'll pull the customer side."
Good (answering a follow-up without leaking the tool chain): "Worth checking who's redeeming save10 specifically — if they're mostly new customers, the coupon is working as acquisition rather than margin-eating. Want me to pull that?"
Good (names coupon codes, not parameter values): "I can sort these by discount given away or by order count — whichever lens is more useful right now."

Rule: no backticks around parameter names or parameter values in the response to the merchant. Coupon codes (save10, loyalvip20, freeship) are merchant vocabulary and are fine in plain text. Internal identifiers (`orderby`, `compare`, `group_by`, `coupon_type`) are developer vocabulary and never belong in merchant-facing output.

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
				'period'     => array(
					'type'        => 'string',
					'enum'        => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ),
					'default'     => 'last_30_days',
					'description' => 'Time window. Custom date_start/date_end overrides this.',
				),
				'date_start' => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom start date (YYYY-MM-DD).',
				),
				'date_end'   => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom end date (YYYY-MM-DD).',
				),
				'compare'    => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include comparison to the previous period (totals + per-row deltas + dropped_out).',
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Number of top coupons to return.',
				),
				'orderby'    => array(
					'type'        => 'string',
					'enum'        => array( 'discount_amount', 'net_revenue', 'orders_count', 'new_customers_count' ),
					'default'     => 'discount_amount',
					'description' => 'Sort column for top_groups.',
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
				'period'           => array( 'type' => 'object' ),
				'currency'         => array( 'type' => 'string' ),
				'orderby'          => array( 'type' => 'string' ),
				'limit'            => array( 'type' => 'integer' ),
				'totals'           => array( 'type' => 'object' ),
				'pipeline'         => array( 'type' => 'object' ),
				'admin_equivalent' => array( 'type' => 'object' ),
				'top_groups'       => array( 'type' => 'array' ),
				'comparison'       => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
				'note'             => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
			),
		);
	}

	/**
	 * Run the ability — delegates to AnalyticsController::fetch_coupon_performance().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_coupon_performance(
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true,
			$input['limit'] ?? 10,
			$input['orderby'] ?? 'discount_amount'
		);
	}
}
