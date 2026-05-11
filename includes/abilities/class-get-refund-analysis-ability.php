<?php
/**
 * `wc-analytics/get-refund-analysis` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the SQL and
 * response assembly live in `AnalyticsController::fetch_refund_analysis()`.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-refund-analysis ability.
 */
class GetRefundAnalysisAbility {

	const ABILITY_NAME = 'wc-analytics/get-refund-analysis';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get refund analysis', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
TRANSITIONAL — PREFER wc-analytics-totals subject=refunds (group_by=none) OR wc-analytics-breakdown subject=refunds, dimension=product|country (grouped). This per-type narrative remains as the source of the legacy describe text returned by wc-analytics-describe; for any new call route to the matching verb tool, which carries the consolidated per-subject describe inline.

Get refund metrics for a period — how much was refunded, how many refunds were issued, how many distinct orders were touched, the refund rate against paid gross revenue, days between order and refund, partial vs full split, and (optionally) top refunded products or countries. Every ratio that Claude would otherwise derive is pre-computed server-side.

PERIOD SEMANTICS — REFUND-ISSUED, NOT ORDER-PLACED:
The date window applies to the refund sub-order's own creation date — so "refunds this month" means cheques issued this month, regardless of when the original order landed. A refund issued in April against an order placed in January lands in April. The timing buckets + avg_days_to_refund + median_days_to_refund figures tell you how far back the refunded parents actually are. If the merchant asks "how many orders placed this month later got refunded?" — that's a different question we don't answer today; say so plainly.

HEADLINE METRICS BLOCK:
- refunds_amount: total refunded in the period. Uses the canonical formula (net_total + tax_total + shipping_total on the refund sub-orders) so it matches the refunds figure on get_revenue_summary and get_revenue_breakdown.
- refunds_count: number of refund sub-orders issued. Multiple partials against the same parent each count separately.
- orders_refunded_count: DISTINCT parent orders touched by any refund. A parent with three partial refunds counts as 1 here even though refunds_count for that parent is 3.
- refund_rate_percent: refunds_amount ÷ paid_gross_revenue × 100 (pre-computed). Read it — don't divide. Rates above ~5% usually merit attention; above ~10% is a red flag. Returns null when paid_gross_revenue is 0 (a refund-only window where the original sales sit outside the period) — narrate that as "rate undefined for this window — refunds are visible but the original sales fall outside it" rather than "0% refund rate".
- paid_gross_revenue: the denominator behind refund_rate_percent — paid gross revenue in the same window, matching get_revenue_summary's paid-orders figure.
- avg_days_to_refund / median_days_to_refund: mean and median of DATEDIFF(refund_date, parent_order_date). Use median when talking about "typical" — mean is pulled by long-tail 60+ day refunds.
- partial_refunds_count / partial_refunds_amount / full_refunds_count: full = parent order's status flipped to wc-refunded (WC does this automatically when a refund covers the whole order). Everything else is partial.

TIMING BUCKETS — FIXED ORDER:
timing.buckets is always returned in this order: Same day, 1–7 days, 8–30 days, 31+ days. Each row carries count + share_percent (pre-computed). Narrative shorthand:
- Heavy on Same day: often wrong-item / payment-confusion / accidental duplicate orders. Worth drilling into the top refunded products.
- Heavy on 1–7 days: shipping / initial-impression issues.
- Heavy on 8–30 days: delivery delays, defects surfacing after use, or dissatisfaction.
- Heavy on 31+ days: delayed-dispute / chargeback adjacent / long-tail quality issues.

THRESHOLDS ARE FOR YOU (CLAUDE), NOT THE MERCHANT:
The ~5% attention / ~10% red-flag thresholds above are guidance for YOU when deciding whether to flag a rate. When surfacing the interpretation to the merchant, state the insight as a characterisation of their numbers — not as a reference to the guidance that produced it. The merchant doesn't have access to "our guidance" and doesn't need to know it exists.

Bad (names the provenance of a threshold): "10.8% is squarely in the 'red flag' zone per our own guidance."
Bad (same shape): "This rate merits attention per our thresholds."
Bad (attribution leak): "By the reference thresholds we use, that's a red flag."
Good (states the insight as fact): "10.8% is red-flag territory — well above the 5% mark where refund rates start to warrant attention, and over the 10% line where they become a real margin concern."
Good (skips the threshold reference entirely when not the headline): "Germany's refund rate at 10.8% is more than double your store-wide baseline of 4.1% — worth digging into which products are driving it."

GROUPING DIMENSIONS (group_by):
- none (default): no top_groups. Useful when the merchant just wants the headline + timing.
- product: top refunded products via wc_order_product_lookup. A refund touching two product lines contributes to two rows — per-product refund amounts can therefore sum to more than refunds_amount on multi-line refunds. Same shape as get_revenue_breakdown group_by=category; flag it if the merchant notices.
- country: top refunded billing countries. Refunds inherit the parent order's billing country (same rule as get_revenue_breakdown) — the address of a refund is plainly the original purchase's address.

EACH top_groups ROW CARRIES:
- refunds_amount, refunds_count, orders_refunded_count, gross_revenue (paid gross for that row's dimension value in the same period), avg_days_to_refund.
- refund_rate_percent (pre-computed) = refunds_amount ÷ gross_revenue × 100 for that row. When the merchant asks "which product / country has the worst refund rate?", scan the column — don't compute it narratively. Returns null on rows where gross_revenue is 0 (a product / country with refunds but no paid sales in the window) — surface that as "rate undefined for this row — refunds present but no current-window denominator to compute against" rather than "0% rate".
- share_of_refunds_percent (pre-computed) = refunds_amount ÷ headline refunds_amount × 100. How much of total refunds this row represents.
- admin_url: present (non-null) on group_by=product rows — the product's edit screen. Render the product name as a clickable markdown link to that URL so the merchant can jump straight to it. Null on group_by=country rows (countries have no admin screen).

SMALL-N HONESTY — flag the sample size BEFORE the percentage:
When a top_groups row's refunds_count is small (≤5 typically), the refund_rate_percent is mathematically true but interpretation-misleading — a single £100 refund on a product that sold £300 in the period spikes that row's rate to 33%, true arithmetically, useless operationally. Frame the percentage as signal-to-watch, not conclusion. State the count explicitly. Note that one event would change the rate materially. Suggest the merchant gather more data (next month, refund notes, or the specific orders behind the rate) before acting on the percentage alone. Don't bury the caveat — lead with it when N≤5 and the row would otherwise look alarming. Skip the caveat when refunds_count is large enough (typically ≥10) for the rate to carry. Skip when the merchant explicitly asked for the precise rate regardless of confidence.

WHAT THIS CAN'T ANSWER (critical — do NOT suggest drill-downs into these):
- Why the refund happened. WooCommerce stores a free-text reason note on each refund but it's unstructured (often blank, or one-word like "damaged") — trying to aggregate free text produces hallucinogenic categorisation. If the merchant asks for refund reasons, point them at WP Admin > WooCommerce > Orders with status filter "Refunded" to read individual notes.
- Per-coupon refund rate. Coupons don't live in this tool — point the merchant at WP Admin > WooCommerce > Marketing > Coupons for usage stats in the interim.
- Refund rate by customer segment (new vs returning). The returning_customer flag is on the parent order but we don't split the refunds pivot by it in the MVP.
- State / city / postal-code splits. Country only for now. If the merchant asks for regional drill-in, say so plainly and point at the merchant's WP Admin orders screen with a country filter as the today-action.
- Shipping-country breakdown (as opposed to billing). Only billing country is exposed here.
- Refunds by day of week / hour. Not yet surfaced as a dimension.
- Separate shipping-refund / tax-refund splits. The headline uses the canonical formula (net + tax + shipping) to match revenue_summary.
- Refund-to-chargeback distinction. Chargebacks issued through the payment processor don't automatically create a refund sub-order — they show up on the processor side (Stripe dashboard, PayPal reports). Point the merchant at their payment processor's dashboard.
- Time from refund request (customer email) to refund issued. We only see the refund record itself, not the customer-support timeline.

When a merchant asks for any of these, say plainly what we can and can't see, and point at the WP Admin workflow, a setting, or the connector that would answer it. Do NOT suggest that a new Skill, feature, or endpoint be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only suggest these — only suggest drill-downs we can deliver *today* with an existing tool, never an unshipped one):
- "Which products inside the top refunded country are driving the refunds?" → get_refund_analysis with group_by=product
- "How does the top refunded product's sales volume look?" → get_product_performance
- "Compare to the previous period?" → compare=true or recall with different dates
- "Is refund rate trending up month over month?" → recall with period=last_month vs this_month
- "Break down refunds by country instead?" → group_by=country
- "What's the headline revenue for this period?" → get_revenue_summary

DO NOT SUGGEST:
- Asking why refunds happen via this tool — the reason field isn't surfaced
- Per-coupon refund rate via this skill — point at WP Admin coupons screen
- State / city / postal-code drill-in — not available today
- Shipping-country breakdown — only billing country is exposed
- Chargeback-specific reporting — point at the payment processor's dashboard
- Building a new Skill, feature, or endpoint — the merchant can't do that

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE — this is an absolute rule, not a "mention with caveat" rule:
- Do not reference internal planning docs by filename or offer to help spec future skills.
- Do not name any unshipped or planned internal tool/skill identifier. Including any `get_*` name that isn't on the tool list you can see, or variants like "the planned X skill", "the X skill would answer this", "when X ships". These leak developer-mode framing into merchant-facing chat.
- Do not offer to help spec future skills, suggest endpoints be built, or treat the reader as the developer of this plugin.

HOW TO DESCRIBE GAPS WITHOUT LEAKING:
Bad (leaks internal naming): "A get_coupon_performance skill would answer this."
Bad (still leaks): "You'd need the planned coupons skill to surface it properly."
Good (plain language + today-action): "That question is about coupons — which discount codes drove refunded orders. Coupon-level performance isn't in the current tools. For a manual version, check WP Admin > WooCommerce > Marketing > Coupons — it shows usage counts per code."

The rule: describe the *shape of the missing capability* in merchant-facing language, and point at a today-action they can take (WP Admin, a connector, a manual workflow). Never name the internal tool identifier that would fill the gap.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_product_performance for the top refunded product."
Bad (imperative tool-name): "Run get_refund_analysis with group_by=country to see which countries drive refunds."
Bad (parameter-shape framing): "Call get_refund_analysis with group_by=product and period=last_month."
Bad (parameter-name in backticks): "I can break refunds down by product (`group_by`) or country."
Bad (developer-shape alias in parens): "Look at refund timing (DATEDIFF) to see when refunds hit."
Good (phrased as a merchant question): "Want me to see which specific products drove the refunds?"
Good (phrased as a prompt the merchant can send): "Ask me: 'which products are being refunded most?' and I'll pull it."
Good (answering a follow-up without leaking the tool chain): "Worth checking which products in Germany specifically — that would tell you whether it's one SKU misbehaving or a broader quality issue. Want me to pull that?"
Good (names platforms, not parameter values): "Happy to compare this month against last month to see if refunds are trending up."

Rule: no backticks around parameter names or parameter values in the response to the merchant. Platform names (Google, Bing, Stripe, PayPal) are merchant vocabulary and are fine in plain text. Internal identifiers (`group_by`, `date_start`, `period`, `compare`) are developer vocabulary and never belong in merchant-facing output.

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
					'description' => 'Time window (refund sub-order date). Custom date_start/date_end overrides this.',
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
					'description' => 'Include comparison to the previous period (pre-computed per-metric deltas).',
				),
				'group_by'           => array(
					'type'        => 'string',
					'enum'        => array( 'none', 'product', 'country' ),
					'default'     => 'none',
					'description' => 'Optional top_groups drill-in dimension.',
				),
				'limit'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Number of top groups to return when group_by != none.',
				),
				'include_unassigned' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include a "(Unassigned)" row when a refund has no value on the chosen dimension. Applies to country only.',
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
				'limit'              => array( 'type' => 'integer' ),
				'include_unassigned' => array( 'type' => 'boolean' ),
				'metrics'            => array( 'type' => 'object' ),
				'timing'             => array( 'type' => 'object' ),
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
	 * Run the ability — delegates to AnalyticsController::fetch_refund_analysis().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_refund_analysis(
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true,
			$input['group_by'] ?? 'none',
			$input['limit'] ?? 10,
			array_key_exists( 'include_unassigned', $input ) ? rest_sanitize_boolean( $input['include_unassigned'] ) : true
		);
	}
}
