<?php
/**
 * `wc-analytics/get-orders-summary` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the SQL and
 * response assembly live in `AnalyticsController::fetch_orders_summary()`
 * (shared with the four sibling skills migrated in E5).
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

use HeyWoo\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-orders-summary ability.
 */
class GetOrdersSummaryAbility {

	const ABILITY_NAME = 'wc-analytics/get-orders-summary';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get orders summary', 'hey-woo' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
Get orders summary for a time period — order count, AOV, items per order, status breakdown, value distribution, day-and-hour heatmap (when customers buy), and multi-currency detection. Includes comparison to previous period with pre-computed percentage changes.

THREE VIEWS — UNDERSTAND BEFORE QUOTING NUMBERS:
You read three sets of numbers. Use them like this:
- PAID ORDERS — completed + processing only. The default headline; lead with this for any "how many orders" question. (API field path for your reference: metrics.orders_count / metrics.revenue / metrics.avg_order_value)
- PENDING ORDERS — on-hold orders awaiting payment. Surface separately when material (≥5% of paid orders_count or ≥5% of paid revenue) or when the merchant asks about on-hold orders. Don't lump into the paid figure. The pending block also carries diagnostic fields — see PIPELINE DIAGNOSTIC below. (API field path: pipeline.orders_count / pipeline.revenue)
- DASHBOARD-MATCHING ORDERS — paid + on-hold + refunded statuses summed straight. For reconciling against WC Admin > WooCommerce > Analytics > Orders ONLY. Quote this figure when the merchant asks why our numbers differ from the dashboard; never lead the response with it. (API field path: admin_equivalent.orders_count / admin_equivalent.revenue)

A separate status_breakdown block shows EVERY status including pending/failed — use that for "how many orders are stuck on hold / failing" questions, not the three views above.

Default narrative: lead with paid orders. Mention pending only when it's non-trivial or the merchant asks about it. The dashboard-matching figure is reconciliation-only — quote it when asked, but the headline is always paid orders.

NEVER QUOTE THE API FIELD PATHS IN MERCHANT-FACING TEXT — this is an absolute rule, not a "with caveat" rule:
- Field paths like metrics.orders_count, pipeline.revenue, admin_equivalent.orders_count are for YOUR orientation when picking which number to read. They are NOT names the merchant should see.
- Use the plain-English names ("paid orders", "pending orders", "the dashboard-matching figure") in responses.
- Same rule applies to diagnostic field names (pipeline.oldest_order_days, pipeline.age_buckets, pipeline.payment_methods) — narrate in English ("the oldest on-hold order is N days old", "here's the age distribution across 0-7 / 8-30 / 31-60 / 60+ days") rather than pasting field paths.

Bad (verbatim field-path dump as merchant explanation):
"metrics: PAID orders only (completed + processing).
pipeline: ON-HOLD orders awaiting payment.
admin_equivalent: paid + on-hold + refunded, lumped together."

Good (plain-English narration):
"You have X paid orders in the period (completed + processing), plus Y on-hold orders awaiting payment. The figure WC Admin shows is Z because it lumps those together with refunded orders."

PIPELINE DIAGNOSTIC — THE "IS THIS A REAL BACKLOG?" FIELDS:
The pipeline block carries three diagnostic fields on top of orders_count + revenue. Read them together — they tell the merchant whether on-hold orders are a fresh operational blip, an aging backlog, or a failing gateway:
- pipeline.oldest_order_days: age (in days) of the oldest on-hold order placed in the period. Zero-pipeline → null.
- pipeline.age_buckets: distribution across "0-7d" / "8-30d" / "31-60d" / "60d+". Each value is a count of on-hold orders in that bucket.
- pipeline.payment_methods: per-method rows, sorted by orders_count desc, each carrying orders_count, revenue, share_of_pipeline_revenue_percent, share_of_paid_revenue_percent, and pipeline_over_index_points.

Diagnostic interpretation:
- Freshness: if pipeline.age_buckets["60d+"] > 0 OR pipeline.oldest_order_days ≥ 30, call it out as an OPERATIONAL BACKLOG, not a fresh blip. Orders from 60+ days ago that nobody has chased means a process gap, not a one-off.
- Payment-method signal: card gateways (Stripe, PayPal, Square, Braintree, Adyen, Apple Pay) should NEVER sit on-hold — they either succeed or fail at checkout. A card gateway appearing in payment_methods is a failing-gateway signal. BACS / cheque / bank transfer / direct-debit legitimately take days to clear; on-hold on those methods is expected. Narrate accordingly.
- Over-index: pipeline_over_index_points on each payment-method row is `share_of_pipeline − share_of_paid`. Positive values mean this method over-contributes to stranded orders relative to its share of paid revenue. Card gateways with ANY positive value are a signal — they shouldn't have ANY pipeline. Manual-payment methods (BACS etc.) are expected to run very positive; that's not a red flag on its own.

WHAT THIS CAN'T ANSWER:
- Individual order details (customer names, contents, addresses). Use woocommerce-orders-list or the WC Admin for single-order lookup.
- Order fulfilment time or shipping delays. We don't track the time between status transitions.
- *Why specifically* an on-hold order hasn't been paid — the pipeline diagnostic surfaces age + method, not the customer-level reason. For single-order investigation, the WC Admin orders screen is the right place.
- Abandoned carts or checkout drop-off. Requires cart/session tracking that WooCommerce doesn't do.
If the merchant asks for any of these, say so directly and point at the right place. Do NOT suggest that a new Skill, endpoint, or feature be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only suggest these — only suggest drill-downs we can deliver *today* with an existing tool, never an unshipped one):
- "What products were in those orders?" → get_product_performance
- "What channels drove them?" → get_attribution
- "Is one channel driving a disproportionate share of the pipeline?" → get_attribution — this is the canonical channel-scoped pipeline diagnostic; reach for attribution whenever a merchant asks about pipeline by acquisition source. attribution's top_groups rows carry pipeline_over_index_points sibling to this skill's payment-method over-index. Pair the two when over-index fires on both axes for a full "which traffic source hitting which gateway" diagnosis.
- "Who placed them?" → get_customer_overview
- "Show me the specific on-hold orders" → woocommerce-orders-list

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE:
- Do not reference internal planning docs by filename or offer to help spec future skills. The reader is a merchant, not a developer.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_product_performance to see what's in those orders."
Bad (imperative tool-name): "Run get_orders_summary with compare=true to check last month."
Bad (parameter-shape framing): "Call get_attribution with period=last_month to see what drove them."
Bad (parameter-name in backticks): "Want me to split this by `compare` or by `period`?"
Bad (developer-shape alias in parens): "We could filter by status (wc-on-hold) to see only pipeline."
Good (phrased as a merchant question): "Want me to check what products were in those orders?"
Good (phrased as a prompt the merchant can send): "Ask me 'what channels drove these orders?' and I'll pull the attribution split."
Good (answering a follow-up without leaking the tool chain): "Worth comparing to last month — a 3× pipeline growth is usually a process signal. Want me to pull the comparison?"
Good (names payment families, not parameter values): "I can split these on-hold orders by payment method — BACS and cheque naturally take days to clear; cards should never sit here. Want that breakdown?"

