<?php
/**
 * `wc-analytics/series` ability.
 *
 * Verb-shaped aggregate router for time-series analytics.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities;

use WooCommerce\CommerceAbilities\Analytics\AnalyticsService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the analytics series ability.
 */
class AnalyticsSeriesAbility {

	const ABILITY_NAME = 'wc-analytics/series';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get analytics series', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Consolidated multi-subject narrative; per-subject sections follow.
				'description'         => __(
					<<<'DESCRIPTION'
Time-series for one of two subjects — customers or products. Returns a per-bucket series with the chosen interval (day / week / month / auto). For headline aggregates use the totals tool; for "broken down by X" without a time axis use the breakdown tool; for "show me the actual records" use the rows tool.

UNIVERSAL RULES (the connector instructions carry these in full):
- Never quote internal field paths in merchant-facing text. Field paths are for YOUR orientation; merchants see plain-English names.
- Never sum across views per bucket — paid / pipeline / admin_equivalent overlap.
- Never name internal tool identifiers, parameter names, or storage slugs in merchant-facing output. Phrase follow-ups as questions.
- Never propose new skills, endpoints, or features as a fix for a gap.
- Never derive bucket counts into period totals — buckets overlap on entities (a customer active in multiple buckets is counted once per bucket). Use the totals tool with the matching subject for the period total; use the series for change over time.

INTERVAL — auto-resolution and series cap:
- interval=auto picks day for ≤90-day ranges, week for ≤730 days, month otherwise. Use auto unless the merchant specifies the granularity.
- The response carries series_cap (the maximum bucket count for the chosen interval — typically 365 daily, 104 weekly, 60 monthly) and series_range_days (the requested range in days). When a series is truncated (cap reached before the range ends), surface that honestly; otherwise the bucket count equals the calendar buckets in the range.
- One absolute rule: always use the granularity the merchant asked for. Never switch from daily to weekly or monthly to try to avoid the gate; the merchant decides the tradeoff, not you.

365-DAY EXTENDED-RANGE GATE — applies to ALL calls (series and aggregate alike):
When the date range spans more than 365 days this tool returns an extended_range_required error (HTTP 400) before any SQL runs — queries that large may temporarily impact site performance. STOP. Do not call any more tools until the merchant explicitly replies. Present the cost_estimate from the error to the merchant and ask for their approval. When the merchant confirms, call wc-analytics-confirm-large-range with the same date_start, date_end, the literal type from cost_estimate.type (series-prefixed — e.g. series:customers — so approvals do not collide with totals or breakdown calls on the same subject), and a description of the query, then call this tool again. There is no other bypass — do not skip the gate by omitting or altering parameters.

ANTI-SPLITTING RULE — ABSOLUTE:
Do NOT split a large date range into smaller chunks (yearly, quarterly, monthly) to avoid the gate. If the merchant asks for 3 years of customer data, pull once for the full range, let the gate fire, present the cost estimate, wait for the merchant's reply, confirm-large-range, then call again. Splitting without the merchant's knowledge is the same violation as passing approval autonomously.

Bad (split to avoid gate — do not do this): Merchant asks for 3 years. I notice each year is under 365 days. I make three parallel calls for 2022, 2023, 2024. No gate fires; merchant never approves anything.
Good (correct): Merchant asks for 3 years. I call for the full range. Gate fires. I present options and wait for the merchant's reply.

Bad (autonomous interval switch — do not do this): Received extended_range_required for a daily request. Changed interval to month to stay under 365 days and called again without the merchant's input.
Good (correct): Received extended_range_required. Stopped and presented the options. Let the merchant decide.

Bad (autonomous approval — do not do this): Received extended_range_required. Did not show the cost estimate to the merchant. Immediately confirmed and called again.
Good (correct): Received extended_range_required with cost_estimate showing 730 days (24 months). Showed the merchant: "Your request covers 24 months of data and may briefly affect site performance. Options: (1) I can load all 24 months — confirm and I'll proceed; (2) I can narrow to the last 12 months instead." Waited for their reply.

================================================================================
SUBJECT = customers  → per-bucket new vs returning customer counts, orders, revenue, AOV per segment, and repeat rate. Same metrics as totals subject=customers, sliced by time bucket.
================================================================================

USE THIS WHEN the merchant asks a trend question: "how is my repeat rate changing month over month?", "is my customer base growing?", "are returning customers spending more per head now than they used to?".

PER-BUCKET ROW SHAPE: bucket (YYYY-MM-DD anchor — Monday for weeks, 1st for months), total_customers, new_customers, returning_customers, overlap_customers, new_customer_percent, returning_customer_percent, repeat_rate_percent, orders_count, new_customer_orders, returning_customer_orders, net_sales, new_customer_net_sales, returning_customer_net_sales, new_customer_spend_per_customer, returning_customer_spend_per_customer, pipeline_customers, pipeline_orders.

AOV and the dashboard-matching figure are NOT bucketed (AOV is mostly stationary on a given store; admin_equivalent is a dashboard-reconciliation lens, not a change-over-time story). For those, use totals subject=customers.

CRITICAL EDGE CASE — the returning_customer flag flip can fire WITHIN any bucket:
The flag is set at order creation and never updated. A customer placing their first AND second order in the same bucket counts once as new AND once as returning, appearing in both buckets within that row. series[i].overlap_customers carries the count of such overlap-customers per bucket — same field as totals subject=customers. If the merchant asks why "new + returning > total" within a bucket, that's the explanation.

PIPELINE IN SERIES — call out when material:
series[i].pipeline_customers / pipeline_orders show customers and orders awaiting payment in each bucket. Surface in the narrative when pipeline_customers is material in a bucket (≥5% of total_customers). A rising pipeline trend is a meaningful signal for merchants using bank transfer, BACS, cheque, or invoicing — it can indicate deteriorating AR collection or growing wholesale flow. For consumer / card-only stores pipeline will usually be ~0 across buckets and should be ignored in the narrative.

DON'T SUM BUCKET COUNTS to get a period total — a customer active in multiple buckets is counted once per bucket, so the sum overstates unique customers. Use totals subject=customers for the period total; use the series for change over time.

WHAT THIS CAN'T ANSWER (customers subject):
- Individual customer names, emails, addresses. Privacy rule.
- Lifetime customer value, cohort retention, time-between-orders. Those belong to totals subject=customer_value.
- Customers broken down by country, role, or first-order coupon. Not currently exposed.
- Churn rate or customer reactivation. Belongs to customer_value cohort retention.
- Per-customer time series. The series buckets reflect the full active base; not a per-customer view.

GOOD FOLLOW-UPS:
- "What's the period headline?" → wc-analytics-totals subject=customers
- "How valuable are they over their lifetime?" → wc-analytics-totals subject=customer_value
- "What channels brought new customers?" → wc-analytics-breakdown subject=attribution (already splits new vs returning per channel)
- "Show me specific orders from new customers" → wc-analytics-rows entity=orders

================================================================================
SUBJECT = products  → top-N products (or variations) with revenue, quantity, orders, refunds, plus a per-product per-bucket sub-series. Same row shape as breakdown subject=products, with the time axis added.
================================================================================

USE THIS WHEN the merchant asks "how is the top product trending day by day?" or "which products are growing month over month?". For the period-level top-N without a time axis, use breakdown subject=products.

INPUT — products subject only:
- group_by: 'product' (default) or 'variation'. Picks parent products vs variations as the row unit.
- limit: top-N to series, 1–50, default 10.
- orderby: ordering field for the top-N. Default net_revenue.

PER-PRODUCT SERIES SHAPE:
Each row in top_products carries the standard breakdown row (admin_url, three views, refunds, stock_status) PLUS a series array with up to series_cap buckets. Each bucket carries the product-level numbers for that interval. Reading the series is how you answer "is the top product trending up or down?" — not by comparing two single-period calls.

COVERAGE AT SERIES SCALE:
Same coverage signal as breakdown subject=products. totals.catalogue_coverage_percent and totals.sku_coverage_percent are computed across the whole period (not per-bucket). Coverage tends to be HIGHER at series scale than at narrow-period scale, because the wider the window the more SKUs get a chance to sell.

WHEN TO USE day vs week vs month:
- day: granularity for ≤90-day windows. Sees weekly cycles, weekend patterns, single-day spikes. Loses signal beyond ~90 days because daily noise dominates.
- week: balanced for 3-month to 2-year windows. Smooths weekend/weekday noise, surfaces seasonality.
- month: long-window narratives (≥2 years). Smooths everything below seasonal patterns.
- auto: pick this when the merchant didn't specify a granularity. Resolves to day / week / month based on range length.

DON'T SUM BUCKET QUANTITIES to derive period quantities — same rule as customers. A product appearing in multiple buckets contributes its full quantity to each bucket. Use breakdown subject=products for the period top-N total.

WHAT THIS CAN'T ANSWER (products subject):
- Product views, add-to-cart counts, or browse data. Sales only.
- Profit margin per product. No COGS data.
- Inventory movement over time (when stock came in or out). Only current stock status.
- Cross-sell / upsell patterns. Not exposed.
- Per-customer purchase history of a product. Use rows entity=orders with a product_id filter for individual orders.

GOOD FOLLOW-UPS:
- "Show me variations of the top product over time" → re-run with group_by=variation
- "What's the period top-N (no time axis)?" → wc-analytics-breakdown subject=products
- "Compare this product's performance to last quarter?" → re-run with different dates and compare=true
- "Which countries did this product sell into?" → wc-analytics-breakdown subject=revenue, dimension=country (filtered context — same period)
- "Show me the actual orders for the top product" → wc-analytics-rows entity=orders with a product_id filter

================================================================================
GLOBAL: how to phrase follow-ups, gaps, and storage vocabulary
================================================================================

When suggesting next steps, phrase drill-downs as merchant questions, never as tool invocations. The merchant invokes a tool by asking a question; you don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in this description (which YOU read), not in the response you write back to the merchant.

Bad (names the invocation): "The next call is wc-analytics-series with interval=month."
Bad (parameter-shape framing): "I'll re-run with group_by=variation."
Bad (parameter-name in backticks): "Want to split this by `interval` or by `compare`?"
Bad (developer-shape alias in parens): "We could filter by stock state (out_of_stock) for the dead variations."
Good (phrased as a merchant question): "Want me to look at this trend monthly instead of daily?"
Good (answers without leaking the tool chain): "Worth checking whether returning-customer spend is growing or shrinking — segment trends often explain overall revenue swings. Want me to pull that?"
Good (names dimensions / intervals merchants recognise): "I can switch this to a weekly or monthly view if the daily noise is hiding the trend."

Rule: no backticks around parameter names or values in merchant-facing output. Granularity names (daily, weekly, monthly), platform names (Google, Bing, Stripe), and stock states merchants see in WP Admin (in-stock, out-of-stock, on-backorder) are merchant vocabulary and are fine in plain text. Internal identifiers (interval, group_by, compare, series_cap) are developer vocabulary and never belong in merchant-facing output.

When describing a gap, name the SHAPE of the missing capability in merchant-facing language and point at a today-action (WP Admin, a connector, a manual workflow). Never name an internal tool identifier. Never propose new skills.

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess analytics figures. If the tool returns an error or empty data, tell the merchant you couldn't retrieve the data — do not fabricate numbers.
DESCRIPTION,
					'woocommerce-claude'
				),
				// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText
				'category'            => AnalyticsBootstrap::CATEGORY,
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
			'required'   => array( 'subject', 'interval' ),
			'properties' => array(
				'subject'    => array(
					'type'        => 'string',
					'enum'        => array( 'customers', 'products' ),
					'description' => "Which subject's time series to return.",
				),
				'interval'   => array(
					'type'        => 'string',
					'enum'        => array( 'day', 'week', 'month', 'auto' ),
					'description' => "Bucket size for the time series. 'auto' picks day/week/month based on range length (≤90 days → day; ≤730 → week; otherwise month).",
				),
				'group_by'   => array(
					'type'        => 'string',
					'enum'        => array( 'product', 'variation' ),
					'default'     => 'product',
					'description' => 'Products subject only — row grouping. Ignored for customers.',
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Products subject only — top-N products to series. Ignored for customers.',
				),
				'orderby'    => array(
					'type'        => 'string',
					'default'     => 'net_revenue',
					'description' => 'Products subject only — ordering field for the top-N. Ignored for customers.',
				),
				'period'     => array(
					'type'    => 'string',
					'enum'    => array(
						'today',
						'yesterday',
						'last_7_days',
						'last_30_days',
						'this_month',
						'last_month',
						'this_quarter',
						'this_year',
						'custom',
					),
					'default' => 'last_30_days',
				),
				'date_start' => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'date_end'   => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'compare'    => array(
					'type'    => 'boolean',
					'default' => true,
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
			'type' => 'object',
		);
	}

