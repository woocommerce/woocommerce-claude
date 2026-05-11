<?php
/**
 * Integration tests — wc-analytics/get-attribution.
 *
 * Pins the invariants that drift silently when the SQL in
 * AnalyticsController::query_attribution_groups() /
 * ::query_attribution_totals() / ::attribution_group_expr() is
 * refactored. Eight group_by dimensions, per-group change math,
 * include_unassigned, channel_source collapse, and the three
 * dimensions (term / medium / content) added after the 2026-04-16
 * demo — covered here so they can't regress unnoticed.
 *
 * What's pinned:
 *
 *   - `group_by=source` emits one top_groups row per distinct
 *     _wc_order_attribution_utm_source value, sorted by net_revenue
 *     desc (the default orderby).
 *   - `group_by=channel` groups on _wc_order_attribution_origin —
 *     the controller does NOT derive channel from utm_medium; it
 *     reads the stored origin string directly.
 *   - `group_by=channel_source` collapses to just the channel when
 *     the channel is in AnalyticsController::$channels_without_source
 *     (Direct, Email, Referral, SMS, Affiliates, Audio, Cross-network,
 *     Display, Mobile Push Notifications, Unassigned) OR when the
 *     source is NULL / empty. Otherwise it's "Channel: source".
 *   - `include_unassigned=false` removes the (Unassigned) row from
 *     top_groups; =true keeps it. Totals are unaffected — coverage %
 *     always reports against the full paid-orders denominator.
 *   - `compare=true` emits a `comparison` block and stamps a `change`
 *     sub-object on every top_groups row that exists in both periods
 *     (direction / amount / percent / metric); new-to-period groups
 *     get direction=new; prior-only groups land in
 *     `comparison.dropped_out` keyed by the original label.
 *   - `group_by=term/medium/content` — the three dimensions added
 *     after the 2026-04-16 demo — each return a row per distinct
 *     value (same shared math as source).
 *   - `orderby` whitelist — orders_count and avg_order_value move
 *     the top-of-list deterministically; unrecognised values fall
 *     back to net_revenue.
 *   - Per-group `pipeline_revenue` / `admin_equivalent_revenue` pick
 *     up on-hold and refunded orders tagged to the same attribution
 *     group; the paid `net_revenue` stays gross of those lenses.
 *   - Per-group `new_customers` / `returning_customers` count orders
 *     by the `returning_customer` flag on each order row (NOT
 *     distinct customers — order-level SUMs, same shape as
 *     revenue / orders_summary).
 *   - `attribution_coverage_percent` = attributed paid orders /
 *     total paid orders × 100. `share_of_revenue_percent` per group
 *     is pre-computed against total paid net_revenue (not the sum
 *     of top_groups, so it stays correct when include_unassigned
 *     flips or limit clips the long tail).
 *   - `items_sold` per group sums `num_items_sold` from wc_order_stats
 *     across paid parent orders for the group. B1 (2 × widget) + B2
 *     (1 × widget) = 3 items on google; other groups keep the
 *     no-line-item orders so their items_sold stays 0.
 *   - Per-group `refunds` column picks up refund sub-orders keyed
 *     by the refund's OWN attribution meta (the LEFT JOIN is on the
 *     refund-sub-order's ID, not the parent's). wc_create_refund()
 *     does NOT copy parent attribution meta, so the refund lands in
 *     the (Unassigned) bucket even when the parent was attributed.
 *     That's deliberate behaviour to pin — rewriting the SQL to
 *     inherit parent meta onto refund rows would silently move
 *     refund amounts between groups.
 *
 * Harness scope: the wp-env tests-cli container runs with HPOS
 * enabled (bootstrap.php sets `woocommerce_custom_orders_table_enabled`
 * and re-runs `WC_Install::install()` to create the wc_orders /
 * wc_orders_meta / wc_order_addresses / wc_order_operational_data
 * tables). Fixture orders save straight into the HPOS tables via
 * `$order->save()`, so every ability assertion below is implicitly
 * running the attribution LEFT JOIN against wc_orders_meta —
 * full end-to-end HPOS coverage, not just function-level routing.
 * The forced-classic branch is covered via the `pre_option_*`
 * filter trick in `test_meta_source_classic_path_returns_postmeta`.
 *
 * Known gaps still uncovered (deliberate, flagged for follow-up):
 *
 *   - The `orderby=orders_count` / `avg_order_value` tie-breaker
 *     rules (they collapse silently when two groups tie on the
 *     chosen metric). Fixture is tuned to avoid ties so the direct
 *     assertions are deterministic; the MySQL tie-break rules
 *     themselves aren't exercised.
 *
 * Fixture shape (period 2025-10-01..2025-10-31):
 *
 *   Widget (simple product, £50 unit price) — used by B's orders to
 *                exercise per-group items_sold.
 *
 *   Customer A — pre-period paid $50, source=google, 2025-09-10.
 *                Establishes prior-period baseline for the compare
 *                test; gives "google" a delta from $50 → $150.
 *   Customer B — in-period paid $100 (2 × widget), 2025-10-05, all
 *                7 dimensions set: Paid Search / google / cpc / brand
 *                / shoes / hero / desktop. First order → flag=0 (new).
 *   Customer B — in-period paid $50 (1 × widget), 2025-10-28, same
 *                attribution as first order. Second order → flag=1
 *                (returning); exercises per-group returning_customers.
 *   Customer C — in-period paid $60, 2025-10-10, Paid Social /
 *                facebook / social / mobile.
 *   Customer D — in-period paid $80, 2025-10-15, Email / klaviyo /
 *                email / desktop. Email is in channels_without_source.
 *   Customer E — in-period paid $10, 2025-10-20, NO attribution meta.
 *                Lands in (Unassigned) for every dimension.
 *   Customer F — in-period paid $40, 2025-10-25, channel=Direct only.
 *                Direct is in channels_without_source.
 *   Customer G — in-period ON-HOLD $50, 2025-10-12, Paid Search /
 *                google / cpc. Exercises per-group pipeline_revenue.
 *   Customer H — in-period REFUNDED $30, 2025-10-14, Paid Social /
 *                facebook / social. Exercises per-group admin_equivalent
 *                (refunded is an admin status) WITHOUT populating the
 *                refunds column (main-order refund, not a sub-order).
 *   Refund     — 1 × widget refunded off Customer B's first order
 *                on 2025-10-30. Creates a real refund sub-order
 *                (parent_id != 0, net_total = −£50). The refund row
 *                carries NO attribution meta of its own — groups
 *                under (Unassigned) despite the parent's google tags.
 *
 * Paid totals (period): $100 + $50 + $60 + $80 + $10 + $40 = $340
 * across 6 orders. Attributed (has source value): 4 (B, B2, C, D);
 * unattributed (no source): 2 (E, F). Coverage = 4/6 = 66.7%.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the get-attribution ability.
 */