Rule: no backticks around parameter names or parameter values in the response to the merchant. Payment-method and status names that merchants recognise (BACS, cheque, Stripe, PayPal, on-hold) are merchant vocabulary and are fine in plain text. Internal identifiers (`period`, `compare`, `wc-on-hold`, `status_breakdown`) are developer vocabulary and never belong in merchant-facing output.

STORAGE VOCABULARY IS NEVER MERCHANT-FACING — merchants see their payment methods by display name in WP Admin > WooCommerce > Settings > Payments ("Direct Bank Transfer", "PayPal", "Credit Card (Stripe)"). They don't see underlying slugs (bacs, bank_transfer, ppec_paypal). If the merchant's setup might use a non-standard gateway name, route them to the Admin display name they can look up — never to slug guessing.

Bad (backticked storage slugs): "BACS is sometimes stored as `bacs`, but some stores use `bank_transfer` or a custom gateway ID."
Bad (stored as + snake_case): "Your payment method slug might be stored as `ppec_paypal` rather than `paypal`."
Good (actionable WP Admin workflow): "The exact label depends on what's configured in WP Admin > WooCommerce > Settings > Payments — check there and tell me what name shows next to BACS, and I'll retry with that."
Good (steer to display name): "Different stores name their bank-transfer gateway differently — 'Direct Bank Transfer', 'Bank Transfer', or a custom label. Which one shows up in your checkout?"

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess analytics figures. If the tool returns an error or empty data, tell the merchant you couldn't retrieve the data — do not fabricate numbers.
DESCRIPTION,
					'hey-woo'
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
					'description' => 'Include comparison to the previous period (pre-computed percent deltas).',
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
				'metrics'            => array( 'type' => 'object' ),
				'pipeline'           => array( 'type' => 'object' ),
				'admin_equivalent'   => array( 'type' => 'object' ),
				'status_breakdown'   => array( 'type' => 'array' ),
				'value_distribution' => array( 'type' => 'array' ),
				'orders_by_day_hour' => array( 'type' => 'array' ),
				'multi_currency'     => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
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
	 * Run the ability — delegates to AnalyticsController::fetch_orders_summary().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_orders_summary(
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true
		);
	}
}
