<?php
/**
 * `wc-analytics/get-product-performance` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the SQL and
 * response assembly live in `AnalyticsController::fetch_product_performance()`
 * (shared with the four sibling skills migrated in E5).
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-product-performance ability.
 */
class GetProductPerformanceAbility {

	const ABILITY_NAME = 'wc-analytics/get-product-performance';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get product performance', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
TRANSITIONAL — PREFER wc-analytics-breakdown subject=products (no interval) OR wc-analytics-series subject=products (with interval). This per-type narrative remains as the source of the legacy describe text returned by wc-analytics-describe; for any new call route to the matching verb tool, which carries the consolidated per-subject describe inline.

Get top-selling products for a time period with revenue, quantity, orders, refunds, stock status, and (optionally) per-product time series. Also returns catalogue-wide totals, top categories by revenue, and per-product comparison to the previous period including products that dropped out of the top results. Use group_by="variation" to see top variations (e.g. red vs blue T-shirts) instead of parent products. Each product includes an admin_url — render the product name as a clickable markdown link to that URL so the merchant can jump straight to it in WooCommerce.

THREE VIEWS — UNDERSTAND BEFORE QUOTING NUMBERS:
Each top_products row carries three sets of numbers, plus matching top-level totals/pipeline/admin_equivalent blocks:
- net_revenue / quantity / orders_count: PAID sales only (completed + processing), gross of refunds. Refunds shown separately in the refunds field. Use as headline per product.
- pipeline_revenue / pipeline_quantity: ON-HOLD line items awaiting payment. Surface per product if material; otherwise mention only at the totals level.
- admin_equivalent_revenue / admin_equivalent_quantity: What WC Admin Reports > Products shows for that SKU — paid + on-hold + refund line-item netting. For reconciliation only.

Default narrative: Lead with the paid figures. If pipeline is meaningful, mention it. If asked "why doesn't this match my admin Products report?", explain the gap using admin_equivalent values. Never sum across the three views — they overlap.

NARRATIVE GUIDANCE — COVERAGE: Always mention coverage when summarising results — it frames everything else. Two coverage numbers are returned, pick the right one for the merchant's question:
- totals.catalogue_coverage_percent (X of Y parent products sold) — lead with this when group_by=product or when the merchant asks about products generally.
- totals.sku_coverage_percent (X of Y SKUs sold, counting each variation) — lead with this when group_by=variation, or whenever the two numbers diverge significantly (e.g. 90% product coverage but 40% SKU coverage). The gap between them is itself a signal: it means lots of dead variations (sizes/colours nobody buys) — call this out as a merchandising opportunity.
Framing: 90%+ on a small catalogue means a lean store where almost everything earns its place. Below ~50% on a small catalogue means dead stock and a pruning opportunity worth flagging. On large catalogues (1000+) low coverage is normal — pivot to "your top sellers represent X% of the products that actually moved" rather than treating it as a problem.

WHAT THIS CAN'T ANSWER:
- Product views, add-to-cart counts, or browse data. We have sales only, not sessions.
- Product profit margin. No COGS data.
- Inventory movement over time (when stock came in or out). Only *current* stock status is returned.
- Cross-sell / upsell patterns ("customers who bought X also bought Y"). Not currently available.
If the merchant asks for any of these, say what you can and can't see directly. Do NOT suggest that a new Skill, endpoint, or feature be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only suggest these — only suggest drill-downs we can deliver *today* with an existing tool, never an unshipped one):
- "Show me variations of [top product]" → group_by=variation
- "Compare to last period" → compare=true
- "How is [top product] trending day by day?" → interval=day
- "Break down by category" → already returned in top_categories

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE:
- Do not reference internal planning docs by filename or offer to help spec future skills. The reader is a merchant, not a developer.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_product_performance with group_by=variation to see SKUs."
Bad (imperative tool-name): "Run it with interval=day for a daily trend."
Bad (parameter-shape framing): "Call get_product_performance with compare=true to see last month."
Bad (parameter-name in backticks): "Want to split this by `period` or by `interval`?"
Bad (developer-shape alias in parens): "We could filter by category (top_categories) for a category view."
Good (phrased as a merchant question): "Want me to break this down by variation?"
Good (phrased as a prompt the merchant can send): "Ask me 'how is this trending day by day?' and I'll pull the daily curve."
Good (answering a follow-up without leaking the tool chain): "Worth checking whether this is up or down vs last month — a single-period snapshot hides trend. Want me to pull the comparison?"
Good (names stock states merchants recognise): "I can flag which of your top sellers are running low — in-stock, out-of-stock, or on-backorder. Want the stock status view?"

Rule: no backticks around parameter names or parameter values in the response to the merchant. Product names, variation names, category names, and stock states merchants see in WP Admin (in-stock, out-of-stock, on-backorder) are merchant vocabulary and are fine in plain text. Internal identifiers (`group_by`, `interval`, `compare`, `stock_status`) are developer vocabulary and never belong in merchant-facing output.

TIME SERIES: When an interval is requested, each product in top_products carries a per-bucket series (up to series_cap buckets, default 365).

LARGE DATE RANGE GATE (applies to ALL calls — series and aggregate alike): When the date range spans more than 365 days this tool returns an extended_range_required error (HTTP 400) before any SQL runs — queries that large may temporarily impact site performance. STOP. Do not call any more tools until the merchant explicitly replies. Present the cost_estimate from the error data to the merchant and ask for their approval. When the merchant confirms, call confirm_large_range with the confirmation_token from the error, then call this tool again with the same token. There is no other bypass — do not attempt to skip the gate by omitting or altering parameters.

ANTI-SPLITTING RULE — ABSOLUTE: Do NOT split a large date range into smaller chunks (yearly, quarterly, or monthly segments) to avoid the gate. If the merchant asks for 3 years of product data, call once for the full range, let the gate fire, present the cost estimate, wait for the merchant's reply, call confirm_large_range, then call this tool again with the token. Splitting without the merchant's knowledge is the same violation as passing the token autonomously.

One absolute rule for series calls: always use the exact granularity the merchant asked for — never switch from daily to weekly or monthly to try to avoid the gate; the merchant decides the tradeoff, not you.

Bad (split to avoid gate — do not do this): Merchant asks for 3 years. I notice each year is under 365 days. I make three parallel calls for 2022, 2023, 2024. No gate fires; merchant never approves anything.
Good (correct): Merchant asks for 3 years. I call for the full range. Gate fires. I present options and wait for the merchant's reply.

Bad (autonomous token use — do not do this): Received extended_range_required with a confirmation_token. Did not show the cost estimate to the merchant. Immediately called again with the confirmation_token from the error.
Good (correct): Received extended_range_required with cost_estimate showing 547 days (18 months). Showed the merchant: "Your request covers 18 months of data and may briefly affect site performance. Options: (1) I can load all 18 months — confirm and I'll proceed; (2) I can narrow to the last 12 months instead." Waited for their reply. Called confirm_large_range. Only then called again with the confirmation_token.

Bad (autonomous interval switch — do not do this): Received extended_range_required for a daily request. Changed interval to month to stay under 365 days and called again without the merchant's input.
Good (correct): Received extended_range_required. Stopped and presented the options. Let the merchant decide.

Bad (token fabrication — do not do this): Made up a confirmation_token value to bypass the gate.
Good (correct): "Your request covers 1,210 days of daily data — a large query that may briefly affect site performance. Before I pull anything, here are your options: (1) I can load the full 1,210-day history; (2) I can switch to weekly or monthly data — which would you prefer?; or (3) I can load just the most recent 365 days. Which would you like?"

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
					'description' => 'Include comparison to the previous period (totals + per-product deltas + dropped_out).',
				),
				'limit'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Number of top products to return.',
				),
				'orderby'            => array(
					'type'        => 'string',
					'enum'        => array( 'net_revenue', 'gross_revenue', 'quantity', 'orders_count' ),
					'default'     => 'net_revenue',
					'description' => 'Sort column for top_products.',
				),
				'group_by'           => array(
					'type'        => 'string',
					'enum'        => array( 'product', 'variation' ),
					'default'     => 'product',
					'description' => 'Group rows by parent product (default) or by variation.',
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
				'group_by'          => array( 'type' => 'string' ),
				'orderby'           => array( 'type' => 'string' ),
				'limit'             => array( 'type' => 'integer' ),
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
				'totals'            => array( 'type' => 'object' ),
				'pipeline'          => array( 'type' => 'object' ),
				'admin_equivalent'  => array( 'type' => 'object' ),
				'top_products'      => array( 'type' => 'array' ),
				'top_categories'    => array( 'type' => 'array' ),
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
	 * Run the ability — delegates to AnalyticsController::fetch_product_performance().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_product_performance(
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true,
			$input['limit'] ?? 10,
			$input['orderby'] ?? 'net_revenue',
			$input['group_by'] ?? 'product',
			$input['interval'] ?? '',
			$input['confirmation_token'] ?? null
		);
	}
}