class Test_Get_Attribution extends WP_UnitTestCase {

	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * Period start used by the default runs.
	 *
	 * @var string
	 */
	private $period_start = '2025-10-01';

	/**
	 * Period end used by the default runs.
	 *
	 * @var string
	 */
	private $period_end = '2025-10-31';

	/**
	 * Order B1 — reused for a refund sub-order in set_up() so the
	 * per-group refunds column gets exercised.
	 *
	 * @var \WC_Order
	 */
	private $order_b1;

	/**
	 * Seed the fixture shape described in the file-level docblock.
	 *
	 * Seeded chronologically so WC's sync_order assigns
	 * returning_customer correctly: each customer's first order
	 * lands with flag=0, subsequent orders with flag=1.
	 */
	public function set_up() {
		parent::set_up();

		// Widget — simple product at £50, used by Customer B's orders
		// to give them line-item num_items_sold values for the
		// per-group items_sold assertions.
		$widget = $this->seed_simple_product(
			array(
				'name'  => 'Widget',
				'sku'   => 'WIDGET-50',
				'price' => 50,
			)
		);

		// Customer A — pre-period "google" order, for compare test.
		$a = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $a,
				'total'       => 50.00,
				'date'        => '2025-09-10 10:00:00',
				'attribution' => array(
					'channel' => 'Paid Search',
					'source'  => 'google',
					'medium'  => 'cpc',
				),
			)
		);

		// Customer B — in-period, first order, all 7 dimensions set.
		// 2 × widget at £50 gives a £100 total with num_items_sold = 2,
		// which feeds the per-group items_sold aggregate via wc_order_stats.
		$b              = $this->seed_customer();
		$this->order_b1 = $this->seed_paid_order(
			array(
				'customer_id' => $b,
				'total'       => 100.00,
				'date'        => '2025-10-05 10:00:00',
				'items'       => array(
					array(
						'product_id' => $widget,
						'qty'        => 2,
					),
				),
				'attribution' => array(
					'channel'  => 'Paid Search',
					'source'   => 'google',
					'medium'   => 'cpc',
					'campaign' => 'brand',
					'term'     => 'shoes',
					'content'  => 'hero',
					'device'   => 'desktop',
				),
			)
		);

		// Customer C — in-period, Paid Social / facebook.
		$c = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $c,
				'total'       => 60.00,
				'date'        => '2025-10-10 10:00:00',
				'attribution' => array(
					'channel' => 'Paid Social',
					'source'  => 'facebook',
					'medium'  => 'social',
					'device'  => 'mobile',
				),
			)
		);

		// Customer G — on-hold order with Paid Search / google
		// attribution. Exercises per-group pipeline_revenue AND
		// per-group admin_equivalent_revenue (admin includes on-hold).
		$g = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $g,
				'total'       => 50.00,
				'date'        => '2025-10-12 10:00:00',
				'status'      => 'on-hold',
				'attribution' => array(
					'channel' => 'Paid Search',
					'source'  => 'google',
					'medium'  => 'cpc',
				),
			)
		);

		// Customer H — refunded order with Paid Social / facebook
		// attribution. Exercises per-group admin_equivalent_revenue
		// WITHOUT affecting the refunds column (that requires a
		// sub-order with parent_id != 0, which we don't seed).
		$h = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $h,
				'total'       => 30.00,
				'date'        => '2025-10-14 10:00:00',
				'status'      => 'refunded',
				'attribution' => array(
					'channel' => 'Paid Social',
					'source'  => 'facebook',
					'medium'  => 'social',
				),
			)
		);

		// Customer D — in-period, Email / klaviyo.
		// Email is in channels_without_source — channel_source collapses
		// to just "Email" even though a source is present.
		$d = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $d,
				'total'       => 80.00,
				'date'        => '2025-10-15 10:00:00',
				'attribution' => array(
					'channel' => 'Email',
					'source'  => 'klaviyo',
					'medium'  => 'email',
					'device'  => 'desktop',
				),
			)
		);

		// Customer E — in-period, no attribution meta at all.
		$e = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $e,
				'total'       => 10.00,
				'date'        => '2025-10-20 10:00:00',
			)
		);

		// Customer F — in-period, channel=Direct only.
		// Direct is in channels_without_source — channel_source collapses
		// to just "Direct". No source/medium, so F lands in (Unassigned)
		// for the source / medium / etc. dimensions.
		$f = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $f,
				'total'       => 40.00,
				'date'        => '2025-10-25 10:00:00',
				'attribution' => array(
					'channel' => 'Direct',
				),
			)
		);

		// Customer B — SECOND in-period order (flag=1 because B's first
		// order already exists). Same attribution as B's first. 1 × widget
		// gives num_items_sold = 1, so google's items_sold = 2 + 1 = 3.
		// Exercises per-group returning_customers and lets orderby=
		// orders_count + orderby=avg_order_value differentiate top positions.
		$this->seed_paid_order(
			array(
				'customer_id' => $b,
				'total'       => 50.00,
				'date'        => '2025-10-28 10:00:00',
				'items'       => array(
					array(
						'product_id' => $widget,
						'qty'        => 1,
					),
				),
				'attribution' => array(
					'channel'  => 'Paid Search',
					'source'   => 'google',
					'medium'   => 'cpc',
					'campaign' => 'brand',
					'term'     => 'shoes',
					'content'  => 'hero',
					'device'   => 'desktop',
				),
			)
		);

		// Refund — 1 × widget refunded off Customer B's first order.
		// seed_refund creates a real wc_order_refund sub-order with
		// parent_id != 0 and net_total = −£50. The refund has NO
		// attribution meta of its own — the LEFT JOIN on the refund's
		// ID returns NULL for every dimension, so it groups under
		// (Unassigned) despite the parent carrying google tags.
		$this->seed_refund(
			$this->order_b1,
			array( $widget => 1 ),
			array(
				'reason' => 'test refund for attribution',
				'date'   => '2025-10-30 10:00:00',
			)
		);
	}

	/**
	 * Invoke the ability over the fixture period with compare=false
	 * and include_unassigned=true unless overridden.
	 *
	 * @param array $overrides Override input keys.
	 * @return array Ability result.
	 */
	private function run_ability( array $overrides = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$input = array_merge(
			array(
				'period'             => 'last_30_days',
				'date_start'         => $this->period_start,
				'date_end'           => $this->period_end,
				'compare'            => false,
				'limit'              => 10,
				'orderby'            => 'net_revenue',
				'group_by'           => 'source',
				'include_unassigned' => true,
			),
			$overrides
		);

		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_attribution(
			$input['period'],
			$input['date_start'],
			$input['date_end'],
			$input['compare'],
			$input['limit'],
			$input['orderby'],
			$input['group_by'],
			$input['include_unassigned']
		);
		$this->assertFalse(
			is_wp_error( $result ),
			'fetch_attribution returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		return $result;
	}

	/**
	 * Locate a top_groups row by key. Returns null when absent.
	 *
	 * @param array  $groups Top_groups array from the ability result.
	 * @param string $key    Group key to locate.
	 * @return array|null
	 */
	private function find_group( array $groups, $key ) {
		foreach ( $groups as $row ) {
			if ( isset( $row['key'] ) && $row['key'] === $key ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Grouping by source emits one row per distinct utm_source, each
	 * with the expected net_revenue / orders_count. Default orderby
	 * (net_revenue) sorts desc so google leads. Fixture scope:
	 * google=$150 (B $100 + B2 $50, 2 orders), klaviyo=$80 (D),
	 * facebook=$60 (C), (Unassigned)=$50 (E+F).
	 */
	public function test_group_by_source_basic() {
		$result = $this->run_ability( array( 'group_by' => 'source' ) );

		$this->assertSame( 'source', $result['group_by'] );
		$this->assertCount( 4, $result['top_groups'], '3 sources + Unassigned.' );

		// Ordered desc by net_revenue.
		$this->assertSame( 'google', $result['top_groups'][0]['key'] );
		$this->assertSame( 'klaviyo', $result['top_groups'][1]['key'] );
		$this->assertSame( 'facebook', $result['top_groups'][2]['key'] );
		$this->assertSame( '(Unassigned)', $result['top_groups'][3]['key'] );

		$this->assertSame( 150.00, (float) $result['top_groups'][0]['net_revenue'] );
		$this->assertSame( 2, (int) $result['top_groups'][0]['orders_count'] );
		$this->assertSame( 80.00, (float) $result['top_groups'][1]['net_revenue'] );
		$this->assertSame( 60.00, (float) $result['top_groups'][2]['net_revenue'] );
		$this->assertSame(
			50.00,
			(float) $result['top_groups'][3]['net_revenue'],
			'Unassigned source covers E ($10, no meta) and F ($40, Direct channel but no source) = $50.'
		);
	}

	/**
	 * Grouping by channel reads _wc_order_attribution_origin literally
	 * — there is no CASE-based derivation from utm_medium. "Direct" is
	 * its own row alongside Paid Search / Email / Paid Social.
	 */
	public function test_group_by_channel_reads_origin_directly() {
		$result = $this->run_ability( array( 'group_by' => 'channel' ) );

		$this->assertSame( 'channel', $result['group_by'] );

		$paid_search = $this->find_group( $result['top_groups'], 'Paid Search' );
		$this->assertNotNull( $paid_search, 'Paid Search channel row should be present.' );
		$this->assertSame( 150.00, (float) $paid_search['net_revenue'] );
		$this->assertSame( 2, (int) $paid_search['orders_count'] );

		$email = $this->find_group( $result['top_groups'], 'Email' );
		$this->assertNotNull( $email );
		$this->assertSame( 80.00, (float) $email['net_revenue'] );

		$paid_social = $this->find_group( $result['top_groups'], 'Paid Social' );
		$this->assertNotNull( $paid_social );
		$this->assertSame( 60.00, (float) $paid_social['net_revenue'] );

		$direct = $this->find_group( $result['top_groups'], 'Direct' );
		$this->assertNotNull( $direct, 'Direct channel row should be present.' );
		$this->assertSame( 40.00, (float) $direct['net_revenue'] );

		$unassigned = $this->find_group( $result['top_groups'], '(Unassigned)' );
		$this->assertNotNull( $unassigned, 'E has no channel meta — should land in Unassigned.' );
		$this->assertSame( 10.00, (float) $unassigned['net_revenue'] );
	}

	/**
	 * Grouping by channel_source renders "Channel: source" when the
	 * channel is NOT in `channels_without_source` AND the source is
	 * populated. When either condition fails, the composite collapses
	 * to just the channel.
	 */
	public function test_group_by_channel_source_collapse() {
		$result = $this->run_ability( array( 'group_by' => 'channel_source' ) );

		$this->assertSame( 'channel_source', $result['group_by'] );

		$paid_search_google = $this->find_group( $result['top_groups'], 'Paid Search: google' );
		$this->assertNotNull( $paid_search_google, 'Paid Search is not in channels_without_source — should render composite.' );
		$this->assertSame( 150.00, (float) $paid_search_google['net_revenue'] );

		$paid_social_facebook = $this->find_group( $result['top_groups'], 'Paid Social: facebook' );
		$this->assertNotNull( $paid_social_facebook );
		$this->assertSame( 60.00, (float) $paid_social_facebook['net_revenue'] );

		$email = $this->find_group( $result['top_groups'], 'Email' );
		$this->assertNotNull( $email, 'Email is in channels_without_source — should collapse despite klaviyo being set.' );
		$this->assertSame( 80.00, (float) $email['net_revenue'] );
		$this->assertNull(
			$this->find_group( $result['top_groups'], 'Email: klaviyo' ),
			'Email: klaviyo composite must NOT appear — Email is in channels_without_source.'
		);

		$direct = $this->find_group( $result['top_groups'], 'Direct' );
		$this->assertNotNull( $direct, 'Direct collapses because Direct is in channels_without_source AND source is empty.' );
		$this->assertSame( 40.00, (float) $direct['net_revenue'] );
	}

	/**
	 * Setting include_unassigned=false excludes the (Unassigned)
	 * top_groups row (E's no-meta order and F's source-less Direct
	 * order for the source dimension). Totals are NOT filtered —
	 * attribution_coverage_percent is always computed against the full
	 * paid-orders denominator.
	 */
	public function test_include_unassigned_toggle() {
		$with = $this->run_ability(
			array(
				'group_by'           => 'source',
				'include_unassigned' => true,
			)
		);
		$this->assertNotNull(
			$this->find_group( $with['top_groups'], '(Unassigned)' ),
			'include_unassigned=true should keep the Unassigned row.'
		);

		$without = $this->run_ability(
			array(
				'group_by'           => 'source',
				'include_unassigned' => false,
			)
		);
		$this->assertNull(
			$this->find_group( $without['top_groups'], '(Unassigned)' ),
			'include_unassigned=false should drop the Unassigned row.'
		);
		$this->assertCount( 3, $without['top_groups'], 'Only the three real sources remain.' );

		// Totals (denominator for coverage %) must be identical regardless
		// of the toggle — it filters rows, not totals.
		$this->assertSame(
			$with['totals']['total_paid_orders'],
			$without['totals']['total_paid_orders'],
			'Totals denominator must not move with the include_unassigned flag.'
		);
		$this->assertSame(
			$with['totals']['attributed_orders'],
			$without['totals']['attributed_orders'],
			'attributed_orders is a totals-level concept — unaffected by include_unassigned.'
		);
	}

	/**
	 * HPOS path (default) — the test harness enables HPOS in bootstrap.php,
	 * so get_order_meta_source() routes through OrderUtil and returns the
	 * wc_orders_meta table plus an order_id join column without any filter
	 * gymnastics. Also asserts the ability still produces real grouped
	 * rows end-to-end, which is the full proof that the attribution
	 * LEFT JOINs now query wc_orders_meta (not postmeta) on HPOS stores.
	 *
	 * Pre-fix history: get_order_meta_source() wrapped `SHOW TABLES LIKE`
	 * in (int) casts where SHOW TABLES returns either the table name (a
	 * string that casts to 0) or NULL (also 0), so the HPOS branch never
	 * fired even on real HPOS stores. Session 8 rerouted detection
	 * through OrderUtil. Session 10 raised HPOS for the full harness so
	 * this path is now the default in every attribution assertion, not
	 * just a function-level contract check.
	 */
	public function test_meta_source_hpos_path_returns_wc_orders_meta() {
		global $wpdb;

		$this->assertSame(
			'yes',
			get_option( 'woocommerce_custom_orders_table_enabled', 'no' ),
			'Bootstrap enables HPOS — failure here means the bootstrap regressed.'
		);

		$source = \WooCommerce\Claude\API\AnalyticsController::get_order_meta_source();
		$this->assertSame( $wpdb->prefix . 'wc_orders_meta', $source['table'] );
		$this->assertSame( 'order_id', $source['id_column'] );

		// Full-stack proof — the ability reads attribution meta out of
		// wc_orders_meta and produces the same grouped rows as classic.
		$result = $this->run_ability( array( 'group_by' => 'source' ) );
		$this->assertNotEmpty( $result['top_groups'] );
		$this->assertNotNull(
			$this->find_group( $result['top_groups'], 'google' ),
			'google group should surface from HPOS wc_orders_meta.'
		);
	}

	/**
	 * Classic path (forced) — with HPOS disabled, get_order_meta_source()
	 * returns the postmeta table and a post_id join column.
	 *
	 * The `woocommerce_custom_orders_table_enabled` option can't be
	 * flipped via update_option() when orders are out of sync — WC's
	 * CustomOrdersTableController throws on the pre-update hook. We
	 * hook `pre_option_*` instead, which short-circuits get_option()
	 * before WC's guards fire. OrderUtil reads the option fresh on
	 * each call so the filter takes effect immediately.
	 *
	 * Only the function-level contract is asserted here. Running the
	 * ability in forced-classic mode would produce empty attribution
	 * groups because the fixture orders' meta lives in wc_orders_meta
	 * (where we wrote it) — the postmeta table is empty for these
	 * orders. That's expected; the HPOS-default path above is the one
	 * that exercises the ability end-to-end.
	 */
	public function test_meta_source_classic_path_returns_postmeta() {
		global $wpdb;

		$force_no = function () {
			return 'no';
		};
		add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $force_no );

		try {
			$source = \WooCommerce\Claude\API\AnalyticsController::get_order_meta_source();
			$this->assertSame( $wpdb->prefix . 'postmeta', $source['table'] );
			$this->assertSame( 'post_id', $source['id_column'] );
		} finally {
			remove_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $force_no );
		}
	}

	/**
	 * Setting compare=true emits a comparison block with per-metric
	 * deltas for totals, and stamps a `change` sub-object on each
	 * top_groups row that appeared in both periods. Groups new to the
	 * period carry direction=new. Groups present only in the prior
	 * period land in comparison.dropped_out.
	 *
	 * Fixture: prior period has one order at source=google ($50).
	 * Current period has google ($100), facebook ($60), klaviyo ($80),
	 * Unassigned ($50).
	 */
	public function test_compare_per_group_change_and_dropped_out() {
		$result = $this->run_ability(
			array(
				'group_by' => 'source',
				'compare'  => true,
			)
		);

		$this->assertIsArray( $result['comparison'], 'comparison block must be present when compare=true.' );
		$this->assertSame( '2025-08-31', $result['comparison']['period']['start'] );
		$this->assertSame( '2025-09-30', $result['comparison']['period']['end'] );

		// google exists in both periods — change block is populated.
		$google = $this->find_group( $result['top_groups'], 'google' );
		$this->assertNotNull( $google );
		$this->assertArrayHasKey( 'change', $google, 'google should carry a change sub-object.' );
		$this->assertSame( 'net_revenue', $google['change']['metric'] );
		$this->assertSame( 'up', $google['change']['direction'] );
		$this->assertSame( 100.00, (float) $google['change']['amount'], 'google: 150 − 50 = 100.' );
		$this->assertSame( 200.0, (float) $google['change']['percent'] );

		// facebook / klaviyo are new to this period.
		$facebook = $this->find_group( $result['top_groups'], 'facebook' );
		$this->assertNotNull( $facebook );
		$this->assertSame( 'new', $facebook['change']['direction'] );

		$klaviyo = $this->find_group( $result['top_groups'], 'klaviyo' );
		$this->assertNotNull( $klaviyo );
		$this->assertSame( 'new', $klaviyo['change']['direction'] );

		// No source dropped out in this fixture — but the key has to
		// exist in the comparison block regardless.
		$this->assertArrayHasKey(
			'dropped_out',
			$result['comparison'],
			'dropped_out key must be present even when empty.'
		);
		$this->assertIsArray( $result['comparison']['dropped_out'] );

		// Totals changes carry the same direction / amount / percent shape.
		$this->assertArrayHasKey( 'net_revenue', $result['comparison']['changes'] );
		$this->assertArrayHasKey( 'direction', $result['comparison']['changes']['net_revenue'] );
		$this->assertArrayHasKey( 'amount', $result['comparison']['changes']['net_revenue'] );
		$this->assertArrayHasKey( 'percent', $result['comparison']['changes']['net_revenue'] );
		$this->assertSame( 'up', $result['comparison']['changes']['net_revenue']['direction'] );
	}

	/**
	 * The three dimensions added after the 2026-04-16 demo signal —
	 * term / medium / content — each group correctly and surface the
	 * expected seeded values. Smoke-level coverage; the underlying
	 * math is shared with the source dimension exercised above.
	 */
	public function test_term_medium_content_dimensions() {
		// term — only B/B2 set utm_term=shoes ($100 + $50).
		$term  = $this->run_ability( array( 'group_by' => 'term' ) );
		$shoes = $this->find_group( $term['top_groups'], 'shoes' );
		$this->assertNotNull( $shoes, 'term=shoes should be present (added post-2026-04-16 demo).' );
		$this->assertSame( 150.00, (float) $shoes['net_revenue'] );

		// medium — B/B2=cpc ($150), C=social ($60), D=email ($80). phpcs:ignore Squiz.PHP.CommentedOutCode.Found -- Fixture summary, not commented-out code; sniff mis-fires on `$var=value` tokens.
		$medium = $this->run_ability( array( 'group_by' => 'medium' ) );
		$this->assertNotNull( $this->find_group( $medium['top_groups'], 'cpc' ) );
		$this->assertNotNull( $this->find_group( $medium['top_groups'], 'social' ) );
		$this->assertNotNull( $this->find_group( $medium['top_groups'], 'email' ) );
		$this->assertSame(
			150.00,
			(float) $this->find_group( $medium['top_groups'], 'cpc' )['net_revenue']
		);

		// content — only B/B2 set utm_content=hero ($100 + $50).
		$content = $this->run_ability( array( 'group_by' => 'content' ) );
		$hero    = $this->find_group( $content['top_groups'], 'hero' );
		$this->assertNotNull( $hero, 'content=hero should be present (added post-2026-04-16 demo).' );
		$this->assertSame( 150.00, (float) $hero['net_revenue'] );
	}

	/**
	 * `orderby` whitelist — orders_count and avg_order_value move
	 * the top-of-list deterministically. Using group_by=channel so
	 * the per-group mix is unambiguous: Paid Search has the highest
	 * orders_count (B + B2 = 2 orders), Email has the highest AOV
	 * ($80, the only single-order group that also beats Paid Search's
	 * $75 per-order average).
	 */
	public function test_orderby_variants() {
		$by_orders = $this->run_ability(
			array(
				'group_by' => 'channel',
				'orderby'  => 'orders_count',
			)
		);
		$this->assertSame( 'orders_count', $by_orders['orderby'] );
		$this->assertSame(
			'Paid Search',
			$by_orders['top_groups'][0]['key'],
			'Paid Search has 2 paid orders (B + B2); every other channel has 1.'
		);
		$this->assertSame( 2, (int) $by_orders['top_groups'][0]['orders_count'] );

		$by_aov = $this->run_ability(
			array(
				'group_by' => 'channel',
				'orderby'  => 'avg_order_value',
			)
		);
		$this->assertSame( 'avg_order_value', $by_aov['orderby'] );
		$this->assertSame(
			'Email',
			$by_aov['top_groups'][0]['key'],
			'Email AOV is $80; Paid Search AOV is $75 (= ($100 + $50) / 2).'
		);
		$this->assertSame( 80.00, (float) $by_aov['top_groups'][0]['avg_order_value'] );
	}

	/**
	 * Per-group pipeline_revenue picks up on-hold orders tagged to the
	 * same attribution group. admin_equivalent_revenue sums paid +
	 * on-hold + refunded for the group. Fixture:
	 *   - google: paid $150 (B+B2), on-hold $50 (G) → pipeline 50,
	 *     admin_equivalent 200 (3 orders).
	 *   - facebook: paid $60 (C), refunded $30 (H) → pipeline 0,
	 *     admin_equivalent 90 (2 orders).
	 */
	public function test_per_group_pipeline_and_admin_equivalent() {
		$result = $this->run_ability( array( 'group_by' => 'source' ) );

		$google = $this->find_group( $result['top_groups'], 'google' );
		$this->assertNotNull( $google );
		$this->assertSame( 50.00, (float) $google['pipeline_revenue'], 'G on-hold $50 tagged google.' );
		$this->assertSame( 1, (int) $google['pipeline_orders_count'] );
		$this->assertSame(
			200.00,
			(float) $google['admin_equivalent_revenue'],
			'B $100 + B2 $50 paid + G $50 on-hold = $200 across admin statuses.'
		);
		$this->assertSame( 3, (int) $google['admin_equivalent_orders_count'] );

		$facebook = $this->find_group( $result['top_groups'], 'facebook' );
		$this->assertNotNull( $facebook );
		$this->assertSame( 0.00, (float) $facebook['pipeline_revenue'], 'No on-hold order for facebook.' );
		$this->assertSame( 0, (int) $facebook['pipeline_orders_count'] );
		$this->assertSame(
			90.00,
			(float) $facebook['admin_equivalent_revenue'],
			'C $60 paid + H $30 refunded = $90; refunded is an admin status.'
		);
		$this->assertSame( 2, (int) $facebook['admin_equivalent_orders_count'] );

		// Top-level pipeline block carries the same totals summed across all groups.
		$this->assertSame( 50.00, (float) $result['pipeline']['revenue'] );
		$this->assertSame( 1, (int) $result['pipeline']['orders_count'] );

		// Top-level admin_equivalent: paid $340 + on-hold $50 + refunded $30 = $420 / 8 orders.
		$this->assertSame( 420.00, (float) $result['admin_equivalent']['revenue'] );
		$this->assertSame( 8, (int) $result['admin_equivalent']['orders_count'] );
	}

	/**
	 * Per-group `new_customers` / `returning_customers` count orders
	 * by the creation-time returning_customer flag on each row. B2
	 * is B's second paid order, so it lands with flag=1; B's first
	 * order is flag=0. google should therefore show new=1 and
	 * returning=1. facebook / klaviyo / Direct all have single first
	 * orders so new=1, returning=0.
	 */
	public function test_per_group_new_and_returning_customers() {
		$result = $this->run_ability( array( 'group_by' => 'source' ) );

		$google = $this->find_group( $result['top_groups'], 'google' );
		$this->assertNotNull( $google );
		$this->assertSame( 1, (int) $google['new_customers'], 'B first order → flag=0.' );
		$this->assertSame(
			1,
			(int) $google['returning_customers'],
			'B2 is B\'s second paid order in the period → flag=1.'
		);

		$facebook = $this->find_group( $result['top_groups'], 'facebook' );
		$this->assertNotNull( $facebook );
		$this->assertSame( 1, (int) $facebook['new_customers'] );
		$this->assertSame( 0, (int) $facebook['returning_customers'] );

		$klaviyo = $this->find_group( $result['top_groups'], 'klaviyo' );
		$this->assertNotNull( $klaviyo );
		$this->assertSame( 1, (int) $klaviyo['new_customers'] );
		$this->assertSame( 0, (int) $klaviyo['returning_customers'] );
	}

	/**
	 * `attribution_coverage_percent` on totals is
	 * attributed_orders / total_paid_orders × 100.
	 * `share_of_revenue_percent` on each top_groups row is the group's
	 * paid net_revenue / total paid net_revenue × 100 — pre-computed
	 * so Claude doesn't divide by hand (canonical rule per
	 * ANALYTICS-SKILLS-MAP.md § Preventing AI Hallucination).
	 *
	 * Fixture paid totals: $340 across 6 orders. Source-attributed:
	 * 4 orders (B, B2, C, D). Coverage = 4/6 = 66.7%.
	 */
	public function test_coverage_and_share_percentages() {
		$result = $this->run_ability( array( 'group_by' => 'source' ) );

		$this->assertSame( 340.00, (float) $result['totals']['net_revenue'] );
		$this->assertSame( 6, (int) $result['totals']['total_paid_orders'] );
		$this->assertSame( 4, (int) $result['totals']['attributed_orders'] );
		$this->assertSame( 2, (int) $result['totals']['unattributed_orders'] );
		$this->assertSame( 66.7, (float) $result['totals']['attribution_coverage_percent'] );

		$google = $this->find_group( $result['top_groups'], 'google' );
		$this->assertSame( 44.1, (float) $google['share_of_revenue_percent'], '150 / 340 = 44.12%.' );

		$klaviyo = $this->find_group( $result['top_groups'], 'klaviyo' );
		$this->assertSame( 23.5, (float) $klaviyo['share_of_revenue_percent'], '80 / 340 = 23.53%.' );

		$facebook = $this->find_group( $result['top_groups'], 'facebook' );
		$this->assertSame( 17.6, (float) $facebook['share_of_revenue_percent'], '60 / 340 = 17.65%.' );

		$unassigned = $this->find_group( $result['top_groups'], '(Unassigned)' );
		$this->assertSame( 14.7, (float) $unassigned['share_of_revenue_percent'], '50 / 340 = 14.71%.' );
	}

	/**
	 * Per-group `items_sold` sums `num_items_sold` from wc_order_stats
	 * across paid parent orders tagged to the group. B1 carries 2 ×
	 * widget and B2 carries 1 × widget — both attributed to google
	 * via Paid Search / google / cpc / brand / shoes / hero / desktop.
	 * Other seeded orders carry no line items, so their items_sold
	 * stays 0 and the google row is the only one that moves.
	 *
	 * Totals.items_sold is the same SUM over the full paid-parent set,
	 * so it matches google's value (no other paid orders carry items).
	 */
	public function test_per_group_items_sold_from_line_items() {
		$result = $this->run_ability( array( 'group_by' => 'source' ) );

		$google = $this->find_group( $result['top_groups'], 'google' );
		$this->assertNotNull( $google );
		$this->assertSame(
			3,
			(int) $google['items_sold'],
			'B1 (2 × widget) + B2 (1 × widget) = 3 items on google.'
		);

		// Other groups — no line items on their paid orders.
		$facebook = $this->find_group( $result['top_groups'], 'facebook' );
		$this->assertNotNull( $facebook );
		$this->assertSame( 0, (int) $facebook['items_sold'] );

		$klaviyo = $this->find_group( $result['top_groups'], 'klaviyo' );
		$this->assertNotNull( $klaviyo );
		$this->assertSame( 0, (int) $klaviyo['items_sold'] );

		$unassigned = $this->find_group( $result['top_groups'], '(Unassigned)' );
		$this->assertNotNull( $unassigned );
		$this->assertSame( 0, (int) $unassigned['items_sold'] );

		// Totals items_sold is the SUM across paid parent orders; only
		// B1 + B2 carry line items, so totals = 3.
		$this->assertSame( 3, (int) $result['totals']['items_sold'] );
	}

	/**
	 * Per-group `refunds` column is populated from refund sub-orders
	 * (parent_id != 0) via the LEFT JOIN on the refund row's OWN
	 * attribution meta. wc_create_refund() does NOT copy the parent's
	 * attribution meta, so every refund row has NULL for every
	 * dimension and groups under (Unassigned).
	 *
	 * Fixture: one refund sub-order off B1 (parent was google-tagged)
	 * for 1 × widget at −£50. The controller reports that £50 under
	 * (Unassigned).refunds; google.refunds stays 0. This pins the
	 * current behaviour loudly — a future rewrite that inherits parent
	 * meta onto refund rows would silently move refund amounts between
	 * groups and this test would catch it.
	 */
	public function test_per_group_refunds_land_on_unassigned_not_parent_group() {
		$result = $this->run_ability( array( 'group_by' => 'source' ) );

		$google = $this->find_group( $result['top_groups'], 'google' );
		$this->assertNotNull( $google );
		$this->assertSame(
			0.00,
			(float) $google['refunds'],
			'Parent B1 is google-tagged, but wc_create_refund does not copy attribution meta onto the refund sub-order — so google.refunds is 0.'
		);

		$unassigned = $this->find_group( $result['top_groups'], '(Unassigned)' );
		$this->assertNotNull( $unassigned );
		$this->assertSame(
			50.00,
			(float) $unassigned['refunds'],
			'Refund sub-order has no attribution meta of its own — the £50 refund amount lands in (Unassigned).refunds.'
		);
	}

	/**
	 * E6: pipeline_customers counts distinct on-hold customers per group.
	 * Customer G is on-hold $50 with google/cpc attribution — the only
	 * pipeline order in the fixture. google should carry
	 * pipeline_customers=1, every other group pipeline_customers=0.
	 * Top-level pipeline block carries the same total.
	 */
	public function test_per_group_pipeline_customers_count() {
		$result = $this->run_ability( array( 'group_by' => 'source' ) );

		$google = $this->find_group( $result['top_groups'], 'google' );
		$this->assertNotNull( $google );
		$this->assertSame( 1, (int) $google['pipeline_customers'], 'Customer G on-hold, google-tagged.' );

		$facebook = $this->find_group( $result['top_groups'], 'facebook' );
		$this->assertNotNull( $facebook );
		$this->assertSame( 0, (int) $facebook['pipeline_customers'], 'No on-hold customer on facebook.' );

		$this->assertSame( 1, (int) $result['pipeline']['customers'], 'Top-level pipeline.customers = 1.' );
	}

	/**
	 * E6: share_of_pipeline_revenue_percent is each group's pipeline
	 * revenue / totals.pipeline.revenue * 100, pre-computed.
	 * pipeline_over_index_points is pipeline_share − paid_share.
	 *
	 * Fixture: google's pipeline revenue is $50 (Customer G) and
	 * totals.pipeline.revenue is also $50 — google is 100% of
	 * pipeline. google's paid share is 44.1% (150/340). Over-index =
	 * 100 − 44.1 = 55.9 points — a strong positive signal.
	 *
	 * Other groups: 0 pipeline revenue → share_of_pipeline = 0.0,
	 * over_index = 0.0 − their paid share (negative).
	 */
	public function test_per_group_pipeline_share_and_over_index_populated() {
		$result = $this->run_ability( array( 'group_by' => 'source' ) );

		$google = $this->find_group( $result['top_groups'], 'google' );
		$this->assertNotNull( $google );
		$this->assertSame(
			100.0,
			(float) $google['share_of_pipeline_revenue_percent'],
			'google is 100% of pipeline revenue (only pipeline group).'
		);
		$this->assertSame(
			55.9,
			(float) $google['pipeline_over_index_points'],
			'google over-index = 100 − 44.1 = 55.9 points (positive → over-contributing to pipeline).'
		);

		$facebook = $this->find_group( $result['top_groups'], 'facebook' );
		$this->assertNotNull( $facebook );
		$this->assertSame(
			0.0,
			(float) $facebook['share_of_pipeline_revenue_percent'],
			'facebook has no pipeline revenue → 0.0% of pipeline.'
		);
		$this->assertSame(
			-17.6,
			(float) $facebook['pipeline_over_index_points'],
			'facebook over-index = 0 − 17.6 = -17.6 (under-indexed on pipeline).'
		);
	}

	/**
	 * E6: when totals.pipeline.revenue is 0 (no on-hold orders in the
	 * period), share_of_pipeline_revenue_percent and
	 * pipeline_over_index_points are both null on every row — NOT 0.
	 * The distinction matters: null = "there's nothing on hold anywhere"
	 * lets Claude suppress the diagnostic narrative entirely;
	 * 0 = "this group has 0% of real pipeline" would mislead.
	 *
	 * September 2025 has Customer A's paid $50 but no on-hold orders.
	 */
	public function test_per_group_pipeline_share_null_when_no_pipeline() {
		$result = $this->run_ability(
			array(
				'date_start' => '2025-09-01',
				'date_end'   => '2025-09-30',
				'group_by'   => 'source',
			)
		);

		$this->assertSame( 0, (int) $result['pipeline']['orders_count'], 'No on-hold orders in September.' );
		$this->assertSame( 0.00, (float) $result['pipeline']['revenue'] );
		$this->assertSame( 0, (int) $result['pipeline']['customers'] );

		$this->assertNotEmpty( $result['top_groups'], 'September has paid orders — top_groups should still populate.' );
		foreach ( $result['top_groups'] as $row ) {
			$this->assertNull(
				$row['share_of_pipeline_revenue_percent'],
				"share_of_pipeline_revenue_percent must be null on {$row['key']} when totals.pipeline.revenue = 0."
			);
			$this->assertNull(
				$row['pipeline_over_index_points'],
				"pipeline_over_index_points must be null on {$row['key']} when totals.pipeline.revenue = 0."
			);
		}
	}
}
