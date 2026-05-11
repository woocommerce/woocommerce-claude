<?php
/**
 * `wc-analytics/get-customer-overview` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the SQL and
 * response assembly live in `AnalyticsController::fetch_customer_overview()`
 * (shared with the four sibling skills migrated in E5).
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-customer-overview ability.
 */
class GetCustomerOverviewAbility {

	const ABILITY_NAME = 'wc-analytics/get-customer-overview';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get customer overview', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
TRANSITIONAL — PREFER wc-analytics-totals subject=customers (no interval) OR wc-analytics-series subject=customers (with interval). This per-type narrative remains as the source of the legacy describe text returned by wc-analytics-describe; for any new call route to the matching verb tool, which carries the consolidated per-subject describe inline.

Get a period-scoped view of who's buying — new vs returning customer counts, orders, revenue, and AOV per segment, plus repeat rate. Includes comparison to the previous period with pre-computed deltas, and optional per-bucket time series via the interval parameter.

THREE VIEWS — UNDERSTAND BEFORE QUOTING NUMBERS:
- metrics: PAID orders only (processing + completed). total_customers = COUNT(DISTINCT customer_id) so no double-counting. new_customers and returning_customers are distinct counts by the returning_customer flag. Use as the headline.
- pipeline: Customers with ON-HOLD orders (awaiting payment — bank transfer, BACS, cheque, invoice). Surface if material; not yet paying customers.
- admin_equivalent: What WC Admin Reports > Customers shows (paid + on-hold + refunded; total_customers = new + returning, which can double-count). Use only for reconciliation against the admin dashboard.

CRITICAL EDGE CASE — the returning_customer flag is set at order creation and never updated:
- A customer's first-ever order has returning_customer=0 (new).
- Every subsequent order has returning_customer=1 (returning).
- If a customer places their first AND second order in the same period, they count once as new AND once as returning — appearing in both buckets.
- metrics.total_customers uses distinct counting so it's NOT the sum of new + returning when overlap exists.
- metrics.overlap_customers = (new + returning) - total_customers; non-zero means some customers flipped buckets within this period. If asked why "new + returning > total", that's the explanation.
- The same flag-flip can fire *within any bucket* of the time series too — series[i].overlap_customers carries the same field at bucket granularity.

KEY METRICS Claude should lead with:
- metrics.new_customers and metrics.returning_customers with new_customer_percent / returning_customer_percent alongside for context.
- metrics.repeat_rate_percent = returning / total. The merchant's "how sticky are my customers this period" number.
- Per-segment spend (new_customer_net_sales, returning_customer_net_sales) and AOV (new_customer_avg_order_value, returning_customer_avg_order_value). Returning customers typically have higher AOV — call out the gap if meaningful.
- Per-customer ratios: new_customer_orders_per_customer, returning_customer_orders_per_customer, new_customer_spend_per_customer, returning_customer_spend_per_customer. These differ from AOV — AOV is per-order, these are per-customer for the period (bake in order frequency). Returning customers often have higher spend-per-customer even when AOVs are identical, because they place more orders within the period. Use these to answer "do returning customers spend more than new?" directly.

NARRATIVE GUIDANCE — COMPARISON: comparison.changes carries pre-computed percent / amount / direction for every primary metric. Use those numbers directly; do not recompute. When direction=up/down, frame the change — growing new-customer base vs growing repeat base tell very different stories.

TIME SERIES (interval param):
Set interval=day|week|month|auto to get a per-bucket series — one row per bucket, paid-view plus a pipeline summary. Use this when the merchant asks a trend question: "how is my repeat rate changing month over month?", "is my customer base growing?", "are returning customers spending more per head now than they used to?". auto picks day for ≤31-day ranges, week for ≤92 days, month otherwise. Series is capped at a 365-day window by default. If interval is omitted, series is null — that is the no-series case, not an error.

LARGE DATE RANGE GATE (applies to ALL calls — series and aggregate alike): When the date range spans more than 365 days this tool returns an extended_range_required error (HTTP 400) before any SQL runs — queries that large may temporarily impact site performance. STOP. Do not call any more tools until the merchant explicitly replies. Present the cost_estimate from the error data to the merchant and ask for their approval. When the merchant confirms, call confirm_large_range with the confirmation_token from the error, then call this tool again with the same token. There is no other bypass — do not attempt to skip the gate by omitting or altering parameters.

ANTI-SPLITTING RULE — ABSOLUTE: Do NOT split a large date range into smaller chunks (yearly, quarterly, or monthly segments) to avoid the gate. If the merchant asks for 3 years of customer data, call once for the full range, let the gate fire, present the cost estimate, wait for the merchant's reply, call confirm_large_range, then call this tool again with the token. Splitting without the merchant's knowledge is the same violation as passing the token autonomously.

One absolute rule for series calls: always use the exact granularity the merchant asked for — never switch from daily to weekly or monthly to try to avoid the gate; the merchant decides the tradeoff, not you.

Bad (split to avoid gate — do not do this): Merchant asks for 3 years. I notice each year is under 365 days. I make three parallel calls for 2022, 2023, 2024. No gate fires; merchant never approves anything.
Good (correct): Merchant asks for 3 years. I call for the full range. Gate fires. I present options and wait for the merchant's reply.

Bad (autonomous token use — do not do this): Received extended_range_required with a confirmation_token. Did not show the cost estimate to the merchant. Immediately called again with the confirmation_token from the error.
Good (correct): Received extended_range_required with cost_estimate showing 730 days (24 months). Showed the merchant: "Your request covers 24 months of customer data and may briefly affect site performance. Options: (1) I can load all 24 months — confirm and I'll proceed; (2) I can narrow to the last 12 months instead." Waited for their reply. Called confirm_large_range. Only then called again with the confirmation_token.

Bad (token fabrication — do not do this): Made up a confirmation_token value to bypass the gate.

Series bucket shape (per row): bucket (YYYY-MM-DD anchor — Monday for weeks, 1st for months), total_customers, new_customers, returning_customers, overlap_customers, new_customer_percent, returning_customer_percent, repeat_rate_percent, orders_count, new_customer_orders, returning_customer_orders, net_sales, new_customer_net_sales, returning_customer_net_sales, new_customer_spend_per_customer, returning_customer_spend_per_customer, pipeline_customers, pipeline_orders. AOV and admin_equivalent are NOT bucketed (AOV is mostly stationary on a given store; admin_equivalent is a dashboard-reconciliation lens, not a change-over-time story).

Pipeline in series — call out when material: series[i].pipeline_customers / pipeline_orders show customers and orders awaiting payment in each bucket. Surface in the narrative when pipeline_customers is material in that bucket (≥5% of total_customers). A rising pipeline trend is a meaningful signal for merchants using bank transfer, BACS, cheque, or invoicing — it can indicate deteriorating AR collection or growing wholesale flow. For consumer / card-only stores pipeline will usually be ~0 across buckets and should be ignored in the narrative.

Don't sum bucket counts across the series to get a period total — a customer active in multiple buckets is counted once per bucket, so the sum overstates unique customers. Use top-level metrics.* for the period total; use the series for *change over time*.

WHAT THIS CAN'T ANSWER:
- Individual customer names, emails, addresses, or contact info. Privacy rule — aggregated data only. Point the merchant at WP Admin > WooCommerce > Customers for individual lookup.
- Lifetime customer value, cohort retention, time-between-orders, lifetime order count per customer. These are lifetime metrics, not period-scoped — they belong to get_customer_value (not yet shipped). When asked, say so directly and point at that skill.
- Customers broken down by country, state, city, zip, or company. Not currently exposed. If the merchant asks, say it's not available — do not propose building it.
- Customers by role (registered-user role). Not currently exposed.
- Customers by first-order coupon, product, or category. Not currently exposed.
- Churn rate or customer reactivation. Needs longitudinal analysis, not a single-period skill. Belongs to get_customer_value.
- Why a specific customer stopped ordering. Would need engagement / email / session data we don't have.

If the merchant asks for any of these, say what you can and can't see directly and honestly. Do NOT suggest that a new Skill, endpoint, or feature be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only suggest these — only suggest drill-downs we can deliver *today* with an existing tool, never an unshipped one):
- "Compare to the previous period" → compare=true (on by default)
- "How has this changed month over month?" → interval=month (or week / day / auto for other ranges)
- "What channels brought new customers?" → get_attribution (already splits new vs returning per channel)
- "Show me specific orders from new customers" → woocommerce-orders-list (no new/returning filter today, but returns dates/totals)

DO NOT SUGGEST AS A FOLLOW-UP (even if adjacent in merchant intent):
- "Who are my best customers over time?" / "top customers by spend" / "customer LTV" — these answer to a skill that is NOT yet shipped. If the merchant asks for this directly, say plainly what we can't show (aggregated customer view only, no lifetime-value ranking) and point them at WP Admin > WooCommerce > Customers for the individual ranked list. Do not volunteer this as a drill-down.
- Listing customer names, emails, or IDs — privacy rule
- Building a new Skill, feature, or endpoint — merchants can't action that
- Churn analysis or reactivation rate — not a single-period skill
- Breaking down by country, role, or first-order product — not currently available

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE — this is an absolute rule, not a "mention with caveat" rule:
- Do not reference `ANALYTICS-SKILLS-MAP.md`, `CAPABILITY-BOUNDARIES.md`, `MERCHANT-QUESTIONS.md`, or any internal planning doc.
- Do not name any unshipped or planned internal tool/skill identifier. Including any `get_*` name that isn't on the tool list you can see, or variants like "the planned X skill", "the X skill would answer this", "when X ships". These leak developer-mode framing into merchant-facing chat. If you catch yourself writing a tool-name-shaped phrase, stop and rewrite without it.
- Do not offer to help spec future skills, suggest endpoints be built, or treat the reader as the developer of this plugin.

HOW TO DESCRIBE GAPS WITHOUT LEAKING:
Bad (leaks internal naming): "A get_customer_value skill would answer this."
Bad (still leaks): "You'd need the planned get_customer_value skill to surface it properly."
Good (describes the capability in plain language, points at a today-action): "That question is about cohort retention — following specific customer groups over time to see when they come back. That longitudinal view isn't in the current tools. For a manual version you can export the customer list from WP Admin > WooCommerce > Customers with a date filter and pivot in a spreadsheet."

The rule: describe the *shape of the missing capability* in merchant-facing language (cohort retention, LTV ranking, churn analysis, etc.), and point at a today-action they can take (WP Admin, a connector, a manual workflow). Never name the internal tool identifier that would fill the gap.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_attribution to see what channels brought them."
Bad (imperative tool-name): "Run get_customer_overview with interval=month for a monthly trend."
Bad (parameter-shape framing): "Call get_customer_overview with period=this_month and compare=true."
Bad (parameter-name in backticks): "Want to split this by `interval` or by `compare`?"
Bad (developer-shape alias in parens): "We could filter by customer type (new_customers vs returning_customers)."
Good (phrased as a merchant question): "Want me to pull the channel breakdown — what drove these new customers?"
Good (phrased as a prompt the merchant can send): "Ask me 'how has this changed month over month?' and I'll pull the trend."
Good (answering a follow-up without leaking the tool chain): "Worth checking whether returning-customer spend is growing or shrinking — the segment split often explains overall revenue swings. Want me to pull the comparison?"
Good (names segments merchants recognise): "I can split this into new vs returning customers and compare AOV, revenue, and order counts per segment. Want that breakdown?"

Rule: no backticks around parameter names or parameter values in the response to the merchant. "New" and "returning" customer labels merchants see in WC Analytics are merchant vocabulary and are fine in plain text. Internal identifiers (`interval`, `compare`, `new_customer_net_sales`, `returning_customer_avg_order_value`, `overlap_customers`) are developer vocabulary and never belong in merchant-facing output.

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess analytics figures. Never sum values across the three views — they overlap. If the tool returns an error or empty data, tell the merchant you couldn't retrieve the data — do not fabricate numbers.
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
					'description' => 'Include comparison to the previous period (pre-computed percent deltas).',
				),
				'interval'           => array(
					'type'        => 'string',
					'enum'        => array( '', 'auto', 'day', 'week', 'month' ),
					'default'     => '',
					'description' => 'Time-series granularity. Empty string → no series. "auto" picks day/week/month based on date range length.',
				),
				'confirmation_token' => array(
					'type'        => 'string',
					'description' => 'Confirmation token from a prior extended_range_required response. Pass this only after the merchant has explicitly approved the large date range query. Do NOT pass it autonomously.',
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
				'period'            => array( 'type' => 'object' ),
				'currency'          => array( 'type' => 'string' ),
				'interval'          => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
				'series_cap'        => array(
					'oneOf' => array(
						array( 'type' => 'integer' ),
						array( 'type' => 'null' ),
					),
				),
				'series_range_days' => array(
					'oneOf' => array(
						array( 'type' => 'integer' ),
						array( 'type' => 'null' ),
					),
				),
				'metrics'           => array( 'type' => 'object' ),
				'pipeline'          => array( 'type' => 'object' ),
				'admin_equivalent'  => array( 'type' => 'object' ),
				'series'            => array(
					'oneOf' => array(
						array( 'type' => 'array' ),
						array( 'type' => 'null' ),
					),
				),
				'comparison'        => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
				'note'              => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
			),
		);
	}

	/**
	 * Run the ability — delegates to AnalyticsController::fetch_customer_overview().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_customer_overview(
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true,
			$input['interval'] ?? '',
			$input['confirmation_token'] ?? null
		);
	}
}
