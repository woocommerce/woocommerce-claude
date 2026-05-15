<?php
/**
 * Analytics data-access layer.
 *
 * Shared query + assembly logic for the analytics skills (revenue, orders,
 * products, customers, attribution). Ability classes in the shared package
 * are thin wrappers over the public `fetch_*` methods here; they own the JSON
 * schemas + permission checks, this class owns the SQL.
 *
 * Historically this lived in the WooCommerce for Claude plugin as a REST
 * controller-shaped helper. It remains directly callable through the plugin's
 * backwards-compatible AnalyticsController alias while new shared code calls
 * AnalyticsService.
 *
 * All responses return aggregated metrics only — no individual customer PII.
 * Uses direct SQL with its own caching layer (not WC core DataStore cache,
 * which has a cache-key bug — `TimeInterval::default_before()` includes
 * microseconds in the cache key, so the MD5 changes every request and the
 * cache never hits).
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Analytics;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Every $wpdb->prepare() call in this file builds variable-length IN-clause placeholders dynamically with $ph = implode(',', array_fill(0, count($values), '%s')) and supplies the matching values through array_merge() into the second arg. The interpolated $ph variables only ever contain %s tokens, never user input. PHPCS doesn't recognise this standard pattern for IN-clause prepares.

/**
 * Shared analytics data-access layer.
 *
 * Holds the SQL + response assembly for every analytics skill. Ability
 * classes are thin wrappers over the public fetch_* methods here.
 */
class AnalyticsService {

	/**
	 * Default maximum buckets in any time-series (interval-based) array.
	 * Covers a full year at daily granularity. Value is returned in the
	 * response as `series_cap` when an interval is set. LargeRangeGate uses
	 * the same 365-day threshold independently.
	 */
	const TIMESERIES_MAX_BUCKETS = 365;

	/**
	 * Get order statuses that count as revenue.
	 *
	 * Uses wc_get_is_paid_statuses() — WC's canonical answer to "which orders
	 * count as paid." Returns ['processing', 'completed'] by default, respects
	 * the woocommerce_order_is_paid_statuses filter for custom statuses.
	 *
	 * We tested both approaches (inclusion via paid statuses, exclusion via
	 * woocommerce_excluded_report_order_statuses). Only wc_get_is_paid_statuses()
	 * produces numbers that match the WC Analytics dashboard exactly.
	 *
	 * @return array Status slugs with 'wc-' prefix.
	 */
	public static function get_paid_statuses() {
		return array_map(
			function ( $status ) {
				return 'wc-' . $status;
			},
			wc_get_is_paid_statuses()
		);
	}

	/**
	 * Get pipeline order statuses — orders placed but not yet paid.
	 *
	 * On-hold is the canonical "awaiting payment" status (bank transfer,
	 * BACS, cheque, invoice). We return this as a separate `pipeline`
	 * block so merchants see paid revenue and pipeline as distinct numbers
	 * — WC Admin Reports lumps them together and overstates revenue for
	 * stores with significant invoice / wire-transfer flow.
	 *
	 * @return array Status slugs with 'wc-' prefix.
	 */
	private static function get_pipeline_statuses() {
		return array( 'wc-on-hold' );
	}

	/**
	 * Statuses that WC Admin Reports counts as "revenue" by default.
	 *
	 * WC Admin excludes [pending, failed, cancelled, auto-draft, trash,
	 * checkout-draft] and counts everything else. We compute an
	 * `admin_equivalent` aggregate using this set so merchants can
	 * reconcile against the dashboard they already trust — without us
	 * pretending the lumped figure is "the right number".
	 *
	 * @return array Status slugs with 'wc-' prefix.
	 */
	private static function get_admin_equivalent_statuses() {
		return array_unique(
			array_merge(
				self::get_paid_statuses(),
				self::get_pipeline_statuses(),
				array( 'wc-refunded' )
			)
		);
	}

	/**
	 * Get the date column to use for analytics queries.
	 *
	 * Reads the merchant's WC Analytics "Date type" setting.
	 * Options: date_created, date_paid, date_completed.
	 *
	 * @return string Column name.
	 */
	public static function get_date_column() {
		$date_type = get_option( 'woocommerce_date_type', 'date_created' );
		$allowed   = array( 'date_created', 'date_paid', 'date_completed' );
		return in_array( $date_type, $allowed, true ) ? $date_type : 'date_created';
	}

	/**
	 * Cache TTL in seconds (1 hour).
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Customer value cache TTL in seconds (1 hour).
	 */
	const CUSTOMER_VALUE_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Months after acquisition before a cohort is treated as mature.
	 */
	const MATURITY_THRESHOLD_MONTHS = 2;

	/**
	 * Count the primary variable-length rows in a result payload.
	 *
	 * Used to populate the rows_returned field in the
	 * woocommerce_claude_skill_executed telemetry hook. Recognised top-level
	 * keys: `top_groups` (revenue breakdown), `top_products` (product
	 * performance), and `rows` (query_analytics in rows mode). Skills that
	 * return a flat scalar result (revenue summary, orders summary, customer
	 * overview) don't carry any of these arrays, so we return 1 — the response
	 * is one "record". `rows` is null in query_analytics aggregate mode and
	 * the is_array() check below skips it correctly.
	 *
	 * @param array $result Assembled fetch_* result.
	 * @return int Row count.
	 */
	private static function count_result_rows( $result ) {
		foreach ( array( 'top_groups', 'top_products', 'rows' ) as $key ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
				return count( $result[ $key ] );
			}
		}
		return 1;
	}

	/**
	 * Calculate the number of time buckets for a given resolved interval and date range.
	 *
	 * @param string $resolved_interval 'day', 'week', 'month', or '' (no series).
	 * @param string $date_start        YYYY-MM-DD.
	 * @param string $date_end          YYYY-MM-DD.
	 * @return int|null Bucket count, or null when no interval is set.
	 */
	public static function calculate_bucket_count( $resolved_interval, $date_start, $date_end ) {
		if ( empty( $resolved_interval ) ) {
			return null;
		}
		$start = new \DateTime( $date_start );
		$end   = new \DateTime( $date_end );
		$days  = $start->diff( $end )->days + 1;

		switch ( $resolved_interval ) {
			case 'day':
				return $days;
			case 'week':
				return (int) ceil( $days / 7 );
			case 'month':
				return (int) ceil( $days / 30 );
			default:
				return null;
		}
	}

	/**
	 * Fetch the revenue-summary payload. Backs the
	 * `wc-analytics/get-revenue-summary` ability.
	 *
	 * @param string      $period     Period shortcut (last_30_days, etc.).
	 * @param string|null $date_start Custom start date (YYYY-MM-DD) — overrides period.
	 * @param string|null $date_end   Custom end date (YYYY-MM-DD) — overrides period.
	 * @param bool        $compare    Include previous-period comparison block.
	 * @return array Response payload.
	 */
	public static function fetch_revenue_summary( $period, $date_start, $date_end, $compare ) {
		$start = microtime( true );

		// Resolve date range.
		$dates = self::resolve_dates( $period, $date_start, $date_end );

		// Check cache. Include settings in key so changes invalidate cache.
		$cache_key = 'woocommerce_claude_revenue_' . md5(
			$dates['start'] . '_' . $dates['end'] . '_' . ( $compare ? '1' : '0' )
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Query current period.
		$metrics = self::query_revenue_metrics( $dates['start'], $dates['end'] );

		$currency = get_woocommerce_currency();

		// Split out the three views. `metrics` keeps its original principled
		// shape (paid revenue, gross of refunds, refunds reported separately).
		// `pipeline` and `admin_equivalent` are sibling blocks so callers can
		// see them without parsing the primary metrics differently.
		list( $primary, $pipeline, $admin_equivalent ) = self::split_revenue_views( $metrics );

		$result = array(
			'period'           => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'         => $currency,
			'metrics'          => $primary,
			'pipeline'         => $pipeline,
			'admin_equivalent' => $admin_equivalent,
			'comparison'       => null,
		);

		// No data note.
		if ( 0 === (int) $primary['orders_count'] ) {
			$result['note'] = 'No completed orders found for this date range.';
		}

		// Comparison period.
		if ( $compare ) {
			$prev_dates                                        = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_metrics                                      = self::query_revenue_metrics( $prev_dates['start'], $prev_dates['end'] );
			list( $prev_primary, $prev_pipeline, $prev_admin ) = self::split_revenue_views( $prev_metrics );
			$changes = self::calculate_changes( $primary, $prev_primary );

			$result['comparison'] = array(
				'period'           => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'metrics'          => $prev_primary,
				'pipeline'         => $prev_pipeline,
				'admin_equivalent' => $prev_admin,
				'changes'          => $changes,
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Run the revenue summary SQL query for a date range.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return array Metrics array.
	 */
	private static function query_revenue_metrics( $date_start, $date_end ) {
		global $wpdb;

		$table = $wpdb->prefix . 'wc_order_stats';

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		// One query, three views.
		//
		// PRIMARY (current `metrics`): paid parent orders + their refund sub-orders,
		// netted at the order level. This is the principled "what did paid customers
		// pay us, less refunds against those orders" figure. Matches our previous behaviour.
		//
		// PIPELINE: on-hold parent orders only (awaiting payment — bank transfer, BACS,
		// invoice, etc.). Reported separately so merchants don't conflate AR with revenue.
		//
		// ADMIN_EQUIVALENT: paid + on-hold + refunded parents + all refund sub-orders,
		// summed straight. Reproduces what WC Admin Reports shows so merchants can
		// reconcile against their dashboard. WHERE expanded to match — without it,
		// on-hold rows wouldn't be visible to the CASE WHEN aggregates.
		$sql = $wpdb->prepare(
			"SELECT
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS orders_count,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN net_total
				         WHEN parent_id != 0 THEN net_total
				         ELSE 0 END) AS net_sales,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN total_sales
				         WHEN parent_id != 0 THEN total_sales
				         ELSE 0 END) AS total_sales,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN num_items_sold ELSE 0 END) AS items_sold,
				ABS(SUM(CASE WHEN parent_id != 0 AND total_sales < 0 THEN total_sales ELSE 0 END)) AS refunds,
				SUM(CASE WHEN parent_id != 0 AND total_sales < 0 THEN 1 ELSE 0 END) AS refund_count,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN tax_total ELSE 0 END) AS taxes,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN shipping_total ELSE 0 END) AS shipping,
				COALESCE(
					SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND net_total > 0 THEN net_total ELSE 0 END) /
					NULLIF(SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND net_total > 0 THEN 1 ELSE 0 END), 0),
					0
				) AS average_order_value,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN customer_id END) AS total_customers,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN net_total ELSE 0 END) AS pipeline_revenue,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) THEN net_total
				         WHEN parent_id != 0 THEN net_total
				         ELSE 0 END) AS admin_revenue,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) THEN 1 ELSE 0 END) AS admin_orders
			FROM {$table}
			WHERE {$date_column} >= %s
				AND {$date_column} <= %s
				AND ( status IN ({$admin_ph}) OR parent_id != 0 )",
			array_merge(
				$paid_statuses, // orders_count.
				$paid_statuses, // net_sales primary leg.
				$paid_statuses, // total_sales primary leg.
				$paid_statuses, // items_sold.
				$paid_statuses, // taxes.
				$paid_statuses, // shipping.
				$paid_statuses, // AOV numerator.
				$paid_statuses, // AOV denominator.
				$paid_statuses, // total_customers.
				$pipeline_statuses, // pipeline_revenue.
				$pipeline_statuses, // pipeline_orders.
				$admin_statuses,    // admin_revenue.
				$admin_statuses,    // admin_orders.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses     // WHERE.
			)
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return self::empty_metrics();
		}

		return array(
			'net_sales'           => round( (float) $row['net_sales'], 2 ),
			'total_sales'         => round( (float) $row['total_sales'], 2 ),
			'orders_count'        => (int) $row['orders_count'],
			'items_sold'          => (int) $row['items_sold'],
			'average_order_value' => round( (float) $row['average_order_value'], 2 ),
			'refunds'             => round( (float) $row['refunds'], 2 ),
			'refund_count'        => (int) $row['refund_count'],
			'taxes'               => round( (float) $row['taxes'], 2 ),
			'shipping'            => round( (float) $row['shipping'], 2 ),
			'total_customers'     => (int) $row['total_customers'],
			// Internal: used by get_revenue() to build the pipeline + admin_equivalent blocks.
			'_pipeline_revenue'   => round( (float) $row['pipeline_revenue'], 2 ),
			'_pipeline_orders'    => (int) $row['pipeline_orders'],
			'_admin_revenue'      => round( (float) $row['admin_revenue'], 2 ),
			'_admin_orders'       => (int) $row['admin_orders'],
		);
	}

	/**
	 * Return zeroed metrics for empty date ranges.
	 */
	private static function empty_metrics() {
		return array(
			'net_sales'           => 0.00,
			'total_sales'         => 0.00,
			'orders_count'        => 0,
			'items_sold'          => 0,
			'average_order_value' => 0.00,
			'refunds'             => 0.00,
			'refund_count'        => 0,
			'taxes'               => 0.00,
			'shipping'            => 0.00,
			'total_customers'     => 0,
			'_pipeline_revenue'   => 0.00,
			'_pipeline_orders'    => 0,
			'_admin_revenue'      => 0.00,
			'_admin_orders'       => 0,
		);
	}

	/**
	 * Split the internal metrics row into three public-facing blocks.
	 *
	 * Strips internal `_pipeline_*` / `_admin_*` keys from `metrics`, builds
	 * self-describing `pipeline` and `admin_equivalent` blocks with a
	 * `definition` string each so the AI doesn't have to guess what the
	 * number means.
	 *
	 * @param array $row Raw metrics row from query_revenue_metrics.
	 * @return array [ primary, pipeline, admin_equivalent ].
	 */
	private static function split_revenue_views( $row ) {
		$primary = $row;
		unset(
			$primary['_pipeline_revenue'],
			$primary['_pipeline_orders'],
			$primary['_admin_revenue'],
			$primary['_admin_orders']
		);

		$pipeline = array(
			'revenue'      => isset( $row['_pipeline_revenue'] ) ? (float) $row['_pipeline_revenue'] : 0.00,
			'orders_count' => isset( $row['_pipeline_orders'] ) ? (int) $row['_pipeline_orders'] : 0,
			'definition'   => 'On-hold orders: placed but awaiting payment (bank transfer, BACS, cheque, invoice). Not yet collected revenue.',
		);

		$admin_equivalent = array(
			'revenue'      => isset( $row['_admin_revenue'] ) ? (float) $row['_admin_revenue'] : 0.00,
			'orders_count' => isset( $row['_admin_orders'] ) ? (int) $row['_admin_orders'] : 0,
			'definition'   => 'What WC Admin Reports shows: paid + on-hold + refunded statuses, with refunds netted at the order level. Use for reconciliation against the admin dashboard.',
		);

		return array( $primary, $pipeline, $admin_equivalent );
	}

	// ─── Orders Summary ───────────────────────────────────────────

	/**
	 * Fetch the orders-summary payload. Backs the
	 * `wc-analytics/get-orders-summary` ability.
	 *
	 * Returns order-focused metrics: counts, AOV, items per order,
	 * status breakdown, value distribution, and multi-currency detection.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start date — overrides period.
	 * @param string|null $date_end   Custom end date — overrides period.
	 * @param bool        $compare    Include previous-period comparison.
	 * @return array Response payload.
	 */
	public static function fetch_orders_summary( $period, $date_start, $date_end, $compare ) {
		$start = microtime( true );

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		$cache_key = 'woocommerce_claude_orders_' . md5(
			$dates['start'] . '_' . $dates['end'] . '_' . ( $compare ? '1' : '0' )
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$metrics            = self::query_order_metrics( $dates['start'], $dates['end'] );
		$status_breakdown   = self::query_status_breakdown( $dates['start'], $dates['end'] );
		$value_distribution = self::query_value_distribution( $dates['start'], $dates['end'] );
		$orders_by_day_hour = self::query_orders_by_day_hour( $dates['start'], $dates['end'] );
		$multi_currency     = self::detect_multi_currency( $dates['start'], $dates['end'] );

		$currency = get_woocommerce_currency();

		list( $primary, $pipeline, $admin_equivalent ) = self::split_order_views( $metrics );

		// Fold pipeline diagnostic fields (age distribution + payment
		// methods with paid-baseline comparison) into the existing
		// pipeline block. Skip the queries entirely when there's nothing
		// on-hold — zero diagnostic value, and spares the DATEDIFF +
		// payment-method JOIN on stores that don't use pipeline workflows.
		if ( $pipeline['orders_count'] > 0 ) {
			$age_distribution              = self::query_pipeline_age_distribution( $dates['start'], $dates['end'] );
			$pipeline['oldest_order_days'] = $age_distribution['oldest_order_days'];
			$pipeline['age_buckets']       = $age_distribution['age_buckets'];
			$pipeline['payment_methods']   = self::query_pipeline_payment_methods( $dates['start'], $dates['end'] );
		} else {
			$pipeline['oldest_order_days'] = null;
			$pipeline['age_buckets']       = array(
				'0-7d'   => 0,
				'8-30d'  => 0,
				'31-60d' => 0,
				'60d+'   => 0,
			);
			$pipeline['payment_methods']   = array();
		}

		$result = array(
			'period'             => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'           => $currency,
			'metrics'            => $primary,
			'pipeline'           => $pipeline,
			'admin_equivalent'   => $admin_equivalent,
			'status_breakdown'   => $status_breakdown,
			'value_distribution' => $value_distribution,
			'orders_by_day_hour' => $orders_by_day_hour,
			'multi_currency'     => $multi_currency,
			'comparison'         => null,
			'note'               => null,
		);

		if ( 0 === (int) $primary['orders_count'] ) {
			$result['note'] = 'No completed orders found for this date range.';
		}

		if ( $compare ) {
			$prev_dates                                        = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_metrics                                      = self::query_order_metrics( $prev_dates['start'], $prev_dates['end'] );
			list( $prev_primary, $prev_pipeline, $prev_admin ) = self::split_order_views( $prev_metrics );
			$changes = self::calculate_order_changes( $primary, $prev_primary );

			$result['comparison'] = array(
				'period'           => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'metrics'          => $prev_primary,
				'pipeline'         => $prev_pipeline,
				'admin_equivalent' => $prev_admin,
				'changes'          => $changes,
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Query order summary metrics for a date range.
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array Tuple of [primary, pipeline, admin_equivalent] metric arrays.
	 */
	private static function query_order_metrics( $date_start, $date_end ) {
		global $wpdb;

		$table             = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		// Same three-view pattern as query_revenue_metrics. See that method's
		// comment for the rationale. Primary metrics restrict to paid parent
		// orders. Pipeline tracks on-hold (awaiting payment). Admin equivalent
		// reproduces WC Admin's order count + net revenue for reconciliation.
		$sql = $wpdb->prepare(
			"SELECT
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS orders_count,
				COALESCE(
					SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND net_total > 0 THEN net_total ELSE 0 END) /
					NULLIF(SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND net_total > 0 THEN 1 ELSE 0 END), 0),
					0
				) AS avg_order_value,
				COALESCE(
					SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN num_items_sold ELSE 0 END) /
					NULLIF(SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN 1 ELSE 0 END), 0),
					0
				) AS avg_items_per_order,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN num_items_sold ELSE 0 END) AS total_items_sold,
				COUNT(DISTINCT CASE WHEN parent_id != 0 AND total_sales < 0 THEN parent_id END) AS orders_with_refunds,
				SUM(CASE WHEN parent_id != 0 AND total_sales < 0 THEN 1 ELSE 0 END) AS refund_count,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN net_total ELSE 0 END) AS pipeline_revenue,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) THEN 1 ELSE 0 END) AS admin_orders,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) THEN net_total
				         WHEN parent_id != 0 THEN net_total
				         ELSE 0 END) AS admin_revenue
			FROM {$table}
			WHERE {$date_column} >= %s
				AND {$date_column} <= %s
				AND ( status IN ({$admin_ph}) OR parent_id != 0 )",
			array_merge(
				$paid_statuses, // orders_count.
				$paid_statuses, // AOV numerator.
				$paid_statuses, // AOV denominator.
				$paid_statuses, // avg_items numerator.
				$paid_statuses, // avg_items denominator.
				$paid_statuses, // total_items_sold.
				$pipeline_statuses, // pipeline_orders.
				$pipeline_statuses, // pipeline_revenue.
				$admin_statuses,    // admin_orders.
				$admin_statuses,    // admin_revenue.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses     // WHERE.
			)
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return self::empty_order_metrics();
		}

		return array(
			'orders_count'        => (int) $row['orders_count'],
			'avg_order_value'     => round( (float) $row['avg_order_value'], 2 ),
			'avg_items_per_order' => round( (float) $row['avg_items_per_order'], 1 ),
			'total_items_sold'    => (int) $row['total_items_sold'],
			'orders_with_refunds' => (int) $row['orders_with_refunds'],
			'refund_count'        => (int) $row['refund_count'],
			'_pipeline_orders'    => (int) $row['pipeline_orders'],
			'_pipeline_revenue'   => round( (float) $row['pipeline_revenue'], 2 ),
			'_admin_orders'       => (int) $row['admin_orders'],
			'_admin_revenue'      => round( (float) $row['admin_revenue'], 2 ),
		);
	}

	/**
	 * Return zeroed order metrics for empty date ranges.
	 */
	private static function empty_order_metrics() {
		return array(
			'orders_count'        => 0,
			'avg_order_value'     => 0.00,
			'avg_items_per_order' => 0.0,
			'total_items_sold'    => 0,
			'orders_with_refunds' => 0,
			'refund_count'        => 0,
			'_pipeline_orders'    => 0,
			'_pipeline_revenue'   => 0.00,
			'_admin_orders'       => 0,
			'_admin_revenue'      => 0.00,
		);
	}

	/**
	 * Split order metrics into primary + pipeline + admin_equivalent blocks.
	 * Mirror of split_revenue_views for the orders endpoint.
	 *
	 * @param array $row Raw metrics row from query_order_metrics.
	 * @return array [ primary, pipeline, admin_equivalent ].
	 */
	private static function split_order_views( $row ) {
		$primary = $row;
		unset(
			$primary['_pipeline_orders'],
			$primary['_pipeline_revenue'],
			$primary['_admin_orders'],
			$primary['_admin_revenue']
		);

		$pipeline = array(
			'orders_count' => isset( $row['_pipeline_orders'] ) ? (int) $row['_pipeline_orders'] : 0,
			'revenue'      => isset( $row['_pipeline_revenue'] ) ? (float) $row['_pipeline_revenue'] : 0.00,
			'definition'   => 'On-hold orders: placed but awaiting payment (bank transfer, BACS, cheque, invoice). Not yet collected revenue.',
		);

		$admin_equivalent = array(
			'orders_count' => isset( $row['_admin_orders'] ) ? (int) $row['_admin_orders'] : 0,
			'revenue'      => isset( $row['_admin_revenue'] ) ? (float) $row['_admin_revenue'] : 0.00,
			'definition'   => 'What WC Admin Reports shows: paid + on-hold + refunded statuses, with refunds netted at the order level. Use for reconciliation against the admin dashboard.',
		);

		return array( $primary, $pipeline, $admin_equivalent );
	}

	/**
	 * Age distribution of on-hold parent orders placed in the period.
	 *
	 * Buckets by `DATEDIFF(NOW(), date_created)` — ages are measured from
	 * when the order was placed to right now, not to the end of the
	 * period. So a period=last_month call on a merchant reviewing their
	 * pipeline in late April returns age buckets that reflect how stale
	 * March orders are TODAY. That matches shot 53's diagnostic intent:
	 * "are these fresh (recent config blip) or old (real backlog)?"
	 *
	 * Period filter still applies — on-hold orders placed BEFORE the
	 * period's start aren't included. A merchant asking about older
	 * backlogs needs to widen the period (e.g. period=this_year).
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array { oldest_order_days: int|null, age_buckets: array }
	 */
	private static function query_pipeline_age_distribution( $date_start, $date_end ) {
		global $wpdb;

		$table             = $wpdb->prefix . 'wc_order_stats';
		$pipeline_statuses = self::get_pipeline_statuses();
		$pipeline_ph       = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$date_column       = self::get_date_column();

		$sql = $wpdb->prepare(
			"SELECT DATEDIFF(NOW(), date_created) AS age_days
			FROM {$table}
			WHERE parent_id = 0
				AND status IN ({$pipeline_ph})
				AND {$date_column} >= %s
				AND {$date_column} <= %s",
			array_merge(
				$pipeline_statuses,
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$buckets = array(
			'0-7d'   => 0,
			'8-30d'  => 0,
			'31-60d' => 0,
			'60d+'   => 0,
		);

		if ( ! $rows ) {
			return array(
				'oldest_order_days' => null,
				'age_buckets'       => $buckets,
			);
		}

		$oldest = 0;
		foreach ( $rows as $row ) {
			$age = (int) $row['age_days'];
			if ( $age < 0 ) {
				$age = 0;
			}
			if ( $age > $oldest ) {
				$oldest = $age;
			}

			if ( $age <= 7 ) {
				++$buckets['0-7d'];
			} elseif ( $age <= 30 ) {
				++$buckets['8-30d'];
			} elseif ( $age <= 60 ) {
				++$buckets['31-60d'];
			} else {
				++$buckets['60d+'];
			}
		}

		return array(
			'oldest_order_days' => $oldest,
			'age_buckets'       => $buckets,
		);
	}

	/**
	 * Payment-method breakdown of on-hold parent orders + comparison
	 * against each method's share of paid revenue.
	 *
	 * Returns one row per payment method that has ≥1 pipeline order,
	 * each carrying both its share of PIPELINE revenue and its share
	 * of PAID revenue. The delta (`pipeline_over_index_points`)
	 * surfaces the diagnostic signal from shot 53: a card gateway
	 * (Stripe, PayPal, Square) sitting on pipeline is a failing-gateway
	 * signal (cards should never hang on-hold — they either succeed
	 * or fail at checkout), whereas BACS / cheque / bank-transfer
	 * legitimately take days to clear.
	 *
	 * Same CASE-based pipeline-vs-paid aggregate as the attribution
	 * three-view pattern, grouped by payment method. HPOS detection
	 * routes through `hpos_enabled()` — HPOS reads from `wc_orders`
	 * (dedicated columns), classic reads from postmeta pairs.
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array List of rows sorted by orders_count DESC, capped
	 *               at 10. Empty when there are no pipeline orders.
	 */
	private static function query_pipeline_payment_methods( $date_start, $date_end ) {
		global $wpdb;

		$os_table          = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$paid_ph           = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph       = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$all_statuses      = array_merge( $paid_statuses, $pipeline_statuses );
		$all_ph            = implode( ', ', array_fill( 0, count( $all_statuses ), '%s' ) );
		$date_column       = self::get_date_column();

		if ( self::hpos_enabled() ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$join         = "LEFT JOIN {$orders_table} wo ON wo.id = {$os_table}.order_id";
			$label_expr   = "COALESCE(NULLIF(wo.payment_method_title, ''), NULLIF(wo.payment_method, ''))";
			$slug_expr    = 'wo.payment_method';
		} else {
			$join       = "LEFT JOIN {$wpdb->postmeta} pm_title
					ON pm_title.post_id = {$os_table}.order_id
					AND pm_title.meta_key = '_payment_method_title'
				LEFT JOIN {$wpdb->postmeta} pm_slug
					ON pm_slug.post_id = {$os_table}.order_id
					AND pm_slug.meta_key = '_payment_method'";
			$label_expr = "COALESCE(NULLIF(pm_title.meta_value, ''), NULLIF(pm_slug.meta_value, ''))";
			$slug_expr  = 'pm_slug.meta_value';
		}

		$sql = $wpdb->prepare(
			"SELECT
				{$label_expr} AS method_label,
				{$slug_expr}  AS method_key,
				SUM(CASE WHEN {$os_table}.status IN ({$pipeline_ph}) THEN {$os_table}.net_total ELSE 0 END) AS pipeline_revenue,
				SUM(CASE WHEN {$os_table}.status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders,
				SUM(CASE WHEN {$os_table}.status IN ({$paid_ph}) THEN {$os_table}.net_total ELSE 0 END) AS paid_revenue,
				SUM(CASE WHEN {$os_table}.status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS paid_orders
			FROM {$os_table}
			{$join}
			WHERE {$os_table}.parent_id = 0
				AND {$os_table}.status IN ({$all_ph})
				AND {$os_table}.{$date_column} >= %s
				AND {$os_table}.{$date_column} <= %s
			GROUP BY method_label, method_key",
			array_merge(
				$pipeline_statuses, // pipeline_revenue.
				$pipeline_statuses, // pipeline_orders.
				$paid_statuses,     // paid_revenue.
				$paid_statuses,     // paid_orders.
				$all_statuses,      // WHERE status IN.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		// Denominators use the full set (pre-filter) so the paid share
		// reflects the store's overall payment mix, not just methods
		// that also have pipeline. A card gateway with 72% of paid
		// revenue AND one stranded order is the diagnostic signal.
		$total_pipeline_revenue = 0.0;
		$total_paid_revenue     = 0.0;
		foreach ( $rows as $row ) {
			$total_pipeline_revenue += (float) $row['pipeline_revenue'];
			$total_paid_revenue     += (float) $row['paid_revenue'];
		}

		$out = array();
		foreach ( $rows as $row ) {
			$pipeline_orders = (int) $row['pipeline_orders'];
			if ( $pipeline_orders <= 0 ) {
				continue;
			}

			$pipeline_revenue  = round( (float) $row['pipeline_revenue'], 2 );
			$paid_revenue      = round( (float) $row['paid_revenue'], 2 );
			$label             = (string) $row['method_label'];
			$slug              = (string) $row['method_key'];
			$share_of_pipeline = ( $total_pipeline_revenue > 0 )
				? round( ( $pipeline_revenue / $total_pipeline_revenue ) * 100, 1 )
				: 0.0;
			$share_of_paid     = ( $total_paid_revenue > 0 )
				? round( ( $paid_revenue / $total_paid_revenue ) * 100, 1 )
				: 0.0;

			$out[] = array(
				'method'                            => '' !== $label ? $label : '(Unassigned)',
				'method_key'                        => $slug,
				'orders_count'                      => $pipeline_orders,
				'revenue'                           => $pipeline_revenue,
				'share_of_pipeline_revenue_percent' => $share_of_pipeline,
				'share_of_paid_revenue_percent'     => $share_of_paid,
				'pipeline_over_index_points'        => round( $share_of_pipeline - $share_of_paid, 1 ),
			);
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $b['orders_count'] <=> $a['orders_count'];
			}
		);

		return array_slice( $out, 0, 10 );
	}

	/**
	 * Query order count and revenue by status.
	 *
	 * Includes ALL statuses (not just paid) so the merchant can see
	 * their full order pipeline (on-hold, pending, failed, etc.).
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array Status breakdown rows.
	 */
	private static function query_status_breakdown( $date_start, $date_end ) {
		global $wpdb;

		$table       = $wpdb->prefix . 'wc_order_stats';
		$date_column = self::get_date_column();

		$sql = $wpdb->prepare(
			"SELECT status,
				COUNT(*) AS count,
				SUM(net_total) AS net_revenue
			FROM {$table}
			WHERE {$date_column} >= %s
				AND {$date_column} <= %s
				AND parent_id = 0
			GROUP BY status
			ORDER BY count DESC",
			$date_start . ' 00:00:00',
			$date_end . ' 23:59:59'
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'status'      => $row['status'],
					'count'       => (int) $row['count'],
					'net_revenue' => round( (float) $row['net_revenue'], 2 ),
				);
			},
			$rows
		);
	}

	/**
	 * Query order value distribution as histogram buckets.
	 *
	 * Dynamically calculates bucket boundaries from the data range.
	 * Returns up to 10 buckets. Only includes paid-status parent orders.
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array Histogram bucket rows.
	 */
	private static function query_value_distribution( $date_start, $date_end ) {
		global $wpdb;

		$table         = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses = self::get_paid_statuses();
		$placeholders  = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$date_column   = self::get_date_column();

		// Get min/max to compute bucket boundaries.
		$range_sql = $wpdb->prepare(
			"SELECT MIN(net_total) AS min_val, MAX(net_total) AS max_val, COUNT(*) AS total
			FROM {$table}
			WHERE {$date_column} >= %s AND {$date_column} <= %s
				AND parent_id = 0
				AND status IN ({$placeholders})",
			array_merge(
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$paid_statuses
			)
		);

		$range = $wpdb->get_row( $range_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $range || 0 === (int) $range['total'] ) {
			return array();
		}

		$min          = max( 0, floor( (float) $range['min_val'] ) );
		$max          = ceil( (float) $range['max_val'] );
		$bucket_count = min( 10, max( 3, (int) ceil( ( $max - $min ) / 50 ) ) );

		if ( $max <= $min ) {
			$bucket_count = 1;
		}

		$bucket_size = max( 1, ceil( ( $max - $min ) / $bucket_count ) );

		$currency_symbol = html_entity_decode( get_woocommerce_currency_symbol() );

		// Build CASE expression for bucketing.
		$cases = array();
		for ( $i = 0; $i < $bucket_count; $i++ ) {
			$lower = $min + ( $i * $bucket_size );
			$upper = $lower + $bucket_size;
			$label = $currency_symbol . number_format( $lower ) . '-' . $currency_symbol . number_format( $upper );
			if ( $i === $bucket_count - 1 ) {
				$cases[] = "WHEN net_total >= {$lower} THEN '{$label}'";
			} else {
				$cases[] = "WHEN net_total >= {$lower} AND net_total < {$upper} THEN '{$label}'";
			}
		}
		$case_expr = 'CASE ' . implode( ' ', $cases ) . " ELSE 'other' END";

		$dist_sql = $wpdb->prepare(
			"SELECT {$case_expr} AS bucket,
				COUNT(*) AS orders_count,
				SUM(net_total) AS total_revenue,
				AVG(net_total) AS avg_value
			FROM {$table}
			WHERE {$date_column} >= %s AND {$date_column} <= %s
				AND parent_id = 0
				AND status IN ({$placeholders})
			GROUP BY bucket
			ORDER BY MIN(net_total)",
			array_merge(
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$paid_statuses
			)
		);

		$rows = $wpdb->get_results( $dist_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'bucket'        => $row['bucket'],
					'orders_count'  => (int) $row['orders_count'],
					'total_revenue' => round( (float) $row['total_revenue'], 2 ),
					'avg_value'     => round( (float) $row['avg_value'], 2 ),
				);
			},
			$rows
		);
	}

	/**
	 * Detect multi-currency orders via HPOS wc_orders table.
	 *
	 * Returns null if single currency (normal case) or if HPOS not available.
	 * Returns array of { currency, orders_count, total } if multiple currencies found.
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array|null Multi-currency note + currency rows, or null when single currency.
	 */
	private static function detect_multi_currency( $date_start, $date_end ) {
		global $wpdb;

		$orders_table = $wpdb->prefix . 'wc_orders';

		// Check HPOS table exists.
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_table )
		);

		if ( $table_exists !== $orders_table ) {
			return null;
		}

		$sql = $wpdb->prepare(
			"SELECT currency, COUNT(*) AS orders_count, SUM(total_amount) AS total
			FROM {$orders_table}
			WHERE date_created_gmt >= %s AND date_created_gmt <= %s
				AND parent_order_id = 0
				AND type = 'shop_order'
			GROUP BY currency",
			$date_start . ' 00:00:00',
			$date_end . ' 23:59:59'
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		// Single currency or no data — not multi-currency.
		if ( ! $rows || count( $rows ) <= 1 ) {
			return null;
		}

		return array(
			'note'       => 'This store has orders in multiple currencies. Totals in metrics are in the base currency and may not reflect accurate cross-currency sums.',
			'currencies' => array_map(
				function ( $row ) {
					return array(
						'currency'     => $row['currency'],
						'orders_count' => (int) $row['orders_count'],
						'total'        => round( (float) $row['total'], 2 ),
					);
				},
				$rows
			),
		);
	}

	/**
	 * Query orders grouped by day of week and hour of day.
	 *
	 * Returns a heatmap-style array: each row is a day_of_week × hour_of_day
	 * combination with order count and revenue. Day names are human-readable
	 * (Monday–Sunday). Only includes paid-status parent orders.
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array Heatmap rows keyed by day and hour.
	 */
	private static function query_orders_by_day_hour( $date_start, $date_end ) {
		global $wpdb;

		$table         = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses = self::get_paid_statuses();
		$placeholders  = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$date_column   = self::get_date_column();

		$sql = $wpdb->prepare(
			"SELECT
				DAYOFWEEK({$date_column}) AS day_num,
				HOUR({$date_column}) AS hour_of_day,
				COUNT(*) AS orders_count,
				SUM(net_total) AS net_revenue
			FROM {$table}
			WHERE {$date_column} >= %s
				AND {$date_column} <= %s
				AND parent_id = 0
				AND status IN ({$placeholders})
			GROUP BY day_num, hour_of_day
			ORDER BY day_num, hour_of_day",
			array_merge(
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$paid_statuses
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		// MySQL DAYOFWEEK: 1=Sunday, 2=Monday, ..., 7=Saturday.
		$day_names = array(
			1 => 'Sunday',
			2 => 'Monday',
			3 => 'Tuesday',
			4 => 'Wednesday',
			5 => 'Thursday',
			6 => 'Friday',
			7 => 'Saturday',
		);

		return array_map(
			function ( $row ) use ( $day_names ) {
				return array(
					'day'          => $day_names[ (int) $row['day_num'] ] ?? 'Unknown',
					'hour'         => (int) $row['hour_of_day'],
					'orders_count' => (int) $row['orders_count'],
					'net_revenue'  => round( (float) $row['net_revenue'], 2 ),
				);
			},
			$rows
		);
	}

	/**
	 * Pre-compute percentage changes for order metrics.
	 *
	 * @param array $current  Current period metrics.
	 * @param array $previous Previous period metrics.
	 * @return array Per-key change rows with amount, percent, direction.
	 */
	private static function calculate_order_changes( $current, $previous ) {
		$compare_keys = array(
			'orders_count',
			'avg_order_value',
			'avg_items_per_order',
			'total_items_sold',
			'orders_with_refunds',
		);

		$changes = array();
		foreach ( $compare_keys as $key ) {
			$curr = (float) $current[ $key ];
			$prev = (float) $previous[ $key ];
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	// ─── Shared Helpers ───────────────────────────────────────────

	/**
	 * Resolve period shortcut or custom dates into start/end strings.
	 *
	 * Uses the WordPress timezone setting so "today" means today in the merchant's timezone.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start date (overrides period).
	 * @param string|null $date_end   Custom end date (overrides period).
	 * @return array { start: string, end: string, label: string }
	 */
	public static function resolve_dates( $period, $date_start = null, $date_end = null ) {
		// Custom dates override period.
		if ( ! empty( $date_start ) && ! empty( $date_end ) ) {
			return array(
				'start' => $date_start,
				'end'   => $date_end,
				'label' => 'Custom range',
			);
		}

		$tz  = wp_timezone();
		$now = new \DateTime( 'now', $tz );

		switch ( $period ) {
			case 'today':
				$start = $now->format( 'Y-m-d' );
				$end   = $start;
				$label = 'Today';
				break;

			case 'yesterday':
				$yesterday = ( clone $now )->modify( '-1 day' );
				$start     = $yesterday->format( 'Y-m-d' );
				$end       = $start;
				$label     = 'Yesterday';
				break;

			case 'last_7_days':
				$end   = $now->format( 'Y-m-d' );
				$start = ( clone $now )->modify( '-6 days' )->format( 'Y-m-d' );
				$label = 'Last 7 days';
				break;

			case 'last_30_days':
			default:
				$end   = $now->format( 'Y-m-d' );
				$start = ( clone $now )->modify( '-29 days' )->format( 'Y-m-d' );
				$label = 'Last 30 days';
				break;

			case 'this_month':
				$start = $now->format( 'Y-m-01' );
				$end   = $now->format( 'Y-m-d' );
				$label = $now->format( 'F Y' );
				break;

			case 'last_month':
				$last_month = ( clone $now )->modify( 'first day of last month' );
				$start      = $last_month->format( 'Y-m-01' );
				$end        = $last_month->format( 'Y-m-t' );
				$label      = $last_month->format( 'F Y' );
				break;

			case 'this_quarter':
				$month   = (int) $now->format( 'n' );
				$q_start = ( (int) ( ( $month - 1 ) / 3 ) ) * 3 + 1;
				$start   = $now->format( 'Y-' ) . str_pad( $q_start, 2, '0', STR_PAD_LEFT ) . '-01';
				$end     = $now->format( 'Y-m-d' );
				$quarter = (int) ceil( $month / 3 );
				$label   = 'Q' . $quarter . ' ' . $now->format( 'Y' );
				break;

			case 'this_year':
				$start = $now->format( 'Y-01-01' );
				$end   = $now->format( 'Y-m-d' );
				$label = $now->format( 'Y' );
				break;
		}

		return array(
			'start' => $start,
			'end'   => $end,
			'label' => $label,
		);
	}

	/**
	 * Calculate the previous period of the same length.
	 *
	 * @param string $start YYYY-MM-DD.
	 * @param string $end   YYYY-MM-DD.
	 * @return array { start: string, end: string }
	 */
	public static function get_previous_period( $start, $end ) {
		$start_dt = new \DateTime( $start );
		$end_dt   = new \DateTime( $end );
		$diff     = $start_dt->diff( $end_dt );
		$days     = $diff->days + 1; // inclusive.

		$prev_end   = ( clone $start_dt )->modify( '-1 day' );
		$prev_start = ( clone $prev_end )->modify( '-' . ( $days - 1 ) . ' days' );

		return array(
			'start' => $prev_start->format( 'Y-m-d' ),
			'end'   => $prev_end->format( 'Y-m-d' ),
		);
	}

	/**
	 * Pre-compute percentage changes between current and previous period.
	 * All comparisons are server-side — Claude reports, doesn't calculate.
	 *
	 * @param array $current  Current period metrics.
	 * @param array $previous Previous period metrics.
	 * @return array Changes per metric.
	 */
	private static function calculate_changes( $current, $previous ) {
		$compare_keys = array(
			'net_sales',
			'total_sales',
			'orders_count',
			'items_sold',
			'average_order_value',
			'refunds',
			'total_customers',
		);

		$changes = array();
		foreach ( $compare_keys as $key ) {
			$curr = (float) $current[ $key ];
			$prev = (float) $previous[ $key ];
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	// ─── Product Performance ──────────────────────────────────────

	/**
	 * Fetch the product-performance payload. Backs the
	 * `wc-analytics/get-product-performance` ability.
	 *
	 * Returns top-N products with aggregate metrics, catalogue totals,
	 * top categories, and (optionally) per-product time series.
	 * When compare=true, also pre-computes per-product deltas for products
	 * present in both periods, plus a dropped_out array.
	 *
	 * @param string      $period             Period shortcut.
	 * @param string|null $date_start         Custom start date — overrides period.
	 * @param string|null $date_end           Custom end date — overrides period.
	 * @param bool        $compare            Include previous-period comparison.
	 * @param int         $limit              Top N to return (1–50).
	 * @param string      $orderby            Sort column.
	 * @param string      $group_by           `product` or `variation`.
	 * @param string      $interval           Time-series granularity: '', 'auto', 'day', 'week', 'month'.
	 * @param string|null $confirmation_token  Token from a prior extended_range_required response.
	 * @param int|null    $series_cap_override Pre-cleared cap from get-data ability (skips internal gate).
	 * @return array|\WP_Error Response payload, or WP_Error if the large-range gate fires.
	 */
	public static function fetch_product_performance( $period, $date_start, $date_end, $compare, $limit, $orderby, $group_by, $interval, $confirmation_token = null, $series_cap_override = null ) {
		$start = microtime( true );
		$limit = (int) $limit;

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		// Resolve auto interval based on date range length.
		$resolved_interval = self::resolve_interval( $interval, $dates['start'], $dates['end'] );

		// Heavy-scan gate. When called through wc-analytics/get-data the gate was
		// already checked session-side and $series_cap_override carries the cleared
		// cap; skip the token-based gate in that case.
		if ( null !== $series_cap_override ) {
			$series_cap = (int) $series_cap_override;
		} else {
			$gate_result = \WooCommerce\CommerceAbilities\Abilities\LargeRangeGate::check(
				$dates['start'],
				$dates['end'],
				$confirmation_token
			);
			if ( is_wp_error( $gate_result ) ) {
				return $gate_result;
			}
			$series_cap = $gate_result;
		}

		$cache_key = 'woocommerce_claude_products_' . md5(
			$dates['start'] . '_' . $dates['end']
			. '_' . ( $compare ? '1' : '0' )
			. '_' . $limit
			. '_' . $orderby
			. '_' . $group_by
			. '_' . $resolved_interval
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$top            = self::query_top_products( $dates['start'], $dates['end'], $limit, $orderby, $group_by );
		$totals         = self::query_product_totals( $dates['start'], $dates['end'] );
		$catalogue_size = self::query_catalogue_size();
		$skus_size      = self::query_sellable_sku_count();
		$top_categories = self::query_top_categories( $dates['start'], $dates['end'], $limit );

		// Pull pipeline + admin_equivalent off the totals row before adding catalogue
		// coverage fields. Surface them as sibling blocks at the top level (mirrors
		// the revenue + orders endpoints).
		$totals_pipeline         = array(
			'revenue'    => isset( $totals['_pipeline_revenue'] ) ? (float) $totals['_pipeline_revenue'] : 0.00,
			'quantity'   => isset( $totals['_pipeline_quantity'] ) ? (int) $totals['_pipeline_quantity'] : 0,
			'definition' => 'On-hold orders: items sold awaiting payment. Per-product pipeline values are on each top_products row.',
		);
		$totals_admin_equivalent = array(
			'revenue'    => isset( $totals['_admin_revenue'] ) ? (float) $totals['_admin_revenue'] : 0.00,
			'quantity'   => isset( $totals['_admin_quantity'] ) ? (int) $totals['_admin_quantity'] : 0,
			'definition' => 'What WC Admin Reports > Products shows: paid + on-hold + refunded with refund-line netting at the SKU level. Per-product admin-equivalent values are on each top_products row. Use for dashboard reconciliation.',
		);
		unset(
			$totals['_pipeline_revenue'],
			$totals['_pipeline_quantity'],
			$totals['_admin_revenue'],
			$totals['_admin_quantity']
		);

		// Catalogue coverage — two views, always returned together so callers can
		// pick the right one for their context (group_by=product vs group_by=variation).
		// catalogue_coverage_percent: distinct PARENT products sold / published parents.
		// sku_coverage_percent: distinct SKUs (simple products + variations) sold /
		// published sellable SKUs (excludes the variable parent itself, which is
		// never the actual sellable unit).
		$totals['products_in_catalogue']      = $catalogue_size;
		$totals['skus_in_catalogue']          = $skus_size;
		$totals['catalogue_coverage_percent'] = ( $catalogue_size > 0 )
			? round( ( $totals['distinct_products_sold'] / $catalogue_size ) * 100, 1 )
			: 0.0;
		$totals['sku_coverage_percent']       = ( $skus_size > 0 )
			? round( ( $totals['distinct_skus_sold'] / $skus_size ) * 100, 1 )
			: 0.0;

		// Calendar days in the requested range — surfaced so Claude can quote the gap
		// to the merchant when truncation fires ("your range spans N days, cap is M").
		$series_range_days = ( new \DateTime( $dates['start'] ) )
			->diff( new \DateTime( $dates['end'] ) )->days + 1;

		// Time series for top products (capped at 5 products to keep response digestible).
		if ( ! empty( $resolved_interval ) && ! empty( $top ) ) {
			$series_product_ids = array_slice( wp_list_pluck( $top, 'product_id' ), 0, 5 );
			$series_map         = self::query_product_series(
				$dates['start'],
				$dates['end'],
				$series_product_ids,
				$resolved_interval,
				$group_by,
				$series_cap
			);
			foreach ( $top as $i => $product ) {
				$pid_series          = isset( $series_map[ $product['product_id'] ] )
					? $series_map[ $product['product_id'] ]
					: array();
				$top[ $i ]['series'] = $pid_series;
			}

			// Safety net: if the SQL returned as many rows as the cap, the series
			// was silently truncated. Return an error so Claude cannot analyse
			// partial data without the merchant's explicit choice. On a confirmed
			// call (confirmation_token valid) series_cap = range_days + 1, so a
			// fully-populated daily series never triggers this.
			$capped = array_filter(
				$top,
				function ( $p ) use ( $series_cap ) {
					return isset( $p['series'] ) && count( $p['series'] ) >= $series_cap;
				}
			);
			if ( ! empty( $capped ) ) {
				return new \WP_Error(
					'extended_range_required',
					'Series data hit the cap of ' . $series_cap . ' rows. Stop and ask the merchant to confirm before retrying: (1) call again with the confirmation_token below to load the full range, or (2) narrow the date range to 365 days or less.',
					array(
						'status'                => 400,
						'confirmation_required' => true,
						'series_range_days'     => $series_range_days,
						'series_cap'            => $series_cap,
						'period'                => array(
							'start' => $dates['start'],
							'end'   => $dates['end'],
							'label' => $dates['label'],
						),
						'interval'              => $resolved_interval,
					)
				);
			}
		}

		// Decorate top products with admin URLs for clickable links in chat output.
		foreach ( $top as $i => $product ) {
			$top[ $i ]['admin_url'] = self::product_admin_url( $product['product_id'] );
		}

		$currency = get_woocommerce_currency();

		$result = array(
			'period'            => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'          => $currency,
			'group_by'          => $group_by,
			'orderby'           => $orderby,
			'limit'             => $limit,
			'interval'          => $resolved_interval ? $resolved_interval : null,
			'series_cap'        => $resolved_interval ? $series_cap : null,
			'series_range_days' => $resolved_interval ? $series_range_days : null,
			'totals'            => $totals,
			'pipeline'          => $totals_pipeline,
			'admin_equivalent'  => $totals_admin_equivalent,
			'top_products'      => $top,
			'top_categories'    => $top_categories,
			'comparison'        => null,
			'note'              => null,
		);

		if ( 0 === (int) $totals['distinct_products_sold'] ) {
			$result['note']         = 'No product sales found for this date range.';
			$result['top_products'] = array();
		}

		// Comparison period: totals + per-product deltas + dropped_out.
		if ( $compare ) {
			$prev_dates      = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_totals_raw = self::query_product_totals( $prev_dates['start'], $prev_dates['end'] );
			$prev_top        = self::query_top_products( $prev_dates['start'], $prev_dates['end'], $limit, $orderby, $group_by );

			// Strip internal pipeline/admin keys from prev totals before exposing,
			// and build matching pipeline/admin_equivalent blocks for the prev period.
			$prev_totals           = $prev_totals_raw;
			$prev_pipeline         = array(
				'revenue'  => isset( $prev_totals_raw['_pipeline_revenue'] ) ? (float) $prev_totals_raw['_pipeline_revenue'] : 0.00,
				'quantity' => isset( $prev_totals_raw['_pipeline_quantity'] ) ? (int) $prev_totals_raw['_pipeline_quantity'] : 0,
			);
			$prev_admin_equivalent = array(
				'revenue'  => isset( $prev_totals_raw['_admin_revenue'] ) ? (float) $prev_totals_raw['_admin_revenue'] : 0.00,
				'quantity' => isset( $prev_totals_raw['_admin_quantity'] ) ? (int) $prev_totals_raw['_admin_quantity'] : 0,
			);
			unset(
				$prev_totals['_pipeline_revenue'],
				$prev_totals['_pipeline_quantity'],
				$prev_totals['_admin_revenue'],
				$prev_totals['_admin_quantity']
			);

			$totals_changes = self::calculate_product_totals_changes( $totals, $prev_totals );

			// Per-product deltas: index previous-period top by product_id for O(1) lookup.
			$prev_by_id = array();
			foreach ( $prev_top as $row ) {
				$prev_by_id[ $row['product_id'] ] = $row;
			}

			$current_ids = array();
			foreach ( $top as $i => $product ) {
				$current_ids[] = $product['product_id'];
				if ( isset( $prev_by_id[ $product['product_id'] ] ) ) {
					$top[ $i ]['change'] = self::calculate_product_change(
						$product,
						$prev_by_id[ $product['product_id'] ],
						$orderby
					);
				} else {
					$top[ $i ]['change'] = array(
						'amount'    => null,
						'percent'   => null,
						'direction' => 'new',
						'note'      => 'New to top results this period.',
					);
				}
			}
			// Re-emit top_products with change metadata attached.
			$result['top_products'] = $top;

			// Products that were in previous top-N but dropped out this period.
			$dropped_out = array();
			foreach ( $prev_top as $row ) {
				if ( ! in_array( $row['product_id'], $current_ids, true ) ) {
					$dropped_out[] = array(
						'product_id'           => $row['product_id'],
						'product_name'         => $row['product_name'],
						'previous_' . $orderby => $row[ $orderby ],
					);
				}
			}

			$result['comparison'] = array(
				'period'           => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'totals'           => $prev_totals,
				'pipeline'         => $prev_pipeline,
				'admin_equivalent' => $prev_admin_equivalent,
				'changes'          => $totals_changes,
				'dropped_out'      => $dropped_out,
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Build admin edit URL for a product (or variation).
	 *
	 * @param int $product_id Product or variation ID.
	 * @return string Admin edit URL.
	 */
	private static function product_admin_url( $product_id ) {
		return admin_url( 'post.php?post=' . (int) $product_id . '&action=edit' );
	}

	/**
	 * Build admin URL for a customer's WooCommerce Analytics single-customer view.
	 *
	 * Targets the WC Admin Customers report (`page=wc-admin&path=/customers`)
	 * rather than `user-edit.php` because the WC view works for both
	 * registered users and guest-checkout records (which never get a WP
	 * user account but do live in `wc_customer_lookup`). Returns null for
	 * `customer_id = 0` (guests not tracked in lookup).
	 *
	 * Output is consumed as a JSON value and rendered as a markdown link in
	 * chat — no HTML escaping here. The id is cast to int so the URL is
	 * always well-formed, and `admin_url()` honours the site's configured
	 * admin path (single-site + multisite safe).
	 *
	 * @param int $customer_id WooCommerce customer id (wc_customer_lookup.customer_id).
	 * @return string|null Admin URL, or null when the id is non-positive.
	 */
	public static function customer_admin_url( $customer_id ) {
		$id = (int) $customer_id;
		if ( $id <= 0 ) {
			return null;
		}
		return admin_url( 'admin.php?page=wc-admin&path=/customers&filter=single_customer&customers=' . $id );
	}

	/**
	 * Build admin edit URL for an order. HPOS-aware via the same `OrderUtil`
	 * helper `get_order_meta_source()` uses — falls back to the classic
	 * `post.php` URL when HPOS is unavailable or disabled. Returns null for
	 * non-positive ids.
	 *
	 * @param int $order_id Order id.
	 * @return string|null Admin URL, or null when the id is non-positive.
	 */
	public static function order_admin_url( $order_id ) {
		$id = (int) $order_id;
		if ( $id <= 0 ) {
			return null;
		}

		$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( class_exists( $util ) && $util::custom_orders_table_usage_is_enabled() ) {
			return admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $id );
		}

		return admin_url( 'post.php?post=' . $id . '&action=edit' );
	}

	/**
	 * Build admin edit URL for a coupon (post_type = shop_coupon). Returns
	 * null for non-positive ids.
	 *
	 * @param int $coupon_id Coupon post id.
	 * @return string|null Admin URL, or null when the id is non-positive.
	 */
	public static function coupon_admin_url( $coupon_id ) {
		$id = (int) $coupon_id;
		if ( $id <= 0 ) {
			return null;
		}
		return admin_url( 'post.php?post=' . $id . '&action=edit' );
	}

	/**
	 * Resolve the time-series interval based on the date range length.
	 *
	 * Empty input → no series (return ''). 'auto' → bucket size based on range:
	 *   ≤31 days → day, ≤92 days → week, else month.
	 *
	 * @param string $interval   Raw param: '', 'auto', 'day', 'week', 'month'.
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return string '' or one of 'day', 'week', 'month'.
	 */
	private static function resolve_interval( $interval, $date_start, $date_end ) {
		if ( empty( $interval ) ) {
			return '';
		}
		if ( in_array( $interval, array( 'day', 'week', 'month' ), true ) ) {
			return $interval;
		}
		// auto.
		$start_dt = new \DateTime( $date_start );
		$end_dt   = new \DateTime( $date_end );
		$days     = $start_dt->diff( $end_dt )->days + 1;

		if ( $days <= 31 ) {
			return 'day';
		}
		if ( $days <= 92 ) {
			return 'week';
		}
		return 'month';
	}

	/**
	 * Query top-N products (or variations) for a date range.
	 *
	 * Joins wc_order_product_lookup → wc_order_stats (status + date filter)
	 * → wp_posts (name) → wc_product_meta_lookup (sku, stock_status).
	 * Refund sub-orders flow through via the parent_id != 0 OR clause; their
	 * negative product_net_revenue rows feed the refunds aggregate.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @param int    $limit      Top N.
	 * @param string $orderby    net_revenue|gross_revenue|quantity|orders_count.
	 * @param string $group_by   product|variation.
	 * @return array Array of associative arrays.
	 */
	private static function query_top_products( $date_start, $date_end, $limit, $orderby, $group_by ) {
		global $wpdb;

		$pl_table   = $wpdb->prefix . 'wc_order_product_lookup';
		$os_table   = $wpdb->prefix . 'wc_order_stats';
		$posts      = $wpdb->posts;
		$meta_table = $wpdb->prefix . 'wc_product_meta_lookup';

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		// Group by variation_id when requested AND the row has a variation, else product_id.
		// Variation rows in wc_order_product_lookup carry both product_id and variation_id;
		// simple-product rows have variation_id = 0. We expose the chosen ID as 'product_id'
		// in the result for consistency.
		$id_column = ( 'variation' === $group_by )
			? "CASE WHEN {$pl_table}.variation_id > 0 THEN {$pl_table}.variation_id ELSE {$pl_table}.product_id END"
			: "{$pl_table}.product_id";

		// Whitelist orderby to avoid SQL injection — values come from REST enum, but defence in depth.
		// Ordering is always by the principled (paid, gross-of-refunds) view so the ranking
		// represents "what sold" rather than mixing in pipeline/refund accounting.
		$orderby_map = array(
			'net_revenue'   => 'net_revenue',
			'gross_revenue' => 'gross_revenue',
			'quantity'      => 'quantity',
			'orders_count'  => 'orders_count',
		);
		$orderby_col = isset( $orderby_map[ $orderby ] ) ? $orderby_map[ $orderby ] : 'net_revenue';

		// Same three-view pattern at the line-item level. WHERE is widened to
		// include on-hold + refunded so those rows feed the new aggregates.
		// Per-product:
		// net_revenue / quantity / orders_count = paid only, gross-of-refunds (unchanged)
		// pipeline_revenue / pipeline_quantity = on-hold (parent only)
		// admin_revenue / admin_quantity = paid + on-hold + refund-sub netting (matches WC Admin Products report).
		$sql = $wpdb->prepare(
			"SELECT
				{$id_column} AS product_id,
				COALESCE({$posts}.post_title, '(deleted product)') AS product_name,
				COALESCE({$meta_table}.sku, '') AS sku,
				COALESCE({$meta_table}.stock_status, '') AS stock_status,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$pl_table}.product_net_revenue ELSE 0 END) AS net_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$pl_table}.product_gross_revenue ELSE 0 END) AS gross_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$pl_table}.product_qty ELSE 0 END) AS quantity,
				COUNT(DISTINCT CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$pl_table}.order_id END) AS orders_count,
				ABS(SUM(CASE WHEN {$pl_table}.product_net_revenue < 0 THEN {$pl_table}.product_net_revenue ELSE 0 END)) AS refunds,
				SUM(CASE WHEN {$pl_table}.product_net_revenue < 0 THEN 1 ELSE 0 END) AS refund_count,
				SUM({$pl_table}.coupon_amount) AS discount,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN {$pl_table}.product_net_revenue ELSE 0 END) AS pipeline_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN {$pl_table}.product_qty ELSE 0 END) AS pipeline_quantity,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$admin_ph}) THEN {$pl_table}.product_net_revenue
				         WHEN {$os_table}.parent_id != 0 THEN {$pl_table}.product_net_revenue
				         ELSE 0 END) AS admin_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$admin_ph}) THEN {$pl_table}.product_qty
				         WHEN {$os_table}.parent_id != 0 THEN {$pl_table}.product_qty
				         ELSE 0 END) AS admin_quantity
			FROM {$pl_table}
			INNER JOIN {$os_table} ON {$pl_table}.order_id = {$os_table}.order_id
			LEFT JOIN {$posts} ON ({$id_column}) = {$posts}.ID
			LEFT JOIN {$meta_table} ON ({$id_column}) = {$meta_table}.product_id
			WHERE {$os_table}.{$date_column} >= %s
				AND {$os_table}.{$date_column} <= %s
				AND ( {$os_table}.status IN ({$admin_ph}) OR {$os_table}.parent_id != 0 )
			GROUP BY product_id
			HAVING net_revenue != 0 OR quantity != 0
			ORDER BY {$orderby_col} DESC
			LIMIT %d",
			array_merge(
				$paid_statuses, // net_revenue.
				$paid_statuses, // gross_revenue.
				$paid_statuses, // quantity.
				$paid_statuses, // orders_count.
				$pipeline_statuses, // pipeline_revenue.
				$pipeline_statuses, // pipeline_quantity.
				$admin_statuses,    // admin_revenue.
				$admin_statuses,    // admin_quantity.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses,    // WHERE.
				array( $limit )
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'product_id'                => (int) $row['product_id'],
					'product_name'              => $row['product_name'],
					'sku'                       => $row['sku'],
					'stock_status'              => $row['stock_status'],
					'net_revenue'               => round( (float) $row['net_revenue'], 2 ),
					'gross_revenue'             => round( (float) $row['gross_revenue'], 2 ),
					'quantity'                  => (int) $row['quantity'],
					'orders_count'              => (int) $row['orders_count'],
					'refunds'                   => round( (float) $row['refunds'], 2 ),
					'refund_count'              => (int) $row['refund_count'],
					'discount'                  => round( (float) $row['discount'], 2 ),
					'pipeline_revenue'          => round( (float) $row['pipeline_revenue'], 2 ),
					'pipeline_quantity'         => (int) $row['pipeline_quantity'],
					'admin_equivalent_revenue'  => round( (float) $row['admin_revenue'], 2 ),
					'admin_equivalent_quantity' => (int) $row['admin_quantity'],
				);
			},
			$rows
		);
	}

	/**
	 * Catalogue-wide totals across all products sold in the period.
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array Totals row with distinct/items/revenue/refund fields.
	 */
	private static function query_product_totals( $date_start, $date_end ) {
		global $wpdb;

		$pl_table = $wpdb->prefix . 'wc_order_product_lookup';
		$os_table = $wpdb->prefix . 'wc_order_stats';

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		// distinct_skus_sold treats each variation as its own SKU; rows where
		// variation_id=0 fall back to product_id (simple products are their own SKU).
		// product_id and variation_id are both wp_posts IDs which are globally unique.
		// Pipeline + admin_equivalent aggregates use the same status-bucket pattern
		// as the per-product query above.
		$sql = $wpdb->prepare(
			"SELECT
				COUNT(DISTINCT CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$pl_table}.product_id END) AS distinct_products_sold,
				COUNT(DISTINCT CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN
					CASE WHEN {$pl_table}.variation_id > 0
						THEN {$pl_table}.variation_id
						ELSE {$pl_table}.product_id
					END
				END) AS distinct_skus_sold,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$pl_table}.product_qty ELSE 0 END) AS total_items_sold,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$pl_table}.product_net_revenue ELSE 0 END) AS total_net_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$pl_table}.product_gross_revenue ELSE 0 END) AS total_gross_revenue,
				ABS(SUM(CASE WHEN {$pl_table}.product_net_revenue < 0 THEN {$pl_table}.product_net_revenue ELSE 0 END)) AS total_refunds,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN {$pl_table}.product_net_revenue ELSE 0 END) AS pipeline_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN {$pl_table}.product_qty ELSE 0 END) AS pipeline_quantity,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$admin_ph}) THEN {$pl_table}.product_net_revenue
				         WHEN {$os_table}.parent_id != 0 THEN {$pl_table}.product_net_revenue
				         ELSE 0 END) AS admin_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$admin_ph}) THEN {$pl_table}.product_qty
				         WHEN {$os_table}.parent_id != 0 THEN {$pl_table}.product_qty
				         ELSE 0 END) AS admin_quantity
			FROM {$pl_table}
			INNER JOIN {$os_table} ON {$pl_table}.order_id = {$os_table}.order_id
			WHERE {$os_table}.{$date_column} >= %s
				AND {$os_table}.{$date_column} <= %s
				AND ( {$os_table}.status IN ({$admin_ph}) OR {$os_table}.parent_id != 0 )",
			array_merge(
				$paid_statuses, // distinct_products_sold.
				$paid_statuses, // distinct_skus_sold.
				$paid_statuses, // total_items_sold.
				$paid_statuses, // total_net_revenue.
				$paid_statuses, // total_gross_revenue.
				$pipeline_statuses, // pipeline_revenue.
				$pipeline_statuses, // pipeline_quantity.
				$admin_statuses,    // admin_revenue.
				$admin_statuses,    // admin_quantity.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses     // WHERE.
			)
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return array(
				'distinct_products_sold' => 0,
				'distinct_skus_sold'     => 0,
				'total_items_sold'       => 0,
				'total_net_revenue'      => 0.00,
				'total_gross_revenue'    => 0.00,
				'total_refunds'          => 0.00,
				'_pipeline_revenue'      => 0.00,
				'_pipeline_quantity'     => 0,
				'_admin_revenue'         => 0.00,
				'_admin_quantity'        => 0,
			);
		}

		return array(
			'distinct_products_sold' => (int) $row['distinct_products_sold'],
			'distinct_skus_sold'     => (int) $row['distinct_skus_sold'],
			'total_items_sold'       => (int) $row['total_items_sold'],
			'total_net_revenue'      => round( (float) $row['total_net_revenue'], 2 ),
			'total_gross_revenue'    => round( (float) $row['total_gross_revenue'], 2 ),
			'total_refunds'          => round( (float) $row['total_refunds'], 2 ),
			'_pipeline_revenue'      => round( (float) $row['pipeline_revenue'], 2 ),
			'_pipeline_quantity'     => (int) $row['pipeline_quantity'],
			'_admin_revenue'         => round( (float) $row['admin_revenue'], 2 ),
			'_admin_quantity'        => (int) $row['admin_quantity'],
		);
	}

	/**
	 * Total published products in the catalogue (date-independent).
	 *
	 * Counts parent products only — both simple products and variable parents.
	 * Use query_sellable_sku_count() for the variation-aware count.
	 */
	private static function query_catalogue_size() {
		global $wpdb;

		$cached = get_transient( 'woocommerce_claude_catalogue_size' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'product' AND post_status = 'publish'"
		);

		// Short cache — catalogue size shouldn't change frequently.
		set_transient( 'woocommerce_claude_catalogue_size', $count, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Total sellable SKUs in the catalogue.
	 *
	 * SKU = an actually-purchasable unit:
	 *   - simple products (post_type='product' with no published variation children)
	 *   - published variations whose parent is also published
	 * Variable parent products are excluded — the parent isn't itself purchasable.
	 *
	 * Computed in two parts and added together; lets each subquery use a tight
	 * index (post_type, post_status) without a more expensive single-pass query.
	 *
	 * @return int
	 */
	private static function query_sellable_sku_count() {
		global $wpdb;

		$cached = get_transient( 'woocommerce_claude_sku_count' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		// Simple products: published 'product' posts with no published variation children.
		$simple = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			WHERE p.post_type = 'product' AND p.post_status = 'publish'
				AND NOT EXISTS (
					SELECT 1 FROM {$wpdb->posts} v
					WHERE v.post_type = 'product_variation'
						AND v.post_status = 'publish'
						AND v.post_parent = p.ID
				)"
		);

		// Published variations of published parent products.
		$variations = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} v
			INNER JOIN {$wpdb->posts} p ON v.post_parent = p.ID
			WHERE v.post_type = 'product_variation' AND v.post_status = 'publish'
				AND p.post_type = 'product' AND p.post_status = 'publish'"
		);

		$count = $simple + $variations;

		set_transient( 'woocommerce_claude_sku_count', $count, self::CACHE_TTL );

		return $count;
	}

	/**
	 * Top-N categories by net_revenue for the period.
	 *
	 * Joins product lookup → orders → term_relationships → term_taxonomy → terms
	 * filtered to product_cat. Each product can be in multiple categories; this
	 * counts revenue against every category the product belongs to (matches
	 * how WC Admin Analytics > Categories presents the data).
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @param int    $limit      Number of rows to return.
	 * @return array Top category rows.
	 */
	private static function query_top_categories( $date_start, $date_end, $limit ) {
		global $wpdb;

		$pl_table = $wpdb->prefix . 'wc_order_product_lookup';
		$os_table = $wpdb->prefix . 'wc_order_stats';
		$tr_table = $wpdb->prefix . 'term_relationships';
		$tt_table = $wpdb->prefix . 'term_taxonomy';
		$t_table  = $wpdb->prefix . 'terms';

		$paid_statuses = self::get_paid_statuses();
		$placeholders  = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$date_column   = self::get_date_column();

		$sql = $wpdb->prepare(
			"SELECT
				{$t_table}.term_id AS category_id,
				{$t_table}.name AS name,
				{$t_table}.slug AS slug,
				SUM(CASE WHEN {$os_table}.parent_id = 0 THEN {$pl_table}.product_net_revenue ELSE 0 END) AS net_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 THEN {$pl_table}.product_qty ELSE 0 END) AS quantity,
				COUNT(DISTINCT CASE WHEN {$os_table}.parent_id = 0 THEN {$pl_table}.order_id END) AS orders_count
			FROM {$pl_table}
			INNER JOIN {$os_table} ON {$pl_table}.order_id = {$os_table}.order_id
			INNER JOIN {$tr_table} ON {$pl_table}.product_id = {$tr_table}.object_id
			INNER JOIN {$tt_table} ON {$tr_table}.term_taxonomy_id = {$tt_table}.term_taxonomy_id AND {$tt_table}.taxonomy = 'product_cat'
			INNER JOIN {$t_table} ON {$tt_table}.term_id = {$t_table}.term_id
			WHERE {$os_table}.{$date_column} >= %s
				AND {$os_table}.{$date_column} <= %s
				AND ( {$os_table}.status IN ({$placeholders}) OR {$os_table}.parent_id != 0 )
			GROUP BY {$t_table}.term_id
			HAVING net_revenue != 0 OR quantity != 0
			ORDER BY net_revenue DESC
			LIMIT %d",
			array_merge(
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$paid_statuses,
				array( $limit )
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'category_id'  => (int) $row['category_id'],
					'name'         => $row['name'],
					'slug'         => $row['slug'],
					'net_revenue'  => round( (float) $row['net_revenue'], 2 ),
					'quantity'     => (int) $row['quantity'],
					'orders_count' => (int) $row['orders_count'],
				);
			},
			$rows
		);
	}

	/**
	 * Per-product time series for a given list of product IDs.
	 *
	 * Returns a map keyed by product_id, each value an array of
	 * { date, net_revenue, quantity } sorted by date ascending.
	 * Capped at $max_buckets per product (default TIMESERIES_MAX_BUCKETS).
	 * Callers should already cap product list to 5.
	 *
	 * @param string   $date_start  YYYY-MM-DD.
	 * @param string   $date_end    YYYY-MM-DD.
	 * @param array    $product_ids Up to 5 product/variation IDs.
	 * @param string   $interval    'day'|'week'|'month'.
	 * @param string   $group_by    'product'|'variation' — must match the top query.
	 * @param int|null $max_buckets Override cap. Null → TIMESERIES_MAX_BUCKETS.
	 * @return array Map[product_id => array of bucket rows].
	 */
	private static function query_product_series( $date_start, $date_end, $product_ids, $interval, $group_by, $max_buckets = null ) {
		$cap = $max_buckets ?? self::TIMESERIES_MAX_BUCKETS;
		global $wpdb;

		if ( empty( $product_ids ) ) {
			return array();
		}

		$pl_table = $wpdb->prefix . 'wc_order_product_lookup';
		$os_table = $wpdb->prefix . 'wc_order_stats';

		$paid_statuses = self::get_paid_statuses();
		$status_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$date_column   = self::get_date_column();

		$id_column = ( 'variation' === $group_by )
			? "CASE WHEN {$pl_table}.variation_id > 0 THEN {$pl_table}.variation_id ELSE {$pl_table}.product_id END"
			: "{$pl_table}.product_id";

		// Bucket expression — produces a YYYY-MM-DD style anchor for each bucket.
		// Note: % must be doubled (%%) so wpdb::prepare doesn't parse the
		// DATE_FORMAT spec as placeholders (%d especially).
		switch ( $interval ) {
			case 'week':
				// Monday-anchored week start.
				$bucket_expr = "DATE_FORMAT(DATE_SUB({$os_table}.{$date_column}, INTERVAL WEEKDAY({$os_table}.{$date_column}) DAY), '%%Y-%%m-%%d')";
				break;
			case 'month':
				$bucket_expr = "DATE_FORMAT({$os_table}.{$date_column}, '%%Y-%%m-01')";
				break;
			case 'day':
			default:
				$bucket_expr = "DATE_FORMAT({$os_table}.{$date_column}, '%%Y-%%m-%%d')";
				break;
		}

		$id_ph = implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) );

		$sql = $wpdb->prepare(
			"SELECT
				{$id_column} AS product_id,
				{$bucket_expr} AS bucket_date,
				SUM(CASE WHEN {$os_table}.parent_id = 0 THEN {$pl_table}.product_net_revenue ELSE 0 END) AS net_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 THEN {$pl_table}.product_qty ELSE 0 END) AS quantity
			FROM {$pl_table}
			INNER JOIN {$os_table} ON {$pl_table}.order_id = {$os_table}.order_id
			WHERE {$os_table}.{$date_column} >= %s
				AND {$os_table}.{$date_column} <= %s
				AND {$os_table}.status IN ({$status_ph})
				AND ({$id_column}) IN ({$id_ph})
			GROUP BY product_id, bucket_date
			ORDER BY product_id, bucket_date",
			array_merge(
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$paid_statuses,
				array_map( 'intval', $product_ids )
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			$pid = (int) $row['product_id'];
			if ( ! isset( $map[ $pid ] ) ) {
				$map[ $pid ] = array();
			}
			// Cap series length — see $cap / TIMESERIES_MAX_BUCKETS.
			if ( count( $map[ $pid ] ) >= $cap ) {
				continue;
			}
			$map[ $pid ][] = array(
				'date'        => $row['bucket_date'],
				'net_revenue' => round( (float) $row['net_revenue'], 2 ),
				'quantity'    => (int) $row['quantity'],
			);
		}

		return $map;
	}

	/**
	 * Pre-compute percentage changes for product totals.
	 *
	 * @param array $current  Current period totals.
	 * @param array $previous Previous period totals.
	 * @return array Per-key change rows with amount, percent, direction.
	 */
	private static function calculate_product_totals_changes( $current, $previous ) {
		$keys = array(
			'distinct_products_sold',
			'total_items_sold',
			'total_net_revenue',
			'total_gross_revenue',
			'total_refunds',
		);

		$changes = array();
		foreach ( $keys as $key ) {
			$curr = (float) $current[ $key ];
			$prev = (float) $previous[ $key ];
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	/**
	 * Calculate the change between two periods for a single product on the
	 * orderby metric. Used to attach a 'change' field to top_products entries.
	 *
	 * @param array  $current  Current period product row.
	 * @param array  $previous Previous period product row.
	 * @param string $orderby  Metric name to compare on.
	 * @return array Change row with metric, amount, percent, direction.
	 */
	private static function calculate_product_change( $current, $previous, $orderby ) {
		$curr = (float) $current[ $orderby ];
		$prev = (float) $previous[ $orderby ];
		$diff = $curr - $prev;

		if ( 0.0 === $prev ) {
			$percent = ( $curr > 0 ) ? 100.0 : 0.0;
		} else {
			$percent = round( ( $diff / $prev ) * 100, 1 );
		}

		if ( $diff > 0 ) {
			$direction = 'up';
		} elseif ( $diff < 0 ) {
			$direction = 'down';
		} else {
			$direction = 'flat';
		}

		return array(
			'metric'    => $orderby,
			'amount'    => round( $diff, 2 ),
			'percent'   => $percent,
			'direction' => $direction,
		);
	}

	// ─── Order Attribution ────────────────────────────────────────

	/**
	 * Attribution dimension → meta_key map.
	 *
	 * Mirrors WC core's order attribution meta:
	 *   channel  → _wc_order_attribution_origin     (Direct, Email, Organic Search, …)
	 *   source   → _wc_order_attribution_utm_source (google, facebook, klaviyo, …)
	 *   campaign → _wc_order_attribution_utm_campaign
	 *   device   → _wc_order_attribution_device_type (desktop, mobile, tablet)
	 *
	 * `channel_source` is a derived dimension (concat of channel + source,
	 * except when the channel is one that never carries a distinct source,
	 * e.g. Direct, Email, Referral) — no meta_key of its own.
	 *
	 * @var array
	 */
	private static $attribution_meta_keys = array(
		'channel'  => '_wc_order_attribution_origin',
		'source'   => '_wc_order_attribution_utm_source',
		'medium'   => '_wc_order_attribution_utm_medium',
		'campaign' => '_wc_order_attribution_utm_campaign',
		'term'     => '_wc_order_attribution_utm_term',
		'content'  => '_wc_order_attribution_utm_content',
		'device'   => '_wc_order_attribution_device_type',
	);

	/**
	 * Channel values that never carry a distinct source — `channel_source`
	 * collapses to just the channel for these. Matches prototype behaviour.
	 *
	 * @var array
	 */
	private static $channels_without_source = array(
		'Affiliates',
		'Audio',
		'Cross-network',
		'Direct',
		'Display',
		'Email',
		'Mobile Push Notifications',
		'Referral',
		'SMS',
		'Unassigned',
	);

	/**
	 * Detect order meta storage — HPOS (wc_orders_meta) vs classic (postmeta).
	 *
	 * HPOS stores attribution in `wc_orders_meta.order_id = wc_order_stats.order_id`.
	 * Classic storage uses `postmeta.post_id = wc_order_stats.order_id` (the order
	 * is a `shop_order` post). Returns [ table, id_column ].
	 *
	 * Uses WooCommerce's own `OrderUtil::custom_orders_table_usage_is_enabled()`
	 * rather than a SHOW TABLES check. Table existence is a leaky proxy — HPOS
	 * can be installed but disabled, and the previous `SHOW TABLES LIKE` +
	 * `(int)` cast never fired a truthy branch anyway because SHOW TABLES returns
	 * a string (which casts to 0) when the table exists. The fix routes HPOS
	 * detection through the same helper WC core uses elsewhere.
	 *
	 * Public so integration tests can exercise both branches by flipping the
	 * `woocommerce_custom_orders_table_enabled` option (OrderUtil reads it
	 * fresh each call).
	 */
	public static function get_order_meta_source() {
		global $wpdb;

		$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( class_exists( $util ) && $util::custom_orders_table_usage_is_enabled() ) {
			return array(
				'table'     => $wpdb->prefix . 'wc_orders_meta',
				'id_column' => 'order_id',
			);
		}

		return array(
			'table'     => $wpdb->prefix . 'postmeta',
			'id_column' => 'post_id',
		);
	}

	/**
	 * Detect order address storage — HPOS (wc_order_addresses) vs classic (postmeta).
	 *
	 * HPOS stores billing + shipping addresses in `wc_order_addresses`, one row
	 * per address_type per order. Classic storage puts address fields in
	 * `postmeta` under per-field meta keys (`_billing_country`,
	 * `_shipping_state`, etc.).
	 *
	 * The two shapes differ materially in JOIN layout:
	 *   - HPOS: one JOIN per address_type ('billing' / 'shipping') yields
	 *     all of country / state / city / postcode as columns on the join.
	 *   - Classic: one JOIN per field (since each lives under its own
	 *     meta_key). The aliases must be unique per field.
	 *
	 * Returns [ mode, table, id_column ] where `mode` is 'hpos' or 'classic'.
	 * Callers branch on `mode` to generate the right JOIN shape.
	 *
	 * Routes HPOS detection through the same `OrderUtil` helper
	 * `get_order_meta_source()` uses — not a SHOW TABLES check (leaky because
	 * HPOS can be installed but disabled).
	 *
	 * Public so integration tests can exercise both branches by flipping the
	 * `woocommerce_custom_orders_table_enabled` option.
	 *
	 * @return array{mode: string, table: string, id_column: string}
	 */
	public static function get_order_addresses_source() {
		global $wpdb;

		$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		if ( class_exists( $util ) && $util::custom_orders_table_usage_is_enabled() ) {
			return array(
				'mode'      => 'hpos',
				'table'     => $wpdb->prefix . 'wc_order_addresses',
				'id_column' => 'order_id',
			);
		}

		return array(
			'mode'      => 'classic',
			'table'     => $wpdb->prefix . 'postmeta',
			'id_column' => 'post_id',
		);
	}

	/**
	 * Build the GROUP BY SQL expression for a given attribution dimension.
	 *
	 * Relies on LEFT JOIN aliases `attr_channel`, `attr_source`, `attr_campaign`,
	 * `attr_device` existing in the query. `channel_source` builds a CASE that
	 * collapses to just the channel for channels that never carry a source.
	 *
	 * Returns null for unknown group_by values.
	 *
	 * @param string $group_by Dimension to group by.
	 * @return string|null SQL expression or null when unknown.
	 */
	private static function attribution_group_expr( $group_by ) {
		switch ( $group_by ) {
			case 'channel':
				return 'attr_channel.meta_value';
			case 'source':
				return 'attr_source.meta_value';
			case 'medium':
				return 'attr_medium.meta_value';
			case 'campaign':
				return 'attr_campaign.meta_value';
			case 'term':
				return 'attr_term.meta_value';
			case 'content':
				return 'attr_content.meta_value';
			case 'device':
				return 'attr_device.meta_value';
			case 'channel_source':
				global $wpdb;
				$escaped = array_map(
					function ( $c ) use ( $wpdb ) {
						return $wpdb->prepare( '%s', $c );
					},
					self::$channels_without_source
				);
				$list    = implode( ', ', $escaped );
				return "CASE WHEN attr_source.meta_value IS NULL OR attr_source.meta_value = '' OR attr_channel.meta_value IN ( {$list} )"
					. ' THEN attr_channel.meta_value'
					. " ELSE CONCAT(attr_channel.meta_value, ': ', attr_source.meta_value) END";
			default:
				return null;
		}
	}

	/**
	 * Fetch the attribution payload. Backs the
	 * `wc-analytics/get-attribution` ability.
	 *
	 * Returns top-N attribution groups (by channel, source, campaign, device,
	 * or channel+source) with per-group paid / pipeline / admin_equivalent
	 * revenue, orders count, AOV, items sold, new vs returning, and refunds.
	 * Totals include attribution coverage % — the share of paid orders that
	 * have attribution data at all.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start date — overrides period.
	 * @param string|null $date_end   Custom end date — overrides period.
	 * @param bool        $compare    Include previous-period comparison.
	 * @param int         $limit      Top N (1–50).
	 * @param string      $orderby    Sort column.
	 * @param string      $group_by   Dimension: channel/source/medium/campaign/term/content/device/channel_source.
	 * @param bool        $include_unassigned Include rows with missing attribution.
	 * @return array Response payload.
	 */
	public static function fetch_attribution( $period, $date_start, $date_end, $compare, $limit, $orderby, $group_by, $include_unassigned ) {
		$start              = microtime( true );
		$limit              = (int) $limit;
		$include_unassigned = (bool) $include_unassigned;

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		$cache_key = 'woocommerce_claude_attribution_' . md5(
			$dates['start'] . '_' . $dates['end']
			. '_' . ( $compare ? '1' : '0' )
			. '_' . $limit
			. '_' . $orderby
			. '_' . $group_by
			. '_' . ( $include_unassigned ? '1' : '0' )
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$top    = self::query_attribution_groups( $dates['start'], $dates['end'], $limit, $orderby, $group_by, $include_unassigned );
		$totals = self::query_attribution_totals( $dates['start'], $dates['end'], $group_by, $include_unassigned );

		// Pull pipeline + admin_equivalent off totals and surface as sibling blocks.
		$totals_pipeline         = array(
			'revenue'      => isset( $totals['_pipeline_revenue'] ) ? (float) $totals['_pipeline_revenue'] : 0.00,
			'orders_count' => isset( $totals['_pipeline_orders_count'] ) ? (int) $totals['_pipeline_orders_count'] : 0,
			'customers'    => isset( $totals['_pipeline_customers'] ) ? (int) $totals['_pipeline_customers'] : 0,
			'definition'   => 'On-hold orders awaiting payment (all attribution groups combined). Per-group pipeline values are on each top_groups row.',
		);
		$totals_admin_equivalent = array(
			'revenue'      => isset( $totals['_admin_revenue'] ) ? (float) $totals['_admin_revenue'] : 0.00,
			'orders_count' => isset( $totals['_admin_orders_count'] ) ? (int) $totals['_admin_orders_count'] : 0,
			'definition'   => 'What WC Admin Reports > Attribution shows: paid + on-hold + refunded orders lumped together. Per-group admin_equivalent values are on each top_groups row. Use for dashboard reconciliation only.',
		);
		unset(
			$totals['_pipeline_revenue'],
			$totals['_pipeline_orders_count'],
			$totals['_pipeline_customers'],
			$totals['_admin_revenue'],
			$totals['_admin_orders_count']
		);

		// Attribution coverage: share of paid orders with any attribution data
		// on the grouped dimension. When group_by=channel, coverage = orders
		// where attr_channel IS NOT NULL AND != ''. Crude signal for tracking
		// gaps — below ~80% usually means a plugin isn't firing on checkout.
		$totals['attribution_coverage_percent'] = ( $totals['total_paid_orders'] > 0 )
			? round( ( $totals['attributed_orders'] / $totals['total_paid_orders'] ) * 100, 1 )
			: 0.0;

		// Per-group share of revenue % — lets Claude say "Organic Search is 42%
		// of your revenue" without having to sum top_groups + divide.
		// Also pre-compute share_of_pipeline_revenue_percent + the over-index
		// delta (pipeline_share − paid_share) so Claude can spot channels that
		// over-contribute to stranded pipeline without doing the subtraction
		// narratively. Shot 53 in the 2026-04-17 E1 demo asked exactly this:
		// "is Social driving a disproportionate share of my on-hold orders?".
		$denominator          = (float) $totals['net_revenue'];
		$pipeline_denominator = (float) $totals_pipeline['revenue'];
		foreach ( $top as $i => $group ) {
			$top[ $i ]['share_of_revenue_percent'] = ( $denominator > 0 )
				? round( ( $group['net_revenue'] / $denominator ) * 100, 1 )
				: 0.0;

			// Null (not 0) when the store has zero pipeline — keeps the
			// signal "no on-hold orders anywhere" distinct from "this group
			// has 0% of real pipeline" in the response shape.
			if ( $pipeline_denominator > 0 ) {
				$top[ $i ]['share_of_pipeline_revenue_percent'] = round(
					( (float) $group['pipeline_revenue'] / $pipeline_denominator ) * 100,
					1
				);
				$top[ $i ]['pipeline_over_index_points']        = round(
					$top[ $i ]['share_of_pipeline_revenue_percent'] - $top[ $i ]['share_of_revenue_percent'],
					1
				);
			} else {
				$top[ $i ]['share_of_pipeline_revenue_percent'] = null;
				$top[ $i ]['pipeline_over_index_points']        = null;
			}
		}

		$currency = get_woocommerce_currency();

		$result = array(
			'period'             => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'           => $currency,
			'group_by'           => $group_by,
			'orderby'            => $orderby,
			'limit'              => $limit,
			'include_unassigned' => $include_unassigned,
			'totals'             => $totals,
			'pipeline'           => $totals_pipeline,
			'admin_equivalent'   => $totals_admin_equivalent,
			'top_groups'         => $top,
			'comparison'         => null,
			'note'               => null,
		);

		if ( 0 === (int) $totals['total_paid_orders'] ) {
			$result['note']       = 'No paid orders found for this date range.';
			$result['top_groups'] = array();
		}

		if ( $compare ) {
			$prev_dates      = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_totals_raw = self::query_attribution_totals( $prev_dates['start'], $prev_dates['end'], $group_by, $include_unassigned );
			$prev_top        = self::query_attribution_groups( $prev_dates['start'], $prev_dates['end'], $limit, $orderby, $group_by, $include_unassigned );

			$prev_totals           = $prev_totals_raw;
			$prev_pipeline         = array(
				'revenue'      => isset( $prev_totals_raw['_pipeline_revenue'] ) ? (float) $prev_totals_raw['_pipeline_revenue'] : 0.00,
				'orders_count' => isset( $prev_totals_raw['_pipeline_orders_count'] ) ? (int) $prev_totals_raw['_pipeline_orders_count'] : 0,
				'customers'    => isset( $prev_totals_raw['_pipeline_customers'] ) ? (int) $prev_totals_raw['_pipeline_customers'] : 0,
			);
			$prev_admin_equivalent = array(
				'revenue'      => isset( $prev_totals_raw['_admin_revenue'] ) ? (float) $prev_totals_raw['_admin_revenue'] : 0.00,
				'orders_count' => isset( $prev_totals_raw['_admin_orders_count'] ) ? (int) $prev_totals_raw['_admin_orders_count'] : 0,
			);
			unset(
				$prev_totals['_pipeline_revenue'],
				$prev_totals['_pipeline_orders_count'],
				$prev_totals['_pipeline_customers'],
				$prev_totals['_admin_revenue'],
				$prev_totals['_admin_orders_count']
			);
			$prev_totals['attribution_coverage_percent'] = ( $prev_totals['total_paid_orders'] > 0 )
				? round( ( $prev_totals['attributed_orders'] / $prev_totals['total_paid_orders'] ) * 100, 1 )
				: 0.0;

			$totals_changes = self::calculate_attribution_totals_changes( $totals, $prev_totals );

			// Per-group deltas for groups present in both periods.
			$prev_by_key = array();
			foreach ( $prev_top as $row ) {
				$prev_by_key[ $row['key'] ] = $row;
			}

			$current_keys = array();
			foreach ( $top as $i => $group ) {
				$current_keys[] = $group['key'];
				if ( isset( $prev_by_key[ $group['key'] ] ) ) {
					$top[ $i ]['change'] = self::calculate_attribution_change(
						$group,
						$prev_by_key[ $group['key'] ],
						$orderby
					);
				} else {
					$top[ $i ]['change'] = array(
						'metric'    => $orderby,
						'amount'    => null,
						'percent'   => null,
						'direction' => 'new',
						'note'      => 'New to top results this period.',
					);
				}
			}
			$result['top_groups'] = $top;

			$dropped_out = array();
			foreach ( $prev_top as $row ) {
				if ( ! in_array( $row['key'], $current_keys, true ) ) {
					$dropped_out[] = array(
						'key'                  => $row['key'],
						'label'                => $row['label'],
						'previous_' . $orderby => $row[ $orderby ],
					);
				}
			}

			$result['comparison'] = array(
				'period'           => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'totals'           => $prev_totals,
				'pipeline'         => $prev_pipeline,
				'admin_equivalent' => $prev_admin_equivalent,
				'changes'          => $totals_changes,
				'dropped_out'      => $dropped_out,
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Query top-N attribution groups for a date range.
	 *
	 * LEFT JOINs attribution meta (channel/source/campaign/device) against
	 * wc_order_stats, groups by the chosen dimension, and computes paid /
	 * pipeline / admin_equivalent revenue + orders + AOV + items sold +
	 * new/returning customers + refunds per group.
	 *
	 * @param string $date_start         YYYY-MM-DD start date.
	 * @param string $date_end           YYYY-MM-DD end date.
	 * @param int    $limit              Number of groups to return.
	 * @param string $orderby            Metric to sort groups by.
	 * @param string $group_by           Dimension to group by.
	 * @param bool   $include_unassigned Whether to include rows with no value for the dimension.
	 * @return array Top attribution group rows.
	 */
	private static function query_attribution_groups( $date_start, $date_end, $limit, $orderby, $group_by, $include_unassigned ) {
		global $wpdb;

		$os_table = $wpdb->prefix . 'wc_order_stats';
		$source   = self::get_order_meta_source();

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		$group_expr = self::attribution_group_expr( $group_by );
		if ( null === $group_expr ) {
			return array();
		}

		// Build LEFT JOINs to attribution meta. Each dimension is its own join
		// against the meta table with a specific meta_key filter. We always
		// join all four dimensions because channel_source needs both channel
		// and source; the query plan stays cheap thanks to the meta_key index.
		$meta_joins  = '';
		$join_values = array();
		foreach ( self::$attribution_meta_keys as $alias_suffix => $meta_key ) {
			$alias         = 'attr_' . $alias_suffix;
			$meta_joins   .= " LEFT JOIN {$source['table']} {$alias} ON {$alias}.{$source['id_column']} = {$os_table}.order_id AND {$alias}.meta_key = %s";
			$join_values[] = $meta_key;
		}

		// Whitelist orderby.
		$orderby_map = array(
			'net_revenue'     => 'net_revenue',
			'orders_count'    => 'orders_count',
			'avg_order_value' => 'avg_order_value',
		);
		$orderby_col = isset( $orderby_map[ $orderby ] ) ? $orderby_map[ $orderby ] : 'net_revenue';

		// Group-expr filter — when include_unassigned = false, exclude NULL/empty.
		$unassigned_filter = $include_unassigned
			? ''
			: " AND ({$group_expr}) IS NOT NULL AND ({$group_expr}) != ''";

		// Three-view aggregates — order-level, parent_id = 0 only (attribution
		// lives on the parent order; refund sub-orders carry no attribution meta).
		$sql = $wpdb->prepare(
			"SELECT
				({$group_expr}) AS group_key,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$os_table}.net_total ELSE 0 END) AS net_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS orders_count,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$os_table}.num_items_sold ELSE 0 END) AS items_sold,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) AND {$os_table}.returning_customer = 0 THEN 1 ELSE 0 END) AS new_customers,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) AND {$os_table}.returning_customer = 1 THEN 1 ELSE 0 END) AS returning_customers,
				ABS(SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN 0
				              WHEN {$os_table}.parent_id != 0 THEN ({$os_table}.net_total + {$os_table}.tax_total + {$os_table}.shipping_total)
				              ELSE 0 END)) AS refunds,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN {$os_table}.net_total ELSE 0 END) AS pipeline_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders_count,
				COUNT(DISTINCT CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN {$os_table}.customer_id END) AS pipeline_customers,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$admin_ph}) THEN {$os_table}.net_total ELSE 0 END) AS admin_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$admin_ph}) THEN 1 ELSE 0 END) AS admin_orders_count,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$os_table}.net_total ELSE 0 END)
					/ NULLIF(SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN 1 ELSE 0 END), 0) AS avg_order_value
			FROM {$os_table}
			{$meta_joins}
			WHERE {$os_table}.{$date_column} >= %s
				AND {$os_table}.{$date_column} <= %s
				AND ( {$os_table}.status IN ({$admin_ph}) OR {$os_table}.parent_id != 0 )
				{$unassigned_filter}
			GROUP BY group_key
			HAVING orders_count != 0 OR pipeline_orders_count != 0 OR admin_orders_count != 0
			ORDER BY {$orderby_col} DESC
			LIMIT %d",
			array_merge(
				$paid_statuses,                                        // net_revenue.
				$paid_statuses,                                        // orders_count.
				$paid_statuses,                                        // items_sold.
				$paid_statuses,                                        // new_customers.
				$paid_statuses,                                        // returning_customers.
				$paid_statuses,                                        // refunds (inner CASE).
				$pipeline_statuses,                                    // pipeline_revenue.
				$pipeline_statuses,                                    // pipeline_orders_count.
				$pipeline_statuses,                                    // pipeline_customers.
				$admin_statuses,                                       // admin_revenue.
				$admin_statuses,                                       // admin_orders_count.
				$paid_statuses,                                        // avg_order_value num.
				$paid_statuses,                                        // avg_order_value den.
				$join_values,                                          // meta_key placeholders (4, in FROM/JOIN clause).
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses,                                       // WHERE.
				array( $limit )
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				$raw_key = $row['group_key'];
				// Normalise NULL/empty to "(Unassigned)" so the label is meaningful
				// in chat output but the key stays a stable string for comparison.
				$is_unassigned = ( null === $raw_key || '' === $raw_key );
				$key           = $is_unassigned ? '(Unassigned)' : (string) $raw_key;
				$label         = '(direct)' === $key ? 'Direct' : $key;

				return array(
					'key'                           => $key,
					'label'                         => $label,
					'net_revenue'                   => round( (float) $row['net_revenue'], 2 ),
					'orders_count'                  => (int) $row['orders_count'],
					'avg_order_value'               => round( (float) $row['avg_order_value'], 2 ),
					'items_sold'                    => (int) $row['items_sold'],
					'new_customers'                 => (int) $row['new_customers'],
					'returning_customers'           => (int) $row['returning_customers'],
					'refunds'                       => round( (float) $row['refunds'], 2 ),
					'pipeline_revenue'              => round( (float) $row['pipeline_revenue'], 2 ),
					'pipeline_orders_count'         => (int) $row['pipeline_orders_count'],
					'pipeline_customers'            => (int) $row['pipeline_customers'],
					'admin_equivalent_revenue'      => round( (float) $row['admin_revenue'], 2 ),
					'admin_equivalent_orders_count' => (int) $row['admin_orders_count'],
				);
			},
			$rows
		);
	}

	/**
	 * Catalogue-wide attribution totals for the period.
	 *
	 * `attributed_orders` = paid orders where the grouped dimension has a
	 * non-empty value. `total_paid_orders` = all paid orders regardless.
	 * Ratio is surfaced as `attribution_coverage_percent` in the response.
	 *
	 * @param string $date_start         YYYY-MM-DD start date.
	 * @param string $date_end           YYYY-MM-DD end date.
	 * @param string $group_by           Dimension to group by.
	 * @param bool   $include_unassigned Whether to include rows with no value for the dimension.
	 * @return array Totals row with revenue, order counts, coverage fields.
	 */
	private static function query_attribution_totals( $date_start, $date_end, $group_by, $include_unassigned ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $include_unassigned intentionally unused here; totals always use the full paid-orders denominator so coverage % is meaningful. See inline note below.
		global $wpdb;

		$os_table = $wpdb->prefix . 'wc_order_stats';
		$source   = self::get_order_meta_source();

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		$group_expr = self::attribution_group_expr( $group_by );
		if ( null === $group_expr ) {
			return self::empty_attribution_totals();
		}

		$meta_joins  = '';
		$join_values = array();
		foreach ( self::$attribution_meta_keys as $alias_suffix => $meta_key ) {
			$alias         = 'attr_' . $alias_suffix;
			$meta_joins   .= " LEFT JOIN {$source['table']} {$alias} ON {$alias}.{$source['id_column']} = {$os_table}.order_id AND {$alias}.meta_key = %s";
			$join_values[] = $meta_key;
		}

		// Note: totals ignore include_unassigned — we always want to see the
		// full paid-orders denominator so coverage % is meaningful. The
		// per-group rows respect include_unassigned separately.
		$sql = $wpdb->prepare(
			"SELECT
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$os_table}.net_total ELSE 0 END) AS net_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS total_paid_orders,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) AND ({$group_expr}) IS NOT NULL AND ({$group_expr}) != '' THEN 1 ELSE 0 END) AS attributed_orders,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN {$os_table}.num_items_sold ELSE 0 END) AS items_sold,
				COUNT(DISTINCT CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$paid_ph}) THEN ({$group_expr}) END) AS distinct_groups,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN {$os_table}.net_total ELSE 0 END) AS pipeline_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders_count,
				COUNT(DISTINCT CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$pipeline_ph}) THEN {$os_table}.customer_id END) AS pipeline_customers,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$admin_ph}) THEN {$os_table}.net_total ELSE 0 END) AS admin_revenue,
				SUM(CASE WHEN {$os_table}.parent_id = 0 AND {$os_table}.status IN ({$admin_ph}) THEN 1 ELSE 0 END) AS admin_orders_count
			FROM {$os_table}
			{$meta_joins}
			WHERE {$os_table}.{$date_column} >= %s
				AND {$os_table}.{$date_column} <= %s
				AND {$os_table}.status IN ({$admin_ph})",
			array_merge(
				$paid_statuses,      // net_revenue.
				$paid_statuses,      // total_paid_orders.
				$paid_statuses,      // attributed_orders.
				$paid_statuses,      // items_sold.
				$paid_statuses,      // distinct_groups.
				$pipeline_statuses,  // pipeline_revenue.
				$pipeline_statuses,  // pipeline_orders_count.
				$pipeline_statuses,  // pipeline_customers.
				$admin_statuses,     // admin_revenue.
				$admin_statuses,     // admin_orders_count.
				$join_values,        // meta_key placeholders (4, in FROM/JOIN clause).
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses      // WHERE.
			)
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return self::empty_attribution_totals();
		}

		$total_paid      = (int) $row['total_paid_orders'];
		$attributed      = (int) $row['attributed_orders'];
		$avg_order_value = $total_paid > 0 ? round( (float) $row['net_revenue'] / $total_paid, 2 ) : 0.00;

		return array(
			'net_revenue'            => round( (float) $row['net_revenue'], 2 ),
			'total_paid_orders'      => $total_paid,
			'attributed_orders'      => $attributed,
			'unattributed_orders'    => $total_paid - $attributed,
			'items_sold'             => (int) $row['items_sold'],
			'avg_order_value'        => $avg_order_value,
			'distinct_groups'        => (int) $row['distinct_groups'],
			'_pipeline_revenue'      => round( (float) $row['pipeline_revenue'], 2 ),
			'_pipeline_orders_count' => (int) $row['pipeline_orders_count'],
			'_pipeline_customers'    => (int) $row['pipeline_customers'],
			'_admin_revenue'         => round( (float) $row['admin_revenue'], 2 ),
			'_admin_orders_count'    => (int) $row['admin_orders_count'],
		);
	}

	/**
	 * Zero-valued totals row used when the period has no attribution data.
	 *
	 * @return array Empty totals row matching the shape of query_attribution_totals().
	 */
	private static function empty_attribution_totals() {
		return array(
			'net_revenue'            => 0.00,
			'total_paid_orders'      => 0,
			'attributed_orders'      => 0,
			'unattributed_orders'    => 0,
			'items_sold'             => 0,
			'avg_order_value'        => 0.00,
			'distinct_groups'        => 0,
			'_pipeline_revenue'      => 0.00,
			'_pipeline_orders_count' => 0,
			'_pipeline_customers'    => 0,
			'_admin_revenue'         => 0.00,
			'_admin_orders_count'    => 0,
		);
	}

	/**
	 * Pre-compute percentage changes for attribution totals.
	 *
	 * @param array $current  Current period totals.
	 * @param array $previous Previous period totals.
	 * @return array Per-key change rows with amount, percent, direction.
	 */
	private static function calculate_attribution_totals_changes( $current, $previous ) {
		$keys = array(
			'net_revenue',
			'total_paid_orders',
			'attributed_orders',
			'avg_order_value',
			'distinct_groups',
			'attribution_coverage_percent',
		);

		$changes = array();
		foreach ( $keys as $key ) {
			$curr = (float) ( $current[ $key ] ?? 0 );
			$prev = (float) ( $previous[ $key ] ?? 0 );
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	// ─── Customer Overview ────────────────────────────────────────

	/**
	 * Fetch the customer-overview payload. Backs the
	 * `wc-analytics/get-customer-overview` ability.
	 *
	 * Period-scoped view of who's buying — new vs returning customer
	 * counts, per-segment orders, spend, and AOV, plus repeat rate.
	 *
	 * Three-view pattern applied to customer counts, same shape as
	 * revenue / orders / products / attribution endpoints.
	 *
	 * Edge case: the `returning_customer` column on `wc_order_stats`
	 * is set at order creation and never updated. A customer whose
	 * first-ever order falls in this period counts as `new`; if they
	 * place a second order in the same period it counts as `returning`,
	 * so they appear in both buckets. The principled `total_customers`
	 * uses COUNT(DISTINCT customer_id) to avoid double-count. The
	 * admin_equivalent view sums new + returning to match WC Admin's
	 * Customers report convention. `overlap_customers` surfaces the
	 * gap explicitly so Claude can explain it when asked.
	 *
	 * @param string      $period             Period shortcut.
	 * @param string|null $date_start         Custom start date — overrides period.
	 * @param string|null $date_end           Custom end date — overrides period.
	 * @param bool        $compare            Include previous-period comparison.
	 * @param string      $interval           Time-series granularity: '', 'auto', 'day', 'week', 'month'.
	 * @param string|null $confirmation_token  Token from a prior extended_range_required response.
	 * @param int|null    $series_cap_override Pre-cleared cap from get-data ability (skips internal gate).
	 * @return array|\WP_Error Response payload, or WP_Error if the large-range gate fires.
	 */
	public static function fetch_customer_overview( $period, $date_start, $date_end, $compare, $interval, $confirmation_token = null, $series_cap_override = null ) {
		$start = microtime( true );

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		// Resolve auto interval based on date range length.
		$resolved_interval = self::resolve_interval( $interval, $dates['start'], $dates['end'] );

		// Heavy-scan gate. When called through wc-analytics/get-data the gate was
		// already checked session-side and $series_cap_override carries the cleared
		// cap; skip the token-based gate in that case.
		if ( null !== $series_cap_override ) {
			$series_cap = (int) $series_cap_override;
		} else {
			$gate_result = \WooCommerce\CommerceAbilities\Abilities\LargeRangeGate::check(
				$dates['start'],
				$dates['end'],
				$confirmation_token
			);
			if ( is_wp_error( $gate_result ) ) {
				return $gate_result;
			}
			$series_cap = $gate_result;
		}

		$cache_key = 'woocommerce_claude_customers_' . md5(
			$dates['start'] . '_' . $dates['end'] . '_' . ( $compare ? '1' : '0' )
			. '_' . $resolved_interval
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$metrics  = self::query_customer_metrics( $dates['start'], $dates['end'] );
		$currency = get_woocommerce_currency();

		list( $primary, $pipeline, $admin_equivalent ) = self::split_customer_views( $metrics );

		// Calendar days in the requested range — surfaced so Claude can quote the gap
		// to the merchant when truncation fires.
		$series_range_days = ( new \DateTime( $dates['start'] ) )
			->diff( new \DateTime( $dates['end'] ) )->days + 1;

		// Time series — one row per bucket at the requested granularity.
		// Paid view + pipeline summary per bucket. admin_equivalent is snapshot-only
		// (dashboard reconciliation lens), so it doesn't appear in the series.
		$series = null;
		if ( ! empty( $resolved_interval ) ) {
			$series = self::query_customer_series( $dates['start'], $dates['end'], $resolved_interval, $series_cap );

			// Safety net: if SQL returned as many rows as the cap, the series was
			// silently truncated. On a confirmed call (confirmation_token valid)
			// series_cap = range_days + 1, so a fully-populated daily series
			// never triggers this.
			if ( count( $series ) >= $series_cap ) {
				return new \WP_Error(
					'extended_range_required',
					'Series data hit the cap of ' . $series_cap . ' rows. Stop and ask the merchant to confirm before retrying: (1) call again with the confirmation_token below to load the full range, or (2) narrow the date range to 365 days or less.',
					array(
						'status'                => 400,
						'confirmation_required' => true,
						'series_range_days'     => $series_range_days,
						'series_cap'            => $series_cap,
						'period'                => array(
							'start' => $dates['start'],
							'end'   => $dates['end'],
							'label' => $dates['label'],
						),
						'interval'              => $resolved_interval,
					)
				);
			}
		}

		$result = array(
			'period'            => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'          => $currency,
			'interval'          => $resolved_interval ? $resolved_interval : null,
			'series_cap'        => $resolved_interval ? $series_cap : null,
			'series_range_days' => $resolved_interval ? $series_range_days : null,
			'metrics'           => $primary,
			'pipeline'          => $pipeline,
			'admin_equivalent'  => $admin_equivalent,
			'series'            => $series,
			'comparison'        => null,
			'note'              => null,
		);

		if ( 0 === (int) $primary['total_customers'] ) {
			$result['note'] = 'No paid orders found for this date range.';
		}

		if ( $compare ) {
			$prev_dates                                        = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_metrics                                      = self::query_customer_metrics( $prev_dates['start'], $prev_dates['end'] );
			list( $prev_primary, $prev_pipeline, $prev_admin ) = self::split_customer_views( $prev_metrics );
			$changes = self::calculate_customer_changes( $primary, $prev_primary );

			$result['comparison'] = array(
				'period'           => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'metrics'          => $prev_primary,
				'pipeline'         => $prev_pipeline,
				'admin_equivalent' => $prev_admin,
				'changes'          => $changes,
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Query customer metrics for a date range.
	 *
	 * Single SQL query, three views:
	 * - PRIMARY: paid statuses, COUNT(DISTINCT) for total so a customer
	 *   whose flag flips within the period isn't double-counted.
	 * - PIPELINE: on-hold only (awaiting payment). Reported separately
	 *   so merchants don't conflate AR with collected customer counts.
	 * - ADMIN_EQUIVALENT: paid + on-hold + refunded; total = new + returning
	 *   (matches WC Admin Customers report) for dashboard reconciliation.
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array Tuple of [primary, pipeline, admin_equivalent] customer arrays.
	 */
	private static function query_customer_metrics( $date_start, $date_end ) {
		global $wpdb;

		$table             = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		// Notes on the query:
		// - parent_id = 0 excludes refund sub-orders everywhere. Refund rows
		// share the parent order's customer_id but aren't a new customer
		// relationship — counting them would inflate counts.
		// - COUNT(DISTINCT customer_id) for primary totals intentionally
		// differs from new + returning (sum), because the creation-time
		// flag can flip mid-period. See overlap_customers below.
		// - AOV denominators use NULLIF to avoid divide-by-zero when a
		// segment has no orders. AOV is computed from orders with
		// net_total > 0 to avoid 100%-coupon orders dragging the average.
		$sql = $wpdb->prepare(
			"SELECT
				-- Primary (paid only, distinct counts)
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN customer_id END) AS total_customers,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 0 THEN customer_id END) AS new_customers,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 1 THEN customer_id END) AS returning_customers,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS orders_count,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 0 THEN 1 ELSE 0 END) AS new_customer_orders,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 1 THEN 1 ELSE 0 END) AS returning_customer_orders,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN net_total ELSE 0 END) AS net_sales,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 0 THEN net_total ELSE 0 END) AS new_customer_net_sales,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 1 THEN net_total ELSE 0 END) AS returning_customer_net_sales,
				COALESCE(
					SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 0 AND net_total > 0 THEN net_total ELSE 0 END) /
					NULLIF(SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 0 AND net_total > 0 THEN 1 ELSE 0 END), 0),
					0
				) AS new_customer_avg_order_value,
				COALESCE(
					SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 1 AND net_total > 0 THEN net_total ELSE 0 END) /
					NULLIF(SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 1 AND net_total > 0 THEN 1 ELSE 0 END), 0),
					0
				) AS returning_customer_avg_order_value,

				-- Pipeline (on-hold only)
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN customer_id END) AS pipeline_customers,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) AND returning_customer = 0 THEN customer_id END) AS pipeline_new_customers,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) AND returning_customer = 1 THEN customer_id END) AS pipeline_returning_customers,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders,

				-- Admin equivalent (paid + on-hold + refunded; sums match WC Admin convention)
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) AND returning_customer = 0 THEN customer_id END) AS admin_new_customers,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) AND returning_customer = 1 THEN customer_id END) AS admin_returning_customers,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) THEN 1 ELSE 0 END) AS admin_orders,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) THEN net_total
				         WHEN parent_id != 0 THEN net_total
				         ELSE 0 END) AS admin_net_sales
			FROM {$table}
			WHERE {$date_column} >= %s
				AND {$date_column} <= %s
				AND ( status IN ({$admin_ph}) OR parent_id != 0 )",
			array_merge(
				$paid_statuses, // total_customers.
				$paid_statuses, // new_customers.
				$paid_statuses, // returning_customers.
				$paid_statuses, // orders_count.
				$paid_statuses, // new_customer_orders.
				$paid_statuses, // returning_customer_orders.
				$paid_statuses, // net_sales.
				$paid_statuses, // new_customer_net_sales.
				$paid_statuses, // returning_customer_net_sales.
				$paid_statuses, // new AOV numerator.
				$paid_statuses, // new AOV denominator.
				$paid_statuses, // returning AOV numerator.
				$paid_statuses, // returning AOV denominator.
				$pipeline_statuses, // pipeline_customers.
				$pipeline_statuses, // pipeline_new_customers.
				$pipeline_statuses, // pipeline_returning_customers.
				$pipeline_statuses, // pipeline_orders.
				$admin_statuses,    // admin_new_customers.
				$admin_statuses,    // admin_returning_customers.
				$admin_statuses,    // admin_orders.
				$admin_statuses,    // admin_net_sales.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses     // WHERE.
			)
		);

		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return self::empty_customer_metrics();
		}

		$total_customers     = (int) $row['total_customers'];
		$new_customers       = (int) $row['new_customers'];
		$returning_customers = (int) $row['returning_customers'];

		// Overlap: customers counted as both new (first order in period)
		// AND returning (second order in period). Creation-time flag artefact.
		$overlap_customers = max( 0, ( $new_customers + $returning_customers ) - $total_customers );

		// Percentages — pre-computed so Claude doesn't do arithmetic.
		$new_percent       = ( $total_customers > 0 ) ? round( ( $new_customers / $total_customers ) * 100, 1 ) : 0.0;
		$returning_percent = ( $total_customers > 0 ) ? round( ( $returning_customers / $total_customers ) * 100, 1 ) : 0.0;
		$repeat_rate       = ( $total_customers > 0 ) ? round( ( $returning_customers / $total_customers ) * 100, 1 ) : 0.0;

		$new_customer_orders_val          = (int) $row['new_customer_orders'];
		$returning_customer_orders_val    = (int) $row['returning_customer_orders'];
		$new_customer_net_sales_val       = (float) $row['new_customer_net_sales'];
		$returning_customer_net_sales_val = (float) $row['returning_customer_net_sales'];

		// Per-customer ratios — pre-computed so Claude doesn't derive them
		// by hand (surfaced in 2026-04-17 demo, shot 24). "Spend per customer"
		// differs from AOV: AOV is per-order, spend-per-customer bakes in
		// order frequency within the period. Returning customers typically
		// place more orders per period, so spend_per_customer gaps widen
		// even when AOVs converge.
		$new_orders_per_customer       = ( $new_customers > 0 )
			? round( $new_customer_orders_val / $new_customers, 2 )
			: 0.0;
		$returning_orders_per_customer = ( $returning_customers > 0 )
			? round( $returning_customer_orders_val / $returning_customers, 2 )
			: 0.0;
		$new_spend_per_customer        = ( $new_customers > 0 )
			? round( $new_customer_net_sales_val / $new_customers, 2 )
			: 0.00;
		$returning_spend_per_customer  = ( $returning_customers > 0 )
			? round( $returning_customer_net_sales_val / $returning_customers, 2 )
			: 0.00;

		return array(
			'total_customers'                        => $total_customers,
			'new_customers'                          => $new_customers,
			'returning_customers'                    => $returning_customers,
			'overlap_customers'                      => $overlap_customers,
			'new_customer_percent'                   => $new_percent,
			'returning_customer_percent'             => $returning_percent,
			'repeat_rate_percent'                    => $repeat_rate,
			'orders_count'                           => (int) $row['orders_count'],
			'new_customer_orders'                    => $new_customer_orders_val,
			'returning_customer_orders'              => $returning_customer_orders_val,
			'net_sales'                              => round( (float) $row['net_sales'], 2 ),
			'new_customer_net_sales'                 => round( $new_customer_net_sales_val, 2 ),
			'returning_customer_net_sales'           => round( $returning_customer_net_sales_val, 2 ),
			'new_customer_avg_order_value'           => round( (float) $row['new_customer_avg_order_value'], 2 ),
			'returning_customer_avg_order_value'     => round( (float) $row['returning_customer_avg_order_value'], 2 ),
			'new_customer_orders_per_customer'       => $new_orders_per_customer,
			'returning_customer_orders_per_customer' => $returning_orders_per_customer,
			'new_customer_spend_per_customer'        => $new_spend_per_customer,
			'returning_customer_spend_per_customer'  => $returning_spend_per_customer,
			// Internal — used by split_customer_views to build the sibling blocks.
			'_pipeline_customers'                    => (int) $row['pipeline_customers'],
			'_pipeline_new_customers'                => (int) $row['pipeline_new_customers'],
			'_pipeline_returning_customers'          => (int) $row['pipeline_returning_customers'],
			'_pipeline_orders'                       => (int) $row['pipeline_orders'],
			'_admin_new_customers'                   => (int) $row['admin_new_customers'],
			'_admin_returning_customers'             => (int) $row['admin_returning_customers'],
			'_admin_orders'                          => (int) $row['admin_orders'],
			'_admin_net_sales'                       => round( (float) $row['admin_net_sales'], 2 ),
		);
	}

	/**
	 * Time series of customer metrics bucketed by day / week / month.
	 *
	 * Returns an array of per-bucket rows in ascending date order. Each row
	 * carries the paid-view metrics plus a pipeline summary. Matches the
	 * top-level `metrics` shape one bucket at a time — same flag-flip edge
	 * case (overlap_customers) can fire at every bucket granularity, so
	 * the field is surfaced per-bucket too.
	 *
	 * admin_equivalent is intentionally NOT bucketed — it exists as a
	 * snapshot reconciliation lens against WC Admin Reports, not as a
	 * change-over-time story.
	 *
	 * Capped at $max_buckets (default TIMESERIES_MAX_BUCKETS). Practical ranges
	 * at default 365: day × last_year → 365, week × last_year → ~52,
	 * month × last_year → 12.
	 *
	 * @param string   $date_start  YYYY-MM-DD.
	 * @param string   $date_end    YYYY-MM-DD.
	 * @param string   $interval    'day'|'week'|'month'.
	 * @param int|null $max_buckets Override cap. Null → TIMESERIES_MAX_BUCKETS.
	 * @return array Array of bucket rows ordered ascending by date.
	 */
	private static function query_customer_series( $date_start, $date_end, $interval, $max_buckets = null ) {
		$cap = $max_buckets ?? self::TIMESERIES_MAX_BUCKETS;
		global $wpdb;

		$table             = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		// Bucket expression — produces a YYYY-MM-DD style anchor for each bucket.
		// Note: % must be doubled (%%) so wpdb::prepare doesn't parse the
		// DATE_FORMAT spec as placeholders.
		switch ( $interval ) {
			case 'week':
				// Monday-anchored week start.
				$bucket_expr = "DATE_FORMAT(DATE_SUB({$date_column}, INTERVAL WEEKDAY({$date_column}) DAY), '%%Y-%%m-%%d')";
				break;
			case 'month':
				$bucket_expr = "DATE_FORMAT({$date_column}, '%%Y-%%m-01')";
				break;
			case 'day':
			default:
				$bucket_expr = "DATE_FORMAT({$date_column}, '%%Y-%%m-%%d')";
				break;
		}

		// Single pass: paid CASE aggregates (for the headline per bucket) +
		// pipeline CASE aggregates (for the AR-trend summary). The WHERE includes
		// paid + pipeline statuses so both CASE families see the rows they need.
		$sql = $wpdb->prepare(
			"SELECT
				{$bucket_expr} AS bucket,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN customer_id END) AS total_customers,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 0 THEN customer_id END) AS new_customers,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 1 THEN customer_id END) AS returning_customers,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS orders_count,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 0 THEN 1 ELSE 0 END) AS new_customer_orders,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 1 THEN 1 ELSE 0 END) AS returning_customer_orders,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN net_total ELSE 0 END) AS net_sales,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 0 THEN net_total ELSE 0 END) AS new_customer_net_sales,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) AND returning_customer = 1 THEN net_total ELSE 0 END) AS returning_customer_net_sales,
				COUNT(DISTINCT CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN customer_id END) AS pipeline_customers,
				SUM(CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders
			FROM {$table}
			WHERE {$date_column} >= %s
				AND {$date_column} <= %s
				AND status IN ({$paid_ph}, {$pipeline_ph})
			GROUP BY bucket
			ORDER BY bucket ASC",
			array_merge(
				$paid_statuses,     // total_customers.
				$paid_statuses,     // new_customers.
				$paid_statuses,     // returning_customers.
				$paid_statuses,     // orders_count.
				$paid_statuses,     // new_customer_orders.
				$paid_statuses,     // returning_customer_orders.
				$paid_statuses,     // net_sales.
				$paid_statuses,     // new_customer_net_sales.
				$paid_statuses,     // returning_customer_net_sales.
				$pipeline_statuses, // pipeline_customers.
				$pipeline_statuses, // pipeline_orders.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$paid_statuses,     // WHERE paid.
				$pipeline_statuses  // WHERE pipeline.
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		$series = array();
		foreach ( $rows as $row ) {
			// Cap series length — see $cap / TIMESERIES_MAX_BUCKETS.
			if ( count( $series ) >= $cap ) {
				break;
			}

			$total_customers     = (int) $row['total_customers'];
			$new_customers       = (int) $row['new_customers'];
			$returning_customers = (int) $row['returning_customers'];

			// Overlap: a customer who placed their first-ever order AND a second
			// order both within this bucket (creation-time flag artefact). The
			// edge case fires at every bucket granularity, not just at the top
			// level — included so Claude can explain (new + returning) > total
			// in any given bucket.
			$overlap_customers = max( 0, ( $new_customers + $returning_customers ) - $total_customers );

			$new_percent       = ( $total_customers > 0 ) ? round( ( $new_customers / $total_customers ) * 100, 1 ) : 0.0;
			$returning_percent = ( $total_customers > 0 ) ? round( ( $returning_customers / $total_customers ) * 100, 1 ) : 0.0;
			$repeat_rate       = ( $total_customers > 0 ) ? round( ( $returning_customers / $total_customers ) * 100, 1 ) : 0.0;

			$new_orders       = (int) $row['new_customer_orders'];
			$returning_orders = (int) $row['returning_customer_orders'];
			$new_sales        = (float) $row['new_customer_net_sales'];
			$returning_sales  = (float) $row['returning_customer_net_sales'];

			$new_spend_per_customer       = ( $new_customers > 0 )
				? round( $new_sales / $new_customers, 2 )
				: 0.00;
			$returning_spend_per_customer = ( $returning_customers > 0 )
				? round( $returning_sales / $returning_customers, 2 )
				: 0.00;

			$series[] = array(
				'bucket'                                => $row['bucket'],
				'total_customers'                       => $total_customers,
				'new_customers'                         => $new_customers,
				'returning_customers'                   => $returning_customers,
				'overlap_customers'                     => $overlap_customers,
				'new_customer_percent'                  => $new_percent,
				'returning_customer_percent'            => $returning_percent,
				'repeat_rate_percent'                   => $repeat_rate,
				'orders_count'                          => (int) $row['orders_count'],
				'new_customer_orders'                   => $new_orders,
				'returning_customer_orders'             => $returning_orders,
				'net_sales'                             => round( (float) $row['net_sales'], 2 ),
				'new_customer_net_sales'                => round( $new_sales, 2 ),
				'returning_customer_net_sales'          => round( $returning_sales, 2 ),
				'new_customer_spend_per_customer'       => $new_spend_per_customer,
				'returning_customer_spend_per_customer' => $returning_spend_per_customer,
				'pipeline_customers'                    => (int) $row['pipeline_customers'],
				'pipeline_orders'                       => (int) $row['pipeline_orders'],
			);
		}

		return $series;
	}

	/**
	 * Return zeroed customer metrics for empty date ranges.
	 */
	private static function empty_customer_metrics() {
		return array(
			'total_customers'                        => 0,
			'new_customers'                          => 0,
			'returning_customers'                    => 0,
			'overlap_customers'                      => 0,
			'new_customer_percent'                   => 0.0,
			'returning_customer_percent'             => 0.0,
			'repeat_rate_percent'                    => 0.0,
			'orders_count'                           => 0,
			'new_customer_orders'                    => 0,
			'returning_customer_orders'              => 0,
			'net_sales'                              => 0.00,
			'new_customer_net_sales'                 => 0.00,
			'returning_customer_net_sales'           => 0.00,
			'new_customer_avg_order_value'           => 0.00,
			'returning_customer_avg_order_value'     => 0.00,
			'new_customer_orders_per_customer'       => 0.0,
			'returning_customer_orders_per_customer' => 0.0,
			'new_customer_spend_per_customer'        => 0.00,
			'returning_customer_spend_per_customer'  => 0.00,
			'_pipeline_customers'                    => 0,
			'_pipeline_new_customers'                => 0,
			'_pipeline_returning_customers'          => 0,
			'_pipeline_orders'                       => 0,
			'_admin_new_customers'                   => 0,
			'_admin_returning_customers'             => 0,
			'_admin_orders'                          => 0,
			'_admin_net_sales'                       => 0.00,
		);
	}

	/**
	 * Split internal customer metrics into primary + pipeline + admin_equivalent.
	 *
	 * @param array $row Raw metrics row from query_customer_metrics.
	 * @return array [ primary, pipeline, admin_equivalent ].
	 */
	private static function split_customer_views( $row ) {
		$primary = $row;
		unset(
			$primary['_pipeline_customers'],
			$primary['_pipeline_new_customers'],
			$primary['_pipeline_returning_customers'],
			$primary['_pipeline_orders'],
			$primary['_admin_new_customers'],
			$primary['_admin_returning_customers'],
			$primary['_admin_orders'],
			$primary['_admin_net_sales']
		);
		$primary['definition'] = 'Paid statuses only (processing + completed). total_customers = COUNT(DISTINCT customer_id) to avoid double-counting customers whose returning_customer flag flips within the period. new_customers / returning_customers are distinct counts per flag value. overlap_customers = (new + returning) - total and surfaces the creation-time-flag edge case.';

		$pipeline_new       = isset( $row['_pipeline_new_customers'] ) ? (int) $row['_pipeline_new_customers'] : 0;
		$pipeline_returning = isset( $row['_pipeline_returning_customers'] ) ? (int) $row['_pipeline_returning_customers'] : 0;

		$pipeline = array(
			'pipeline_customers'           => isset( $row['_pipeline_customers'] ) ? (int) $row['_pipeline_customers'] : 0,
			'pipeline_new_customers'       => $pipeline_new,
			'pipeline_returning_customers' => $pipeline_returning,
			'pipeline_orders'              => isset( $row['_pipeline_orders'] ) ? (int) $row['_pipeline_orders'] : 0,
			'definition'                   => 'Customers with on-hold orders in this period (awaiting payment — bank transfer, BACS, cheque, invoice). Not yet paying customers.',
		);

		$admin_new       = isset( $row['_admin_new_customers'] ) ? (int) $row['_admin_new_customers'] : 0;
		$admin_returning = isset( $row['_admin_returning_customers'] ) ? (int) $row['_admin_returning_customers'] : 0;
		$admin_total     = $admin_new + $admin_returning; // Match WC Admin's sum convention.

		$admin_equivalent = array(
			'total_customers'     => $admin_total,
			'new_customers'       => $admin_new,
			'returning_customers' => $admin_returning,
			'orders_count'        => isset( $row['_admin_orders'] ) ? (int) $row['_admin_orders'] : 0,
			'net_sales'           => isset( $row['_admin_net_sales'] ) ? (float) $row['_admin_net_sales'] : 0.00,
			'definition'          => 'What WC Admin Reports > Customers shows: paid + on-hold + refunded statuses, total_customers = new + returning (can double-count customers whose flag flips within the period). Use for reconciliation against the admin dashboard.',
		);

		return array( $primary, $pipeline, $admin_equivalent );
	}

	/**
	 * Pre-compute percentage changes for customer metrics.
	 *
	 * @param array $current  Current period customer metrics.
	 * @param array $previous Previous period customer metrics.
	 * @return array Per-key change rows with amount, percent, direction.
	 */
	private static function calculate_customer_changes( $current, $previous ) {
		$compare_keys = array(
			'total_customers',
			'new_customers',
			'returning_customers',
			'repeat_rate_percent',
			'orders_count',
			'new_customer_orders',
			'returning_customer_orders',
			'net_sales',
			'new_customer_net_sales',
			'returning_customer_net_sales',
			'new_customer_avg_order_value',
			'returning_customer_avg_order_value',
			'new_customer_orders_per_customer',
			'returning_customer_orders_per_customer',
			'new_customer_spend_per_customer',
			'returning_customer_spend_per_customer',
		);

		$changes = array();
		foreach ( $compare_keys as $key ) {
			$curr = (float) $current[ $key ];
			$prev = (float) $previous[ $key ];
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	/**
	 * Per-group change on the orderby metric. Attached to top_groups entries
	 * when compare=true and the group appears in both periods.
	 *
	 * @param array  $current  Current period group row.
	 * @param array  $previous Previous period group row.
	 * @param string $orderby  Metric name to compare on.
	 * @return array Change row with metric, amount, percent, direction.
	 */
	private static function calculate_attribution_change( $current, $previous, $orderby ) {
		$curr = (float) $current[ $orderby ];
		$prev = (float) $previous[ $orderby ];
		$diff = $curr - $prev;

		if ( 0.0 === $prev ) {
			$percent = ( $curr > 0 ) ? 100.0 : 0.0;
		} else {
			$percent = round( ( $diff / $prev ) * 100, 1 );
		}

		if ( $diff > 0 ) {
			$direction = 'up';
		} elseif ( $diff < 0 ) {
			$direction = 'down';
		} else {
			$direction = 'flat';
		}

		return array(
			'metric'    => $orderby,
			'amount'    => round( $diff, 2 ),
			'percent'   => $percent,
			'direction' => $direction,
		);
	}

	// Customer Value.

	/**
	 * Fetch the customer-value payload. Backs the
	 * `wc-analytics/get-customer-value` ability.
	 *
	 * @param string      $period          Period shortcut.
	 * @param string|null $date_start      Custom start date — overrides period.
	 * @param string|null $date_end        Custom end date — overrides period.
	 * @param bool        $compare         Include previous-period comparison.
	 * @param int         $limit           Number of top customers to return.
	 * @param bool        $include_cohorts Include cohort-retention matrix.
	 * @return array Response payload.
	 */
	public static function fetch_customer_value( $period, $date_start, $date_end, $compare, $limit, $include_cohorts ) {
		$start = microtime( true );

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		$cache_key = 'woocommerce_claude_customer_value_' . md5(
			$dates['start'] . '_' . $dates['end']
			. '_' . ( $compare ? '1' : '0' )
			. '_' . $limit
			. '_' . ( $include_cohorts ? '1' : '0' )
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$rows = self::query_lifetime_aggregates( $dates['start'], $dates['end'] );

		$metrics       = self::compute_metrics( $rows );
		$segments      = self::compute_segments( $rows );
		$opportunities = self::compute_opportunities( $segments );
		$items         = self::compute_items_histogram( $rows );

		$customer_ids  = array_map(
			function ( $row ) {
				return (int) $row['customer_id'];
			},
			$rows
		);
		$top_customers = self::build_top_customers( $rows, $limit );
		$time_between  = self::query_time_between_orders( $customer_ids );

		$cohorts = null;
		if ( $include_cohorts ) {
			$cohorts = self::query_cohort_retention( $dates['start'], $dates['end'] );
		}

		$result = array(
			'period'              => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'            => get_woocommerce_currency(),
			'privacy_mode'        => 'pseudonymised',
			'metrics'             => $metrics,
			'segments'            => $segments,
			'opportunities'       => $opportunities,
			'top_customers'       => $top_customers,
			'items_over_lifetime' => $items,
			'cohorts'             => $cohorts,
			'time_between_orders' => $time_between,
			'comparison'          => null,
			'note'                => null,
			'privacy_note'        => 'Top customers are pseudonymised (Customer #N). Real names and emails are never returned — the merchant looks up the identity behind a `Customer #N` in WP Admin > WooCommerce > Customers.',
		);

		if ( 0 === $metrics['active_customers'] ) {
			$result['note'] = 'No paid orders found for this date range — no active-customer base to summarise.';
		}

		if ( $compare ) {
			$prev_dates    = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_rows     = self::query_lifetime_aggregates( $prev_dates['start'], $prev_dates['end'] );
			$prev_metrics  = self::compute_metrics( $prev_rows );
			$prev_segments = self::compute_segments( $prev_rows );
			$changes       = self::calculate_customer_value_changes( $metrics, $prev_metrics );

			$result['comparison'] = array(
				'period'   => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'metrics'  => $prev_metrics,
				'segments' => $prev_segments,
				'changes'  => $changes,
			);
		}

		set_transient( $cache_key, $result, self::CUSTOMER_VALUE_CACHE_TTL );

		return $result;
	}

	/**
	 * Query per-customer lifetime aggregates for customers active in the
	 * period. Each row = one customer × their full lifetime history of
	 * paid orders.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_lifetime_aggregates( $date_start, $date_end ) {
		global $wpdb;

		$table       = $wpdb->prefix . 'wc_order_stats';
		$statuses    = self::get_paid_statuses();
		$status_ph   = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$date_column = self::get_date_column();

		// Inner subquery identifies customers active in the period.
		// Outer query sums lifetime stats for those customers across
		// their entire paid order history (not filtered to the period).
		$sql = $wpdb->prepare(
			"SELECT
				os.customer_id,
				SUM(os.net_total) AS lifetime_spend,
				COUNT(DISTINCT os.order_id) AS lifetime_orders,
				SUM(os.num_items_sold) AS lifetime_items,
				MIN(os.{$date_column}) AS first_order,
				MAX(os.{$date_column}) AS last_order
			FROM {$table} os
			WHERE os.parent_id = 0
				AND os.status IN ({$status_ph})
				AND os.customer_id > 0
				AND os.customer_id IN (
					SELECT DISTINCT customer_id
					FROM {$table}
					WHERE {$date_column} >= %s
						AND {$date_column} <= %s
						AND parent_id = 0
						AND status IN ({$status_ph})
						AND customer_id > 0
				)
			GROUP BY os.customer_id",
			array_merge(
				$statuses, // outer IN.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$statuses  // inner IN.
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Roll up metrics from per-customer lifetime rows.
	 *
	 * @param array $rows Per-customer lifetime rows from the active-base query.
	 * @return array
	 */
	private static function compute_metrics( $rows ) {
		$count = count( $rows );

		if ( 0 === $count ) {
			return array(
				'active_customers'        => 0,
				'avg_lifetime_spend'      => 0.00,
				'median_lifetime_spend'   => 0.00,
				'max_lifetime_spend'      => 0.00,
				'avg_lifetime_orders'     => 0.0,
				'avg_lifetime_items'      => 0.0,
				'avg_days_between_orders' => null,
				'definition'              => 'Lifetime metrics for customers with at least one paid order in this period. Lifetime spend = SUM(net_total) across every paid order that customer has ever placed (completed + processing only; refund sub-orders excluded). Based on wc_get_is_paid_statuses() — matches the headline paid view in other skills.',
			);
		}

		$spends = array();
		$orders = array();
		$items  = array();

		foreach ( $rows as $row ) {
			$spends[] = (float) $row['lifetime_spend'];
			$orders[] = (int) $row['lifetime_orders'];
			$items[]  = (int) $row['lifetime_items'];
		}

		sort( $spends );
		$mid    = (int) floor( $count / 2 );
		$median = ( 0 === $count % 2 )
			? ( ( $spends[ $mid - 1 ] + $spends[ $mid ] ) / 2 )
			: $spends[ $mid ];

		$avg_orders = array_sum( $orders ) / $count;
		$avg_items  = array_sum( $items ) / $count;

		// avg_days_between_orders is computed from the time-between-orders
		// query and pasted into metrics there — here we default to null so
		// the block stays self-consistent when the query fails.
		return array(
			'active_customers'        => $count,
			'avg_lifetime_spend'      => round( array_sum( $spends ) / $count, 2 ),
			'median_lifetime_spend'   => round( $median, 2 ),
			'max_lifetime_spend'      => round( max( $spends ), 2 ),
			'avg_lifetime_orders'     => round( $avg_orders, 2 ),
			'avg_lifetime_items'      => round( $avg_items, 2 ),
			'avg_days_between_orders' => null,
			'definition'              => 'Lifetime metrics for customers with at least one paid order in this period. Lifetime spend = SUM(net_total) across every paid order that customer has ever placed (completed + processing only; refund sub-orders excluded). Based on wc_get_is_paid_statuses() — matches the headline paid view in other skills.',
		);
	}

	/**
	 * Split active-base into one-time vs repeat lifetime segments.
	 *
	 * @param array $rows Per-customer lifetime rows from the active-base query.
	 * @return array
	 */
	private static function compute_segments( $rows ) {
		$one_time = array(
			'customers'            => 0,
			'share_percent'        => 0.0,
			'total_lifetime_spend' => 0.00,
			'avg_lifetime_spend'   => 0.00,
			'avg_order_value'      => 0.00,
			'avg_lifetime_orders'  => 1.0,
			'definition'           => 'Customers whose full lifetime history consists of a single paid order (across all time, not just this period).',
		);
		$repeat   = array(
			'customers'            => 0,
			'share_percent'        => 0.0,
			'total_lifetime_spend' => 0.00,
			'avg_lifetime_spend'   => 0.00,
			'avg_order_value'      => 0.00,
			'avg_lifetime_orders'  => 0.0,
			'definition'           => 'Customers with two or more paid orders over their full lifetime. AOV here = total lifetime spend / total lifetime orders (per-order average within the segment).',
		);

		$one_time_spend  = 0.0;
		$repeat_spend    = 0.0;
		$repeat_orders   = 0;
		$total_customers = count( $rows );

		foreach ( $rows as $row ) {
			if ( 1 === (int) $row['lifetime_orders'] ) {
				++$one_time['customers'];
				$one_time_spend += (float) $row['lifetime_spend'];
			} else {
				++$repeat['customers'];
				$repeat_spend  += (float) $row['lifetime_spend'];
				$repeat_orders += (int) $row['lifetime_orders'];
			}
		}

		if ( $one_time['customers'] > 0 ) {
			$one_time['total_lifetime_spend'] = round( $one_time_spend, 2 );
			$one_time['avg_lifetime_spend']   = round( $one_time_spend / $one_time['customers'], 2 );
			$one_time['avg_order_value']      = $one_time['avg_lifetime_spend'];
		}
		if ( $repeat['customers'] > 0 ) {
			$repeat['total_lifetime_spend'] = round( $repeat_spend, 2 );
			$repeat['avg_lifetime_spend']   = round( $repeat_spend / $repeat['customers'], 2 );
			$repeat['avg_lifetime_orders']  = round( $repeat_orders / $repeat['customers'], 2 );
			$repeat['avg_order_value']      = $repeat_orders > 0 ? round( $repeat_spend / $repeat_orders, 2 ) : 0.00;
		}
		if ( $total_customers > 0 ) {
			$one_time['share_percent'] = round( $one_time['customers'] * 100 / $total_customers, 1 );
			$repeat['share_percent']   = round( $repeat['customers'] * 100 / $total_customers, 1 );
		}

		return array(
			'one_time' => $one_time,
			'repeat'   => $repeat,
		);
	}

	/**
	 * Pre-computed lever table so Claude reports uplift scenarios rather
	 * than deriving them from `segments.one_time` / `segments.repeat` by
	 * hand. Single arithmetic step per scenario; zero free-hand maths.
	 *
	 * `uplift_per_conversion` can be negative on stores where the
	 * currently-active one-time base happens to have spent more on their
	 * single order than the repeat segment has on each of theirs. Return
	 * the block anyway — Claude can read the sign and frame it as
	 * "no lever here" rather than guessing.
	 *
	 * @param array $segments Output of compute_segments().
	 * @return array
	 */
	private static function compute_opportunities( $segments ) {
		$stranded        = (int) ( $segments['one_time']['customers'] ?? 0 );
		$stranded_avg    = (float) ( $segments['one_time']['avg_lifetime_spend'] ?? 0.0 );
		$repeat_avg      = (float) ( $segments['repeat']['avg_lifetime_spend'] ?? 0.0 );
		$uplift_per_conv = round( $repeat_avg - $stranded_avg, 2 );
		$rates           = array( 10, 25, 50 );
		$scenarios       = array();

		foreach ( $rates as $rate ) {
			$conversions = (int) round( $stranded * ( $rate / 100 ) );
			$scenarios[] = array(
				'conversion_rate_percent' => $rate,
				'conversions'             => $conversions,
				'estimated_uplift'        => round( $conversions * $uplift_per_conv, 2 ),
			);
		}

		return array(
			'one_to_repeat_conversion' => array(
				'stranded_customers'    => $stranded,
				'stranded_avg_lifetime' => round( $stranded_avg, 2 ),
				'repeat_avg_lifetime'   => round( $repeat_avg, 2 ),
				'uplift_per_conversion' => $uplift_per_conv,
				'scenarios'             => $scenarios,
				'definition'            => 'Pre-computed scenario table for converting one-time buyers to repeaters. conversions = round(stranded_customers × conversion_rate_percent / 100); estimated_uplift = conversions × uplift_per_conversion. Uplift can be negative when the active one-time base has out-spent the repeat segment on average — report the sign honestly; do not recompute.',
			),
		);
	}

	/**
	 * Build the top_customers list. Sorted by lifetime_spend desc. Always
	 * pseudonymised — real names / emails are never surfaced.
	 *
	 * @param array $rows  Per-customer lifetime rows from the active-base query.
	 * @param int   $limit Number of rows to return.
	 * @return array
	 */
	private static function build_top_customers( $rows, $limit ) {
		if ( empty( $rows ) ) {
			return array();
		}

		// Sort by lifetime_spend desc.
		usort(
			$rows,
			function ( $a, $b ) {
				return (float) $b['lifetime_spend'] <=> (float) $a['lifetime_spend'];
			}
		);

		$top = array_slice( $rows, 0, $limit );

		// Hydrate country (not PII — country is fine for analytics narratives).
		$customer_ids = array_map(
			function ( $row ) {
				return (int) $row['customer_id'];
			},
			$top
		);
		$lookups      = self::fetch_customer_lookup( $customer_ids );

		$result = array();
		foreach ( $top as $row ) {
			$cid      = (int) $row['customer_id'];
			$lifetime = (float) $row['lifetime_spend'];
			$orders   = (int) $row['lifetime_orders'];
			$aov      = $orders > 0 ? round( $lifetime / $orders, 2 ) : 0.00;
			$lookup   = $lookups[ $cid ] ?? array();

			$result[] = array(
				'id'              => 'Customer #' . $cid,
				'admin_url'       => self::customer_admin_url( $cid ),
				'lifetime_orders' => $orders,
				'lifetime_spend'  => round( $lifetime, 2 ),
				'avg_order_value' => $aov,
				'first_order'     => self::format_date( $row['first_order'] ),
				'last_order'      => self::format_date( $row['last_order'] ),
				'country'         => $lookup['country'] ?? null,
			);
		}

		return $result;
	}

	/**
	 * Fetch country from wc_customer_lookup for a set of customer ids.
	 * Returns a map keyed by customer_id. PII columns (first_name,
	 * last_name, email) are never selected.
	 *
	 * @param int[] $customer_ids Customer IDs to hydrate.
	 * @return array
	 */
	private static function fetch_customer_lookup( $customer_ids ) {
		if ( empty( $customer_ids ) ) {
			return array();
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'wc_customer_lookup';
		$ids_ph = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );

		$sql = $wpdb->prepare(
			"SELECT customer_id, country
			FROM {$table}
			WHERE customer_id IN ({$ids_ph})",
			$customer_ids
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row['customer_id'] ] = $row;
		}
		return $map;
	}

	/**
	 * Items-over-lifetime histogram. Buckets per-customer total items sold.
	 *
	 * @param array $rows Per-customer lifetime rows from the active-base query.
	 * @return array
	 */
	private static function compute_items_histogram( $rows ) {
		$buckets = array(
			'1 item'      => array(
				'min' => 0,
				'max' => 1,
			),
			'2-3 items'   => array(
				'min' => 2,
				'max' => 3,
			),
			'4-5 items'   => array(
				'min' => 4,
				'max' => 5,
			),
			'6-10 items'  => array(
				'min' => 6,
				'max' => 10,
			),
			'11-20 items' => array(
				'min' => 11,
				'max' => 20,
			),
			'20+ items'   => array(
				'min' => 21,
				'max' => PHP_INT_MAX,
			),
		);

		$result = array();
		foreach ( array_keys( $buckets ) as $label ) {
			$result[] = array(
				'bucket'          => $label,
				'customers_count' => 0,
				'avg_items'       => 0.0,
				'avg_spend'       => 0.00,
				'share_percent'   => 0.0,
			);
		}

		$total    = count( $rows );
		$by_label = array();
		foreach ( $buckets as $label => $_ ) {
			$by_label[ $label ] = array(
				'items' => array(),
				'spend' => array(),
			);
		}

		foreach ( $rows as $row ) {
			$items = (int) $row['lifetime_items'];
			foreach ( $buckets as $label => $range ) {
				if ( $items >= $range['min'] && $items <= $range['max'] ) {
					$by_label[ $label ]['items'][] = $items;
					$by_label[ $label ]['spend'][] = (float) $row['lifetime_spend'];
					break;
				}
			}
		}

		$i = 0;
		foreach ( $buckets as $label => $_ ) {
			$bucket_rows                     = $by_label[ $label ];
			$count                           = count( $bucket_rows['items'] );
			$result[ $i ]['customers_count'] = $count;
			$result[ $i ]['avg_items']       = $count > 0 ? round( array_sum( $bucket_rows['items'] ) / $count, 1 ) : 0.0;
			$result[ $i ]['avg_spend']       = $count > 0 ? round( array_sum( $bucket_rows['spend'] ) / $count, 2 ) : 0.00;
			$result[ $i ]['share_percent']   = $total > 0 ? round( $count * 100 / $total, 1 ) : 0.0;
			++$i;
		}

		return array(
			'buckets'    => $result,
			'definition' => 'Distribution of customers in the active base by total items purchased across their full lifetime (sum of num_items_sold across every paid order).',
		);
	}

	/**
	 * Time between consecutive paid orders for active-base repeaters.
	 * Uses LAG() window function (MySQL 8+ / MariaDB 10.2+).
	 *
	 * @param int[] $customer_ids Active-base customer IDs to compute gaps for.
	 * @return array
	 */
	private static function query_time_between_orders( $customer_ids ) {
		$empty = array(
			'avg_days'    => null,
			'repeat_gaps' => 0,
			'buckets'     => array(),
			'definition'  => 'Gaps between consecutive paid orders, per customer, for repeaters in the active base. Bucketed. Uses the LAG window function — requires MySQL 8+ or MariaDB 10.2+.',
		);

		if ( empty( $customer_ids ) ) {
			return $empty;
		}

		global $wpdb;
		$table       = $wpdb->prefix . 'wc_order_stats';
		$statuses    = self::get_paid_statuses();
		$status_ph   = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$ids_ph      = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$date_column = self::get_date_column();

		$sql = $wpdb->prepare(
			"SELECT days_between
			FROM (
				SELECT DATEDIFF(
					{$date_column},
					LAG({$date_column}) OVER (PARTITION BY customer_id ORDER BY {$date_column})
				) AS days_between
				FROM {$table}
				WHERE parent_id = 0
					AND status IN ({$status_ph})
					AND customer_id > 0
					AND customer_id IN ({$ids_ph})
			) gaps
			WHERE days_between IS NOT NULL",
			array_merge( $statuses, $customer_ids )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return $empty;
		}

		$bucket_defs = array(
			'Under 1 week' => array( 'max' => 6 ),
			'1-4 weeks'    => array( 'max' => 29 ),
			'1-3 months'   => array( 'max' => 89 ),
			'3-6 months'   => array( 'max' => 179 ),
			'6-12 months'  => array( 'max' => 364 ),
			'12+ months'   => array( 'max' => PHP_INT_MAX ),
		);

		$counts     = array_fill_keys( array_keys( $bucket_defs ), 0 );
		$sum_days   = array_fill_keys( array_keys( $bucket_defs ), 0 );
		$total_gaps = 0;
		$total_days = 0;

		foreach ( $rows as $row ) {
			$days = (int) $row['days_between'];
			++$total_gaps;
			$total_days += $days;

			foreach ( $bucket_defs as $label => $def ) {
				if ( $days <= $def['max'] ) {
					++$counts[ $label ];
					$sum_days[ $label ] += $days;
					break;
				}
			}
		}

		$buckets = array();
		foreach ( $bucket_defs as $label => $_ ) {
			$c         = $counts[ $label ];
			$buckets[] = array(
				'bucket'        => $label,
				'repeat_gaps'   => $c,
				'avg_days'      => $c > 0 ? round( $sum_days[ $label ] / $c, 1 ) : 0.0,
				'share_percent' => $total_gaps > 0 ? round( $c * 100 / $total_gaps, 1 ) : 0.0,
			);
		}

		return array(
			'avg_days'    => $total_gaps > 0 ? round( $total_days / $total_gaps, 1 ) : null,
			'repeat_gaps' => $total_gaps,
			'buckets'     => $buckets,
			'definition'  => 'Gaps between consecutive paid orders, per customer, for repeaters in the active base. avg_days is the mean across all gaps. Each gap is one customer moving from order N to order N+1. A customer with 3 orders contributes 2 gaps.',
		);
	}

	/**
	 * Cohort retention matrix — customers whose first paid order lands in
	 * the period, tracked forward through time.
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array
	 */
	private static function query_cohort_retention( $date_start, $date_end ) {
		global $wpdb;

		$table       = $wpdb->prefix . 'wc_order_stats';
		$statuses    = self::get_paid_statuses();
		$status_ph   = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$date_column = self::get_date_column();

		// Cohort sizes: customers whose first paid order falls in the period,
		// grouped by YYYY-MM of first order. This is the denominator for
		// retention percentages at each offset. `lifetime_repeaters` counts
		// cohort members who have ever returned (≥ 2 paid orders in their
		// full lifetime) — feeds the per-cohort `lifetime_retention_percent`
		// ("ever returned") without an additional query pass.
		$sizes_sql = $wpdb->prepare(
			"SELECT DATE_FORMAT(first_order, '%%Y-%%m') AS cohort_month,
				COUNT(*) AS cohort_size,
				MIN(first_order) AS cohort_first_order,
				SUM(CASE WHEN lifetime_orders > 1 THEN 1 ELSE 0 END) AS lifetime_repeaters
			FROM (
				SELECT customer_id,
					MIN({$date_column}) AS first_order,
					COUNT(*) AS lifetime_orders
				FROM {$table}
				WHERE parent_id = 0 AND status IN ({$status_ph}) AND customer_id > 0
				GROUP BY customer_id
				HAVING MIN({$date_column}) >= %s AND MIN({$date_column}) <= %s
			) first_orders
			GROUP BY cohort_month
			ORDER BY cohort_month",
			array_merge( $statuses, array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ) )
		);

		$sizes = $wpdb->get_results( $sizes_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $sizes ) ) {
			return array();
		}

		// Retention matrix: for customers in each cohort, count orders at
		// each month offset from their first order. month=0 will always
		// equal the cohort size (the first order itself).
		$matrix_sql = $wpdb->prepare(
			"SELECT
				DATE_FORMAT(cohorts.first_order, '%%Y-%%m') AS cohort_month,
				TIMESTAMPDIFF(MONTH, cohorts.first_order, os.{$date_column}) AS months_since,
				COUNT(DISTINCT os.customer_id) AS customers_retained,
				SUM(os.net_total) AS revenue
			FROM {$table} os
			JOIN (
				SELECT customer_id, MIN({$date_column}) AS first_order
				FROM {$table}
				WHERE parent_id = 0 AND status IN ({$status_ph}) AND customer_id > 0
				GROUP BY customer_id
				HAVING MIN({$date_column}) >= %s AND MIN({$date_column}) <= %s
			) cohorts ON cohorts.customer_id = os.customer_id
			WHERE os.parent_id = 0 AND os.status IN ({$status_ph})
			GROUP BY cohort_month, months_since
			ORDER BY cohort_month, months_since",
			array_merge( $statuses, array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ), $statuses )
		);

		$matrix = $wpdb->get_results( $matrix_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $matrix ) ) {
			$matrix = array();
		}

		/*
		 * Assemble the nested shape. Each cohort carries:
		 * - `months_since_acquisition` — months elapsed between the cohort's
		 *   month anchor (first-of-month) and today. Stable across customers
		 *   in the same cohort regardless of their exact first-order day.
		 * - `maturity` — "mature" once month-1 retention has had time to
		 *   fully land (≥ 2 months since anchor), otherwise "ongoing".
		 *   Teaches Claude to call out incomplete cohorts without guessing
		 *   the cutoff.
		 * - `lifetime_retention_percent` — share of the cohort that has
		 *   ever returned (≥ 2 lifetime paid orders), cumulative across the
		 *   entire customer lifetime. Cleanest longitudinal retention number,
		 *   not affected by month-N slicing.
		 */
		$by_cohort = array();
		$now       = new \DateTime( 'now' );
		foreach ( $sizes as $row ) {
			$cohort                   = $row['cohort_month'];
			$size                     = (int) $row['cohort_size'];
			$repeaters                = (int) $row['lifetime_repeaters'];
			$months_since_acquisition = self::months_between( $cohort . '-01', $now );
			$maturity                 = $months_since_acquisition >= self::MATURITY_THRESHOLD_MONTHS
				? 'mature'
				: 'ongoing';

			// `flips_to_mature_on` pre-computes the exact date an ongoing
			// cohort reaches the maturity threshold. Kills the off-by-a-month
			// narration that surfaces when Claude derives flip timing by
			// hand ("end of April" / "mid-June" rather than the structural
			// YYYY-MM-01 anchor).
			$flips_on = null;
			if ( 'ongoing' === $maturity ) {
				$flip_dt = \DateTime::createFromFormat( 'Y-m-d', $cohort . '-01' );
				if ( $flip_dt ) {
					$flip_dt->modify( '+' . self::MATURITY_THRESHOLD_MONTHS . ' months' );
					$flips_on = $flip_dt->format( 'Y-m-d' );
				}
			}

			$by_cohort[ $cohort ] = array(
				'cohort'                     => $cohort,
				'size'                       => $size,
				'months_since_acquisition'   => $months_since_acquisition,
				'maturity'                   => $maturity,
				'maturity_threshold_months'  => self::MATURITY_THRESHOLD_MONTHS,
				'flips_to_mature_on'         => $flips_on,
				'lifetime_retention_percent' => $size > 0 ? round( $repeaters * 100 / $size, 1 ) : 0.0,
				'offsets'                    => array(),
			);
		}

		$cum_revenue = array();
		foreach ( $matrix as $row ) {
			$cohort = $row['cohort_month'];
			if ( ! isset( $by_cohort[ $cohort ] ) ) {
				continue;
			}
			$month    = (int) $row['months_since'];
			$retained = (int) $row['customers_retained'];
			$revenue  = (float) $row['revenue'];
			$size     = $by_cohort[ $cohort ]['size'];

			$cum_revenue[ $cohort ] = ( $cum_revenue[ $cohort ] ?? 0 ) + $revenue;
			$retention_percent      = $size > 0 ? round( $retained * 100 / $size, 1 ) : 0.0;
			$cumulative_avg_ltv     = $size > 0 ? round( $cum_revenue[ $cohort ] / $size, 2 ) : 0.00;

			$by_cohort[ $cohort ]['offsets'][] = array(
				'month'              => $month,
				'customers'          => $retained,
				'retention_percent'  => $retention_percent,
				'revenue'            => round( $revenue, 2 ),
				'cumulative_revenue' => round( $cum_revenue[ $cohort ], 2 ),
				'cumulative_avg_ltv' => $cumulative_avg_ltv,
			);
		}

		return array_values( $by_cohort );
	}

	/**
	 * Calculate pre-computed deltas on the skill's headline metrics.
	 *
	 * @param array $current  Current-period response payload.
	 * @param array $previous Previous-period response payload (same shape).
	 * @return array
	 */
	private static function calculate_customer_value_changes( $current, $previous ) {
		$keys = array(
			'active_customers',
			'avg_lifetime_spend',
			'median_lifetime_spend',
			'max_lifetime_spend',
			'avg_lifetime_orders',
			'avg_lifetime_items',
		);

		$changes = array();
		foreach ( $keys as $key ) {
			$curr = (float) ( $current[ $key ] ?? 0 );
			$prev = (float) ( $previous[ $key ] ?? 0 );
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	/**
	 * Whole months elapsed between a `YYYY-MM-DD` anchor and a DateTime.
	 * Both operands are normalised to their first-of-month at midnight
	 * before diffing so a cohort's "age" is stable regardless of which
	 * day in the month its earliest first_order landed on.
	 *
	 * @param string    $anchor YYYY-MM-DD (typically cohort-month + '-01').
	 * @param \DateTime $now    Reference "now" for the comparison.
	 * @return int
	 */
	private static function months_between( $anchor, $now ) {
		$anchor_dt = \DateTime::createFromFormat( 'Y-m-d', $anchor );
		if ( ! $anchor_dt ) {
			return 0;
		}
		$anchor_dt->setTime( 0, 0, 0 );
		$anchor_dt->modify( 'first day of this month' );

		$now_dt = clone $now;
		$now_dt->setTime( 0, 0, 0 );
		$now_dt->modify( 'first day of this month' );

		if ( $now_dt < $anchor_dt ) {
			return 0;
		}

		$diff = $anchor_dt->diff( $now_dt );
		return (int) ( $diff->y * 12 + $diff->m );
	}

	/**
	 * Normalise a wc_order_stats DATETIME to YYYY-MM-DD or null.
	 *
	 * @param string|null $value Raw DATETIME string from wc_order_stats.
	 * @return string|null
	 */
	private static function format_date( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		// wc_order_stats stores DATETIME. Return the date portion for
		// stability — time of day is meaningless at this analytical level.
		$dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
		if ( ! $dt ) {
			return $value;
		}
		return $dt->format( 'Y-m-d' );
	}

	// ─── Revenue Breakdown ────────────────────────────────────────

	/**
	 * Fetch the revenue-breakdown payload. Backs the
	 * `wc-analytics/get-revenue-breakdown` ability.
	 *
	 * Decomposes paid revenue by ONE of four dimensions per call:
	 * category, country, payment_method, shipping_method. Same
	 * three-view pattern as get_attribution — paid headline,
	 * pipeline sibling for on-hold revenue, admin_equivalent for
	 * dashboard reconciliation. Per-group share_of_revenue_percent
	 * is pre-computed so Claude never divides top_groups[n]/totals.
	 *
	 * Category is product-level (via wc_order_product_lookup); the
	 * other three are order-level (via wc_order_stats + a per-dim JOIN).
	 * Refunds attribute to the parent order's dimension value (country
	 * / payment_method / shipping_method inherit naturally), unlike
	 * attribution which keeps refund meta isolated to the sub-order.
	 *
	 * @param string      $period             Period shortcut.
	 * @param string|null $date_start         Custom start date — overrides period.
	 * @param string|null $date_end           Custom end date — overrides period.
	 * @param bool        $compare            Include previous-period comparison.
	 * @param int         $limit              Number of top groups to return.
	 * @param string      $orderby            Sort column for top_groups.
	 * @param string      $group_by           Dimension to group by.
	 * @param bool        $include_unassigned Include a "(Unassigned)" row.
	 * @return array Response payload.
	 */
	public static function fetch_revenue_breakdown( $period, $date_start, $date_end, $compare, $limit, $orderby, $group_by, $include_unassigned ) {
		$start              = microtime( true );
		$limit              = (int) $limit;
		$include_unassigned = (bool) $include_unassigned;

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		$cache_key = 'woocommerce_claude_revenue_breakdown_' . md5(
			$dates['start'] . '_' . $dates['end']
			. '_' . ( $compare ? '1' : '0' )
			. '_' . $limit
			. '_' . $orderby
			. '_' . $group_by
			. '_' . ( $include_unassigned ? '1' : '0' )
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$top    = self::query_revenue_breakdown_groups( $dates['start'], $dates['end'], $limit, $orderby, $group_by, $include_unassigned );
		$totals = self::query_revenue_breakdown_totals( $dates['start'], $dates['end'], $group_by );

		$totals_pipeline         = array(
			'revenue'      => isset( $totals['_pipeline_revenue'] ) ? (float) $totals['_pipeline_revenue'] : 0.00,
			'orders_count' => isset( $totals['_pipeline_orders_count'] ) ? (int) $totals['_pipeline_orders_count'] : 0,
			'definition'   => 'On-hold orders awaiting payment (all groups combined). Per-group pipeline values are on each top_groups row.',
		);
		$totals_admin_equivalent = array(
			'revenue'      => isset( $totals['_admin_revenue'] ) ? (float) $totals['_admin_revenue'] : 0.00,
			'orders_count' => isset( $totals['_admin_orders_count'] ) ? (int) $totals['_admin_orders_count'] : 0,
			'definition'   => 'What WC Admin Reports shows: paid + on-hold + refunded orders lumped together. Per-group admin_equivalent values are on each top_groups row. Use for dashboard reconciliation only.',
		);
		unset(
			$totals['_pipeline_revenue'],
			$totals['_pipeline_orders_count'],
			$totals['_admin_revenue'],
			$totals['_admin_orders_count']
		);

		$totals['coverage_percent'] = ( $totals['total_paid_orders'] > 0 )
			? round( ( $totals['covered_orders'] / $totals['total_paid_orders'] ) * 100, 1 )
			: 0.0;

		// Per-group share of revenue — always against the full paid revenue,
		// not the sum of top_groups, so it stays correct when include_unassigned
		// flips or the long tail gets clipped by limit.
		$denominator = (float) $totals['net_revenue'];
		foreach ( $top as $i => $group ) {
			$top[ $i ]['share_of_revenue_percent'] = ( $denominator > 0 )
				? round( ( $group['net_revenue'] / $denominator ) * 100, 1 )
				: 0.0;
		}

		$currency = get_woocommerce_currency();

		$result = array(
			'period'             => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'           => $currency,
			'group_by'           => $group_by,
			'orderby'            => $orderby,
			'limit'              => $limit,
			'include_unassigned' => $include_unassigned,
			'totals'             => $totals,
			'pipeline'           => $totals_pipeline,
			'admin_equivalent'   => $totals_admin_equivalent,
			'top_groups'         => $top,
			'comparison'         => null,
			'note'               => null,
		);

		if ( 0 === (int) $totals['total_paid_orders'] ) {
			$result['note']       = 'No paid orders found for this date range.';
			$result['top_groups'] = array();
		}

		if ( $compare ) {
			$prev_dates      = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_totals_raw = self::query_revenue_breakdown_totals( $prev_dates['start'], $prev_dates['end'], $group_by );
			$prev_top        = self::query_revenue_breakdown_groups( $prev_dates['start'], $prev_dates['end'], $limit, $orderby, $group_by, $include_unassigned );

			$prev_totals           = $prev_totals_raw;
			$prev_pipeline         = array(
				'revenue'      => isset( $prev_totals_raw['_pipeline_revenue'] ) ? (float) $prev_totals_raw['_pipeline_revenue'] : 0.00,
				'orders_count' => isset( $prev_totals_raw['_pipeline_orders_count'] ) ? (int) $prev_totals_raw['_pipeline_orders_count'] : 0,
			);
			$prev_admin_equivalent = array(
				'revenue'      => isset( $prev_totals_raw['_admin_revenue'] ) ? (float) $prev_totals_raw['_admin_revenue'] : 0.00,
				'orders_count' => isset( $prev_totals_raw['_admin_orders_count'] ) ? (int) $prev_totals_raw['_admin_orders_count'] : 0,
			);
			unset(
				$prev_totals['_pipeline_revenue'],
				$prev_totals['_pipeline_orders_count'],
				$prev_totals['_admin_revenue'],
				$prev_totals['_admin_orders_count']
			);
			$prev_totals['coverage_percent'] = ( $prev_totals['total_paid_orders'] > 0 )
				? round( ( $prev_totals['covered_orders'] / $prev_totals['total_paid_orders'] ) * 100, 1 )
				: 0.0;

			$totals_changes = self::calculate_revenue_breakdown_totals_changes( $totals, $prev_totals );

			$prev_by_key = array();
			foreach ( $prev_top as $row ) {
				$prev_by_key[ $row['key'] ] = $row;
			}

			$current_keys = array();
			foreach ( $top as $i => $group ) {
				$current_keys[] = $group['key'];
				if ( isset( $prev_by_key[ $group['key'] ] ) ) {
					$top[ $i ]['change'] = self::calculate_attribution_change(
						$group,
						$prev_by_key[ $group['key'] ],
						$orderby
					);
				} else {
					$top[ $i ]['change'] = array(
						'metric'    => $orderby,
						'amount'    => null,
						'percent'   => null,
						'direction' => 'new',
						'note'      => 'New to top results this period.',
					);
				}
			}
			$result['top_groups'] = $top;

			$dropped_out = array();
			foreach ( $prev_top as $row ) {
				if ( ! in_array( $row['key'], $current_keys, true ) ) {
					$dropped_out[] = array(
						'key'                  => $row['key'],
						'label'                => $row['label'],
						'previous_' . $orderby => $row[ $orderby ],
					);
				}
			}

			$result['comparison'] = array(
				'period'           => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'totals'           => $prev_totals,
				'pipeline'         => $prev_pipeline,
				'admin_equivalent' => $prev_admin_equivalent,
				'changes'          => $totals_changes,
				'dropped_out'      => $dropped_out,
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Dispatch to the per-dimension groups query. Each query returns the
	 * same row shape (key/label + paid/pipeline/admin metrics + items_sold
	 * + refunds) so the assembly layer stays dimension-agnostic.
	 *
	 * @param string $date_start         YYYY-MM-DD start.
	 * @param string $date_end           YYYY-MM-DD end.
	 * @param int    $limit              Number of top groups.
	 * @param string $orderby            Sort metric.
	 * @param string $group_by           Dimension.
	 * @param bool   $include_unassigned Include rows with NULL/empty dimension value.
	 * @return array Top group rows.
	 */
	private static function query_revenue_breakdown_groups( $date_start, $date_end, $limit, $orderby, $group_by, $include_unassigned ) {
		switch ( $group_by ) {
			case 'category':
				return self::query_revenue_by_category( $date_start, $date_end, $limit, $orderby, $include_unassigned );
			case 'country':
				return self::query_revenue_by_country( $date_start, $date_end, $limit, $orderby, $include_unassigned );
			case 'payment_method':
				return self::query_revenue_by_payment_method( $date_start, $date_end, $limit, $orderby, $include_unassigned );
			case 'shipping_method':
				return self::query_revenue_by_shipping_method( $date_start, $date_end, $limit, $orderby, $include_unassigned );
		}
		return array();
	}

	/**
	 * Store-wide totals for the revenue-breakdown response. Includes a
	 * `covered_orders` count per dimension (orders whose dimension value
	 * is non-null / non-empty) so `coverage_percent` can contextualise
	 * how much of the revenue the breakdown accounts for.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @param string $group_by   Dimension (affects covered_orders only).
	 * @return array Totals row.
	 */
	private static function query_revenue_breakdown_totals( $date_start, $date_end, $group_by ) {
		global $wpdb;

		$os_table          = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		// Base totals — same shape across all dimensions, no JOIN needed.
		// `refunds` sums refund sub-orders (parent_id != 0) with the
		// tax + shipping components included, matching revenue_summary's
		// definition so `net_sales` = `net_revenue - refunds` reconciles
		// cleanly against revenue_summary.metrics.net_sales.
		$totals_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN net_total ELSE 0 END) AS net_revenue,
					SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS total_paid_orders,
					SUM(CASE WHEN parent_id = 0 AND status IN ({$paid_ph}) THEN num_items_sold ELSE 0 END) AS items_sold,
					ABS(SUM(CASE WHEN parent_id != 0 THEN (net_total + tax_total + shipping_total) ELSE 0 END)) AS refunds,
					SUM(CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN net_total ELSE 0 END) AS pipeline_revenue,
					SUM(CASE WHEN parent_id = 0 AND status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders_count,
					SUM(CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) THEN net_total ELSE 0 END) AS admin_revenue,
					SUM(CASE WHEN parent_id = 0 AND status IN ({$admin_ph}) THEN 1 ELSE 0 END) AS admin_orders_count
				FROM {$os_table}
				WHERE {$date_column} >= %s AND {$date_column} <= %s",
				array_merge(
					$paid_statuses,
					$paid_statuses,
					$paid_statuses,
					$pipeline_statuses,
					$pipeline_statuses,
					$admin_statuses,
					$admin_statuses,
					array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
				)
			),
			ARRAY_A
		);

		if ( ! $totals_row ) {
			return self::empty_revenue_breakdown_totals();
		}

		$net_revenue       = (float) $totals_row['net_revenue'];
		$refunds           = (float) $totals_row['refunds'];
		$total_paid_orders = (int) $totals_row['total_paid_orders'];

		// Dimension-specific covered_orders — how many paid orders could be
		// attributed to a non-empty dimension value. Used for coverage %.
		$covered_orders  = self::query_revenue_breakdown_covered_orders( $date_start, $date_end, $group_by );
		$distinct_groups = self::query_revenue_breakdown_distinct_groups( $date_start, $date_end, $group_by );

		return array(
			'net_revenue'            => round( $net_revenue, 2 ),
			'refunds'                => round( $refunds, 2 ),
			'net_sales'              => round( $net_revenue - $refunds, 2 ),
			'total_paid_orders'      => $total_paid_orders,
			'items_sold'             => (int) $totals_row['items_sold'],
			'avg_order_value'        => $total_paid_orders > 0 ? round( $net_revenue / $total_paid_orders, 2 ) : 0.00,
			'covered_orders'         => $covered_orders,
			'distinct_groups'        => $distinct_groups,
			'_pipeline_revenue'      => round( (float) $totals_row['pipeline_revenue'], 2 ),
			'_pipeline_orders_count' => (int) $totals_row['pipeline_orders_count'],
			'_admin_revenue'         => round( (float) $totals_row['admin_revenue'], 2 ),
			'_admin_orders_count'    => (int) $totals_row['admin_orders_count'],
		);
	}

	/**
	 * Count paid orders whose dimension value is non-null / non-empty.
	 * Used to compute `coverage_percent` for the breakdown response.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @param string $group_by   Dimension.
	 * @return int Paid orders with a resolvable dimension value.
	 */
	private static function query_revenue_breakdown_covered_orders( $date_start, $date_end, $group_by ) {
		global $wpdb;

		$os_table      = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses = self::get_paid_statuses();
		$paid_ph       = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$date_column   = self::get_date_column();

		switch ( $group_by ) {
			case 'category':
				$pl_table = $wpdb->prefix . 'wc_order_product_lookup';
				$tr_table = $wpdb->prefix . 'term_relationships';
				$tt_table = $wpdb->prefix . 'term_taxonomy';
				$count    = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT os.order_id)
						FROM {$os_table} os
						JOIN {$pl_table} pl ON pl.order_id = os.order_id
						JOIN {$tr_table} tr ON tr.object_id = pl.product_id
						JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
						WHERE os.parent_id = 0
							AND os.status IN ({$paid_ph})
							AND os.{$date_column} >= %s AND os.{$date_column} <= %s",
						array_merge(
							$paid_statuses,
							array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
						)
					)
				);
				return (int) $count;

			case 'country':
				$addr_join = self::revenue_breakdown_country_join( 'os' );
				$count     = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT os.order_id)
						FROM {$os_table} os
						{$addr_join['join']}
						WHERE os.parent_id = 0
							AND os.status IN ({$paid_ph})
							AND os.{$date_column} >= %s AND os.{$date_column} <= %s
							AND {$addr_join['expr']} IS NOT NULL
							AND {$addr_join['expr']} != ''",
						array_merge(
							$paid_statuses,
							array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
						)
					)
				);
				return (int) $count;

			case 'payment_method':
				$pm_join = self::revenue_breakdown_payment_join( 'os' );
				$count   = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT os.order_id)
						FROM {$os_table} os
						{$pm_join['join']}
						WHERE os.parent_id = 0
							AND os.status IN ({$paid_ph})
							AND os.{$date_column} >= %s AND os.{$date_column} <= %s
							AND {$pm_join['expr']} IS NOT NULL
							AND {$pm_join['expr']} != ''",
						array_merge(
							$paid_statuses,
							array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
						)
					)
				);
				return (int) $count;

			case 'shipping_method':
				$sm_join = self::revenue_breakdown_shipping_join( 'os' );
				$count   = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT os.order_id)
						FROM {$os_table} os
						{$sm_join['join']}
						WHERE os.parent_id = 0
							AND os.status IN ({$paid_ph})
							AND os.{$date_column} >= %s AND os.{$date_column} <= %s
							AND {$sm_join['expr']} IS NOT NULL
							AND {$sm_join['expr']} != ''",
						array_merge(
							$paid_statuses,
							array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
						)
					)
				);
				return (int) $count;
		}

		return 0;
	}

	/**
	 * Count distinct non-empty dimension values for the period.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @param string $group_by   Dimension.
	 * @return int Distinct groups with at least one paid order.
	 */
	private static function query_revenue_breakdown_distinct_groups( $date_start, $date_end, $group_by ) {
		global $wpdb;

		$os_table      = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses = self::get_paid_statuses();
		$paid_ph       = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$date_column   = self::get_date_column();

		switch ( $group_by ) {
			case 'category':
				$pl_table = $wpdb->prefix . 'wc_order_product_lookup';
				$tr_table = $wpdb->prefix . 'term_relationships';
				$tt_table = $wpdb->prefix . 'term_taxonomy';
				$count    = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT tt.term_id)
						FROM {$os_table} os
						JOIN {$pl_table} pl ON pl.order_id = os.order_id
						JOIN {$tr_table} tr ON tr.object_id = pl.product_id
						JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
						WHERE os.parent_id = 0
							AND os.status IN ({$paid_ph})
							AND os.{$date_column} >= %s AND os.{$date_column} <= %s",
						array_merge(
							$paid_statuses,
							array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
						)
					)
				);
				return (int) $count;

			case 'country':
				$addr_join = self::revenue_breakdown_country_join( 'os' );
				$count     = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT {$addr_join['expr']})
						FROM {$os_table} os
						{$addr_join['join']}
						WHERE os.parent_id = 0
							AND os.status IN ({$paid_ph})
							AND os.{$date_column} >= %s AND os.{$date_column} <= %s
							AND {$addr_join['expr']} IS NOT NULL
							AND {$addr_join['expr']} != ''",
						array_merge(
							$paid_statuses,
							array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
						)
					)
				);
				return (int) $count;

			case 'payment_method':
				$pm_join = self::revenue_breakdown_payment_join( 'os' );
				$count   = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT {$pm_join['expr']})
						FROM {$os_table} os
						{$pm_join['join']}
						WHERE os.parent_id = 0
							AND os.status IN ({$paid_ph})
							AND os.{$date_column} >= %s AND os.{$date_column} <= %s
							AND {$pm_join['expr']} IS NOT NULL
							AND {$pm_join['expr']} != ''",
						array_merge(
							$paid_statuses,
							array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
						)
					)
				);
				return (int) $count;

			case 'shipping_method':
				$sm_join = self::revenue_breakdown_shipping_join( 'os' );
				$count   = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(DISTINCT {$sm_join['expr']})
						FROM {$os_table} os
						{$sm_join['join']}
						WHERE os.parent_id = 0
							AND os.status IN ({$paid_ph})
							AND os.{$date_column} >= %s AND os.{$date_column} <= %s
							AND {$sm_join['expr']} IS NOT NULL
							AND {$sm_join['expr']} != ''",
						array_merge(
							$paid_statuses,
							array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
						)
					)
				);
				return (int) $count;
		}

		return 0;
	}

	/**
	 * Empty-state fallback for revenue-breakdown totals.
	 *
	 * @return array Zeroed totals row.
	 */
	private static function empty_revenue_breakdown_totals() {
		return array(
			'net_revenue'            => 0.00,
			'refunds'                => 0.00,
			'net_sales'              => 0.00,
			'total_paid_orders'      => 0,
			'items_sold'             => 0,
			'avg_order_value'        => 0.00,
			'covered_orders'         => 0,
			'distinct_groups'        => 0,
			'_pipeline_revenue'      => 0.00,
			'_pipeline_orders_count' => 0,
			'_admin_revenue'         => 0.00,
			'_admin_orders_count'    => 0,
		);
	}

	/**
	 * JOIN clause + SELECT expression for billing-country lookup.
	 *
	 * Refund sub-orders inherit their parent order's country via the
	 * CASE on parent_id — more honest for revenue_breakdown than
	 * attribution's "refund → (Unassigned)" behaviour because the
	 * address of a refund is obviously the parent's.
	 *
	 * HPOS: wc_order_addresses with address_type = 'billing'.
	 * Classic: postmeta with meta_key = '_billing_country'.
	 *
	 * @param string $os_alias Alias of the wc_order_stats table in the outer query.
	 * @return array { join: string, expr: string, values: array }
	 */
	private static function revenue_breakdown_country_join( $os_alias ) {
		global $wpdb;

		if ( self::hpos_enabled() ) {
			$addr_table = $wpdb->prefix . 'wc_order_addresses';
			return array(
				'join' => "LEFT JOIN {$addr_table} addr_billing
					ON addr_billing.order_id = (CASE WHEN {$os_alias}.parent_id = 0 THEN {$os_alias}.order_id ELSE {$os_alias}.parent_id END)
					AND addr_billing.address_type = 'billing'",
				'expr' => 'addr_billing.country',
			);
		}

		return array(
			'join' => "LEFT JOIN {$wpdb->postmeta} addr_billing
				ON addr_billing.post_id = (CASE WHEN {$os_alias}.parent_id = 0 THEN {$os_alias}.order_id ELSE {$os_alias}.parent_id END)
				AND addr_billing.meta_key = '_billing_country'",
			'expr' => 'addr_billing.meta_value',
		);
	}

	/**
	 * JOIN clause + SELECT expression for payment-method lookup.
	 *
	 * Prefers the human-readable `payment_method_title` (HPOS column)
	 * or `_payment_method_title` (classic postmeta). Falls back to the
	 * `payment_method` slug when the title is empty.
	 *
	 * @param string $os_alias Alias of the wc_order_stats table.
	 * @return array { join: string, expr: string }
	 */
	private static function revenue_breakdown_payment_join( $os_alias ) {
		global $wpdb;

		if ( self::hpos_enabled() ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			return array(
				'join' => "LEFT JOIN {$orders_table} wo
					ON wo.id = (CASE WHEN {$os_alias}.parent_id = 0 THEN {$os_alias}.order_id ELSE {$os_alias}.parent_id END)",
				'expr' => "COALESCE(NULLIF(wo.payment_method_title, ''), NULLIF(wo.payment_method, ''))",
			);
		}

		return array(
			'join' => "LEFT JOIN {$wpdb->postmeta} pm_title
					ON pm_title.post_id = (CASE WHEN {$os_alias}.parent_id = 0 THEN {$os_alias}.order_id ELSE {$os_alias}.parent_id END)
					AND pm_title.meta_key = '_payment_method_title'
				LEFT JOIN {$wpdb->postmeta} pm_slug
					ON pm_slug.post_id = (CASE WHEN {$os_alias}.parent_id = 0 THEN {$os_alias}.order_id ELSE {$os_alias}.parent_id END)
					AND pm_slug.meta_key = '_payment_method'",
			'expr' => "COALESCE(NULLIF(pm_title.meta_value, ''), NULLIF(pm_slug.meta_value, ''))",
		);
	}

	/**
	 * JOIN clause + SELECT expression for shipping-method lookup.
	 *
	 * Uses a subquery against `woocommerce_order_items` (the shared
	 * table, not HPOS-specific) with `order_item_type = 'shipping'`.
	 * `MIN(order_item_name)` picks one method per order — orders
	 * usually have a single shipping line; multi-line orders pick
	 * lexicographically first, which is the safe default.
	 *
	 * Orders without a shipping line (digital, local pickup) won't
	 * match the LEFT JOIN and land in (Unassigned) — honest "no
	 * shipping charged" signal, not missing data.
	 *
	 * @param string $os_alias Alias of the wc_order_stats table.
	 * @return array { join: string, expr: string }
	 */
	private static function revenue_breakdown_shipping_join( $os_alias ) {
		global $wpdb;

		$items_table = $wpdb->prefix . 'woocommerce_order_items';
		return array(
			'join' => "LEFT JOIN (
					SELECT order_id, MIN(order_item_name) AS shipping_method
					FROM {$items_table}
					WHERE order_item_type = 'shipping'
					GROUP BY order_id
				) sm ON sm.order_id = (CASE WHEN {$os_alias}.parent_id = 0 THEN {$os_alias}.order_id ELSE {$os_alias}.parent_id END)",
			'expr' => 'sm.shipping_method',
		);
	}

	/**
	 * HPOS-on detection for the breakdown dimension JOINs.
	 *
	 * Routes through `OrderUtil` — the same path `get_order_meta_source()`
	 * uses. Break out of that helper because country / payment_method
	 * use different tables (wc_order_addresses / wc_orders) than the
	 * attribution helpers (wc_orders_meta).
	 *
	 * @return bool
	 */
	private static function hpos_enabled() {
		$util = '\\Automattic\\WooCommerce\\Utilities\\OrderUtil';
		return class_exists( $util ) && $util::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Revenue by product category. Product-level roll-up via
	 * wc_order_product_lookup joined to term_relationships → term_taxonomy
	 * → terms. An order with products in multiple categories contributes
	 * its product-level subtotals to EACH category — intentional, flagged
	 * in the tool description so the merchant isn't surprised when
	 * per-category totals don't sum to order totals.
	 *
	 * @param string $date_start         YYYY-MM-DD start.
	 * @param string $date_end           YYYY-MM-DD end.
	 * @param int    $limit              Top N.
	 * @param string $orderby            Sort metric.
	 * @param bool   $include_unassigned Kept on signature for uniformity; an
	 *                                    order without any categorised product has
	 *                                    no row in term_relationships and can't
	 *                                    land in (Unassigned) via an INNER JOIN.
	 *                                    Always false-effective for category.
	 * @return array Top group rows.
	 */
	private static function query_revenue_by_category( $date_start, $date_end, $limit, $orderby, $include_unassigned ) {
		unset( $include_unassigned );
		global $wpdb;

		$os_table = $wpdb->prefix . 'wc_order_stats';
		$pl_table = $wpdb->prefix . 'wc_order_product_lookup';
		$tr_table = $wpdb->prefix . 'term_relationships';
		$tt_table = $wpdb->prefix . 'term_taxonomy';
		$t_table  = $wpdb->prefix . 'terms';

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		$orderby_col = self::revenue_breakdown_orderby( $orderby );

		$sql = $wpdb->prepare(
			"SELECT
				t.term_id AS group_key,
				t.name AS group_label,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN pl.product_net_revenue ELSE 0 END) AS net_revenue,
				COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.order_id END) AS orders_count,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN pl.product_qty ELSE 0 END) AS items_sold,
				ABS(SUM(CASE WHEN os.parent_id != 0 THEN pl.product_net_revenue ELSE 0 END)) AS refunds,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN pl.product_net_revenue ELSE 0 END) AS pipeline_revenue,
				COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN os.order_id END) AS pipeline_orders_count,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN pl.product_net_revenue ELSE 0 END) AS admin_revenue,
				COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN os.order_id END) AS admin_orders_count,
				(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN pl.product_net_revenue ELSE 0 END)
					/ NULLIF(COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.order_id END), 0)) AS avg_order_value
			FROM {$pl_table} pl
			JOIN {$os_table} os ON os.order_id = pl.order_id
			JOIN {$tr_table} tr ON tr.object_id = pl.product_id
			JOIN {$tt_table} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
			JOIN {$t_table} t ON t.term_id = tt.term_id
			WHERE os.{$date_column} >= %s AND os.{$date_column} <= %s
				AND ( (os.parent_id = 0 AND os.status IN ({$admin_ph})) OR os.parent_id != 0 )
			GROUP BY t.term_id, t.name
			HAVING orders_count != 0 OR pipeline_orders_count != 0 OR admin_orders_count != 0
			ORDER BY {$orderby_col} DESC
			LIMIT %d",
			array_merge(
				$paid_statuses,                                                         // net_revenue.
				$paid_statuses,                                                         // orders_count.
				$paid_statuses,                                                         // items_sold.
				$pipeline_statuses,                                                     // pipeline_revenue.
				$pipeline_statuses,                                                     // pipeline_orders_count.
				$admin_statuses,                                                        // admin_revenue.
				$admin_statuses,                                                        // admin_orders_count.
				$paid_statuses,                                                         // avg_order_value num.
				$paid_statuses,                                                         // avg_order_value den.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses,                                                        // WHERE.
				array( $limit )
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return self::shape_revenue_group_row( (string) $row['group_label'], (string) $row['group_label'], $row );
			},
			$rows
		);
	}

	/**
	 * Revenue by billing country. Order-level roll-up via wc_order_stats
	 * joined to wc_order_addresses (HPOS) or _billing_country postmeta.
	 *
	 * Refunds attribute to the PARENT order's country via the CASE on
	 * parent_id in the JOIN — more honest than attribution's refund-to-
	 * (Unassigned) default because the address of a refund is plainly
	 * the parent's.
	 *
	 * @param string $date_start         YYYY-MM-DD start.
	 * @param string $date_end           YYYY-MM-DD end.
	 * @param int    $limit              Top N.
	 * @param string $orderby            Sort metric.
	 * @param bool   $include_unassigned Include (Unassigned) row for orders without country.
	 * @return array Top group rows.
	 */
	private static function query_revenue_by_country( $date_start, $date_end, $limit, $orderby, $include_unassigned ) {
		global $wpdb;

		$os_table      = $wpdb->prefix . 'wc_order_stats';
		$addr_join_arr = self::revenue_breakdown_country_join( 'os' );
		$join          = $addr_join_arr['join'];
		$group_expr    = $addr_join_arr['expr'];

		return self::query_revenue_breakdown_order_level(
			$date_start,
			$date_end,
			$limit,
			$orderby,
			$include_unassigned,
			$os_table,
			$join,
			$group_expr,
			'country'
		);
	}

	/**
	 * Revenue by payment method. Order-level roll-up via wc_order_stats
	 * joined to wc_orders.payment_method_title (HPOS) or the
	 * `_payment_method_title` / `_payment_method` postmeta pair.
	 *
	 * @param string $date_start         YYYY-MM-DD start.
	 * @param string $date_end           YYYY-MM-DD end.
	 * @param int    $limit              Top N.
	 * @param string $orderby            Sort metric.
	 * @param bool   $include_unassigned Include (Unassigned) row for orders with no payment method.
	 * @return array Top group rows.
	 */
	private static function query_revenue_by_payment_method( $date_start, $date_end, $limit, $orderby, $include_unassigned ) {
		global $wpdb;

		$os_table    = $wpdb->prefix . 'wc_order_stats';
		$pm_join_arr = self::revenue_breakdown_payment_join( 'os' );
		$join        = $pm_join_arr['join'];
		$group_expr  = $pm_join_arr['expr'];

		return self::query_revenue_breakdown_order_level(
			$date_start,
			$date_end,
			$limit,
			$orderby,
			$include_unassigned,
			$os_table,
			$join,
			$group_expr,
			'payment_method'
		);
	}

	/**
	 * Revenue by shipping method. Order-level roll-up via wc_order_stats
	 * joined to a subquery over woocommerce_order_items (one shipping
	 * method per order via MIN).
	 *
	 * @param string $date_start         YYYY-MM-DD start.
	 * @param string $date_end           YYYY-MM-DD end.
	 * @param int    $limit              Top N.
	 * @param string $orderby            Sort metric.
	 * @param bool   $include_unassigned Include (Unassigned) row for orders without a shipping line.
	 * @return array Top group rows.
	 */
	private static function query_revenue_by_shipping_method( $date_start, $date_end, $limit, $orderby, $include_unassigned ) {
		global $wpdb;

		$os_table    = $wpdb->prefix . 'wc_order_stats';
		$sm_join_arr = self::revenue_breakdown_shipping_join( 'os' );
		$join        = $sm_join_arr['join'];
		$group_expr  = $sm_join_arr['expr'];

		return self::query_revenue_breakdown_order_level(
			$date_start,
			$date_end,
			$limit,
			$orderby,
			$include_unassigned,
			$os_table,
			$join,
			$group_expr,
			'shipping_method'
		);
	}

	/**
	 * Shared order-level SQL runner for the three non-category dimensions.
	 *
	 * Parameterises the JOIN + GROUP expression so country, payment_method,
	 * and shipping_method share the same aggregate shape. Category isn't
	 * eligible because it joins per-product rows, not per-order rows.
	 *
	 * @param string $date_start         YYYY-MM-DD start.
	 * @param string $date_end           YYYY-MM-DD end.
	 * @param int    $limit              Top N.
	 * @param string $orderby            Sort metric.
	 * @param bool   $include_unassigned Include rows where group_expr is NULL/empty.
	 * @param string $os_table           Fully-qualified wc_order_stats table name.
	 * @param string $join               JOIN clause string to splice into the FROM.
	 * @param string $group_expr         SQL expression yielding the group value.
	 * @param string $group_by           Dimension name (only used for error-msg safety).
	 * @return array Top group rows.
	 */
	private static function query_revenue_breakdown_order_level( $date_start, $date_end, $limit, $orderby, $include_unassigned, $os_table, $join, $group_expr, $group_by ) {
		unset( $group_by );
		global $wpdb;

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		$orderby_col = self::revenue_breakdown_orderby( $orderby );

		$unassigned_filter = $include_unassigned
			? ''
			: " AND ({$group_expr}) IS NOT NULL AND ({$group_expr}) != ''";

		$sql = $wpdb->prepare(
			"SELECT
				({$group_expr}) AS group_key,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.net_total ELSE 0 END) AS net_revenue,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS orders_count,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.num_items_sold ELSE 0 END) AS items_sold,
				ABS(SUM(CASE WHEN os.parent_id != 0 THEN (os.net_total + os.tax_total + os.shipping_total) ELSE 0 END)) AS refunds,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN os.net_total ELSE 0 END) AS pipeline_revenue,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders_count,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN os.net_total ELSE 0 END) AS admin_revenue,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN 1 ELSE 0 END) AS admin_orders_count,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.net_total ELSE 0 END)
					/ NULLIF(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN 1 ELSE 0 END), 0) AS avg_order_value
			FROM {$os_table} os
			{$join}
			WHERE os.{$date_column} >= %s AND os.{$date_column} <= %s
				AND ( (os.parent_id = 0 AND os.status IN ({$admin_ph})) OR os.parent_id != 0 )
				{$unassigned_filter}
			GROUP BY group_key
			HAVING orders_count != 0 OR pipeline_orders_count != 0 OR admin_orders_count != 0
			ORDER BY {$orderby_col} DESC
			LIMIT %d",
			array_merge(
				$paid_statuses,                                                         // net_revenue.
				$paid_statuses,                                                         // orders_count.
				$paid_statuses,                                                         // items_sold.
				$pipeline_statuses,                                                     // pipeline_revenue.
				$pipeline_statuses,                                                     // pipeline_orders_count.
				$admin_statuses,                                                        // admin_revenue.
				$admin_statuses,                                                        // admin_orders_count.
				$paid_statuses,                                                         // avg num.
				$paid_statuses,                                                         // avg den.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses,                                                        // WHERE admin filter.
				array( $limit )
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				$raw_key       = $row['group_key'];
				$is_unassigned = ( null === $raw_key || '' === $raw_key );
				$key           = $is_unassigned ? '(Unassigned)' : (string) $raw_key;
				$label         = $key;

				return self::shape_revenue_group_row( $key, $label, $row );
			},
			$rows
		);
	}

	/**
	 * Whitelist orderby column against injection. Falls back to
	 * net_revenue for any unknown value.
	 *
	 * @param string $orderby Requested sort metric.
	 * @return string Safe column name.
	 */
	private static function revenue_breakdown_orderby( $orderby ) {
		$orderby_map = array(
			'net_revenue'     => 'net_revenue',
			'orders_count'    => 'orders_count',
			'avg_order_value' => 'avg_order_value',
		);
		return isset( $orderby_map[ $orderby ] ) ? $orderby_map[ $orderby ] : 'net_revenue';
	}

	/**
	 * Assemble a uniform top_groups row. Used by every dimension so the
	 * response shape stays identical across category / country /
	 * payment_method / shipping_method.
	 *
	 * @param string $key   Group key (stable string).
	 * @param string $label Human-readable label.
	 * @param array  $row   Raw SQL row.
	 * @return array Shaped group row.
	 */
	private static function shape_revenue_group_row( $key, $label, $row ) {
		$net_revenue = round( (float) $row['net_revenue'], 2 );
		$refunds     = round( (float) $row['refunds'], 2 );

		// Pre-computed to keep Claude from narrating the arithmetic.
		// Guarded against zero-revenue rows (pipeline-only groups like
		// bacs where paid = £0 but pipeline > 0) and negative net_revenue
		// edge cases — both return 0.0.
		$refund_rate_percent = ( $net_revenue > 0 )
			? round( ( $refunds / $net_revenue ) * 100, 1 )
			: 0.0;

		return array(
			'key'                           => $key,
			'label'                         => $label,
			'net_revenue'                   => $net_revenue,
			'orders_count'                  => (int) $row['orders_count'],
			'avg_order_value'               => round( (float) $row['avg_order_value'], 2 ),
			'items_sold'                    => (int) $row['items_sold'],
			'refunds'                       => $refunds,
			'refund_rate_percent'           => $refund_rate_percent,
			'pipeline_revenue'              => round( (float) $row['pipeline_revenue'], 2 ),
			'pipeline_orders_count'         => (int) $row['pipeline_orders_count'],
			'admin_equivalent_revenue'      => round( (float) $row['admin_revenue'], 2 ),
			'admin_equivalent_orders_count' => (int) $row['admin_orders_count'],
		);
	}

	/**
	 * Pre-compute percentage changes for revenue-breakdown totals.
	 * Mirrors `calculate_attribution_totals_changes` but on the revenue-
	 * breakdown key set.
	 *
	 * @param array $current  Current period totals.
	 * @param array $previous Previous period totals.
	 * @return array Per-key change rows with amount, percent, direction.
	 */
	private static function calculate_revenue_breakdown_totals_changes( $current, $previous ) {
		$keys = array(
			'net_revenue',
			'refunds',
			'net_sales',
			'total_paid_orders',
			'items_sold',
			'avg_order_value',
			'distinct_groups',
			'coverage_percent',
		);

		$changes = array();
		foreach ( $keys as $key ) {
			$curr = (float) ( $current[ $key ] ?? 0 );
			$prev = (float) ( $previous[ $key ] ?? 0 );
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	// ─── Coupon Performance ──────────────────────────────────────

	/**
	 * Fetch the coupon-performance payload. Backs the
	 * `wc-analytics/get-coupon-performance` ability.
	 *
	 * Answers "are my coupons working?" — per-coupon usage, discount
	 * totals, revenue driven, refund rate per coupon, plus store-wide
	 * coupon attachment rate and with-coupon vs without-coupon AOV.
	 * Three-view pattern per row (paid / pipeline / admin_equivalent),
	 * per-row share_of_coupon_revenue_percent + refund_rate_percent +
	 * new_customer_share_percent pre-computed so the model never
	 * divides narratively.
	 *
	 * Refund attribution: a refund sub-order inherits its parent
	 * order's coupon(s) via the CASE on parent_id in the JOIN, so a
	 * refund on an order that used `save15` contributes to `save15`'s
	 * refunds column.
	 *
	 * Multi-coupon orders: an order that used TWO coupons contributes
	 * its revenue to EACH coupon's row (same shape as the multi-
	 * category double-count on get_revenue_breakdown). The
	 * unduplicated figure lives on totals.revenue_with_coupon.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start date — overrides period.
	 * @param string|null $date_end   Custom end date — overrides period.
	 * @param bool        $compare    Include previous-period comparison.
	 * @param int         $limit      Number of top coupons to return.
	 * @param string      $orderby    Sort column for top_groups.
	 * @return array Response payload.
	 */
	public static function fetch_coupon_performance( $period, $date_start, $date_end, $compare, $limit, $orderby ) {
		$start = microtime( true );
		$limit = (int) $limit;

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		$cache_key = 'woocommerce_claude_coupon_performance_' . md5(
			$dates['start'] . '_' . $dates['end']
			. '_' . ( $compare ? '1' : '0' )
			. '_' . $limit
			. '_' . $orderby
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$top    = self::query_coupon_performance_groups( $dates['start'], $dates['end'], $limit, $orderby );
		$totals = self::query_coupon_performance_totals( $dates['start'], $dates['end'] );

		$totals_pipeline         = array(
			'revenue'      => isset( $totals['_pipeline_revenue'] ) ? (float) $totals['_pipeline_revenue'] : 0.00,
			'orders_count' => isset( $totals['_pipeline_orders_count'] ) ? (int) $totals['_pipeline_orders_count'] : 0,
			'definition'   => 'On-hold orders awaiting payment (all coupons combined). Per-coupon pipeline values are on each top_groups row.',
		);
		$totals_admin_equivalent = array(
			'revenue'      => isset( $totals['_admin_revenue'] ) ? (float) $totals['_admin_revenue'] : 0.00,
			'orders_count' => isset( $totals['_admin_orders_count'] ) ? (int) $totals['_admin_orders_count'] : 0,
			'definition'   => 'What WC Admin > Marketing > Coupons shows: paid + on-hold + refunded orders lumped together. Per-coupon admin_equivalent values are on each top_groups row. Use for dashboard reconciliation only.',
		);
		unset(
			$totals['_pipeline_revenue'],
			$totals['_pipeline_orders_count'],
			$totals['_admin_revenue'],
			$totals['_admin_orders_count']
		);

		// Per-row share-of — computed against unduplicated
		// revenue_with_coupon / total_discount_amount denominators so
		// percentages stay honest when the long tail gets clipped.
		$revenue_denom  = (float) $totals['revenue_with_coupon'];
		$discount_denom = (float) $totals['total_discount_amount'];

		foreach ( $top as $i => $row ) {
			$top[ $i ]['share_of_coupon_revenue_percent'] = ( $revenue_denom > 0 )
				? round( ( $row['net_revenue'] / $revenue_denom ) * 100, 1 )
				: 0.0;
			$top[ $i ]['share_of_total_discount_percent'] = ( $discount_denom > 0 )
				? round( ( $row['discount_amount'] / $discount_denom ) * 100, 1 )
				: 0.0;
		}

		$currency = get_woocommerce_currency();

		$result = array(
			'period'           => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'         => $currency,
			'orderby'          => $orderby,
			'limit'            => $limit,
			'totals'           => $totals,
			'pipeline'         => $totals_pipeline,
			'admin_equivalent' => $totals_admin_equivalent,
			'top_groups'       => $top,
			'comparison'       => null,
			'note'             => null,
		);

		if ( 0 === (int) $totals['total_paid_orders'] ) {
			$result['note']       = 'No paid orders found for this date range.';
			$result['top_groups'] = array();
		} elseif ( 0 === (int) $totals['orders_with_coupon'] ) {
			$result['note']       = 'No coupon usage in this period. Paid orders in range: ' . (int) $totals['total_paid_orders'] . '.';
			$result['top_groups'] = array();
		}

		if ( $compare ) {
			$prev_dates      = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_totals_raw = self::query_coupon_performance_totals( $prev_dates['start'], $prev_dates['end'] );
			$prev_top        = self::query_coupon_performance_groups( $prev_dates['start'], $prev_dates['end'], $limit, $orderby );

			$prev_totals           = $prev_totals_raw;
			$prev_pipeline         = array(
				'revenue'      => isset( $prev_totals_raw['_pipeline_revenue'] ) ? (float) $prev_totals_raw['_pipeline_revenue'] : 0.00,
				'orders_count' => isset( $prev_totals_raw['_pipeline_orders_count'] ) ? (int) $prev_totals_raw['_pipeline_orders_count'] : 0,
			);
			$prev_admin_equivalent = array(
				'revenue'      => isset( $prev_totals_raw['_admin_revenue'] ) ? (float) $prev_totals_raw['_admin_revenue'] : 0.00,
				'orders_count' => isset( $prev_totals_raw['_admin_orders_count'] ) ? (int) $prev_totals_raw['_admin_orders_count'] : 0,
			);
			unset(
				$prev_totals['_pipeline_revenue'],
				$prev_totals['_pipeline_orders_count'],
				$prev_totals['_admin_revenue'],
				$prev_totals['_admin_orders_count']
			);

			$totals_changes = self::calculate_coupon_performance_totals_changes( $totals, $prev_totals );

			$prev_by_key = array();
			foreach ( $prev_top as $prev_row ) {
				$prev_by_key[ $prev_row['key'] ] = $prev_row;
			}

			$current_keys = array();
			foreach ( $top as $i => $row ) {
				$current_keys[] = $row['key'];
				if ( isset( $prev_by_key[ $row['key'] ] ) ) {
					$top[ $i ]['change'] = self::calculate_attribution_change(
						$row,
						$prev_by_key[ $row['key'] ],
						$orderby
					);
				} else {
					$top[ $i ]['change'] = array(
						'metric'    => $orderby,
						'amount'    => null,
						'percent'   => null,
						'direction' => 'new',
						'note'      => 'New to top results this period.',
					);
				}
			}
			$result['top_groups'] = $top;

			$dropped_out = array();
			foreach ( $prev_top as $prev_row ) {
				if ( ! in_array( $prev_row['key'], $current_keys, true ) ) {
					$dropped_out[] = array(
						'key'                  => $prev_row['key'],
						'coupon_code'          => $prev_row['coupon_code'],
						'previous_' . $orderby => $prev_row[ $orderby ],
					);
				}
			}

			$result['comparison'] = array(
				'period'           => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'totals'           => $prev_totals,
				'pipeline'         => $prev_pipeline,
				'admin_equivalent' => $prev_admin_equivalent,
				'changes'          => $totals_changes,
				'dropped_out'      => $dropped_out,
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Store-wide totals for the coupon-performance response.
	 *
	 * Uses a LEFT JOIN to a per-order coupons-used subquery against
	 * wc_order_coupon_lookup, so every paid order is classified as
	 * with-coupon or without-coupon via a single CASE partition.
	 * Ports the `coupons_used` strategy from the WC Analytics dashboard.
	 *
	 * Refund contribution: refund sub-orders (parent_id != 0) add to
	 * totals.refunds via the same (net_total + tax_total +
	 * shipping_total) formula as revenue_summary, so
	 * net_sales = net_revenue − refunds reconciles cleanly.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @return array Totals row.
	 */
	private static function query_coupon_performance_totals( $date_start, $date_end ) {
		global $wpdb;

		$os_table  = $wpdb->prefix . 'wc_order_stats';
		$ocl_table = $wpdb->prefix . 'wc_order_coupon_lookup';

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		// The LEFT JOIN subquery aggregates coupon usage per order_id
		// (orders can use multiple coupons) into a single row carrying
		// the order's total discount amount. cu.order_id IS NOT NULL
		// means the order used at least one coupon. coupon_id is
		// intentionally NOT carried on the join — the per-order MIN
		// would silently undercount distinct coupons on multi-coupon
		// orders (any coupon that only ever appears alongside a
		// lower-id coupon would never be the row minimum and would be
		// dropped from `distinct_coupons_used`). The distinct count
		// runs as a separate query below, against ungrouped rows.
		$totals_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.net_total ELSE 0 END) AS net_revenue,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN 1 ELSE 0 END) AS total_paid_orders,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.num_items_sold ELSE 0 END) AS items_sold,
					ABS(SUM(CASE WHEN os.parent_id != 0 THEN (os.net_total + os.tax_total + os.shipping_total) ELSE 0 END)) AS refunds,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) AND cu.order_id IS NOT NULL THEN 1 ELSE 0 END) AS orders_with_coupon,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) AND cu.order_id IS NULL THEN 1 ELSE 0 END) AS orders_without_coupon,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) AND cu.order_id IS NOT NULL THEN os.net_total ELSE 0 END) AS revenue_with_coupon,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) AND cu.order_id IS NULL THEN os.net_total ELSE 0 END) AS revenue_without_coupon,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN COALESCE(cu.discount_amount, 0) ELSE 0 END) AS total_discount_amount,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN os.net_total ELSE 0 END) AS pipeline_revenue,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN 1 ELSE 0 END) AS pipeline_orders_count,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN os.net_total ELSE 0 END) AS admin_revenue,
					SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN 1 ELSE 0 END) AS admin_orders_count
				FROM {$os_table} os
				LEFT JOIN (
					SELECT order_id, SUM(discount_amount) AS discount_amount
					FROM {$ocl_table}
					GROUP BY order_id
				) cu ON cu.order_id = os.order_id
				WHERE os.{$date_column} >= %s AND os.{$date_column} <= %s",
				array_merge(
					$paid_statuses,                                                             // net_revenue.
					$paid_statuses,                                                             // total_paid_orders.
					$paid_statuses,                                                             // items_sold.
					$paid_statuses,                                                             // orders_with_coupon.
					$paid_statuses,                                                             // orders_without_coupon.
					$paid_statuses,                                                             // revenue_with_coupon.
					$paid_statuses,                                                             // revenue_without_coupon.
					$paid_statuses,                                                             // total_discount_amount.
					$pipeline_statuses,                                                         // pipeline_revenue.
					$pipeline_statuses,                                                         // pipeline_orders_count.
					$admin_statuses,                                                            // admin_revenue.
					$admin_statuses,                                                            // admin_orders_count.
					array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
				)
			),
			ARRAY_A
		);

		// Distinct coupons used in the period, counted against the raw
		// wc_order_coupon_lookup rows (one per coupon-attachment, not
		// one per order). Joined to wc_order_stats to scope to paid
		// parent orders in the same window.
		$distinct_coupons_used = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT ocl.coupon_id)
				FROM {$ocl_table} ocl
				INNER JOIN {$os_table} os ON os.order_id = ocl.order_id
				WHERE os.parent_id = 0
					AND os.status IN ({$paid_ph})
					AND os.{$date_column} >= %s
					AND os.{$date_column} <= %s",
				array_merge(
					$paid_statuses,
					array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
				)
			)
		);

		if ( ! $totals_row ) {
			return array(
				'net_revenue'                    => 0.00,
				'refunds'                        => 0.00,
				'net_sales'                      => 0.00,
				'total_paid_orders'              => 0,
				'items_sold'                     => 0,
				'orders_with_coupon'             => 0,
				'orders_without_coupon'          => 0,
				'coupon_attachment_rate_percent' => 0.0,
				'revenue_with_coupon'            => 0.00,
				'revenue_without_coupon'         => 0.00,
				'total_discount_amount'          => 0.00,
				'avg_discount_per_coupon_order'  => 0.00,
				'avg_order_value_with_coupon'    => 0.00,
				'avg_order_value_without_coupon' => 0.00,
				'distinct_coupons_used'          => 0,
				'_pipeline_revenue'              => 0.00,
				'_pipeline_orders_count'         => 0,
				'_admin_revenue'                 => 0.00,
				'_admin_orders_count'            => 0,
			);
		}

		$net_revenue           = (float) $totals_row['net_revenue'];
		$refunds               = (float) $totals_row['refunds'];
		$total_paid_orders     = (int) $totals_row['total_paid_orders'];
		$orders_with_coupon    = (int) $totals_row['orders_with_coupon'];
		$orders_without_coupon = (int) $totals_row['orders_without_coupon'];
		$revenue_with_coupon   = (float) $totals_row['revenue_with_coupon'];
		$revenue_without       = (float) $totals_row['revenue_without_coupon'];
		$total_discount        = (float) $totals_row['total_discount_amount'];

		return array(
			'net_revenue'                    => round( $net_revenue, 2 ),
			'refunds'                        => round( $refunds, 2 ),
			'net_sales'                      => round( $net_revenue - $refunds, 2 ),
			'total_paid_orders'              => $total_paid_orders,
			'items_sold'                     => (int) $totals_row['items_sold'],
			'orders_with_coupon'             => $orders_with_coupon,
			'orders_without_coupon'          => $orders_without_coupon,
			'coupon_attachment_rate_percent' => $total_paid_orders > 0
				? round( ( $orders_with_coupon / $total_paid_orders ) * 100, 1 )
				: 0.0,
			'revenue_with_coupon'            => round( $revenue_with_coupon, 2 ),
			'revenue_without_coupon'         => round( $revenue_without, 2 ),
			'total_discount_amount'          => round( $total_discount, 2 ),
			'avg_discount_per_coupon_order'  => $orders_with_coupon > 0
				? round( $total_discount / $orders_with_coupon, 2 )
				: 0.00,
			'avg_order_value_with_coupon'    => $orders_with_coupon > 0
				? round( $revenue_with_coupon / $orders_with_coupon, 2 )
				: 0.00,
			'avg_order_value_without_coupon' => $orders_without_coupon > 0
				? round( $revenue_without / $orders_without_coupon, 2 )
				: 0.00,
			'distinct_coupons_used'          => $distinct_coupons_used,
			'_pipeline_revenue'              => round( (float) $totals_row['pipeline_revenue'], 2 ),
			'_pipeline_orders_count'         => (int) $totals_row['pipeline_orders_count'],
			'_admin_revenue'                 => round( (float) $totals_row['admin_revenue'], 2 ),
			'_admin_orders_count'            => (int) $totals_row['admin_orders_count'],
		);
	}

	/**
	 * Per-coupon top_groups rows.
	 *
	 * JOINs wc_order_coupon_lookup to wc_order_stats on the parent's
	 * order_id (via CASE parent_id) so refund sub-orders inherit the
	 * parent's coupons — same refund-attribution trick as
	 * revenue_breakdown_country_join. Coupon code comes from wp_posts
	 * (post_type='shop_coupon'); coupon metadata (coupon_type +
	 * configured coupon_amount) from wp_postmeta.
	 *
	 * Deleted coupons with no surviving wp_posts row surface their
	 * numeric ID as the code and coupon_type='unknown' — honest "this
	 * was used but has since been deleted" signal.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @param int    $limit      Top N.
	 * @param string $orderby    Sort metric.
	 * @return array Top group rows.
	 */
	private static function query_coupon_performance_groups( $date_start, $date_end, $limit, $orderby ) {
		global $wpdb;

		$os_table  = $wpdb->prefix . 'wc_order_stats';
		$ocl_table = $wpdb->prefix . 'wc_order_coupon_lookup';
		$posts     = $wpdb->posts;
		$postmeta  = $wpdb->postmeta;

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		$orderby_col = self::coupon_performance_orderby( $orderby );

		// JOIN-path summary:
		// ocl  : one row per (order, coupon). Grouping key is coupon_id.
		// os   : wc_order_stats on order_id — either the real paid/
		// pipeline order (parent_id=0) or a refund sub-order.
		// ocl_parent : inherit the parent order's coupon row for refund
		// sub-orders via the CASE on parent_id. Lets
		// refund values attribute to each coupon the
		// parent used, matching revenue_breakdown's
		// country / payment_method refund-attribution.
		// posts / postmeta : coupon title (= code), type, configured
		// discount value from wp_postmeta.
		$sql = $wpdb->prepare(
			"SELECT
				ocl.coupon_id AS group_key,
				COALESCE(NULLIF(p.post_title, ''), CAST(ocl.coupon_id AS CHAR)) AS coupon_code,
				p.ID AS coupon_post_id,
				COALESCE(pm_type.meta_value, 'unknown') AS coupon_type,
				COALESCE(CAST(pm_amount.meta_value AS DECIMAL(18,4)), 0) AS coupon_amount,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.net_total ELSE 0 END) AS net_revenue,
				COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.order_id END) AS orders_count,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.num_items_sold ELSE 0 END) AS items_sold,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN ocl.discount_amount ELSE 0 END) AS discount_amount,
				COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) AND os.returning_customer = 0 THEN os.order_id END) AS new_customers_count,
				COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) AND os.returning_customer = 1 THEN os.order_id END) AS returning_customers_count,
				ABS(SUM(CASE WHEN os.parent_id != 0 THEN (os.net_total + os.tax_total + os.shipping_total) ELSE 0 END)) AS refunds,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN os.net_total ELSE 0 END) AS pipeline_revenue,
				COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN os.order_id END) AS pipeline_orders_count,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN os.net_total ELSE 0 END) AS admin_revenue,
				COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN os.order_id END) AS admin_orders_count,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.net_total ELSE 0 END)
					/ NULLIF(COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.order_id END), 0) AS avg_order_value
			FROM {$ocl_table} ocl
			JOIN {$os_table} os
				ON ocl.order_id = (CASE WHEN os.parent_id = 0 THEN os.order_id ELSE os.parent_id END)
			LEFT JOIN {$posts} p
				ON p.ID = ocl.coupon_id
				AND p.post_type = 'shop_coupon'
			LEFT JOIN {$postmeta} pm_type
				ON pm_type.post_id = ocl.coupon_id
				AND pm_type.meta_key = 'discount_type'
			LEFT JOIN {$postmeta} pm_amount
				ON pm_amount.post_id = ocl.coupon_id
				AND pm_amount.meta_key = 'coupon_amount'
			WHERE os.{$date_column} >= %s AND os.{$date_column} <= %s
				AND ( (os.parent_id = 0 AND os.status IN ({$admin_ph})) OR os.parent_id != 0 )
			GROUP BY ocl.coupon_id, coupon_code, coupon_post_id, coupon_type, coupon_amount
			HAVING orders_count != 0 OR pipeline_orders_count != 0 OR admin_orders_count != 0
			ORDER BY {$orderby_col} DESC
			LIMIT %d",
			array_merge(
				$paid_statuses,                                                             // net_revenue.
				$paid_statuses,                                                             // orders_count.
				$paid_statuses,                                                             // items_sold.
				$paid_statuses,                                                             // discount_amount.
				$paid_statuses,                                                             // new_customers_count.
				$paid_statuses,                                                             // returning_customers_count.
				$pipeline_statuses,                                                         // pipeline_revenue.
				$pipeline_statuses,                                                         // pipeline_orders_count.
				$admin_statuses,                                                            // admin_revenue.
				$admin_statuses,                                                            // admin_orders_count.
				$paid_statuses,                                                             // avg_order_value num.
				$paid_statuses,                                                             // avg_order_value den.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$admin_statuses,                                                            // WHERE.
				array( $limit )
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return self::shape_coupon_group_row( $row );
			},
			$rows
		);
	}

	/**
	 * Whitelist orderby column against injection. Falls back to
	 * discount_amount for any unknown value.
	 *
	 * @param string $orderby Requested sort metric.
	 * @return string Safe column name.
	 */
	private static function coupon_performance_orderby( $orderby ) {
		$orderby_map = array(
			'discount_amount'     => 'discount_amount',
			'net_revenue'         => 'net_revenue',
			'orders_count'        => 'orders_count',
			'new_customers_count' => 'new_customers_count',
		);
		return isset( $orderby_map[ $orderby ] ) ? $orderby_map[ $orderby ] : 'discount_amount';
	}

	/**
	 * Assemble a uniform top_groups row for coupon performance.
	 *
	 * Pre-computed ratios per row:
	 *   refund_rate_percent                 = refunds / net_revenue × 100
	 *   avg_discount_per_order              = discount_amount / orders_count
	 *   new_customer_share_percent          = new / (new + returning) × 100
	 *   effective_campaign_cost             = discount_amount + refunds
	 *   effective_cost_to_paid_revenue_pct  = (discount + refunds) / net_revenue × 100
	 *
	 * Zero-guards on every denominator — pipeline-only rows (a coupon
	 * used only on on-hold orders) return 0.0 rather than dividing by
	 * zero. The tool description tells Claude to say "paid-revenue-less
	 * row" rather than quoting "0% refund rate". Same convention applies
	 * to effective_cost_to_paid_revenue_percent.
	 *
	 * `effective_campaign_cost` exists because the merchant-scope
	 * question "what did this coupon campaign actually cost me?" needs
	 * BOTH the discount given away AND any refund outflow on those
	 * coupon orders — a refunded coupon order is discount-lost plus
	 * goods-returned, margin-wise. Pre-computing the sum + the share
	 * prevents Claude from narrating the addition (narrative-layer
	 * drift is a pre-compute trigger too — see CLAUDE.md).
	 *
	 * @param array $row Raw SQL row.
	 * @return array Shaped group row.
	 */
	private static function shape_coupon_group_row( $row ) {
		$coupon_id                 = (int) $row['group_key'];
		$coupon_code               = (string) $row['coupon_code'];
		$net_revenue               = round( (float) $row['net_revenue'], 2 );
		$orders_count              = (int) $row['orders_count'];
		$discount_amount           = round( (float) $row['discount_amount'], 2 );
		$refunds                   = round( (float) $row['refunds'], 2 );
		$new_customers_count       = (int) $row['new_customers_count'];
		$returning_customers_count = (int) $row['returning_customers_count'];
		$customers_total           = $new_customers_count + $returning_customers_count;

		$refund_rate_percent = ( $net_revenue > 0 )
			? round( ( $refunds / $net_revenue ) * 100, 1 )
			: 0.0;

		$avg_discount_per_order = ( $orders_count > 0 )
			? round( $discount_amount / $orders_count, 2 )
			: 0.00;

		$new_customer_share_percent = ( $customers_total > 0 )
			? round( ( $new_customers_count / $customers_total ) * 100, 1 )
			: 0.0;

		$effective_campaign_cost = round( $discount_amount + $refunds, 2 );

		$effective_cost_to_paid_revenue_percent = ( $net_revenue > 0 )
			? round( ( $effective_campaign_cost / $net_revenue ) * 100, 1 )
			: 0.0;

		// Coupon post may be deleted — the row is preserved (LEFT JOIN +
		// COALESCE on coupon_code) but coupon_post_id is null. Skip the URL
		// in that case so we don't link the merchant to a missing edit
		// screen; the description-side "deleted coupons surface their numeric
		// ID" guidance still surfaces the row honestly.
		$coupon_post_id = isset( $row['coupon_post_id'] ) && null !== $row['coupon_post_id']
			? (int) $row['coupon_post_id']
			: 0;

		return array(
			'key'                                    => $coupon_id,
			'coupon_code'                            => $coupon_code,
			'admin_url'                              => self::coupon_admin_url( $coupon_post_id ),
			'coupon_type'                            => (string) $row['coupon_type'],
			'coupon_amount'                          => round( (float) $row['coupon_amount'], 2 ),
			'net_revenue'                            => $net_revenue,
			'orders_count'                           => $orders_count,
			'avg_order_value'                        => round( (float) $row['avg_order_value'], 2 ),
			'items_sold'                             => (int) $row['items_sold'],
			'discount_amount'                        => $discount_amount,
			'avg_discount_per_order'                 => $avg_discount_per_order,
			'new_customers_count'                    => $new_customers_count,
			'returning_customers_count'              => $returning_customers_count,
			'new_customer_share_percent'             => $new_customer_share_percent,
			'refunds'                                => $refunds,
			'refund_rate_percent'                    => $refund_rate_percent,
			'effective_campaign_cost'                => $effective_campaign_cost,
			'effective_cost_to_paid_revenue_percent' => $effective_cost_to_paid_revenue_percent,
			'pipeline_revenue'                       => round( (float) $row['pipeline_revenue'], 2 ),
			'pipeline_orders_count'                  => (int) $row['pipeline_orders_count'],
			'admin_equivalent_revenue'               => round( (float) $row['admin_revenue'], 2 ),
			'admin_equivalent_orders_count'          => (int) $row['admin_orders_count'],
		);
	}

	/**
	 * Pre-compute percentage changes for coupon-performance totals.
	 * Mirrors `calculate_revenue_breakdown_totals_changes` but on the
	 * coupon-performance key set.
	 *
	 * @param array $current  Current period totals.
	 * @param array $previous Previous period totals.
	 * @return array Per-key change rows with amount, percent, direction.
	 */
	private static function calculate_coupon_performance_totals_changes( $current, $previous ) {
		$keys = array(
			'net_revenue',
			'refunds',
			'net_sales',
			'total_paid_orders',
			'items_sold',
			'orders_with_coupon',
			'orders_without_coupon',
			'coupon_attachment_rate_percent',
			'revenue_with_coupon',
			'revenue_without_coupon',
			'total_discount_amount',
			'avg_discount_per_coupon_order',
			'avg_order_value_with_coupon',
			'avg_order_value_without_coupon',
			'distinct_coupons_used',
		);

		$changes = array();
		foreach ( $keys as $key ) {
			$curr = (float) ( $current[ $key ] ?? 0 );
			$prev = (float) ( $previous[ $key ] ?? 0 );
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	// ─── Refund Analysis ──────────────────────────────────────────

	/**
	 * Fetch the refund-analysis payload. Backs the
	 * `wc-analytics/get-refund-analysis` ability.
	 *
	 * The "period" semantics here are refund-issued: the date column
	 * applies to the refund sub-order's own date_created, answering
	 * "how many refunds did I issue this period?" (not "how many paid
	 * orders placed this period later got refunded?"). That aligns
	 * with how merchants reason — "my refunds this month" means
	 * cheques issued this month, regardless of when the original order
	 * landed. `timing.buckets` + `avg_days_to_refund` tell them how
	 * far back the parents actually are.
	 *
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start date (YYYY-MM-DD).
	 * @param string|null $date_end   Custom end date (YYYY-MM-DD).
	 * @param bool        $compare    Include previous-period comparison.
	 * @param string      $group_by   'none' | 'product' | 'country'.
	 * @param int         $limit      Top N rows in top_groups (1–50).
	 * @param bool        $include_unassigned Include rows whose group key is NULL/empty.
	 * @return array Response payload.
	 */
	public static function fetch_refund_analysis( $period, $date_start, $date_end, $compare, $group_by, $limit, $include_unassigned ) {
		$start              = microtime( true );
		$limit              = (int) $limit;
		$include_unassigned = (bool) $include_unassigned;

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		$cache_key = 'woocommerce_claude_refund_analysis_' . md5(
			$dates['start'] . '_' . $dates['end']
			. '_' . ( $compare ? '1' : '0' )
			. '_' . $group_by
			. '_' . $limit
			. '_' . ( $include_unassigned ? '1' : '0' )
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$metrics = self::query_refund_metrics( $dates['start'], $dates['end'] );
		$timing  = self::query_refund_timing_buckets( $dates['start'], $dates['end'] );
		$top     = array();
		if ( 'none' !== $group_by ) {
			$top = self::query_refund_top_groups( $dates['start'], $dates['end'], $group_by, $limit, $include_unassigned );
		}

		// Pre-compute per-row share_of_refunds_percent + refund_rate_percent.
		$refunds_denom = (float) $metrics['refunds_amount'];
		foreach ( $top as $i => $row ) {
			$top[ $i ]['share_of_refunds_percent'] = ( $refunds_denom > 0 )
				? round( ( $row['refunds_amount'] / $refunds_denom ) * 100, 1 )
				: 0.0;

			$gross                            = (float) $row['gross_revenue'];
			$top[ $i ]['refund_rate_percent'] = ( $gross > 0 )
				? round( ( $row['refunds_amount'] / $gross ) * 100, 1 )
				: null;
		}

		$currency = get_woocommerce_currency();

		$result = array(
			'period'             => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'           => $currency,
			'group_by'           => $group_by,
			'limit'              => $limit,
			'include_unassigned' => $include_unassigned,
			'metrics'            => $metrics,
			'timing'             => $timing,
			'top_groups'         => $top,
			'comparison'         => null,
			'note'               => null,
		);

		if ( 0 === (int) $metrics['refunds_count'] ) {
			$result['note']       = 'No refunds issued in this date range.';
			$result['top_groups'] = array();
		}

		if ( $compare ) {
			$prev_dates   = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_metrics = self::query_refund_metrics( $prev_dates['start'], $prev_dates['end'] );

			$result['comparison'] = array(
				'period'  => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'metrics' => $prev_metrics,
				'changes' => self::calculate_refund_changes( $metrics, $prev_metrics ),
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Zeroed metrics for empty periods — exposes the full key set so the
	 * ability schema never sees a missing field.
	 *
	 * @return array
	 */
	private static function empty_refund_metrics() {
		return array(
			'refunds_amount'         => 0.00,
			'refunds_count'          => 0,
			'orders_refunded_count'  => 0,
			'paid_gross_revenue'     => 0.00,
			'refund_rate_percent'    => 0.0,
			'avg_days_to_refund'     => 0.0,
			'median_days_to_refund'  => 0.0,
			'partial_refunds_count'  => 0,
			'partial_refunds_amount' => 0.00,
			'full_refunds_count'     => 0,
		);
	}

	/**
	 * Query the top-level refund metrics block.
	 *
	 * Two parallel aggregates against wc_order_stats:
	 *   - Refund sub-orders in the period (parent_id != 0, date_created
	 *     on the refund itself) — refunds_amount, refunds_count,
	 *     orders_refunded (DISTINCT parent_id), avg/median days to refund,
	 *     partial vs full split.
	 *   - Paid gross revenue in the period (parent_id = 0, paid statuses,
	 *     date_created on the parent) — denominator for refund_rate_percent.
	 *
	 * Two SQL calls rather than one massive CASE-pivot: the refund side
	 * needs a self-join to the parent row for DATEDIFF, which would
	 * pollute the paid-gross aggregate with DISTINCT plumbing we don't
	 * need. Two small queries beat one complicated one.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @return array Metrics block with public + pre-computed keys.
	 */
	private static function query_refund_metrics( $date_start, $date_end ) {
		global $wpdb;

		$os_table      = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses = self::get_paid_statuses();
		$paid_ph       = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$date_column   = self::get_date_column();

		// Refund-side aggregate. Self-join on os.parent_id = parent.order_id
		// so we can (a) read the parent status for partial/full detection
		// and (b) compute DATEDIFF(refund.date_created, parent.<date_column>)
		// — days between the original order and the refund.
		//
		// The refund side always uses `refund.date_created`, regardless
		// of `woocommerce_date_type`. Refund sub-orders frequently lack
		// `date_paid` / `date_completed` (no payment processed against
		// the refund; refunds aren't "completed" in the order-flow
		// sense), so filtering refunds by those columns dropped them on
		// real-world stores configured for payment-date analytics. The
		// parent side honours the merchant's chosen column so the
		// DATEDIFF semantics still match their analytics setting.
		//
		// The LEFT JOIN protects against orphan refund rows (parent
		// deleted), which shouldn't happen in a healthy store but
		// shouldn't crash us either.
		$refund_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					ABS(SUM(refund.net_total + refund.tax_total + refund.shipping_total)) AS refunds_amount,
					COUNT(*) AS refunds_count,
					COUNT(DISTINCT refund.parent_id) AS orders_refunded_count,
					SUM(CASE WHEN parent.status = 'wc-refunded' THEN 1 ELSE 0 END) AS full_refunds_count,
					ABS(SUM(CASE WHEN parent.status != 'wc-refunded' OR parent.status IS NULL THEN (refund.net_total + refund.tax_total + refund.shipping_total) ELSE 0 END)) AS partial_refunds_amount,
					AVG(DATEDIFF(refund.date_created, parent.{$date_column})) AS avg_days_to_refund
				FROM {$os_table} refund
				LEFT JOIN {$os_table} parent ON parent.order_id = refund.parent_id AND parent.parent_id = 0
				WHERE refund.parent_id != 0
					AND refund.date_created >= %s
					AND refund.date_created <= %s",
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
			),
			ARRAY_A
		);

		$paid_gross = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(net_total)
				FROM {$os_table}
				WHERE parent_id = 0
					AND status IN ({$paid_ph})
					AND {$date_column} >= %s
					AND {$date_column} <= %s",
				array_merge(
					$paid_statuses,
					array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
				)
			)
		);

		if ( ! $refund_row || 0 === (int) $refund_row['refunds_count'] ) {
			$empty                       = self::empty_refund_metrics();
			$empty['paid_gross_revenue'] = round( (float) $paid_gross, 2 );
			return $empty;
		}

		$refunds_amount        = round( (float) $refund_row['refunds_amount'], 2 );
		$refunds_count         = (int) $refund_row['refunds_count'];
		$full_refunds_count    = (int) $refund_row['full_refunds_count'];
		$partial_refunds_count = $refunds_count - $full_refunds_count;
		$paid_gross_revenue    = round( (float) $paid_gross, 2 );

		// Median via a separate query — MySQL has no native MEDIAN and the
		// percentile trick needs a window function that's only available
		// on MySQL 8+. Pull the day diffs back and compute in PHP — refund
		// counts are usually < 1000 per month per store, so the array is
		// small.
		$median_days = self::query_refund_median_days( $date_start, $date_end );

		// Mathematically undefined when paid_gross_revenue is 0. Returning
		// 0.0 here pre-fix made a refund-only window look like "0% refund
		// rate" instead of "rate has no denominator" — the merchant got a
		// healthy-looking number for a window that actually warrants a
		// "we can't compute that" answer.
		$refund_rate_percent = ( $paid_gross_revenue > 0 )
			? round( ( $refunds_amount / $paid_gross_revenue ) * 100, 1 )
			: null;

		return array(
			'refunds_amount'         => $refunds_amount,
			'refunds_count'          => $refunds_count,
			'orders_refunded_count'  => (int) $refund_row['orders_refunded_count'],
			'paid_gross_revenue'     => $paid_gross_revenue,
			'refund_rate_percent'    => $refund_rate_percent,
			'avg_days_to_refund'     => round( (float) $refund_row['avg_days_to_refund'], 1 ),
			'median_days_to_refund'  => $median_days,
			'partial_refunds_count'  => $partial_refunds_count,
			'partial_refunds_amount' => round( (float) $refund_row['partial_refunds_amount'], 2 ),
			'full_refunds_count'     => $full_refunds_count,
		);
	}

	/**
	 * Median days-to-refund.
	 *
	 * MySQL before 8.0 has no percentile / median function, so we pull
	 * the list of day-diffs back to PHP and compute it there. A store
	 * issuing thousands of refunds a month is rare, so this stays
	 * cheap — and it keeps the SQL portable across MySQL 5.7 hosts.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @return float Median days (rounded to 1dp).
	 */
	private static function query_refund_median_days( $date_start, $date_end ) {
		global $wpdb;

		$os_table    = $wpdb->prefix . 'wc_order_stats';
		$date_column = self::get_date_column();

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DATEDIFF(refund.date_created, parent.{$date_column}) AS days_to_refund
				FROM {$os_table} refund
				LEFT JOIN {$os_table} parent ON parent.order_id = refund.parent_id AND parent.parent_id = 0
				WHERE refund.parent_id != 0
					AND refund.date_created >= %s
					AND refund.date_created <= %s
					AND parent.order_id IS NOT NULL
				ORDER BY days_to_refund ASC",
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
			)
		);

		if ( empty( $rows ) ) {
			return 0.0;
		}

		$values = array_map( 'intval', $rows );
		$n      = count( $values );
		if ( 0 === $n % 2 ) {
			$mid    = (int) ( $n / 2 );
			$median = ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
		} else {
			$median = $values[ (int) ( ( $n - 1 ) / 2 ) ];
		}
		return round( (float) $median, 1 );
	}

	/**
	 * Bucket refund sub-orders by how long after the original order
	 * they were issued.
	 *
	 * Buckets are calendar-rounded — "Same day" is DATEDIFF = 0
	 * (refund.date_created's date matches parent.date_created's date);
	 * "1–7 days" is DATEDIFF 1–7 inclusive; "8–30" is 8–30; "31+" is
	 * everything else. Returns a fixed-order array so Claude doesn't
	 * have to sort buckets itself.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @return array { buckets: [{key,label,count,share_percent}], total: int }
	 */
	private static function query_refund_timing_buckets( $date_start, $date_end ) {
		global $wpdb;

		$os_table    = $wpdb->prefix . 'wc_order_stats';
		$date_column = self::get_date_column();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM(CASE WHEN DATEDIFF(refund.date_created, parent.{$date_column}) = 0 THEN 1 ELSE 0 END) AS same_day,
					SUM(CASE WHEN DATEDIFF(refund.date_created, parent.{$date_column}) BETWEEN 1 AND 7 THEN 1 ELSE 0 END) AS within_week,
					SUM(CASE WHEN DATEDIFF(refund.date_created, parent.{$date_column}) BETWEEN 8 AND 30 THEN 1 ELSE 0 END) AS within_month,
					SUM(CASE WHEN DATEDIFF(refund.date_created, parent.{$date_column}) > 30 THEN 1 ELSE 0 END) AS beyond_month,
					COUNT(*) AS total
				FROM {$os_table} refund
				LEFT JOIN {$os_table} parent ON parent.order_id = refund.parent_id AND parent.parent_id = 0
				WHERE refund.parent_id != 0
					AND refund.date_created >= %s
					AND refund.date_created <= %s
					AND parent.order_id IS NOT NULL",
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
			),
			ARRAY_A
		);

		$total = $row ? (int) $row['total'] : 0;

		$buckets = array(
			array(
				'key'   => 'same_day',
				'label' => 'Same day',
				'count' => $row ? (int) $row['same_day'] : 0,
			),
			array(
				'key'   => 'within_week',
				'label' => '1–7 days',
				'count' => $row ? (int) $row['within_week'] : 0,
			),
			array(
				'key'   => 'within_month',
				'label' => '8–30 days',
				'count' => $row ? (int) $row['within_month'] : 0,
			),
			array(
				'key'   => 'beyond_month',
				'label' => '31+ days',
				'count' => $row ? (int) $row['beyond_month'] : 0,
			),
		);

		foreach ( $buckets as $i => $bucket ) {
			$buckets[ $i ]['share_percent'] = ( $total > 0 )
				? round( ( $bucket['count'] / $total ) * 100, 1 )
				: 0.0;
		}

		return array(
			'buckets'    => $buckets,
			'total'      => $total,
			'definition' => 'Count of refund sub-orders bucketed by days between the original order and the refund. "Same day" = DATEDIFF 0. Useful shape: heavy on Same day often signals wrong-item / payment-confusion; heavy on 31+ days signals delivery / quality issues.',
		);
	}

	/**
	 * Dispatch to the per-dimension top_groups query for refunds.
	 *
	 * @param string $date_start         YYYY-MM-DD start.
	 * @param string $date_end           YYYY-MM-DD end.
	 * @param string $group_by           'product' | 'country'.
	 * @param int    $limit              Top N.
	 * @param bool   $include_unassigned Include (Unassigned) rows.
	 * @return array Top group rows.
	 */
	private static function query_refund_top_groups( $date_start, $date_end, $group_by, $limit, $include_unassigned ) {
		switch ( $group_by ) {
			case 'product':
				return self::query_refund_top_groups_product( $date_start, $date_end, $limit );
			case 'country':
				return self::query_refund_top_groups_country( $date_start, $date_end, $limit, $include_unassigned );
		}
		return array();
	}

	/**
	 * Top refunded products in a period.
	 *
	 * Joins refund sub-orders' product_lookup rows to produce per-product
	 * refund amount, count of distinct refunds touching that product,
	 * distinct parent orders refunded for that product, the product's
	 * paid gross revenue in the same period (denominator for row-level
	 * refund_rate_percent), and avg days-to-refund on the product's
	 * refunds.
	 *
	 * Attribution rule: a refund touching two product lines contributes
	 * to two rows. Per-product refund amounts can therefore sum to more
	 * than the headline refunds_amount on multi-line refunds — same
	 * shape as get_revenue_breakdown group_by=category and flagged in
	 * the tool description.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @param int    $limit      Top N.
	 * @return array Top rows.
	 */
	private static function query_refund_top_groups_product( $date_start, $date_end, $limit ) {
		global $wpdb;

		$os_table      = $wpdb->prefix . 'wc_order_stats';
		$pl_table      = $wpdb->prefix . 'wc_order_product_lookup';
		$posts_table   = $wpdb->posts;
		$paid_statuses = self::get_paid_statuses();
		$paid_ph       = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$date_column   = self::get_date_column();

		// Refund-side aggregate per product: pull product_id, group, sum
		// ABS of product_net_revenue on the refund rows. Join to parent
		// for DATEDIFF. Join to the posts table for the product title.
		// LEFT JOIN to a per-product paid-gross subquery so every row
		// carries a denominator for row-level refund_rate_percent.
		$sql = $wpdb->prepare(
			"SELECT
				pl.product_id AS group_key,
				COALESCE(p.post_title, CONCAT('#', pl.product_id)) AS group_label,
				p.ID AS product_post_id,
				ABS(SUM(pl.product_net_revenue)) AS refunds_amount,
				COUNT(*) AS refunds_count,
				COUNT(DISTINCT refund.parent_id) AS orders_refunded_count,
				AVG(DATEDIFF(refund.date_created, parent.{$date_column})) AS avg_days_to_refund,
				COALESCE(paid.paid_gross, 0) AS gross_revenue
			FROM {$pl_table} pl
			JOIN {$os_table} refund ON refund.order_id = pl.order_id
			LEFT JOIN {$os_table} parent ON parent.order_id = refund.parent_id AND parent.parent_id = 0
			LEFT JOIN {$posts_table} p ON p.ID = pl.product_id
			LEFT JOIN (
				SELECT pl2.product_id, SUM(pl2.product_net_revenue) AS paid_gross
				FROM {$pl_table} pl2
				JOIN {$os_table} os2 ON os2.order_id = pl2.order_id
				WHERE os2.parent_id = 0
					AND os2.status IN ({$paid_ph})
					AND os2.{$date_column} >= %s
					AND os2.{$date_column} <= %s
				GROUP BY pl2.product_id
			) paid ON paid.product_id = pl.product_id
			WHERE refund.parent_id != 0
				AND refund.date_created >= %s
				AND refund.date_created <= %s
			GROUP BY pl.product_id, p.post_title, p.ID, paid.paid_gross
			ORDER BY refunds_amount DESC
			LIMIT %d",
			array_merge(
				$paid_statuses,
				array(
					$date_start . ' 00:00:00',
					$date_end . ' 23:59:59',
					$date_start . ' 00:00:00',
					$date_end . ' 23:59:59',
					$limit,
				)
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				$key = (string) $row['group_key'];
				// Product post may be deleted — refunds against it stay in
				// the report (LEFT JOIN + COALESCE on the label) but linking
				// to a missing edit screen would mislead, so gate the URL
				// on the joined post actually existing.
				$admin_url = ( isset( $row['product_post_id'] ) && null !== $row['product_post_id'] )
					? self::product_admin_url( (int) $row['product_post_id'] )
					: null;
				return self::shape_refund_group_row( $key, (string) $row['group_label'], $row, $admin_url );
			},
			$rows
		);
	}

	/**
	 * Top refunded countries in a period.
	 *
	 * Refunds inherit the parent order's billing country via the same
	 * `revenue_breakdown_country_join()` helper `get_revenue_breakdown`
	 * uses — so "refunds by country" attributes refund sub-orders to
	 * where the original purchase shipped from billing-wise.
	 *
	 * Paid-gross denominator is per-country paid revenue in the same
	 * window, joined in via a subquery so each row carries its own
	 * refund_rate_percent.
	 *
	 * @param string $date_start         YYYY-MM-DD start.
	 * @param string $date_end           YYYY-MM-DD end.
	 * @param int    $limit              Top N.
	 * @param bool   $include_unassigned Include (Unassigned) row.
	 * @return array Top rows.
	 */
	private static function query_refund_top_groups_country( $date_start, $date_end, $limit, $include_unassigned ) {
		global $wpdb;

		$os_table      = $wpdb->prefix . 'wc_order_stats';
		$paid_statuses = self::get_paid_statuses();
		$paid_ph       = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$date_column   = self::get_date_column();

		$addr = self::revenue_breakdown_country_join( 'refund' );
		$join = $addr['join'];
		$expr = $addr['expr'];

		// Paid-gross denominator subquery: same country join, but keyed
		// from the paid parent row. The outer SELECT uses the refund-
		// side country expression so `paid.country = country_expr`
		// matches — both point at the parent's billing country.
		$paid_addr = self::revenue_breakdown_country_join( 'os2' );

		$unassigned_filter = $include_unassigned
			? ''
			: " AND ({$expr}) IS NOT NULL AND ({$expr}) != ''";

		$sql = $wpdb->prepare(
			"SELECT
				({$expr}) AS group_key,
				ABS(SUM(refund.net_total + refund.tax_total + refund.shipping_total)) AS refunds_amount,
				COUNT(*) AS refunds_count,
				COUNT(DISTINCT refund.parent_id) AS orders_refunded_count,
				AVG(DATEDIFF(refund.date_created, parent.{$date_column})) AS avg_days_to_refund,
				COALESCE(paid.paid_gross, 0) AS gross_revenue
			FROM {$os_table} refund
			LEFT JOIN {$os_table} parent ON parent.order_id = refund.parent_id AND parent.parent_id = 0
			{$join}
			LEFT JOIN (
				SELECT ({$paid_addr['expr']}) AS country, SUM(os2.net_total) AS paid_gross
				FROM {$os_table} os2
				{$paid_addr['join']}
				WHERE os2.parent_id = 0
					AND os2.status IN ({$paid_ph})
					AND os2.{$date_column} >= %s
					AND os2.{$date_column} <= %s
				GROUP BY country
			) paid ON paid.country = ({$expr})
			WHERE refund.parent_id != 0
				AND refund.date_created >= %s
				AND refund.date_created <= %s
				{$unassigned_filter}
			GROUP BY group_key, paid.paid_gross
			ORDER BY refunds_amount DESC
			LIMIT %d",
			array_merge(
				$paid_statuses,
				array(
					$date_start . ' 00:00:00',
					$date_end . ' 23:59:59',
					$date_start . ' 00:00:00',
					$date_end . ' 23:59:59',
					$limit,
				)
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				$raw_key       = $row['group_key'];
				$is_unassigned = ( null === $raw_key || '' === $raw_key );
				$key           = $is_unassigned ? '(Unassigned)' : (string) $raw_key;
				$label         = $key;
				return self::shape_refund_group_row( $key, $label, $row );
			},
			$rows
		);
	}

	/**
	 * Assemble a uniform top_groups row for refund responses. Used by
	 * product + country dimensions so the response shape stays identical.
	 * Pre-computes refund_rate_percent and share_of_refunds_percent in
	 * fetch_refund_analysis() because the store-wide denominator isn't
	 * available here — this helper only handles the per-row mapping.
	 *
	 * `$admin_url` is null for country rows (countries have no admin
	 * screen) and a product-edit URL for product rows. The key always
	 * stays present so the response shape is identical across dimensions.
	 *
	 * @param string      $key       Group key (stable string).
	 * @param string      $label     Human-readable label.
	 * @param array       $row       Raw SQL row.
	 * @param string|null $admin_url Optional WP Admin URL for the row's entity.
	 * @return array Shaped group row.
	 */
	private static function shape_refund_group_row( $key, $label, $row, $admin_url = null ) {
		return array(
			'key'                   => $key,
			'label'                 => $label,
			'admin_url'             => $admin_url,
			'refunds_amount'        => round( (float) $row['refunds_amount'], 2 ),
			'refunds_count'         => (int) $row['refunds_count'],
			'orders_refunded_count' => (int) $row['orders_refunded_count'],
			'gross_revenue'         => round( (float) $row['gross_revenue'], 2 ),
			'avg_days_to_refund'    => round( (float) $row['avg_days_to_refund'], 1 ),
		);
	}

	/**
	 * Pre-compute percentage changes for refund metrics.
	 *
	 * @param array $current  Current period metrics.
	 * @param array $previous Previous period metrics.
	 * @return array Per-key change rows with amount, percent, direction.
	 */
	private static function calculate_refund_changes( $current, $previous ) {
		$keys = array(
			'refunds_amount',
			'refunds_count',
			'orders_refunded_count',
			'refund_rate_percent',
			'avg_days_to_refund',
			'median_days_to_refund',
			'partial_refunds_count',
			'partial_refunds_amount',
			'full_refunds_count',
		);

		$changes = array();
		foreach ( $keys as $key ) {
			$curr = (float) ( $current[ $key ] ?? 0 );
			$prev = (float) ( $previous[ $key ] ?? 0 );
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	// ─── Tax Summary ──────────────────────────────────────────────

	/**
	 * Fetch the tax-summary payload. Backs the
	 * `wc-analytics/get-tax-summary` ability.
	 *
	 * Answers "what tax did I collect, and what do I owe?" — total tax
	 * with order_tax / shipping_tax split, refunded tax, the net-tax
	 * figure for VAT / sales-tax returns, and a per-rate breakdown.
	 * Three-view pattern (paid / pipeline / admin_equivalent) so
	 * collected, on-hold, and dashboard totals stay distinct.
	 *
	 * Refund attribution: a refund sub-order's tax_lookup row carries
	 * the same `tax_rate_id` as the original line, so refunded_tax
	 * attributes back to the rate that collected it.
	 *
	 * Empty-period handling: when totals.total_tax is 0 and pipeline.total_tax
	 * is 0, the response includes a `note` explaining the store either
	 * doesn't charge tax in the period or has no taxable orders. When
	 * top_rates is empty but totals.total_tax > 0, that's tax collected
	 * without a configured rate (legacy / manual entry) — narrate honestly.
	 *
	 * @param string      $period     Period shortcut (last_30_days, etc.).
	 * @param string|null $date_start Custom start date (YYYY-MM-DD) — overrides period.
	 * @param string|null $date_end   Custom end date (YYYY-MM-DD) — overrides period.
	 * @param bool        $compare    Include previous-period comparison.
	 * @param int         $limit      Number of top tax rates to return.
	 * @param string      $orderby    Sort column for top_rates.
	 * @return array Response payload.
	 */
	public static function fetch_tax_summary( $period, $date_start, $date_end, $compare, $limit, $orderby ) {
		$start   = microtime( true );
		$limit   = (int) $limit;
		$orderby = self::tax_summary_orderby( $orderby );

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		$cache_key = 'woocommerce_claude_tax_summary_' . md5(
			$dates['start'] . '_' . $dates['end']
			. '_' . ( $compare ? '1' : '0' )
			. '_' . $limit
			. '_' . $orderby
			. '_' . self::get_date_column()
			. '_' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$totals    = self::query_tax_summary_totals( $dates['start'], $dates['end'] );
		$top_rates = self::query_tax_summary_rates( $dates['start'], $dates['end'], $limit, $orderby );

		$pipeline_block         = array(
			'total_tax'    => isset( $totals['_pipeline_total_tax'] ) ? (float) $totals['_pipeline_total_tax'] : 0.00,
			'orders_count' => isset( $totals['_pipeline_orders_count'] ) ? (int) $totals['_pipeline_orders_count'] : 0,
			'definition'   => 'Tax on on-hold orders awaiting payment. Funds are not yet in the merchant\'s account, so this is collected at checkout but not legally collected revenue until the order clears. Surface separately when material; do not lump into total_tax.',
		);
		$admin_equivalent_block = array(
			'total_tax'    => isset( $totals['_admin_total_tax'] ) ? (float) $totals['_admin_total_tax'] : 0.00,
			'orders_count' => isset( $totals['_admin_orders_count'] ) ? (int) $totals['_admin_orders_count'] : 0,
			'definition'   => 'What WC Admin > WooCommerce > Reports > Tax shows: paid + on-hold + refunded summed straight. For dashboard reconciliation only; not the figure for a VAT / sales-tax return.',
		);
		unset(
			$totals['_pipeline_total_tax'],
			$totals['_pipeline_orders_count'],
			$totals['_admin_total_tax'],
			$totals['_admin_orders_count']
		);

		// Pre-computed share_of_tax_percent per row — denominator is full
		// paid total_tax so the figure stays correct regardless of limit.
		$share_denominator = (float) $totals['total_tax'];
		foreach ( $top_rates as $i => $row ) {
			$top_rates[ $i ]['share_of_tax_percent'] = ( $share_denominator > 0 )
				? round( ( $row['total_tax'] / $share_denominator ) * 100, 1 )
				: 0.0;
		}

		$currency = get_woocommerce_currency();

		$result = array(
			'period'           => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'         => $currency,
			'orderby'          => $orderby,
			'limit'            => $limit,
			'totals'           => $totals,
			'pipeline'         => $pipeline_block,
			'admin_equivalent' => $admin_equivalent_block,
			'top_rates'        => $top_rates,
			'comparison'       => null,
			'note'             => null,
		);

		// Empty-period and unmatched-rate notes — honest "no data" framing.
		if ( 0.0 === (float) $totals['total_tax'] && 0.0 === (float) $pipeline_block['total_tax'] ) {
			$result['note'] = 'No tax was collected in this period. The store either does not charge tax, or has no taxable orders in this date range. Check WP Admin > WooCommerce > Settings > Tax for the configured rates.';
		} elseif ( empty( $top_rates ) && (float) $totals['total_tax'] > 0 ) {
			$result['note'] = 'Tax was collected but cannot be attributed to a configured tax rate. This usually means the orders carry tax_rate_id values that no longer match a row in WP Admin > WooCommerce > Settings > Tax (legacy data or manually-entered tax). The headline totals are still accurate.';
		}

		if ( $compare ) {
			$prev_dates            = self::get_previous_period( $dates['start'], $dates['end'] );
			$prev_totals_raw       = self::query_tax_summary_totals( $prev_dates['start'], $prev_dates['end'] );
			$prev_top              = self::query_tax_summary_rates( $prev_dates['start'], $prev_dates['end'], $limit, $orderby );
			$prev_pipeline         = array(
				'total_tax'    => isset( $prev_totals_raw['_pipeline_total_tax'] ) ? (float) $prev_totals_raw['_pipeline_total_tax'] : 0.00,
				'orders_count' => isset( $prev_totals_raw['_pipeline_orders_count'] ) ? (int) $prev_totals_raw['_pipeline_orders_count'] : 0,
			);
			$prev_admin_equivalent = array(
				'total_tax'    => isset( $prev_totals_raw['_admin_total_tax'] ) ? (float) $prev_totals_raw['_admin_total_tax'] : 0.00,
				'orders_count' => isset( $prev_totals_raw['_admin_orders_count'] ) ? (int) $prev_totals_raw['_admin_orders_count'] : 0,
			);
			$prev_totals           = $prev_totals_raw;
			unset(
				$prev_totals['_pipeline_total_tax'],
				$prev_totals['_pipeline_orders_count'],
				$prev_totals['_admin_total_tax'],
				$prev_totals['_admin_orders_count']
			);

			$totals_changes = self::calculate_tax_summary_totals_changes( $totals, $prev_totals );

			$prev_by_key = array();
			foreach ( $prev_top as $row ) {
				$prev_by_key[ self::tax_summary_row_key( $row ) ] = $row;
			}

			$current_keys = array();
			foreach ( $top_rates as $i => $row ) {
				$key            = self::tax_summary_row_key( $row );
				$current_keys[] = $key;
				if ( isset( $prev_by_key[ $key ] ) ) {
					$top_rates[ $i ]['change'] = self::calculate_attribution_change(
						$row,
						$prev_by_key[ $key ],
						$orderby
					);
				} else {
					$top_rates[ $i ]['change'] = array(
						'metric'    => $orderby,
						'amount'    => null,
						'percent'   => null,
						'direction' => 'new',
						'note'      => 'New to top results this period.',
					);
				}
			}
			$result['top_rates'] = $top_rates;

			$dropped_out = array();
			foreach ( $prev_top as $row ) {
				$key = self::tax_summary_row_key( $row );
				if ( ! in_array( $key, $current_keys, true ) ) {
					$dropped_out[] = array(
						'tax_rate_id'          => $row['tax_rate_id'],
						'tax_rate_name'        => $row['tax_rate_name'],
						'tax_rate_country'     => $row['tax_rate_country'],
						'previous_' . $orderby => $row[ $orderby ],
					);
				}
			}

			$result['comparison'] = array(
				'period'           => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'totals'           => $prev_totals,
				'pipeline'         => $prev_pipeline,
				'admin_equivalent' => $prev_admin_equivalent,
				'changes'          => $totals_changes,
				'dropped_out'      => $dropped_out,
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Whitelist + default for the tax_summary orderby parameter.
	 *
	 * @param string $orderby Caller-supplied value.
	 * @return string Sanitised column name (always one of the four allowed).
	 */
	private static function tax_summary_orderby( $orderby ) {
		$allowed = array( 'total_tax', 'order_tax', 'shipping_tax', 'orders_count' );
		return in_array( $orderby, $allowed, true ) ? $orderby : 'total_tax';
	}

	/**
	 * Stable identity for a tax-summary row across periods. Uses
	 * tax_rate_id when non-zero (the canonical join), otherwise
	 * country|state|name to keep unmatched / legacy rows comparable.
	 *
	 * @param array $row Tax-summary row.
	 * @return string Stable per-rate key.
	 */
	private static function tax_summary_row_key( $row ) {
		$id = (int) ( $row['tax_rate_id'] ?? 0 );
		if ( $id > 0 ) {
			return 'rate:' . $id;
		}
		return 'unmatched:' . ( $row['tax_rate_country'] ?? '' ) . '|' . ( $row['tax_rate_state'] ?? '' ) . '|' . ( $row['tax_rate_name'] ?? '' );
	}

	/**
	 * Store-wide tax totals for the period. Single pass over wc_order_stats
	 * joined to wc_order_tax_lookup. Returns paid + pipeline + admin
	 * aggregates so the top-level blocks can be assembled in one place.
	 *
	 * Includes a paid `net_revenue` field (recomputed off the same join)
	 * so the response can pre-compute `effective_tax_rate_percent`
	 * without a second query — keeps the comparison block consistent
	 * across periods because both periods use the same denominator
	 * definition.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @return array Totals row + internal pipeline / admin fields prefixed `_`.
	 */
	private static function query_tax_summary_totals( $date_start, $date_end ) {
		global $wpdb;

		$os_table = $wpdb->prefix . 'wc_order_stats';
		$tl_table = $wpdb->prefix . 'wc_order_tax_lookup';

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		// `paid_net_revenue` runs as a separate query against
		// wc_order_stats only — without the wc_order_tax_lookup join.
		// An order with multiple tax-rate rows (mixed VAT on goods +
		// shipping, reduced-rate items, etc.) has multiple rows in
		// wc_order_tax_lookup, and a LEFT JOIN multiplies the
		// wc_order_stats row across them. Summing os.net_total across
		// the joined result counts the order's revenue once per tax row
		// — inflating the denominator and deflating the
		// `effective_tax_rate_percent` ratio computed below.
		// Tax aggregates (total_tax / order_tax / etc.) correctly want
		// to sum across the joined tl rows, so they stay in the main
		// query.
		$paid_net_revenue = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(net_total), 0)
				FROM {$os_table}
				WHERE parent_id = 0
					AND status IN ({$paid_ph})
					AND {$date_column} >= %s
					AND {$date_column} <= %s",
				array_merge(
					$paid_statuses,
					array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' )
				)
			)
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN tl.total_tax ELSE 0 END), 0) AS total_tax,
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN tl.order_tax ELSE 0 END), 0) AS order_tax,
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN tl.shipping_tax ELSE 0 END), 0) AS shipping_tax,
					COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.order_id END) AS total_paid_orders,
					COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) AND tl.total_tax > 0 THEN os.order_id END) AS taxable_orders,
					COALESCE(ABS(SUM(CASE WHEN os.parent_id != 0 THEN tl.total_tax ELSE 0 END)), 0) AS refunded_tax,
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN tl.total_tax ELSE 0 END), 0) AS pipeline_total_tax,
					COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN os.order_id END) AS pipeline_orders_count,
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN tl.total_tax ELSE 0 END), 0) AS admin_total_tax,
					COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN os.order_id END) AS admin_orders_count
				FROM {$os_table} os
				LEFT JOIN {$tl_table} tl ON tl.order_id = os.order_id
				WHERE os.{$date_column} >= %s AND os.{$date_column} <= %s
					AND ( os.status IN ({$admin_ph}) OR os.parent_id != 0 )",
				array_merge(
					$paid_statuses, // total_tax.
					$paid_statuses, // order_tax.
					$paid_statuses, // shipping_tax.
					$paid_statuses, // total_paid_orders.
					$paid_statuses, // taxable_orders.
					$pipeline_statuses, // pipeline_total_tax.
					$pipeline_statuses, // pipeline_orders_count.
					$admin_statuses,    // admin_total_tax.
					$admin_statuses,    // admin_orders_count.
					array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
					$admin_statuses     // WHERE.
				)
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return self::empty_tax_summary_totals();
		}

		$total_tax         = (float) $row['total_tax'];
		$order_tax         = (float) $row['order_tax'];
		$shipping_tax      = (float) $row['shipping_tax'];
		$refunded_tax      = (float) $row['refunded_tax'];
		$total_paid_orders = (int) $row['total_paid_orders'];
		$taxable_orders    = (int) $row['taxable_orders'];

		$net_tax                    = round( $total_tax - $refunded_tax, 2 );
		$taxable_share_percent      = ( $total_paid_orders > 0 )
			? round( ( $taxable_orders / $total_paid_orders ) * 100, 1 )
			: 0.0;
		$effective_tax_rate_percent = ( $paid_net_revenue > 0 )
			? round( ( $total_tax / $paid_net_revenue ) * 100, 1 )
			: 0.0;

		return array(
			'total_tax'                  => round( $total_tax, 2 ),
			'order_tax'                  => round( $order_tax, 2 ),
			'shipping_tax'               => round( $shipping_tax, 2 ),
			'refunded_tax'               => round( $refunded_tax, 2 ),
			'net_tax'                    => $net_tax,
			'paid_net_revenue'           => round( $paid_net_revenue, 2 ),
			'total_paid_orders'          => $total_paid_orders,
			'taxable_orders'             => $taxable_orders,
			'taxable_share_percent'      => $taxable_share_percent,
			'effective_tax_rate_percent' => $effective_tax_rate_percent,
			'definition'                 => 'PAID tax — orders in completed + processing. total_tax is gross of refunds; net_tax = total_tax − refunded_tax (the figure for a VAT / sales-tax return). On-hold tax sits separately in the pipeline block.',
			// Internal: assembled into pipeline / admin_equivalent blocks.
			'_pipeline_total_tax'        => round( (float) $row['pipeline_total_tax'], 2 ),
			'_pipeline_orders_count'     => (int) $row['pipeline_orders_count'],
			'_admin_total_tax'           => round( (float) $row['admin_total_tax'], 2 ),
			'_admin_orders_count'        => (int) $row['admin_orders_count'],
		);
	}

	/**
	 * Per-rate breakdown for the period. GROUP BY tax_rate_id with a
	 * LEFT JOIN onto woocommerce_tax_rates for the rate's name + country
	 * + state + percentage. Refund sub-orders contribute to refunded_tax
	 * for the rate they originally collected against.
	 *
	 * @param string $date_start YYYY-MM-DD start.
	 * @param string $date_end   YYYY-MM-DD end.
	 * @param int    $limit      Max rows.
	 * @param string $orderby    Sort column (already whitelisted by caller).
	 * @return array Per-rate rows.
	 */
	private static function query_tax_summary_rates( $date_start, $date_end, $limit, $orderby ) {
		global $wpdb;

		$os_table = $wpdb->prefix . 'wc_order_stats';
		$tl_table = $wpdb->prefix . 'wc_order_tax_lookup';
		$tr_table = $wpdb->prefix . 'woocommerce_tax_rates';

		$paid_statuses     = self::get_paid_statuses();
		$pipeline_statuses = self::get_pipeline_statuses();
		$admin_statuses    = self::get_admin_equivalent_statuses();

		$paid_ph     = implode( ', ', array_fill( 0, count( $paid_statuses ), '%s' ) );
		$pipeline_ph = implode( ', ', array_fill( 0, count( $pipeline_statuses ), '%s' ) );
		$admin_ph    = implode( ', ', array_fill( 0, count( $admin_statuses ), '%s' ) );

		$date_column = self::get_date_column();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					tl.tax_rate_id AS tax_rate_id,
					COALESCE(MAX(tr.tax_rate_name), '') AS tax_rate_name,
					COALESCE(MAX(tr.tax_rate_country), '') AS tax_rate_country,
					COALESCE(MAX(tr.tax_rate_state), '') AS tax_rate_state,
					COALESCE(MAX(tr.tax_rate), '') AS tax_rate_raw,
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN tl.total_tax ELSE 0 END), 0) AS total_tax,
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN tl.order_tax ELSE 0 END), 0) AS order_tax,
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN tl.shipping_tax ELSE 0 END), 0) AS shipping_tax,
					COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$paid_ph}) THEN os.order_id END) AS orders_count,
					COALESCE(ABS(SUM(CASE WHEN os.parent_id != 0 THEN tl.total_tax ELSE 0 END)), 0) AS refunded_tax,
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN tl.total_tax ELSE 0 END), 0) AS pipeline_total_tax,
					COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$pipeline_ph}) THEN os.order_id END) AS pipeline_orders_count,
					COALESCE(SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN tl.total_tax ELSE 0 END), 0) AS admin_equivalent_total_tax,
					COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$admin_ph}) THEN os.order_id END) AS admin_equivalent_orders_count
				FROM {$os_table} os
				INNER JOIN {$tl_table} tl ON tl.order_id = os.order_id
				LEFT JOIN {$tr_table} tr ON tl.tax_rate_id = tr.tax_rate_id
				WHERE os.{$date_column} >= %s AND os.{$date_column} <= %s
					AND ( os.status IN ({$admin_ph}) OR os.parent_id != 0 )
				GROUP BY tl.tax_rate_id
				HAVING (total_tax + pipeline_total_tax + refunded_tax) > 0
				ORDER BY {$orderby} DESC
				LIMIT %d",
				array_merge(
					$paid_statuses, // total_tax.
					$paid_statuses, // order_tax.
					$paid_statuses, // shipping_tax.
					$paid_statuses, // orders_count.
					$pipeline_statuses, // pipeline_total_tax.
					$pipeline_statuses, // pipeline_orders_count.
					$admin_statuses,    // admin_equivalent_total_tax.
					$admin_statuses,    // admin_equivalent_orders_count.
					array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
					$admin_statuses,
					array( $limit )
				)
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			$tax_rate_percent = '' === (string) $row['tax_rate_raw']
				? null
				: round( (float) $row['tax_rate_raw'], 4 );

			$out[] = array(
				'tax_rate_id'                   => (int) $row['tax_rate_id'],
				'tax_rate_name'                 => (string) $row['tax_rate_name'],
				'tax_rate_country'              => (string) $row['tax_rate_country'],
				'tax_rate_state'                => (string) $row['tax_rate_state'],
				'tax_rate_percent'              => $tax_rate_percent,
				'total_tax'                     => round( (float) $row['total_tax'], 2 ),
				'order_tax'                     => round( (float) $row['order_tax'], 2 ),
				'shipping_tax'                  => round( (float) $row['shipping_tax'], 2 ),
				'orders_count'                  => (int) $row['orders_count'],
				'refunded_tax'                  => round( (float) $row['refunded_tax'], 2 ),
				'pipeline_total_tax'            => round( (float) $row['pipeline_total_tax'], 2 ),
				'pipeline_orders_count'         => (int) $row['pipeline_orders_count'],
				'admin_equivalent_total_tax'    => round( (float) $row['admin_equivalent_total_tax'], 2 ),
				'admin_equivalent_orders_count' => (int) $row['admin_equivalent_orders_count'],
			);
		}

		return $out;
	}

	/**
	 * Empty-totals shape — used when the totals query returns no row.
	 *
	 * @return array
	 */
	private static function empty_tax_summary_totals() {
		return array(
			'total_tax'                  => 0.00,
			'order_tax'                  => 0.00,
			'shipping_tax'               => 0.00,
			'refunded_tax'               => 0.00,
			'net_tax'                    => 0.00,
			'paid_net_revenue'           => 0.00,
			'total_paid_orders'          => 0,
			'taxable_orders'             => 0,
			'taxable_share_percent'      => 0.0,
			'effective_tax_rate_percent' => 0.0,
			'definition'                 => 'PAID tax — orders in completed + processing. total_tax is gross of refunds; net_tax = total_tax − refunded_tax (the figure for a VAT / sales-tax return). On-hold tax sits separately in the pipeline block.',
			'_pipeline_total_tax'        => 0.00,
			'_pipeline_orders_count'     => 0,
			'_admin_total_tax'           => 0.00,
			'_admin_orders_count'        => 0,
		);
	}

	/**
	 * Pre-compute amount/percent/direction for every total in the
	 * tax_summary response. Mirrors calculate_revenue_breakdown_totals_changes
	 * in shape; uses a tax-summary key set.
	 *
	 * @param array $current  Current-period totals.
	 * @param array $previous Previous-period totals.
	 * @return array Map of metric key → { amount, percent, direction }.
	 */
	private static function calculate_tax_summary_totals_changes( $current, $previous ) {
		$keys = array(
			'total_tax',
			'order_tax',
			'shipping_tax',
			'refunded_tax',
			'net_tax',
			'paid_net_revenue',
			'total_paid_orders',
			'taxable_orders',
			'taxable_share_percent',
			'effective_tax_rate_percent',
		);

		$changes = array();
		foreach ( $keys as $key ) {
			$curr = (float) ( $current[ $key ] ?? 0 );
			$prev = (float) ( $previous[ $key ] ?? 0 );
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	// ─────────────────────────────────────────────────────────────────────
	// query_analytics — flexible filter engine across orders / products /
	// customers. Entry point dispatches on $entity; each entity helper owns
	// its own field registry + FROM + summary/rows SELECTs. Shared filter
	// engine turns the field-registry-bound filter array into a WHERE + JOINs
	// + prepared values tuple.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Fetch the query-analytics payload. Backs the
	 * `wc-analytics/query-analytics` ability.
	 *
	 * Entity dispatch:
	 *  - orders   → qa_orders_entity()
	 *  - products → (added in a later commit)
	 *  - customers→ (added in a later commit)
	 *
	 * @param string      $entity     orders | products | customers.
	 * @param array       $filters    Array of {field, operator, value} specs.
	 * @param string      $match_mode      'all' (AND) or 'any' (OR).
	 * @param string      $period     Period shortcut.
	 * @param string|null $date_start Custom start date (YYYY-MM-DD) — overrides period.
	 * @param string|null $date_end   Custom end date (YYYY-MM-DD) — overrides period.
	 * @param string      $mode       'aggregate' (default) or 'rows'.
	 * @param int         $limit      Rows-mode cap (ignored in aggregate).
	 * @param string|null $orderby    Rows-mode sort column (entity default if null).
	 * @param string      $order      Rows-mode sort direction (ASC/DESC).
	 * @return array|\WP_Error Response payload, or WP_Error on invalid input.
	 */
	public static function fetch_query_analytics( $entity, $filters, $match_mode, $period, $date_start, $date_end, $mode, $limit, $orderby, $order ) {
		$start = microtime( true );

		$entity     = in_array( $entity, array( 'orders', 'products', 'customers' ), true ) ? $entity : 'orders';
		$match_mode = ( 'any' === $match_mode ) ? 'any' : 'all';
		$mode       = ( 'rows' === $mode ) ? 'rows' : 'aggregate';
		$order      = ( 'ASC' === strtoupper( (string) $order ) ) ? 'ASC' : 'DESC';
		$limit      = max( 1, min( 50, (int) $limit ) );

		$dates = self::resolve_dates( $period, $date_start, $date_end );

		$cache_key = 'woocommerce_claude_query_analytics_' . md5(
			$entity
			. '|' . $match_mode
			. '|' . wp_json_encode( $filters )
			. '|' . $dates['start'] . '_' . $dates['end']
			. '|' . $mode
			. '|' . $limit
			. '|' . (string) $orderby
			. '|' . $order
			. '|' . self::get_date_column()
			. '|' . implode( ',', self::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		switch ( $entity ) {
			case 'orders':
				$result = self::qa_orders_entity( $dates, $filters, $match_mode, $mode, $limit, $orderby, $order );
				break;
			case 'products':
				$result = self::qa_products_entity( $dates, $filters, $match_mode, $mode, $limit, $orderby, $order );
				break;
			case 'customers':
				$result = self::qa_customers_entity( $dates, $filters, $match_mode, $mode, $limit, $orderby, $order );
				break;
			default:
				return new \WP_Error(
					'unknown_entity',
					__( 'Unknown entity. Expected one of: orders, products, customers.', 'woocommerce-claude' ),
					array( 'status' => 400 )
				);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Orders-entity query branch.
	 *
	 * Universe: all orders in the period with parent_id=0 AND status IN paid
	 * statuses. Summary: same universe further filtered by the merchant's
	 * filter spec. Pipeline + admin_equivalent siblings computed only when
	 * no explicit `status` filter was given (otherwise the blocks conflict
	 * with the merchant's explicit status choice).
	 *
	 * @param array       $dates   resolve_dates() output.
	 * @param array       $filters Filter specs.
	 * @param string      $match_mode   'all' | 'any'.
	 * @param string      $mode    'aggregate' | 'rows'.
	 * @param int         $limit   Rows-mode cap.
	 * @param string|null $orderby Rows-mode sort field.
	 * @param string      $order   'ASC' | 'DESC'.
	 * @return array|\WP_Error
	 */
	private static function qa_orders_entity( $dates, $filters, $match_mode, $mode, $limit, $orderby, $order ) {
		$registry = self::qa_orders_field_registry();

		$validation = self::qa_validate_filters( $filters, $registry );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$has_status_filter = false;
		foreach ( $filters as $f ) {
			if ( isset( $f['field'] ) && 'status' === $f['field'] ) {
				$has_status_filter = true;
				break;
			}
		}

		$where_parts = self::qa_build_where( $filters, $match_mode, $registry );
		if ( is_wp_error( $where_parts ) ) {
			return $where_parts;
		}
		$filter_sql  = $where_parts['sql'];
		$filter_vals = $where_parts['values'];
		$joins       = $where_parts['joins'];

		global $wpdb;
		$os_table = $wpdb->prefix . 'wc_order_stats';
		$from     = "{$os_table} AS os";

		$date_col         = 'os.' . self::get_date_column();
		$base_where_extra = array(
			"{$date_col} >= %s",
			"{$date_col} <= %s",
			'os.parent_id = 0',
		);
		$base_where_vals  = array( $dates['start'] . ' 00:00:00', $dates['end'] . ' 23:59:59' );

		$paid_statuses = self::get_paid_statuses();
		$status_ph     = implode( ',', array_fill( 0, count( $paid_statuses ), '%s' ) );

		$summary = self::qa_orders_compute_summary(
			$from,
			$joins,
			$base_where_extra,
			$base_where_vals,
			$filter_sql,
			$filter_vals,
			$has_status_filter,
			$paid_statuses,
			$status_ph
		);
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$universe = self::qa_orders_compute_universe(
			$from,
			$base_where_extra,
			$base_where_vals,
			$paid_statuses,
			$status_ph
		);

		$share_of_universe = array(
			'share_of_orders_percent'  => ( $universe['orders_count_in_period'] > 0 )
				? round( ( $summary['matched_count'] / $universe['orders_count_in_period'] ) * 100, 1 )
				: 0.0,
			'share_of_revenue_percent' => ( $universe['revenue_in_period'] > 0 )
				? round( ( $summary['net_revenue'] / $universe['revenue_in_period'] ) * 100, 1 )
				: 0.0,
		);

		$pipeline         = null;
		$admin_equivalent = null;
		if ( ! $has_status_filter ) {
			$pipeline_statuses = array( 'wc-on-hold' );
			$admin_statuses    = array_unique( array_merge( $paid_statuses, $pipeline_statuses, array( 'wc-refunded' ) ) );

			$pipeline         = self::qa_orders_sibling_block(
				$from,
				$joins,
				$base_where_extra,
				$base_where_vals,
				$filter_sql,
				$filter_vals,
				$pipeline_statuses,
				'On-hold orders matching your filter — awaiting payment, not yet counted as revenue.'
			);
			$admin_equivalent = self::qa_orders_sibling_block(
				$from,
				$joins,
				$base_where_extra,
				$base_where_vals,
				$filter_sql,
				$filter_vals,
				$admin_statuses,
				'Paid + on-hold + refunded combined — what WC Admin Reports counts. Use for dashboard reconciliation only.'
			);
		}

		$rows         = null;
		$privacy_mode = null;
		if ( 'rows' === $mode ) {
			$privacy_mode = 'pseudonymised';
			$rows         = self::qa_orders_fetch_rows(
				$from,
				$joins,
				$base_where_extra,
				$base_where_vals,
				$filter_sql,
				$filter_vals,
				$has_status_filter,
				$paid_statuses,
				$status_ph,
				$limit,
				$orderby,
				$order,
				$registry
			);
		}

		$sample_caveat = null;
		if ( $summary['matched_count'] > 0 && $summary['matched_count'] <= 5 ) {
			$sample_caveat = 'Small sample (' . (int) $summary['matched_count']
				. ' orders). Percentages and averages are noisy — treat as signal to watch, not conclusion.';
		}

		$note = null;
		if ( 0 === (int) $summary['matched_count'] ) {
			$note = 'No orders matched the filter combination for this period.';
		} elseif ( $has_status_filter ) {
			$note = 'Explicit status filter — pipeline and dashboard-matching sibling blocks are omitted because they conflict with the status you chose.';
		}

		$result = array(
			'period'             => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'entity'             => 'orders',
			'mode'               => $mode,
			'match'              => $match_mode,
			'filters_applied'    => $filters,
			'currency'           => get_woocommerce_currency(),
			'summary'            => $summary,
			'rows'               => $rows,
			'universe'           => $universe,
			'share_of_universe'  => $share_of_universe,
			'pipeline'           => $pipeline,
			'admin_equivalent'   => $admin_equivalent,
			'sample_size_caveat' => $sample_caveat,
			'privacy_mode'       => $privacy_mode,
			'note'               => $note,
		);

		return $result;
	}

	/**
	 * Compute the orders-entity aggregate summary (paid view by default,
	 * merchant's status filter if explicit).
	 *
	 * @param string $from             FROM clause (table + alias).
	 * @param array  $joins            JOIN clauses (unique).
	 * @param array  $base_where       Base WHERE fragments (date, parent_id).
	 * @param array  $base_vals        Values for the base WHERE.
	 * @param string $filter_sql       Merchant-filter SQL fragment (may be empty).
	 * @param array  $filter_vals      Values for the merchant filter.
	 * @param bool   $has_status_filter Whether merchant supplied a status filter.
	 * @param array  $paid_statuses    Default paid statuses (applied when no merchant status filter).
	 * @param string $status_ph        Placeholder list for paid_statuses.
	 * @return array
	 */
	private static function qa_orders_compute_summary( $from, $joins, $base_where, $base_vals, $filter_sql, $filter_vals, $has_status_filter, $paid_statuses, $status_ph ) {
		global $wpdb;

		$where = $base_where;
		$vals  = $base_vals;

		if ( ! $has_status_filter ) {
			$where[] = "os.status IN ({$status_ph})";
			$vals    = array_merge( $vals, $paid_statuses );
		}

		if ( ! empty( $filter_sql ) ) {
			$where[] = $filter_sql;
			$vals    = array_merge( $vals, $filter_vals );
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$joins_sql = implode( "\n", $joins );

		$sql = "SELECT
			COUNT(DISTINCT os.order_id) AS matched_count,
			COALESCE(SUM(os.net_total), 0) AS net_revenue,
			COALESCE(SUM(os.total_sales), 0) AS gross_revenue,
			COALESCE(SUM(os.num_items_sold), 0) AS total_items,
			COALESCE(SUM(CASE WHEN os.returning_customer = 0 THEN 1 ELSE 0 END), 0) AS new_customer_orders,
			COALESCE(SUM(CASE WHEN os.returning_customer = 1 THEN 1 ELSE 0 END), 0) AS returning_customer_orders
		FROM {$from} {$joins_sql} {$where_sql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		if ( ! $row ) {
			$row = array(
				'matched_count'             => 0,
				'net_revenue'               => 0,
				'gross_revenue'             => 0,
				'total_items'               => 0,
				'new_customer_orders'       => 0,
				'returning_customer_orders' => 0,
			);
		}

		$matched = (int) $row['matched_count'];
		$net     = (float) $row['net_revenue'];
		$aov     = ( $matched > 0 ) ? round( $net / $matched, 2 ) : 0.0;

		return array(
			'matched_count'             => $matched,
			'net_revenue'               => round( $net, 2 ),
			'gross_revenue'             => round( (float) $row['gross_revenue'], 2 ),
			'total_items'               => (int) $row['total_items'],
			'avg_order_value'           => $aov,
			'new_customer_orders'       => (int) $row['new_customer_orders'],
			'returning_customer_orders' => (int) $row['returning_customer_orders'],
			'definition'                => 'Orders matching your filter. Paid statuses (completed + processing) by default; explicit status filter overrides.',
		);
	}

	/**
	 * Compute the orders-entity universe — all paid orders in the period,
	 * no filters. Provides the denominator for share_of_universe.
	 *
	 * @param string $from       FROM clause.
	 * @param array  $base_where Base WHERE fragments.
	 * @param array  $base_vals  Values for base WHERE.
	 * @param array  $paid_statuses Paid statuses.
	 * @param string $status_ph  Placeholder list.
	 * @return array
	 */
	private static function qa_orders_compute_universe( $from, $base_where, $base_vals, $paid_statuses, $status_ph ) {
		global $wpdb;

		$where = $base_where;
		$vals  = $base_vals;

		$where[] = "os.status IN ({$status_ph})";
		$vals    = array_merge( $vals, $paid_statuses );

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$sql = "SELECT
			COUNT(DISTINCT os.order_id) AS orders_count_in_period,
			COALESCE(SUM(os.net_total), 0) AS revenue_in_period
		FROM {$from} {$where_sql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		return array(
			'orders_count_in_period' => $row ? (int) $row['orders_count_in_period'] : 0,
			'revenue_in_period'      => $row ? round( (float) $row['revenue_in_period'], 2 ) : 0.0,
			'definition'             => 'All paid orders in the period (completed + processing, no other filters applied). Denominator for share_of_universe.',
		);
	}

	/**
	 * Compute a pipeline-or-admin_equivalent sibling block — same filter set
	 * as the summary, different status set.
	 *
	 * @param string $from             FROM clause.
	 * @param array  $joins            JOIN clauses.
	 * @param array  $base_where       Base WHERE fragments.
	 * @param array  $base_vals        Base values.
	 * @param string $filter_sql       Merchant filter SQL.
	 * @param array  $filter_vals      Merchant filter values.
	 * @param array  $statuses         Status set for this block (with wc- prefix).
	 * @param string $definition       Per-block definition string.
	 * @return array
	 */
	private static function qa_orders_sibling_block( $from, $joins, $base_where, $base_vals, $filter_sql, $filter_vals, $statuses, $definition ) {
		global $wpdb;

		$where = $base_where;
		$vals  = $base_vals;

		$ph      = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$where[] = "os.status IN ({$ph})";
		$vals    = array_merge( $vals, $statuses );

		if ( ! empty( $filter_sql ) ) {
			$where[] = $filter_sql;
			$vals    = array_merge( $vals, $filter_vals );
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$joins_sql = implode( "\n", $joins );

		$sql = "SELECT
			COUNT(DISTINCT os.order_id) AS matched_count,
			COALESCE(SUM(os.net_total), 0) AS net_revenue
		FROM {$from} {$joins_sql} {$where_sql}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		return array(
			'matched_count' => $row ? (int) $row['matched_count'] : 0,
			'net_revenue'   => $row ? round( (float) $row['net_revenue'], 2 ) : 0.0,
			'definition'    => $definition,
		);
	}

	/**
	 * Fetch rows-mode result. Customer id is always pseudonymised
	 * (`Customer #N` / `Guest`) — real names / emails are never selected.
	 *
	 * @param string      $from             FROM clause.
	 * @param array       $joins            JOIN clauses.
	 * @param array       $base_where       Base WHERE fragments.
	 * @param array       $base_vals        Base values.
	 * @param string      $filter_sql       Merchant filter SQL.
	 * @param array       $filter_vals      Merchant filter values.
	 * @param bool        $has_status_filter Explicit status filter flag.
	 * @param array       $paid_statuses    Default paid statuses.
	 * @param string      $status_ph        Placeholder list.
	 * @param int         $limit            Row cap.
	 * @param string|null $orderby     Sort field (entity field name, not column).
	 * @param string      $order            ASC|DESC.
	 * @param array       $registry         Field registry.
	 * @return array
	 */
	private static function qa_orders_fetch_rows( $from, $joins, $base_where, $base_vals, $filter_sql, $filter_vals, $has_status_filter, $paid_statuses, $status_ph, $limit, $orderby, $order, $registry ) {
		global $wpdb;

		$where = $base_where;
		$vals  = $base_vals;

		if ( ! $has_status_filter ) {
			$where[] = "os.status IN ({$status_ph})";
			$vals    = array_merge( $vals, $paid_statuses );
		}

		if ( ! empty( $filter_sql ) ) {
			$where[] = $filter_sql;
			$vals    = array_merge( $vals, $filter_vals );
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$joins_sql = implode( "\n", $joins );

		// Resolve orderby to a registry column; default to date_created DESC.
		$order_col = 'os.date_created';
		if ( ! empty( $orderby ) && isset( $registry[ $orderby ]['column'] ) && empty( $registry[ $orderby ]['sub_query'] ) ) {
			$order_col = $registry[ $orderby ]['column'];
		}

		$sql = "SELECT
			os.order_id,
			os.date_created,
			os.status,
			os.net_total,
			os.total_sales,
			os.num_items_sold,
			os.customer_id,
			os.returning_customer
		FROM {$from} {$joins_sql} {$where_sql}
		ORDER BY {$order_col} {$order}
		LIMIT %d";

		$vals[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$raw_rows = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		if ( ! is_array( $raw_rows ) ) {
			return array();
		}

		$rows = array();
		foreach ( $raw_rows as $r ) {
			$customer_id = (int) $r['customer_id'];
			$rows[]      = array(
				'order_id'           => (int) $r['order_id'],
				'order_ref'          => '#' . (int) $r['order_id'],
				'admin_url'          => self::order_admin_url( $r['order_id'] ),
				'date'               => substr( (string) $r['date_created'], 0, 10 ),
				'status'             => (string) $r['status'],
				'net_total'          => round( (float) $r['net_total'], 2 ),
				'gross_total'        => round( (float) $r['total_sales'], 2 ),
				'num_items_sold'     => (int) $r['num_items_sold'],
				'customer_id_pseudo' => $customer_id ? 'Customer #' . $customer_id : 'Guest',
				'customer_admin_url' => self::customer_admin_url( $customer_id ),
				'returning_customer' => (bool) $r['returning_customer'],
			);
		}

		return $rows;
	}

	/**
	 * Orders-entity field registry. Maps Claude-facing field names to SQL
	 * column expressions, types, labels, and optional JOINs. The filter
	 * engine uses this to translate filter specs into SQL fragments.
	 *
	 * Types drive operator validation (numeric → numeric ops, string →
	 * string ops, etc.). Fields with a `join` clause contribute a LEFT JOIN
	 * only when that field is referenced in a filter; `join_key` dedupes
	 * across multiple fields on the same join (all billing_* share one
	 * join, all attribution_* each get their own).
	 *
	 * @return array
	 */
	private static function qa_orders_field_registry() {
		global $wpdb;
		$os  = $wpdb->prefix . 'wc_order_stats';
		$o   = $wpdb->prefix . 'wc_orders';
		$opl = $wpdb->prefix . 'wc_order_product_lookup';
		$ocl = $wpdb->prefix . 'wc_order_coupon_lookup';
		unset( $os );

		$meta = self::get_order_meta_source();
		$mt   = $meta['table'];
		$mi   = $meta['id_column'];

		// Address storage differs between HPOS and classic. HPOS keeps one row
		// per (order, address_type) in wc_order_addresses with country / state /
		// city / postcode as columns. Classic stores each field under its own
		// postmeta key. The registry branches per-field on mode so a classic-
		// storage merchant filtering billing_country doesn't JOIN a non-existent
		// table (silent 60s hang through the MCP bridge, error instantly at SQL
		// layer — see fix/query-analytics-address-fields-classic-storage).
		$addr        = self::get_order_addresses_source();
		$addr_mode   = $addr['mode'];
		$at          = $addr['table'];
		$ai          = $addr['id_column'];
		$addr_fields = self::qa_orders_address_fields( $addr_mode, $at, $ai );

		// Same trap, different table: `currency` and `payment_method` live on
		// `wc_orders` columns under HPOS, but on classic-storage stores those
		// rows are empty (or the table doesn't carry the data sync) — the
		// JOIN matches zero rows and the filter silently returns no matches.
		// Branch the JOIN per storage mode the same way the address fields do.
		$pm_fields = self::qa_orders_payment_currency_fields( self::hpos_enabled(), $o, $mt, $mi );

		return array(
			// ─── Core order fields ───
			'order_total'          => array(
				'column' => 'os.net_total',
				'type'   => 'numeric',
				'label'  => 'Net order total (excluding refunds)',
			),
			'gross_total'          => array(
				'column' => 'os.total_sales',
				'type'   => 'numeric',
				'label'  => 'Gross total (net + tax + shipping)',
			),
			'num_items_sold'       => array(
				'column' => 'os.num_items_sold',
				'type'   => 'numeric',
				'label'  => 'Items sold on this order',
			),
			'status'               => array(
				'column'      => 'os.status',
				'type'        => 'status_enum',
				'label'       => 'Order status',
				'enum_values' => array( 'completed', 'processing', 'on-hold', 'pending', 'failed', 'cancelled', 'refunded' ),
			),
			'date_created'         => array(
				'column' => 'os.date_created',
				'type'   => 'date',
				'label'  => 'Order date',
			),
			'returning_customer'   => array(
				'column' => 'os.returning_customer',
				'type'   => 'boolean',
				'label'  => 'Customer had previous orders when this one was placed',
			),
			'tax_total'            => array(
				'column' => 'os.tax_total',
				'type'   => 'numeric',
				'label'  => 'Tax collected on this order',
			),
			'shipping_total'       => array(
				'column' => 'os.shipping_total',
				'type'   => 'numeric',
				'label'  => 'Shipping charged',
			),

			// ─── Currency + payment method (HPOS wc_orders columns OR classic postmeta) ───
			'currency'             => $pm_fields['currency'],
			'payment_method'       => $pm_fields['payment_method'],

			// ─── Billing + Shipping address (HPOS wc_order_addresses OR classic postmeta) ───
			'billing_country'      => $addr_fields['billing_country'],
			'billing_state'        => $addr_fields['billing_state'],
			'billing_city'         => $addr_fields['billing_city'],
			'billing_postcode'     => $addr_fields['billing_postcode'],
			'shipping_country'     => $addr_fields['shipping_country'],
			'shipping_state'       => $addr_fields['shipping_state'],

			// ─── Attribution meta (via wc_orders_meta or postmeta, picked at run time) ───
			// `channel` is the human-readable dimension (Organic Search, Direct,
			// Email, Paid Search, Social, Referral) that WC derives from raw
			// source_type. Matches get_attribution's group_by=channel vocabulary.
			'attribution_channel'  => array(
				'column'   => 'attr_channel.meta_value',
				'type'     => 'string',
				'label'    => 'Attribution channel (Direct, Organic Search, Paid Search, Email, Social, Referral)',
				'join'     => "LEFT JOIN {$mt} AS attr_channel ON os.order_id = attr_channel.{$mi} AND attr_channel.meta_key = '_wc_order_attribution_origin'",
				'join_key' => 'attr_channel',
			),
			'attribution_source'   => array(
				'column'   => 'attr_us.meta_value',
				'type'     => 'string',
				'label'    => 'Attribution utm_source (google, facebook, etc.)',
				'join'     => "LEFT JOIN {$mt} AS attr_us ON os.order_id = attr_us.{$mi} AND attr_us.meta_key = '_wc_order_attribution_utm_source'",
				'join_key' => 'attr_utm_source',
			),
			'attribution_campaign' => array(
				'column'   => 'attr_uc.meta_value',
				'type'     => 'string',
				'label'    => 'Attribution utm_campaign',
				'join'     => "LEFT JOIN {$mt} AS attr_uc ON os.order_id = attr_uc.{$mi} AND attr_uc.meta_key = '_wc_order_attribution_utm_campaign'",
				'join_key' => 'attr_utm_campaign',
			),
			'attribution_device'   => array(
				'column'   => 'attr_dt.meta_value',
				'type'     => 'string',
				'label'    => 'Attribution device type (desktop, mobile, tablet)',
				'join'     => "LEFT JOIN {$mt} AS attr_dt ON os.order_id = attr_dt.{$mi} AND attr_dt.meta_key = '_wc_order_attribution_device_type'",
				'join_key' => 'attr_device_type',
			),

			// ─── Coupon (aggregated subquery so one order with multiple coupons is one row) ───
			'coupon_code'          => array(
				'column'   => 'cp.coupon_codes',
				'type'     => 'string',
				'label'    => 'Coupon code(s) applied',
				'join'     => "LEFT JOIN (SELECT ocl.order_id, GROUP_CONCAT(DISTINCT posts.post_title SEPARATOR ',') AS coupon_codes, SUM(ocl.discount_amount) AS discount_amount FROM {$ocl} AS ocl LEFT JOIN {$wpdb->posts} AS posts ON ocl.coupon_id = posts.ID GROUP BY ocl.order_id) AS cp ON os.order_id = cp.order_id",
				'join_key' => 'coupon_lookup',
			),
			'discount_amount'      => array(
				'column'   => 'cp.discount_amount',
				'type'     => 'numeric',
				'label'    => 'Total discount applied (sum of all coupons on the order)',
				'join'     => "LEFT JOIN (SELECT ocl.order_id, GROUP_CONCAT(DISTINCT posts.post_title SEPARATOR ',') AS coupon_codes, SUM(ocl.discount_amount) AS discount_amount FROM {$ocl} AS ocl LEFT JOIN {$wpdb->posts} AS posts ON ocl.coupon_id = posts.ID GROUP BY ocl.order_id) AS cp ON os.order_id = cp.order_id",
				'join_key' => 'coupon_lookup',
			),

			// ─── Line-item product (EXISTS subquery so join doesn't duplicate rows) ───
			'product_id'           => array(
				'sub_query' => "EXISTS (SELECT 1 FROM {$opl} WHERE {$opl}.order_id = os.order_id AND {$opl}.product_id {{OP}} {{VAL}})",
				'type'      => 'numeric_subquery',
				'label'     => 'Order contains line item with this product id',
			),
		);
	}

	/**
	 * Build the billing + shipping address registry entries for whichever
	 * storage mode the store uses.
	 *
	 * HPOS (wc_order_addresses): one row per (order_id, address_type). All of
	 * country / state / city / postcode share a single JOIN per address_type —
	 * aliases `ba` (billing) and `sa` (shipping). Dedup via `join_key`.
	 *
	 * Classic (postmeta): each field lives under its own meta_key
	 * (`_billing_country`, `_shipping_state`, etc.), so each field needs its
	 * own JOIN and its own alias. No alias sharing.
	 *
	 * Returns an assoc array keyed by Claude-facing field name, each value
	 * matching the `qa_orders_field_registry()` entry shape.
	 *
	 * @param string $mode      'hpos' or 'classic'.
	 * @param string $table     Address / meta table name (prefixed).
	 * @param string $id_column JOIN id column on the address table.
	 * @return array<string, array>
	 */
	private static function qa_orders_address_fields( $mode, $table, $id_column ) {
		if ( 'hpos' === $mode ) {
			$billing_join  = "LEFT JOIN {$table} AS ba ON os.order_id = ba.{$id_column} AND ba.address_type = 'billing'";
			$shipping_join = "LEFT JOIN {$table} AS sa ON os.order_id = sa.{$id_column} AND sa.address_type = 'shipping'";

			return array(
				'billing_country'  => array(
					'column'   => 'ba.country',
					'type'     => 'string',
					'label'    => 'Billing country (ISO-2)',
					'join'     => $billing_join,
					'join_key' => 'ba',
				),
				'billing_state'    => array(
					'column'   => 'ba.state',
					'type'     => 'string',
					'label'    => 'Billing state/region',
					'join'     => $billing_join,
					'join_key' => 'ba',
				),
				'billing_city'     => array(
					'column'   => 'ba.city',
					'type'     => 'string',
					'label'    => 'Billing city',
					'join'     => $billing_join,
					'join_key' => 'ba',
				),
				'billing_postcode' => array(
					'column'   => 'ba.postcode',
					'type'     => 'string',
					'label'    => 'Billing postcode',
					'join'     => $billing_join,
					'join_key' => 'ba',
				),
				'shipping_country' => array(
					'column'   => 'sa.country',
					'type'     => 'string',
					'label'    => 'Shipping country (ISO-2)',
					'join'     => $shipping_join,
					'join_key' => 'sa',
				),
				'shipping_state'   => array(
					'column'   => 'sa.state',
					'type'     => 'string',
					'label'    => 'Shipping state/region',
					'join'     => $shipping_join,
					'join_key' => 'sa',
				),
			);
		}

		// Classic (postmeta) — one JOIN per field. Alias pattern
		// `addr_<fieldname>` keeps each JOIN's alias unique so multiple address
		// filters on the same query don't collide.
		$classic = function ( $meta_key, $label ) use ( $table, $id_column ) {
			$alias = 'addr_' . str_replace( array( '-', ' ' ), '_', ltrim( $meta_key, '_' ) );
			return array(
				'column'   => "{$alias}.meta_value",
				'type'     => 'string',
				'label'    => $label,
				'join'     => "LEFT JOIN {$table} AS {$alias} ON os.order_id = {$alias}.{$id_column} AND {$alias}.meta_key = '{$meta_key}'",
				'join_key' => $alias,
			);
		};

		return array(
			'billing_country'  => $classic( '_billing_country', 'Billing country (ISO-2)' ),
			'billing_state'    => $classic( '_billing_state', 'Billing state/region' ),
			'billing_city'     => $classic( '_billing_city', 'Billing city' ),
			'billing_postcode' => $classic( '_billing_postcode', 'Billing postcode' ),
			'shipping_country' => $classic( '_shipping_country', 'Shipping country (ISO-2)' ),
			'shipping_state'   => $classic( '_shipping_state', 'Shipping state/region' ),
		);
	}

	/**
	 * Build the `currency` and `payment_method` registry entries against the
	 * active order storage mode.
	 *
	 * HPOS keeps both as columns on `wc_orders` (`o.currency`,
	 * `o.payment_method`), so a single JOIN to `wc_orders` suffices for both
	 * fields. Classic storage keeps each under its own postmeta key —
	 * `_order_currency` and `_payment_method` — so each field needs its own
	 * JOIN with a distinct alias. Without this branching, classic-storage
	 * stores hit a `LEFT JOIN wc_orders ON os.order_id = o.id` against an
	 * empty/unsynced `wc_orders` table and the filter silently matches no
	 * rows.
	 *
	 * @param bool   $is_hpos          True when WC's HPOS feature is enabled.
	 * @param string $orders_table     Fully-qualified `wc_orders` table name.
	 * @param string $meta_table       Order meta source table — `wc_orders_meta`
	 *                                 on HPOS, `postmeta` on classic.
	 * @param string $meta_id_column   ID column on the meta table — `order_id`
	 *                                 on HPOS, `post_id` on classic.
	 * @return array<string, array>
	 */
	private static function qa_orders_payment_currency_fields( $is_hpos, $orders_table, $meta_table, $meta_id_column ) {
		if ( $is_hpos ) {
			$orders_join = "LEFT JOIN {$orders_table} AS o ON os.order_id = o.id";
			return array(
				'currency'       => array(
					'column'   => 'o.currency',
					'type'     => 'string',
					'label'    => 'Order currency',
					'join'     => $orders_join,
					'join_key' => 'wc_orders',
				),
				'payment_method' => array(
					'column'   => 'o.payment_method',
					'type'     => 'string',
					'label'    => 'Payment gateway slug (e.g. stripe, bacs, ppec_paypal)',
					'join'     => $orders_join,
					'join_key' => 'wc_orders',
				),
			);
		}

		// Classic — one JOIN per field. Aliases are field-scoped so a
		// query filtering on both currency and payment_method doesn't
		// collide under a shared join_key.
		return array(
			'currency'       => array(
				'column'   => 'pm_currency.meta_value',
				'type'     => 'string',
				'label'    => 'Order currency',
				'join'     => "LEFT JOIN {$meta_table} AS pm_currency ON os.order_id = pm_currency.{$meta_id_column} AND pm_currency.meta_key = '_order_currency'",
				'join_key' => 'pm_currency',
			),
			'payment_method' => array(
				'column'   => 'pm_payment.meta_value',
				'type'     => 'string',
				'label'    => 'Payment gateway slug (e.g. stripe, bacs, ppec_paypal)',
				'join'     => "LEFT JOIN {$meta_table} AS pm_payment ON os.order_id = pm_payment.{$meta_id_column} AND pm_payment.meta_key = '_payment_method'",
				'join_key' => 'pm_payment',
			),
		);
	}

	/**
	 * Validate a filter array against an entity's field registry.
	 *
	 * @param array $filters  Filter array.
	 * @param array $registry Entity field registry.
	 * @return \WP_Error|null WP_Error on invalid input; null on success.
	 */
	private static function qa_validate_filters( $filters, $registry ) {
		if ( ! is_array( $filters ) ) {
			return new \WP_Error( 'invalid_filters', __( 'Filters must be an array.', 'woocommerce-claude' ), array( 'status' => 400 ) );
		}

		foreach ( $filters as $i => $f ) {
			if ( ! is_array( $f ) ) {
				return new \WP_Error(
					'invalid_filter',
					sprintf(
						/* translators: %d: filter index */
						__( 'Filter at index %d is not an object.', 'woocommerce-claude' ),
						$i
					),
					array( 'status' => 400 )
				);
			}

			$field = $f['field'] ?? null;
			$op    = $f['operator'] ?? null;

			if ( empty( $field ) || ! isset( $registry[ $field ] ) ) {
				return new \WP_Error(
					'unknown_field',
					sprintf(
						/* translators: %s: field name */
						__( 'Unknown field: %s. See the tool description for the field list.', 'woocommerce-claude' ),
						(string) $field
					),
					array(
						'status'           => 400,
						'available_fields' => array_keys( $registry ),
					)
				);
			}

			if ( empty( $op ) ) {
				return new \WP_Error(
					'missing_operator',
					sprintf(
						/* translators: %s: field name */
						__( 'Filter on %s is missing an operator.', 'woocommerce-claude' ),
						$field
					),
					array( 'status' => 400 )
				);
			}

			$type    = $registry[ $field ]['type'];
			$allowed = self::qa_operators_for_type( $type );
			if ( ! in_array( $op, $allowed, true ) ) {
				return new \WP_Error(
					'invalid_operator',
					sprintf(
						/* translators: 1: operator, 2: field, 3: list of allowed operators */
						__( 'Operator %1$s is not valid for field %2$s. Allowed: %3$s.', 'woocommerce-claude' ),
						$op,
						$field,
						implode( ', ', $allowed )
					),
					array( 'status' => 400 )
				);
			}
		}

		return null;
	}

	/**
	 * Allowed operators per field type. Claude-friendly names; qa_build_filter_clause
	 * maps to SQL primitives.
	 *
	 * @param string $type Field type.
	 * @return array
	 */
	private static function qa_operators_for_type( $type ) {
		switch ( $type ) {
			case 'numeric':
			case 'numeric_subquery':
				return array( 'is', 'is_not', 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal', 'between', 'is_in', 'is_not_in' );
			case 'date':
				return array( 'is', 'is_not', 'greater_than', 'less_than', 'between' );
			case 'boolean':
				return array( 'is', 'is_not' );
			case 'status_enum':
				return array( 'is', 'is_not', 'is_in', 'is_not_in' );
			default:
				return array( 'is', 'is_not', 'is_in', 'is_not_in', 'contains', 'not_contains', 'starts_with', 'is_empty', 'is_not_empty' );
		}
	}

	/**
	 * Build the combined WHERE clause + values + required JOINs for a
	 * filter array against an entity registry.
	 *
	 * Returns WP_Error when a filter passes validation but can't be
	 * translated to SQL by `qa_build_filter_clause()` — e.g. `between`
	 * with fewer than two values, an empty `is_in` array, or a string
	 * operator on a subquery field. Pre-fix the function silently
	 * dropped these, so a single-filter request fell through to an
	 * unfiltered query and returned the entire dataset. Surfacing the
	 * failure makes the caller fix the input rather than acting on
	 * silently wrong data.
	 *
	 * @param array  $filters    Filter array.
	 * @param string $match_mode 'all' | 'any'.
	 * @param array  $registry   Entity field registry.
	 * @return array|\WP_Error { sql: string, values: array, joins: array } on success.
	 */
	private static function qa_build_where( $filters, $match_mode, $registry ) {
		if ( empty( $filters ) ) {
			return array(
				'sql'    => '',
				'values' => array(),
				'joins'  => array(),
			);
		}

		$relation = ( 'any' === $match_mode ) ? 'OR' : 'AND';
		$clauses  = array();
		$values   = array();
		$joins    = array();

		foreach ( $filters as $f ) {
			$field_def = $registry[ $f['field'] ];
			$op        = $f['operator'];
			$val       = $f['value'] ?? null;

			$clause = self::qa_build_filter_clause( $field_def, $op, $val );
			if ( ! $clause ) {
				return new \WP_Error(
					'untranslatable_filter',
					sprintf(
						/* translators: 1: operator, 2: field */
						__( 'Filter "%1$s" on field "%2$s" cannot be translated to SQL — check the operator/value shape against the field type. Common causes: "between" needs two values; "is_in" / "is_not_in" need a non-empty array; subquery fields don\'t support "between" or string operators ("contains" / "starts_with" / "is_empty").', 'woocommerce-claude' ),
						(string) $op,
						(string) $f['field']
					),
					array(
						'status'   => 400,
						'field'    => $f['field'],
						'operator' => $op,
					)
				);
			}

			$clauses[] = $clause['sql'];
			$values    = array_merge( $values, $clause['values'] );

			if ( ! empty( $field_def['join'] ) ) {
				$key           = $field_def['join_key'] ?? $f['field'];
				$joins[ $key ] = $field_def['join'];
			}
		}

		if ( empty( $clauses ) ) {
			return array(
				'sql'    => '',
				'values' => array(),
				'joins'  => array_values( $joins ),
			);
		}

		return array(
			'sql'    => '(' . implode( " {$relation} ", $clauses ) . ')',
			'values' => $values,
			'joins'  => array_values( $joins ),
		);
	}

	/**
	 * Expand a `YYYY-MM-DD` date string to the start or end of that day.
	 *
	 * MySQL coerces a bare date literal to midnight on that day, which
	 * silently drops every row dated after midnight when used as the
	 * upper bound of a BETWEEN. Filters that take a `date` value need
	 * the calendar-day semantics most merchants expect: a row at
	 * `2026-01-31 14:30:00` is "on January 31st" for the purposes of a
	 * `2026-01-31` upper bound.
	 *
	 * Pass-through if the value already has a time component, isn't a
	 * string, or doesn't match the date-only shape — explicit
	 * datetimes from advanced callers are honoured as-is.
	 *
	 * @param mixed  $value    Raw filter value (typically a string).
	 * @param string $boundary 'start' (00:00:00) or 'end' (23:59:59).
	 * @return mixed The original value, or an expanded `YYYY-MM-DD HH:MM:SS` string.
	 */
	private static function qa_expand_date_to_boundary( $value, $boundary ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		return ( 'start' === $boundary ) ? $value . ' 00:00:00' : $value . ' 23:59:59';
	}

	/**
	 * Translate a single filter spec to a SQL fragment + values.
	 *
	 * For fields with a 'sub_query' template (e.g. product_id), the {{OP}}
	 * and {{VAL}} placeholders are substituted with the operator primitive
	 * and prepared-placeholder form. For regular column fields the operator
	 * primitive is applied directly against the column expression.
	 *
	 * @param array  $field_def Field definition from the registry.
	 * @param string $op        Claude-facing operator name.
	 * @param mixed  $value     Raw filter value.
	 * @return array|null { sql: string, values: array } or null on unusable input.
	 */
	private static function qa_build_filter_clause( $field_def, $op, $value ) {
		$type      = $field_def['type'];
		$sub_query = $field_def['sub_query'] ?? null;
		$col       = $field_def['column'] ?? null;

		// Normalise the value to an array for IN / BETWEEN operators.
		$arr = is_array( $value ) ? $value : array( $value );

		// Special-case status_enum: the stored column carries 'wc-' prefixed
		// values (wc-completed, wc-on-hold). The merchant-friendly value
		// omits the prefix ('completed'); prepend before matching.
		$apply_status_prefix = function ( $v ) {
			$v = (string) $v;
			return str_starts_with( $v, 'wc-' ) ? $v : 'wc-' . $v;
		};
		if ( 'status_enum' === $type ) {
			$arr = array_map( $apply_status_prefix, $arr );
		}

		// Normalise booleans to 0/1 ints for returning_customer.
		if ( 'boolean' === $type ) {
			$arr = array_map(
				function ( $v ) {
					return rest_sanitize_boolean( $v ) ? 1 : 0;
				},
				$arr
			);
		}

		$sql       = '';
		$values    = array();
		$primitive = null;

		switch ( $op ) {
			case 'is':
				$primitive = '=';
				$sql       = $sub_query ? null : "{$col} = %s";
				$values[]  = $arr[0] ?? '';
				break;

			case 'is_not':
				if ( $sub_query ) {
					// Negate at the EXISTS level, not inside the subquery
					// predicate. `EXISTS (... value != X)` matches any
					// parent row whose joined table has at least one row
					// where value is anything-but-X — so an order
					// containing both X and Y wrongly satisfies "is_not X"
					// via its Y row. The correct semantics is "no joined
					// row has value X", which is `NOT EXISTS (... value
					// = X)`. Mirror the is_not_in case below, which
					// already negates at the wrapper.
					$template = str_replace( array( '{{OP}}', '{{VAL}}' ), array( '=', '%s' ), $sub_query );
					$sql      = "NOT {$template}";
				} else {
					$primitive = '!=';
					$sql       = "{$col} <> %s";
				}
				$values[] = $arr[0] ?? '';
				break;

			case 'greater_than':
				$primitive = '>';
				$sql       = $sub_query ? null : "{$col} > %s";
				$values[]  = $arr[0] ?? '';
				break;

			case 'greater_than_or_equal':
				$primitive = '>=';
				$sql       = $sub_query ? null : "{$col} >= %s";
				$values[]  = $arr[0] ?? '';
				break;

			case 'less_than':
				$primitive = '<';
				$sql       = $sub_query ? null : "{$col} < %s";
				$values[]  = $arr[0] ?? '';
				break;

			case 'less_than_or_equal':
				$primitive = '<=';
				$sql       = $sub_query ? null : "{$col} <= %s";
				$values[]  = $arr[0] ?? '';
				break;

			case 'between':
				if ( count( $arr ) < 2 ) {
					return null;
				}
				$primitive = 'BETWEEN';
				if ( ! $sub_query ) {
					$sql      = "{$col} BETWEEN %s AND %s";
					$values[] = ( 'date' === $type ) ? self::qa_expand_date_to_boundary( $arr[0], 'start' ) : $arr[0];
					$values[] = ( 'date' === $type ) ? self::qa_expand_date_to_boundary( $arr[1], 'end' ) : $arr[1];
				} else {
					$sql = null; // BETWEEN inside sub_query template isn't supported.
				}
				break;

			case 'is_in':
				if ( empty( $arr ) ) {
					return null;
				}
				$ph = implode( ',', array_fill( 0, count( $arr ), '%s' ) );
				if ( $sub_query ) {
					$template = str_replace( array( '{{OP}}', '{{VAL}}' ), array( 'IN', "({$ph})" ), $sub_query );
					$sql      = $template;
				} else {
					$sql = "{$col} IN ({$ph})";
				}
				$values = array_merge( $values, $arr );
				break;

			case 'is_not_in':
				if ( empty( $arr ) ) {
					return null;
				}
				$ph = implode( ',', array_fill( 0, count( $arr ), '%s' ) );
				if ( $sub_query ) {
					// For subquery fields, negate the EXISTS.
					$template = str_replace( array( '{{OP}}', '{{VAL}}' ), array( 'IN', "({$ph})" ), $sub_query );
					$sql      = "NOT {$template}";
				} else {
					$sql = "{$col} NOT IN ({$ph})";
				}
				$values = array_merge( $values, $arr );
				break;

			case 'contains':
				if ( $sub_query ) {
					return null;
				}
				global $wpdb;
				$sql      = "{$col} LIKE %s";
				$values[] = '%' . $wpdb->esc_like( (string) ( $arr[0] ?? '' ) ) . '%';
				break;

			case 'not_contains':
				if ( $sub_query ) {
					return null;
				}
				global $wpdb;
				$sql      = "{$col} NOT LIKE %s";
				$values[] = '%' . $wpdb->esc_like( (string) ( $arr[0] ?? '' ) ) . '%';
				break;

			case 'starts_with':
				if ( $sub_query ) {
					return null;
				}
				global $wpdb;
				$sql      = "{$col} LIKE %s";
				$values[] = $wpdb->esc_like( (string) ( $arr[0] ?? '' ) ) . '%';
				break;

			case 'is_empty':
				if ( $sub_query ) {
					return null;
				}
				$sql = "({$col} IS NULL OR {$col} = '')";
				break;

			case 'is_not_empty':
				if ( $sub_query ) {
					return null;
				}
				$sql = "({$col} IS NOT NULL AND {$col} <> '')";
				break;

			default:
				return null;
		}

		// Handle sub_query EXISTS path for the single-value operators (is / is_not / greater_than / etc.).
		if ( $sub_query && null === $sql && null !== $primitive && ! in_array( $op, array( 'is_in', 'is_not_in', 'between' ), true ) ) {
			$template = str_replace( array( '{{OP}}', '{{VAL}}' ), array( $primitive, '%s' ), $sub_query );
			$sql      = $template;
		}

		if ( empty( $sql ) ) {
			return null;
		}

		return array(
			'sql'    => $sql,
			'values' => $values,
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// query_analytics — products entity. Catalog attributes (price, stock,
	// category, type, status) are as-of-now; sales aggregates
	// (units_sold_in_period, revenue_in_period, orders_count_in_period) are
	// scoped to the date range via an inline subquery joined onto wp_posts.
	// Universe = products with ≥1 paid sale in period. Summary fields mix
	// catalog-level (avg_price, matched_count) with sales-level totals.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Products-entity query branch.
	 *
	 * @param array       $dates   resolve_dates() output.
	 * @param array       $filters Filter specs.
	 * @param string      $match_mode   'all' | 'any'.
	 * @param string      $mode    'aggregate' | 'rows'.
	 * @param int         $limit   Rows-mode cap.
	 * @param string|null $orderby Rows-mode sort field.
	 * @param string      $order   'ASC' | 'DESC'.
	 * @return array|\WP_Error
	 */
	private static function qa_products_entity( $dates, $filters, $match_mode, $mode, $limit, $orderby, $order ) {
		global $wpdb;

		$registry = self::qa_products_field_registry( $dates['start'], $dates['end'] );

		$validation = self::qa_validate_filters( $filters, $registry );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$has_status_filter = false;
		foreach ( $filters as $f ) {
			if ( isset( $f['field'] ) && 'status' === $f['field'] ) {
				$has_status_filter = true;
				break;
			}
		}

		$where_parts = self::qa_build_where( $filters, $match_mode, $registry );
		if ( is_wp_error( $where_parts ) ) {
			return $where_parts;
		}
		$filter_sql  = $where_parts['sql'];
		$filter_vals = $where_parts['values'];
		$joins       = $where_parts['joins'];

		// Build the sales subquery with the period baked in. Joined
		// unconditionally because summary fields reference it.
		$paid_statuses = self::get_paid_statuses();
		$status_ph     = implode( ',', array_fill( 0, count( $paid_statuses ), '%s' ) );

		$pl_table = $wpdb->prefix . 'wc_order_product_lookup';
		$os_table = $wpdb->prefix . 'wc_order_stats';
		$date_col = self::get_date_column();

		$sales_subquery = "LEFT JOIN (
			SELECT pl.product_id,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$status_ph}) THEN pl.product_qty ELSE 0 END) AS units_in_period,
				SUM(CASE WHEN os.parent_id = 0 AND os.status IN ({$status_ph}) THEN pl.product_net_revenue ELSE 0 END) AS revenue_in_period,
				COUNT(DISTINCT CASE WHEN os.parent_id = 0 AND os.status IN ({$status_ph}) THEN pl.order_id END) AS orders_count_in_period
			FROM {$pl_table} AS pl
			INNER JOIN {$os_table} AS os ON pl.order_id = os.order_id
			WHERE os.{$date_col} >= %s AND os.{$date_col} <= %s
			GROUP BY pl.product_id
		) AS sales ON sales.product_id = p.ID";
		$sales_vals     = array_merge( $paid_statuses, $paid_statuses, $paid_statuses, array( $dates['start'] . ' 00:00:00', $dates['end'] . ' 23:59:59' ) );

		$from = "{$wpdb->posts} AS p
			INNER JOIN {$wpdb->prefix}wc_product_meta_lookup AS pml ON p.ID = pml.product_id
			{$sales_subquery}";

		$base_where_extra = array( 'p.post_type = %s' );
		$base_where_vals  = array( 'product' );
		if ( ! $has_status_filter ) {
			$base_where_extra[] = 'p.post_status = %s';
			$base_where_vals[]  = 'publish';
		}

		$summary = self::qa_products_compute_summary(
			$from,
			$joins,
			$base_where_extra,
			$base_where_vals,
			$sales_vals,
			$filter_sql,
			$filter_vals
		);

		$universe = self::qa_products_compute_universe( $dates, $paid_statuses, $status_ph, $date_col );

		$share_of_universe = array(
			'share_of_products_percent' => ( $universe['products_with_sales_in_period'] > 0 )
				? round( ( $summary['matched_count'] / $universe['products_with_sales_in_period'] ) * 100, 1 )
				: 0.0,
			'share_of_revenue_percent'  => ( $universe['total_revenue_in_period'] > 0 )
				? round( ( $summary['total_revenue_in_period'] / $universe['total_revenue_in_period'] ) * 100, 1 )
				: 0.0,
			'definition'                => 'Denominator is products that sold at least once in the period (not full catalog). Share is against the "what moved" universe, which is the meaningful comparison for sales-velocity questions.',
		);

		$rows = null;
		if ( 'rows' === $mode ) {
			$rows = self::qa_products_fetch_rows(
				$from,
				$joins,
				$base_where_extra,
				$base_where_vals,
				$sales_vals,
				$filter_sql,
				$filter_vals,
				$limit,
				$orderby,
				$order,
				$registry
			);
		}

		$sample_caveat = null;
		if ( $summary['matched_count'] > 0 && $summary['matched_count'] <= 5 ) {
			$sample_caveat = 'Small sample (' . (int) $summary['matched_count']
				. ' products). Averages are noisy on tiny N — treat as signal to watch, not conclusion.';
		}

		$note = null;
		if ( 0 === (int) $summary['matched_count'] ) {
			$note = 'No products matched the filter combination.';
		}

		return array(
			'period'             => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'entity'             => 'products',
			'mode'               => $mode,
			'match'              => $match_mode,
			'filters_applied'    => $filters,
			'currency'           => get_woocommerce_currency(),
			'summary'            => $summary,
			'rows'               => $rows,
			'universe'           => $universe,
			'share_of_universe'  => $share_of_universe,
			// Pipeline + admin_equivalent are order-level concepts; products
			// entity intentionally omits them. Null preserves the envelope.
			'pipeline'           => null,
			'admin_equivalent'   => null,
			'sample_size_caveat' => $sample_caveat,
			'privacy_mode'       => null,
			'note'               => $note,
		);
	}

	/**
	 * Products-entity aggregate summary. Catalog-level count + period sales
	 * roll-ups + catalog-wide averages (price across matched products).
	 *
	 * @param string $from        FROM clause.
	 * @param array  $joins       JOINs from filter fields.
	 * @param array  $base_where  Base WHERE fragments.
	 * @param array  $base_vals   Values for base WHERE.
	 * @param array  $sales_vals  Values for the inline sales subquery.
	 * @param string $filter_sql  Merchant filter WHERE fragment.
	 * @param array  $filter_vals Values for merchant filter.
	 * @return array
	 */
	private static function qa_products_compute_summary( $from, $joins, $base_where, $base_vals, $sales_vals, $filter_sql, $filter_vals ) {
		global $wpdb;

		$where = $base_where;
		if ( ! empty( $filter_sql ) ) {
			$where[] = $filter_sql;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$joins_sql = implode( "\n", $joins );

		$sql = "SELECT
			COUNT(DISTINCT p.ID) AS matched_count,
			COALESCE(SUM(COALESCE(sales.revenue_in_period, 0)), 0) AS total_revenue_in_period,
			COALESCE(SUM(COALESCE(sales.units_in_period, 0)), 0) AS total_units_sold_in_period,
			COALESCE(AVG(pml.min_price), 0) AS avg_price,
			COALESCE(SUM(CASE WHEN COALESCE(sales.units_in_period, 0) > 0 THEN 1 ELSE 0 END), 0) AS products_with_sales_count,
			COALESCE(SUM(CASE WHEN pml.stock_status = 'outofstock' THEN 1 ELSE 0 END), 0) AS out_of_stock_count
		FROM {$from} {$joins_sql} {$where_sql}";

		$vals = array_merge( $sales_vals, $base_vals, $filter_vals );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		if ( ! $row ) {
			$row = array(
				'matched_count'              => 0,
				'total_revenue_in_period'    => 0,
				'total_units_sold_in_period' => 0,
				'avg_price'                  => 0,
				'products_with_sales_count'  => 0,
				'out_of_stock_count'         => 0,
			);
		}

		$matched             = (int) $row['matched_count'];
		$units               = (int) $row['total_units_sold_in_period'];
		$avg_units           = ( $matched > 0 ) ? round( $units / $matched, 2 ) : 0.0;
		$products_with_sales = (int) $row['products_with_sales_count'];
		$zero_sales          = $matched - $products_with_sales;

		return array(
			'matched_count'              => $matched,
			'total_revenue_in_period'    => round( (float) $row['total_revenue_in_period'], 2 ),
			'total_units_sold_in_period' => $units,
			'avg_price'                  => round( (float) $row['avg_price'], 2 ),
			'avg_units_per_product'      => $avg_units,
			'products_with_sales_count'  => $products_with_sales,
			'products_with_zero_sales'   => $zero_sales,
			'out_of_stock_count'         => (int) $row['out_of_stock_count'],
			'definition'                 => 'Products matching your filter. Catalog attributes (price, stock, category) are as-of-now; sales aggregates (revenue_in_period, units_sold_in_period) are scoped to the period.',
		);
	}

	/**
	 * Products-entity universe — count of products with ≥1 paid sale in
	 * the period + total paid revenue in period. Denominator for
	 * share_of_universe.
	 *
	 * @param array  $dates         resolve_dates() output.
	 * @param array  $paid_statuses Paid statuses (with wc- prefix).
	 * @param string $status_ph     Placeholder list.
	 * @param string $date_col      Active date column (date_created/paid/completed).
	 * @return array
	 */
	private static function qa_products_compute_universe( $dates, $paid_statuses, $status_ph, $date_col ) {
		global $wpdb;

		$pl_table = $wpdb->prefix . 'wc_order_product_lookup';
		$os_table = $wpdb->prefix . 'wc_order_stats';

		$sql = "SELECT
			COUNT(DISTINCT pl.product_id) AS products_with_sales_in_period,
			COALESCE(SUM(pl.product_net_revenue), 0) AS total_revenue_in_period,
			COALESCE(SUM(pl.product_qty), 0) AS total_units_in_period
		FROM {$pl_table} AS pl
		INNER JOIN {$os_table} AS os ON pl.order_id = os.order_id
		WHERE os.{$date_col} >= %s
			AND os.{$date_col} <= %s
			AND os.parent_id = 0
			AND os.status IN ({$status_ph})";

		$vals = array_merge( array( $dates['start'] . ' 00:00:00', $dates['end'] . ' 23:59:59' ), $paid_statuses );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		return array(
			'products_with_sales_in_period' => $row ? (int) $row['products_with_sales_in_period'] : 0,
			'total_revenue_in_period'       => $row ? round( (float) $row['total_revenue_in_period'], 2 ) : 0.0,
			'total_units_in_period'         => $row ? (int) $row['total_units_in_period'] : 0,
			'definition'                    => 'Products that recorded at least one paid sale in the period. Baseline for "what moved".',
		);
	}

	/**
	 * Products-entity rows mode. Each row carries catalog + period sales
	 * data — no PII involved, so no privacy_mode branch.
	 *
	 * @param string      $from        FROM clause.
	 * @param array       $joins       JOIN clauses from filter fields.
	 * @param array       $base_where  Base WHERE fragments.
	 * @param array       $base_vals   Values for base WHERE.
	 * @param array       $sales_vals  Values for inline sales subquery.
	 * @param string      $filter_sql  Merchant filter WHERE.
	 * @param array       $filter_vals Merchant filter values.
	 * @param int         $limit       Row cap.
	 * @param string|null $orderby     Sort field (entity field name).
	 * @param string      $order       ASC | DESC.
	 * @param array       $registry    Field registry.
	 * @return array
	 */
	private static function qa_products_fetch_rows( $from, $joins, $base_where, $base_vals, $sales_vals, $filter_sql, $filter_vals, $limit, $orderby, $order, $registry ) {
		global $wpdb;

		$where = $base_where;
		if ( ! empty( $filter_sql ) ) {
			$where[] = $filter_sql;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$joins_sql = implode( "\n", $joins );

		// Default orderby: revenue_in_period DESC. Fall back to product id
		// when orderby is a subquery field (category) that doesn't resolve
		// to a sortable column.
		$order_col = 'COALESCE(sales.revenue_in_period, 0)';
		if ( ! empty( $orderby ) && isset( $registry[ $orderby ]['column'] ) && empty( $registry[ $orderby ]['sub_query'] ) ) {
			$order_col = $registry[ $orderby ]['column'];
		}

		$sql = "SELECT
			p.ID AS product_id,
			p.post_title AS name,
			p.post_status AS status,
			p.post_date AS date_created,
			pml.sku AS sku,
			pml.min_price AS price,
			pml.stock_status AS stock_status,
			pml.stock_quantity AS stock_quantity,
			pml.onsale AS onsale,
			COALESCE(sales.units_in_period, 0) AS units_sold_in_period,
			COALESCE(sales.revenue_in_period, 0) AS revenue_in_period,
			COALESCE(sales.orders_count_in_period, 0) AS orders_count_in_period
		FROM {$from} {$joins_sql} {$where_sql}
		ORDER BY {$order_col} {$order}
		LIMIT %d";

		$vals = array_merge( $sales_vals, $base_vals, $filter_vals, array( $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$raw = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();
		foreach ( $raw as $r ) {
			$product_id = (int) $r['product_id'];
			$rows[]     = array(
				'product_id'             => $product_id,
				'admin_url'              => self::product_admin_url( $product_id ),
				'name'                   => (string) $r['name'],
				'sku'                    => (string) $r['sku'],
				'status'                 => (string) $r['status'],
				'date_created'           => substr( (string) $r['date_created'], 0, 10 ),
				'price'                  => round( (float) $r['price'], 2 ),
				'stock_status'           => (string) $r['stock_status'],
				'stock_quantity'         => null === $r['stock_quantity'] ? null : (int) $r['stock_quantity'],
				'onsale'                 => (bool) $r['onsale'],
				'units_sold_in_period'   => (int) $r['units_sold_in_period'],
				'revenue_in_period'      => round( (float) $r['revenue_in_period'], 2 ),
				'orders_count_in_period' => (int) $r['orders_count_in_period'],
			);
		}

		return $rows;
	}

	/**
	 * Products-entity field registry. Catalog attributes via
	 * wc_product_meta_lookup + wp_posts; period-scoped sales aggregates
	 * via the sales subquery joined on p.ID.
	 *
	 * @param string $date_start Period start — baked into sales subquery. Unused
	 *                          directly here; kept so the signature signals
	 *                          date-aware registry (future-proofing for
	 *                          variant registries that branch on period).
	 * @param string $date_end   Period end — same comment.
	 * @return array
	 */
	private static function qa_products_field_registry( $date_start, $date_end ) {
		global $wpdb;
		unset( $date_start, $date_end ); // Reserved for future variant registries.

		$tr = $wpdb->term_relationships;
		$tt = $wpdb->term_taxonomy;
		$t  = $wpdb->terms;

		return array(
			'product_id'             => array(
				'column' => 'p.ID',
				'type'   => 'numeric',
				'label'  => 'Product id',
			),
			'sku'                    => array(
				'column' => 'pml.sku',
				'type'   => 'string',
				'label'  => 'Product SKU',
			),
			'name'                   => array(
				'column' => 'p.post_title',
				'type'   => 'string',
				'label'  => 'Product name',
			),
			'price'                  => array(
				'column' => 'pml.min_price',
				'type'   => 'numeric',
				'label'  => 'Current price (the figure customers see)',
			),
			'status'                 => array(
				'column'      => 'p.post_status',
				'type'        => 'default',
				'label'       => 'Catalog status (publish / draft / private / trash)',
				'enum_values' => array( 'publish', 'draft', 'private', 'trash' ),
			),
			'stock_status'           => array(
				'column'      => 'pml.stock_status',
				'type'        => 'default',
				'label'       => 'Stock status (instock / outofstock / onbackorder)',
				'enum_values' => array( 'instock', 'outofstock', 'onbackorder' ),
			),
			'stock_quantity'         => array(
				'column' => 'pml.stock_quantity',
				'type'   => 'numeric',
				'label'  => 'Stock quantity',
			),
			'date_created'           => array(
				'column' => 'p.post_date',
				'type'   => 'date',
				'label'  => 'Date product was added to the catalog',
			),
			'onsale'                 => array(
				'column' => 'pml.onsale',
				'type'   => 'boolean',
				'label'  => 'Currently on sale (sale_price < regular_price)',
			),
			'category'               => array(
				// Category is a term relationship — sub_query via term_relationships + taxonomy + terms.
				'sub_query' => "EXISTS (SELECT 1 FROM {$tr} AS cat_tr INNER JOIN {$tt} AS cat_tt ON cat_tr.term_taxonomy_id = cat_tt.term_taxonomy_id INNER JOIN {$t} AS cat_t ON cat_tt.term_id = cat_t.term_id WHERE cat_tr.object_id = p.ID AND cat_tt.taxonomy = 'product_cat' AND cat_t.slug {{OP}} {{VAL}})",
				'type'      => 'default',
				'label'     => 'Product category slug (e.g. "apparel", "electronics")',
			),
			'units_sold_in_period'   => array(
				'column' => 'COALESCE(sales.units_in_period, 0)',
				'type'   => 'numeric',
				'label'  => 'Units sold in the date range (paid orders only)',
			),
			'revenue_in_period'      => array(
				'column' => 'COALESCE(sales.revenue_in_period, 0)',
				'type'   => 'numeric',
				'label'  => 'Net revenue from paid orders in the date range',
			),
			'orders_count_in_period' => array(
				'column' => 'COALESCE(sales.orders_count_in_period, 0)',
				'type'   => 'numeric',
				'label'  => 'Distinct paid orders that included this product in the period',
			),
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// query_analytics — customers entity. Active-base frame: customers with
	// ≥1 paid order in the period + match the filter spec. Lifetime
	// aggregates (lifetime_orders_count, lifetime_spend, first/last order
	// date) are computed from the customer's FULL history, not just the
	// period. Matches the active-base frame in get_customer_value.
	//
	// PII shape: names/emails NEVER appear in the filter registry (leak
	// surface) and are NEVER returned in rows-mode. Customer rows always
	// carry the pseudonymised `Customer #N` identifier.
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Customers-entity query branch.
	 *
	 * @param array       $dates   resolve_dates() output.
	 * @param array       $filters Filter specs.
	 * @param string      $match_mode   'all' | 'any'.
	 * @param string      $mode    'aggregate' | 'rows'.
	 * @param int         $limit   Rows-mode cap.
	 * @param string|null $orderby Rows-mode sort field.
	 * @param string      $order   'ASC' | 'DESC'.
	 * @return array|\WP_Error
	 */
	private static function qa_customers_entity( $dates, $filters, $match_mode, $mode, $limit, $orderby, $order ) {
		global $wpdb;

		$registry = self::qa_customers_field_registry();

		$validation = self::qa_validate_filters( $filters, $registry );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$where_parts = self::qa_build_where( $filters, $match_mode, $registry );
		if ( is_wp_error( $where_parts ) ) {
			return $where_parts;
		}
		$filter_sql  = $where_parts['sql'];
		$filter_vals = $where_parts['values'];
		$joins       = $where_parts['joins'];

		$paid_statuses = self::get_paid_statuses();
		$status_ph     = implode( ',', array_fill( 0, count( $paid_statuses ), '%s' ) );

		$os_table = $wpdb->prefix . 'wc_order_stats';
		$cl_table = $wpdb->prefix . 'wc_customer_lookup';
		$date_col = self::get_date_column();

		// Active-in-period subquery: customers with at least one paid order
		// in the date window. INNER JOIN scopes the overall result set.
		$active_subquery = "INNER JOIN (
			SELECT DISTINCT customer_id
			FROM {$os_table}
			WHERE parent_id = 0 AND status IN ({$status_ph})
				AND {$date_col} >= %s AND {$date_col} <= %s
		) AS active ON active.customer_id = cl.customer_id";

		// Lifetime aggregates: sum across customer's FULL paid history.
		$ltv_subquery = "LEFT JOIN (
			SELECT customer_id,
				COUNT(*) AS lifetime_orders_count,
				COALESCE(SUM(net_total), 0) AS lifetime_spend,
				MIN({$date_col}) AS first_order_date,
				MAX({$date_col}) AS last_order_date
			FROM {$os_table}
			WHERE parent_id = 0 AND status IN ({$status_ph})
			GROUP BY customer_id
		) AS ltv ON ltv.customer_id = cl.customer_id";

		$from = "{$cl_table} AS cl {$active_subquery} {$ltv_subquery}";

		$subquery_vals = array_merge(
			$paid_statuses, // active_in_period status IN.
			array( $dates['start'] . ' 00:00:00', $dates['end'] . ' 23:59:59' ),
			$paid_statuses  // lifetime status IN.
		);

		$summary = self::qa_customers_compute_summary(
			$from,
			$joins,
			$subquery_vals,
			$filter_sql,
			$filter_vals
		);

		$universe = self::qa_customers_compute_universe( $dates, $paid_statuses, $status_ph, $date_col );

		$share_of_universe = array(
			'share_of_customers_percent'      => ( $universe['active_customers_in_period'] > 0 )
				? round( ( $summary['matched_count'] / $universe['active_customers_in_period'] ) * 100, 1 )
				: 0.0,
			'share_of_lifetime_spend_percent' => ( $universe['total_lifetime_spend_active_base'] > 0 )
				? round( ( $summary['total_lifetime_spend'] / $universe['total_lifetime_spend_active_base'] ) * 100, 1 )
				: 0.0,
			'definition'                      => 'Denominator is customers with ≥1 paid order in the period (active base). share_of_lifetime_spend_percent compares matched customers lifetime spend against the full active base lifetime spend — an LTV-weighted share, not order-count-weighted.',
		);

		$privacy_mode = null;
		$rows         = null;
		if ( 'rows' === $mode ) {
			$privacy_mode = 'pseudonymised';
			$rows         = self::qa_customers_fetch_rows(
				$from,
				$joins,
				$subquery_vals,
				$filter_sql,
				$filter_vals,
				$limit,
				$orderby,
				$order,
				$registry
			);
		}

		$sample_caveat = null;
		if ( $summary['matched_count'] > 0 && $summary['matched_count'] <= 5 ) {
			$sample_caveat = 'Small sample (' . (int) $summary['matched_count']
				. ' customers). LTV averages are noisy on tiny N — treat as signal to watch, not conclusion.';
		}

		$note = null;
		if ( 0 === (int) $summary['matched_count'] ) {
			$note = 'No customers matched the filter combination (active-base frame — customers must have ≥1 paid order in the period). If the merchant is asking about inactive customers, widen the period.';
		}

		return array(
			'period'             => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'entity'             => 'customers',
			'mode'               => $mode,
			'match'              => $match_mode,
			'filters_applied'    => $filters,
			'currency'           => get_woocommerce_currency(),
			'summary'            => $summary,
			'rows'               => $rows,
			'universe'           => $universe,
			'share_of_universe'  => $share_of_universe,
			'pipeline'           => null,
			'admin_equivalent'   => null,
			'sample_size_caveat' => $sample_caveat,
			'privacy_mode'       => $privacy_mode,
			'note'               => $note,
		);
	}

	/**
	 * Customers-entity aggregate summary. Headline counts + lifetime roll-ups
	 * + averaging + median (approximated via MySQL GROUP_CONCAT trick kept
	 * off the hot path — approximation acceptable at aggregate level).
	 *
	 * @param string $from          FROM clause.
	 * @param array  $joins         JOINs.
	 * @param array  $subquery_vals Values for inline subqueries.
	 * @param string $filter_sql    Merchant filter WHERE.
	 * @param array  $filter_vals   Merchant filter values.
	 * @return array
	 */
	private static function qa_customers_compute_summary( $from, $joins, $subquery_vals, $filter_sql, $filter_vals ) {
		global $wpdb;

		$where = array();
		if ( ! empty( $filter_sql ) ) {
			$where[] = $filter_sql;
		}

		$where_sql = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );
		$joins_sql = implode( "\n", $joins );

		$sql = "SELECT
			COUNT(DISTINCT cl.customer_id) AS matched_count,
			COALESCE(SUM(ltv.lifetime_orders_count), 0) AS total_lifetime_orders,
			COALESCE(SUM(ltv.lifetime_spend), 0) AS total_lifetime_spend,
			COALESCE(AVG(ltv.lifetime_spend), 0) AS avg_lifetime_spend,
			COALESCE(AVG(ltv.lifetime_orders_count), 0) AS avg_lifetime_orders
		FROM {$from} {$joins_sql} {$where_sql}";

		$vals = array_merge( $subquery_vals, $filter_vals );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		if ( ! $row ) {
			$row = array(
				'matched_count'         => 0,
				'total_lifetime_orders' => 0,
				'total_lifetime_spend'  => 0,
				'avg_lifetime_spend'    => 0,
				'avg_lifetime_orders'   => 0,
			);
		}

		$matched = (int) $row['matched_count'];
		$total   = (float) $row['total_lifetime_spend'];
		$avg_aov = ( (int) $row['total_lifetime_orders'] > 0 )
			? round( $total / (int) $row['total_lifetime_orders'], 2 )
			: 0.0;

		return array(
			'matched_count'         => $matched,
			'total_lifetime_spend'  => round( $total, 2 ),
			'total_lifetime_orders' => (int) $row['total_lifetime_orders'],
			'avg_lifetime_spend'    => round( (float) $row['avg_lifetime_spend'], 2 ),
			'avg_lifetime_orders'   => round( (float) $row['avg_lifetime_orders'], 2 ),
			'avg_order_value'       => $avg_aov,
			'definition'            => 'Customers active in the period (≥1 paid order) matching your filter. Lifetime aggregates (lifetime_spend, lifetime_orders_count) come from FULL history, not just the period.',
		);
	}

	/**
	 * Customers-entity universe — all customers active in period (with ≥1
	 * paid order) + their combined lifetime spend. Denominator for
	 * share_of_universe.
	 *
	 * @param array  $dates         resolve_dates() output.
	 * @param array  $paid_statuses Paid statuses (with wc- prefix).
	 * @param string $status_ph     Placeholder list.
	 * @param string $date_col      Active date column.
	 * @return array
	 */
	private static function qa_customers_compute_universe( $dates, $paid_statuses, $status_ph, $date_col ) {
		global $wpdb;

		$os_table = $wpdb->prefix . 'wc_order_stats';

		$sql = "SELECT
			COUNT(DISTINCT active.customer_id) AS active_customers_in_period,
			COALESCE(SUM(ltv.lifetime_spend), 0) AS total_lifetime_spend_active_base
		FROM (
			SELECT DISTINCT customer_id
			FROM {$os_table}
			WHERE parent_id = 0 AND status IN ({$status_ph})
				AND {$date_col} >= %s AND {$date_col} <= %s
		) AS active
		LEFT JOIN (
			SELECT customer_id, SUM(net_total) AS lifetime_spend
			FROM {$os_table}
			WHERE parent_id = 0 AND status IN ({$status_ph})
			GROUP BY customer_id
		) AS ltv ON ltv.customer_id = active.customer_id";

		$vals = array_merge(
			$paid_statuses,
			array( $dates['start'] . ' 00:00:00', $dates['end'] . ' 23:59:59' ),
			$paid_statuses
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		return array(
			'active_customers_in_period'       => $row ? (int) $row['active_customers_in_period'] : 0,
			'total_lifetime_spend_active_base' => $row ? round( (float) $row['total_lifetime_spend_active_base'], 2 ) : 0.0,
			'definition'                       => 'Customers with ≥1 paid order in the period. Lifetime spend sums across their full history.',
		);
	}

	/**
	 * Customers-entity rows mode. Always returns pseudonymised customer id
	 * ("Customer #N"); first_name / last_name / email are never selected
	 * or returned.
	 *
	 * @param string      $from          FROM clause.
	 * @param array       $joins         JOINs.
	 * @param array       $subquery_vals Values for inline subqueries.
	 * @param string      $filter_sql    Merchant filter WHERE.
	 * @param array       $filter_vals   Merchant filter values.
	 * @param int         $limit         Row cap.
	 * @param string|null $orderby       Sort field.
	 * @param string      $order         ASC | DESC.
	 * @param array       $registry      Field registry.
	 * @return array
	 */
	private static function qa_customers_fetch_rows( $from, $joins, $subquery_vals, $filter_sql, $filter_vals, $limit, $orderby, $order, $registry ) {
		global $wpdb;

		$where = array();
		if ( ! empty( $filter_sql ) ) {
			$where[] = $filter_sql;
		}

		$where_sql = empty( $where ) ? '' : 'WHERE ' . implode( ' AND ', $where );
		$joins_sql = implode( "\n", $joins );

		$order_col = 'COALESCE(ltv.lifetime_spend, 0)';
		if ( ! empty( $orderby ) && isset( $registry[ $orderby ]['column'] ) && empty( $registry[ $orderby ]['sub_query'] ) ) {
			$order_col = $registry[ $orderby ]['column'];
		}

		$sql = "SELECT
			cl.customer_id,
			cl.country,
			cl.state,
			cl.city,
			cl.postcode,
			cl.date_registered,
			cl.date_last_active,
			COALESCE(ltv.lifetime_orders_count, 0) AS lifetime_orders_count,
			COALESCE(ltv.lifetime_spend, 0) AS lifetime_spend,
			ltv.first_order_date,
			ltv.last_order_date
		FROM {$from} {$joins_sql} {$where_sql}
		ORDER BY {$order_col} {$order}
		LIMIT %d";

		$vals = array_merge( $subquery_vals, $filter_vals, array( $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$raw = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$rows = array();
		foreach ( $raw as $r ) {
			$customer_id = (int) $r['customer_id'];
			$rows[]      = array(
				'customer_id_pseudo'    => 'Customer #' . $customer_id,
				'admin_url'             => self::customer_admin_url( $customer_id ),
				'country'               => (string) $r['country'],
				'state'                 => (string) $r['state'],
				'city'                  => (string) $r['city'],
				'postcode'              => (string) $r['postcode'],
				'date_registered'       => null === $r['date_registered'] ? null : substr( (string) $r['date_registered'], 0, 10 ),
				'date_last_active'      => null === $r['date_last_active'] ? null : substr( (string) $r['date_last_active'], 0, 10 ),
				'lifetime_orders_count' => (int) $r['lifetime_orders_count'],
				'lifetime_spend'        => round( (float) $r['lifetime_spend'], 2 ),
				'first_order_date'      => null === $r['first_order_date'] ? null : substr( (string) $r['first_order_date'], 0, 10 ),
				'last_order_date'       => null === $r['last_order_date'] ? null : substr( (string) $r['last_order_date'], 0, 10 ),
			);
		}

		return $rows;
	}

	/**
	 * Customers-entity field registry. Identity + geography + lifetime
	 * aggregates. PII fields (first_name/last_name/email) are deliberately
	 * NOT in the filter registry — they are a leak surface and the
	 * customers entity never returns them.
	 *
	 * @return array
	 */
	private static function qa_customers_field_registry() {
		return array(
			'customer_id'           => array(
				'column' => 'cl.customer_id',
				'type'   => 'numeric',
				'label'  => 'Customer id',
			),
			'country'               => array(
				'column' => 'cl.country',
				'type'   => 'string',
				'label'  => 'Billing country (ISO-2) from customer lookup',
			),
			'state'                 => array(
				'column' => 'cl.state',
				'type'   => 'string',
				'label'  => 'Billing state/region',
			),
			'city'                  => array(
				'column' => 'cl.city',
				'type'   => 'string',
				'label'  => 'Billing city',
			),
			'postcode'              => array(
				'column' => 'cl.postcode',
				'type'   => 'string',
				'label'  => 'Billing postcode',
			),
			'date_registered'       => array(
				'column' => 'cl.date_registered',
				'type'   => 'date',
				'label'  => 'Date the customer account was registered (null for guest orders)',
			),
			'date_last_active'      => array(
				'column' => 'cl.date_last_active',
				'type'   => 'date',
				'label'  => 'Last activity date on record',
			),
			'lifetime_orders_count' => array(
				'column' => 'COALESCE(ltv.lifetime_orders_count, 0)',
				'type'   => 'numeric',
				'label'  => 'Total paid orders across the customer\'s full history',
			),
			'lifetime_spend'        => array(
				'column' => 'COALESCE(ltv.lifetime_spend, 0)',
				'type'   => 'numeric',
				'label'  => 'Total spend across the customer\'s full history (net of refunds not included)',
			),
			'first_order_date'      => array(
				'column' => 'ltv.first_order_date',
				'type'   => 'date',
				'label'  => 'Date of the customer\'s first paid order',
			),
			'last_order_date'       => array(
				'column' => 'ltv.last_order_date',
				'type'   => 'date',
				'label'  => 'Date of the customer\'s most recent paid order',
			),
		);
	}
}