	/**
	 * Run the time-series subject through the matching analytics fetch method.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error Response payload.
	 */
	public static function execute( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$subject    = isset( $input['subject'] ) ? (string) $input['subject'] : '';
		$interval   = isset( $input['interval'] ) ? (string) $input['interval'] : '';
		$group_by   = isset( $input['group_by'] ) ? (string) $input['group_by'] : 'product';
		$limit      = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$orderby    = isset( $input['orderby'] ) ? (string) $input['orderby'] : 'net_revenue';
		$period     = isset( $input['period'] ) ? (string) $input['period'] : 'last_30_days';
		$date_start = $input['date_start'] ?? null;
		$date_end   = $input['date_end'] ?? null;
		$compare    = array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true;
		if ( ! in_array( $subject, array( 'customers', 'products' ), true ) ) {
			return new \WP_Error(
				'invalid_series_subject',
				"Unknown series subject: {$subject}.",
				array( 'status' => 400 )
			);
		}
		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );
		// Tool-prefixed type — see totals ability for the rationale.
		$gate_result = LargeRangeGate::check_run( $dates['start'], $dates['end'], 'series:' . $subject );
		if ( is_wp_error( $gate_result ) ) {
			return $gate_result;
		}
		$series_cap = $gate_result;
		$start_ms   = microtime( true );
		$result     = self::dispatch(
			$subject,
			$interval,
			$period,
			$date_start,
			$date_end,
			$compare,
			$limit,
			$orderby,
			$group_by,
			$series_cap
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		/**
		 * This action is documented in class-analytics-controller.php::fetch_revenue_summary().
		 * Verb-shaped tools add tool, subject, and shape metadata for telemetry.
		 *
		 * @since 0.1.0
		 */
		do_action(
			'woocommerce_claude_skill_executed',
			self::ABILITY_NAME,
			array(
				'tool'          => self::ABILITY_NAME,
				'subject'       => $subject,
				'shape'         => 'series',
				'duration_ms'   => (int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				'cache_hit'     => null,
				'rows_returned' => self::row_count_from( $result ),
				'date_start'    => $dates['start'],
				'date_end'      => $dates['end'],
				'interval'      => $interval,
				'bucket_count'  => AnalyticsService::calculate_bucket_count( $interval, $dates['start'], $dates['end'] ),
			)
		);

		return array_merge(
			array(
				'subject'  => $subject,
				'interval' => $interval,
			),
			$result
		);
	}

	/**
	 * Count the primary series rows returned by the delegated analytics method.
	 *
	 * @param array $result Response payload.
	 * @return int
	 */
	private static function row_count_from( $result ) {
		if ( isset( $result['top_products'] ) && is_array( $result['top_products'] ) ) {
			return count( $result['top_products'] );
		}

		if ( isset( $result['series'] ) && is_array( $result['series'] ) ) {
			return count( $result['series'] );
		}

		return 1;
	}

	/**
	 * Dispatch to the matching time-series analytics method.
	 *
	 * @param string $subject    Analytics subject slug.
	 * @param string $interval   Time-series bucket interval.
	 * @param string $period     Period shortcut.
	 * @param string $date_start Custom start date (YYYY-MM-DD), or null.
	 * @param string $date_end   Custom end date (YYYY-MM-DD), or null.
	 * @param bool   $compare    Include previous-period comparison.
	 * @param int    $limit      Top N products to return.
	 * @param string $orderby    Sort column.
	 * @param string $group_by   Product grouping dimension.
	 * @param int    $series_cap Series cap from the session gate check.
	 * @return array|\WP_Error Response payload.
	 */
	private static function dispatch( $subject, $interval, $period, $date_start, $date_end, $compare, $limit, $orderby, $group_by, $series_cap ) {
		switch ( $subject ) {
			case 'customers':
				return AnalyticsService::fetch_customer_overview(
					$period,
					$date_start,
					$date_end,
					$compare,
					$interval,
					null,
					$series_cap
				);

			case 'products':
				return AnalyticsService::fetch_product_performance(
					$period,
					$date_start,
					$date_end,
					$compare,
					$limit,
					$orderby,
					$group_by,
					$interval,
					null,
					$series_cap
				);

			default:
				return new \WP_Error(
					'invalid_series_subject',
					"Unknown series subject: {$subject}.",
					array( 'status' => 400 )
				);
		}
	}
}
