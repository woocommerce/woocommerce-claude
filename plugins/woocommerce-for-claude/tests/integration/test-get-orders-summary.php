<?php
/**
 * Integration tests — wc-analytics/get-orders-summary.
 *
 * Pins the invariants around AnalyticsController::query_order_metrics(),
 * ::query_status_breakdown(), ::query_value_distribution(),
 * ::query_orders_by_day_hour(), and ::detect_multi_currency(). Same
 * three-view pattern as revenue-summary, but with order-focused
 * aggregates (AOV, items/order, refund counts) and four sibling
 * blocks (status, distribution, heatmap, currency).
 *
 * What's pinned:
 *
 *   - `metrics.orders_count` / `metrics.avg_order_value` /
 *     `metrics.avg_items_per_order` / `metrics.total_items_sold`
 *     cover paid parent orders only; refund sub-orders don't leak in.
 *   - `metrics.orders_with_refunds` counts distinct parent orders
 *     that have a refund sub-order (not the refund count itself —
 *     a single order refunded twice counts once here).
 *   - `status_breakdown` returns all parent-order statuses (paid,
 *     on-hold, refunded, pending, failed) regardless of admin-status
 *     membership, sorted by count desc.
 *   - `value_distribution` emits histogram buckets based on paid
 *     parent orders only, with dynamic bucket boundaries.
 *   - `multi_currency` returns null for a single-currency period.
 *     The harness runs with HPOS enabled (see bootstrap.php), so
 *     `detect_multi_currency()` actually executes its GROUP BY
 *     against wc_orders and short-circuits on `count($rows) <= 1`
 *     rather than on the table-missing guard.
 *   - `compare=true` emits per-metric changes on the same shape as
 *     revenue-summary.
 *
 * Known gap (deliberate, flagged for follow-up):
 *
 *   - `orders_by_day_hour` is exercised at structural level only.
 *     DAYOFWEEK depends on MySQL timezone and date_created storage
 *     — pinning specific day_num values invites flakiness across
 *     harnesses. A follow-up can harden the timezone assumption
 *     and assert on specific cells.
 *   - Multi-currency detection with actual cross-currency orders.
 *     The table-present + single-currency null case is pinned;
 *     the populated-array return needs a second fixture that seeds
 *     orders in different currencies and asserts the currencies
 *     rollup. Left for a later PR.
 *
 * Fixture shape (period 2025-10-01..2025-10-31, prior 2025-08-31..2025-09-30):
 *
 *   One simple product P1 at £30. Same order set as
 *   test-get-revenue-summary.php so the aggregates are comparable.
 *
 *   Current-period orders:
 *     O1: 2×P1 (£60),  paid,     2025-10-05.
 *     O2: 1×P1 (£30),  paid,     2025-10-10.
 *     O3: 1×P1 (£30),  paid,     2025-10-15.
 *     O4: 1×P1 (£30),  on-hold,  2025-10-18.
 *     O5: 1×P1 (£30),  refunded, 2025-10-20.
 *     O6: refund sub-order of O1, 1 × P1 (−£30), 2025-10-22.
 *     O7: 1×P1 (£30),  pending,  2025-10-25.
 *
 *   Prior: O8 1×P1 (£30), paid, 2025-09-15.
 *
 * Paid current-period totals:
 *   orders_count          = 3     (O1+O2+O3)
 *   avg_order_value       = £40   (£120 / 3; net_total > 0 filter)
 *   avg_items_per_order   = 1.3   (4 items / 3 orders)
 *   total_items_sold      = 4     (2+1+1)
 *   orders_with_refunds   = 1     (O1 has O6 as refund sub-order)
 *   refund_count          = 1     (one sub-order with total_sales < 0)
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the get-orders-summary ability.
 */
class Test_Get_Orders_Summary extends WP_UnitTestCase {

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
	 * Product id populated in set_up().
	 *
	 * @var int
	 */
	private $p1;

	/**
	 * O1 reference stored so seed_refund can find the parent order.
	 *
	 * @var \WC_Order
	 */
	private $order_o1;

	/**
	 * Seed the fixture shape described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		$this->p1 = $this->seed_simple_product(
			array(
				'name'  => 'Blue T-Shirt',
				'sku'   => 'SHIRT-BLUE',
				'price' => 30,
			)
		);

		$cust_g = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_g,
				'total'       => 30.00,
				'date'        => '2025-09-15 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
				),
			)
		);

		$cust_a         = $this->seed_customer();
		$this->order_o1 = $this->seed_paid_order(
			array(
				'customer_id' => $cust_a,
				'total'       => 60.00,
				'date'        => '2025-10-05 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 2,
					),
				),
			)
		);

		$cust_b = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_b,
				'total'       => 30.00,
				'date'        => '2025-10-10 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
				),
			)
		);

		$cust_c = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_c,
				'total'       => 30.00,
				'date'        => '2025-10-15 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
				),
			)
		);

		$cust_d = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_d,
				'total'       => 30.00,
				'date'        => '2025-10-18 10:00:00',
				'status'      => 'on-hold',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
				),
			)
		);

		$cust_e = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_e,
				'total'       => 30.00,
				'date'        => '2025-10-20 10:00:00',
				'status'      => 'refunded',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
				),
			)
		);

		$this->seed_refund(
			$this->order_o1,
			array( $this->p1 => 1 ),
			array(
				'reason' => 'Wrong size',
				'date'   => '2025-10-22 10:00:00',
			)
		);

		$cust_f = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_f,
				'total'       => 30.00,
				'date'        => '2025-10-25 10:00:00',
				'status'      => 'pending',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
				),
			)
		);
	}

	/**
	 * Invoke the ability over the fixture period with compare=false.
	 *
	 * @param array $overrides Override input keys.
	 * @return array Ability result.
	 */
	private function run_ability( array $overrides = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$input = array_merge(
			array(
				'period'     => 'last_30_days',
				'date_start' => $this->period_start,
				'date_end'   => $this->period_end,
				'compare'    => false,
			),
			$overrides
		);

		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_orders_summary(
			$input['period'],
			$input['date_start'],
			$input['date_end'],
			$input['compare']
		);
		$this->assertFalse(
			is_wp_error( $result ),
			'fetch_orders_summary returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		return $result;
	}

	/**
	 * Find a status-breakdown row by status slug.
	 *
	 * @param array  $rows   Status-breakdown rows.
	 * @param string $status Status slug to find.
	 * @return array|null
	 */
	private function find_status( array $rows, $status ) {
		foreach ( $rows as $row ) {
			if ( $row['status'] === $status ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Primary metrics cover paid parent orders only. avg_items_per_order
	 * uses total_items_sold / orders_count (4 / 3 = 1.3 to 1 dp).
	 */
	public function test_primary_metrics_cover_paid_only() {
		$result = $this->run_ability();

		$m = $result['metrics'];
		$this->assertSame( 3, (int) $m['orders_count'], 'Paid parent orders: O1, O2, O3.' );
		$this->assertSame(
			40.00,
			(float) $m['avg_order_value'],
			'£120 / 3 paid orders; net_total > 0 filter.'
		);
		$this->assertSame(
			1.3,
			(float) $m['avg_items_per_order'],
			'4 items / 3 orders = 1.33... rounded to 1 dp = 1.3.'
		);
		$this->assertSame( 4, (int) $m['total_items_sold'], '2+1+1 paid items.' );
	}

	/**
	 * The orders_with_refunds field is a COUNT(DISTINCT parent_id) over
	 * refund sub-orders. A single parent with multiple partial refunds
	 * still counts once. refund_count sums the refund rows themselves.
	 */
	public function test_orders_with_refunds_and_refund_count() {
		$result = $this->run_ability();

		$m = $result['metrics'];
		$this->assertSame(
			1,
			(int) $m['orders_with_refunds'],
			'O1 is the only parent with a refund sub-order.'
		);
		$this->assertSame( 1, (int) $m['refund_count'] );
	}

	/**
	 * The status_breakdown includes every parent_id=0 row's status —
	 * paid, on-hold, refunded AND pending (which is outside the
	 * admin-equivalent set and therefore absent from primary metrics).
	 * Sorted by count desc.
	 */
	public function test_status_breakdown_includes_all_statuses() {
		$result = $this->run_ability();

		$this->assertNotEmpty( $result['status_breakdown'] );
		$statuses = array_column( $result['status_breakdown'], 'status' );
		$this->assertContains( 'wc-completed', $statuses );
		$this->assertContains( 'wc-on-hold', $statuses );
		$this->assertContains( 'wc-refunded', $statuses );
		$this->assertContains(
			'wc-pending',
			$statuses,
			'Pending is outside admin statuses but must surface in status_breakdown.'
		);

		$completed = $this->find_status( $result['status_breakdown'], 'wc-completed' );
		$this->assertNotNull( $completed );
		$this->assertSame( 3, (int) $completed['count'] );
		$this->assertSame( 120.00, (float) $completed['net_revenue'] );

		// First row must be the highest-count status.
		$this->assertSame(
			'wc-completed',
			$result['status_breakdown'][0]['status'],
			'Sorted by count desc.'
		);
	}

	/**
	 * The value_distribution emits histogram buckets over paid parent
	 * orders. With net_totals £30, £30, £30, £60 across paid orders,
	 * the buckets span £30..£60 and sum to 3 paid orders of £120.
	 *
	 * Specific bucket boundaries are dynamic — assert shape, paid-
	 * order count sum, and total revenue sum rather than pinned
	 * labels so this doesn't bind to a specific bucket algorithm.
	 */
	public function test_value_distribution_histogram() {
		$result = $this->run_ability();

		$this->assertNotEmpty( $result['value_distribution'] );

		$total_orders  = 0;
		$total_revenue = 0.0;
		foreach ( $result['value_distribution'] as $bucket ) {
			$this->assertArrayHasKey( 'bucket', $bucket );
			$this->assertArrayHasKey( 'orders_count', $bucket );
			$this->assertArrayHasKey( 'total_revenue', $bucket );
			$total_orders  += (int) $bucket['orders_count'];
			$total_revenue += (float) $bucket['total_revenue'];
		}

		$this->assertSame( 3, $total_orders, 'Buckets sum to 3 paid parent orders.' );
		$this->assertSame( 120.00, round( $total_revenue, 2 ), 'Buckets sum to £120 paid revenue.' );
	}

	/**
	 * Multi-currency detection runs against the HPOS wc_orders table
	 * (enabled for the whole test harness in bootstrap.php) and returns
	 * null when the period carries a single currency. Fixture orders
	 * are all in the store's base currency, so the detection path is
	 * fully exercised but short-circuits on the `count($rows) <= 1`
	 * branch, not the `$table_exists` branch.
	 *
	 * Pins both the harness invariant (wc_orders table present) and
	 * the single-currency return contract together so a regression
	 * that ships multi-currency data inadvertently would loudly flip
	 * this result to a populated array.
	 */
	public function test_multi_currency_null_when_single_currency() {
		global $wpdb;

		$wc_orders_row = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders' )
		);
		$this->assertSame(
			$wpdb->prefix . 'wc_orders',
			$wc_orders_row,
			'Harness assumption: HPOS wc_orders table is installed.'
		);

		$result = $this->run_ability();
		$this->assertNull(
			$result['multi_currency'],
			'Fixture is single-currency — detect_multi_currency() returns null after the GROUP BY runs.'
		);
	}

	/**
	 * Setting compare=true stamps the comparison block with
	 * previous-period metrics and per-metric changes. Prior had 1 paid
	 * order; current has 3 → orders_count direction=up, amount=2,
	 * percent=200.
	 */
	public function test_compare_changes_block_populated() {
		$result = $this->run_ability( array( 'compare' => true ) );

		$this->assertIsArray( $result['comparison'] );
		$this->assertSame( '2025-08-31', $result['comparison']['period']['start'] );
		$this->assertSame( '2025-09-30', $result['comparison']['period']['end'] );
		$this->assertSame( 1, (int) $result['comparison']['metrics']['orders_count'] );

		$changes = $result['comparison']['changes'];
		$this->assertSame( 'up', $changes['orders_count']['direction'] );
		$this->assertSame( 2.0, (float) $changes['orders_count']['amount'] );
		$this->assertSame( 200.0, (float) $changes['orders_count']['percent'] );
	}

	/**
	 * E7: pipeline.oldest_order_days and pipeline.age_buckets reflect
	 * how long on-hold orders have been sitting there as of NOW. The
	 * fixture has O4 dated 2025-10-18 — any test run after late 2025
	 * places O4 in the 60d+ bucket. Use ≥ 60 as a lower bound so the
	 * test doesn't drift with calendar time.
	 */
	public function test_pipeline_age_buckets_place_aged_on_hold_in_60d_plus() {
		$result = $this->run_ability();

		$this->assertGreaterThanOrEqual(
			60,
			(int) $result['pipeline']['oldest_order_days'],
			'O4 from 2025-10-18 should be ≥ 60 days old by any sensible test-run date.'
		);

		$buckets = $result['pipeline']['age_buckets'];
		$this->assertSame( 1, (int) $buckets['60d+'], 'O4 lands in 60d+ bucket.' );
		$this->assertSame( 0, (int) $buckets['0-7d'] );
		$this->assertSame( 0, (int) $buckets['8-30d'] );
		$this->assertSame( 0, (int) $buckets['31-60d'] );
	}

	/**
	 * E7 + E8: pipeline diagnostic fields collapse to their empty shape
	 * when there are no on-hold orders in the period. oldest_order_days
	 * is null (distinct from 0 — there is literally no order to age).
	 * Every age bucket is 0. payment_methods is an empty array.
	 *
	 * September 2025 has one paid order (cust_g, 2025-09-15) but no
	 * on-hold orders.
	 */
	public function test_pipeline_diagnostic_empty_when_no_on_hold() {
		$result = $this->run_ability(
			array(
				'date_start' => '2025-09-01',
				'date_end'   => '2025-09-30',
			)
		);

		$this->assertSame( 0, (int) $result['pipeline']['orders_count'] );
		$this->assertNull( $result['pipeline']['oldest_order_days'] );
		$this->assertSame(
			array(
				'0-7d'   => 0,
				'8-30d'  => 0,
				'31-60d' => 0,
				'60d+'   => 0,
			),
			$result['pipeline']['age_buckets']
		);
		$this->assertSame( array(), $result['pipeline']['payment_methods'] );
	}

	/**
	 * E8: pipeline.payment_methods groups on-hold orders by payment
	 * method and pre-computes share_of_pipeline_revenue_percent,
	 * share_of_paid_revenue_percent, and pipeline_over_index_points
	 * against the full paid denominator.
	 *
	 * Fixture O4 has no payment method set → the single on-hold row
	 * surfaces as "(Unassigned)". Paid orders O1+O2+O3 also have no
	 * method, so (Unassigned) = 100% of paid AND 100% of pipeline →
	 * over_index = 0.0 points (proportional, no diagnostic signal).
	 */
	public function test_pipeline_payment_methods_with_default_fixture() {
		$result = $this->run_ability();

		$methods = $result['pipeline']['payment_methods'];
		$this->assertCount( 1, $methods, 'O4 is the only on-hold order; single (Unassigned) row.' );

		$row = $methods[0];
		$this->assertSame( '(Unassigned)', $row['method'] );
		$this->assertSame( 1, (int) $row['orders_count'] );
		$this->assertSame( 30.00, (float) $row['revenue'] );
		$this->assertSame( 100.0, (float) $row['share_of_pipeline_revenue_percent'] );
		$this->assertSame( 100.0, (float) $row['share_of_paid_revenue_percent'] );
		$this->assertSame( 0.0, (float) $row['pipeline_over_index_points'] );
	}

	/**
	 * E8: pipeline_over_index_points surfaces the "failing gateway vs
	 * legitimate slow-pay" signal. BACS has £100 pipeline and zero
	 * paid → very positive over-index (legitimate for BACS). Stripe
	 * has £40 pipeline and £200 paid → negative over-index (cards
	 * shouldn't sit on-hold; any positive value is a failing-gateway
	 * signal, and here Stripe's share of pipeline is much smaller
	 * than its share of paid).
	 *
	 * Denominators (after seeding):
	 *   Pipeline: (Unassigned) £30 + BACS £100 + Stripe £40 = £170
	 *   Paid:     (Unassigned) £120 + Stripe £200 = £320
	 *
	 *   BACS       pipeline_share = 100/170 = 58.8%, paid_share = 0%,    over_index = +58.8
	 *   Stripe     pipeline_share =  40/170 = 23.5%, paid_share = 62.5%, over_index = -39.0
	 *   Unassigned pipeline_share =  30/170 = 17.6%, paid_share = 37.5%, over_index = -19.9
	 */
	public function test_pipeline_payment_methods_over_index_surfaces_diagnostic_signal() {
		$cust_bacs   = $this->seed_customer();
		$cust_stripe = $this->seed_customer();
		$cust_paid   = $this->seed_customer();

		// Seed without `items` so the seeder uses $order->set_total()
		// directly — line-item math would otherwise recalculate the
		// total from p1's £30 unit price and ignore the literal values.
		$this->seed_paid_order(
			array(
				'customer_id'          => $cust_bacs,
				'total'                => 100.00,
				'status'               => 'on-hold',
				'date'                 => '2025-10-19 10:00:00',
				'payment_method'       => 'bacs',
				'payment_method_title' => 'Direct bank transfer',
			)
		);

		$this->seed_paid_order(
			array(
				'customer_id'          => $cust_stripe,
				'total'                => 40.00,
				'status'               => 'on-hold',
				'date'                 => '2025-10-20 10:00:00',
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Stripe',
			)
		);

		// Paid Stripe order to give Stripe a realistic paid baseline —
		// without it, Stripe's paid share would be 0 and the over-index
		// would just be pipeline_share, not the "under-indexed card
		// gateway" signal we want to pin.
		$this->seed_paid_order(
			array(
				'customer_id'          => $cust_paid,
				'total'                => 200.00,
				'status'               => 'completed',
				'date'                 => '2025-10-21 10:00:00',
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Stripe',
			)
		);

		$result    = $this->run_ability();
		$by_method = array();
		foreach ( $result['pipeline']['payment_methods'] as $row ) {
			$by_method[ $row['method'] ] = $row;
		}

		$this->assertArrayHasKey( 'Direct bank transfer', $by_method );
		$bacs = $by_method['Direct bank transfer'];
		$this->assertSame( 1, (int) $bacs['orders_count'] );
		$this->assertSame( 100.00, (float) $bacs['revenue'] );
		$this->assertSame( 58.8, (float) $bacs['share_of_pipeline_revenue_percent'], '100/170.' );
		$this->assertSame( 0.0, (float) $bacs['share_of_paid_revenue_percent'], 'BACS has no paid revenue.' );
		$this->assertSame( 58.8, (float) $bacs['pipeline_over_index_points'], 'BACS over-indexed on pipeline (expected for slow-pay method).' );

		$this->assertArrayHasKey( 'Stripe', $by_method );
		$stripe = $by_method['Stripe'];
		$this->assertSame( 1, (int) $stripe['orders_count'] );
		$this->assertSame( 23.5, (float) $stripe['share_of_pipeline_revenue_percent'], '40/170.' );
		$this->assertSame( 62.5, (float) $stripe['share_of_paid_revenue_percent'], '200/320.' );
		$this->assertSame( -39.0, (float) $stripe['pipeline_over_index_points'], 'Stripe under-indexed on pipeline (expected for card gateway).' );

		$this->assertArrayHasKey( '(Unassigned)', $by_method );
		$unassigned = $by_method['(Unassigned)'];
		$this->assertSame( 17.6, (float) $unassigned['share_of_pipeline_revenue_percent'], '30/170.' );
		$this->assertSame( 37.5, (float) $unassigned['share_of_paid_revenue_percent'], '120/320.' );
		$this->assertSame( -19.9, (float) $unassigned['pipeline_over_index_points'] );
	}

	/**
	 * E7: age_buckets distribute on-hold orders across all four ranges
	 * when their ages span the bucket boundaries. Seeds four on-hold
	 * orders at ~3 / ~15 / ~45 / ~90 days before now — each age lands
	 * firmly inside a distinct bucket with ≥5 days of margin, so a ±1
	 * day MySQL/PHP timezone slip won't reshuffle them.
	 *
	 * Uses a custom date window (today − 120 days to today) that
	 * excludes the fixture's 2025-10-18 O4 — so the four seeded orders
	 * are the entire on-hold set this test sees.
	 */
	public function test_pipeline_age_buckets_distribute_across_all_ranges() {
		$now     = time();
		$days    = array( 3, 15, 45, 90 );
		$cust_id = array();
		foreach ( $days as $offset ) {
			$cust_id[ $offset ] = $this->seed_customer();
			$this->seed_paid_order(
				array(
					'customer_id' => $cust_id[ $offset ],
					'total'       => 50.00,
					'status'      => 'on-hold',
					'date'        => gmdate( 'Y-m-d H:i:s', $now - ( $offset * DAY_IN_SECONDS ) ),
					'items'       => array(
						array(
							'product_id' => $this->p1,
							'qty'        => 1,
						),
					),
				)
			);
		}

		$result = $this->run_ability(
			array(
				'date_start' => gmdate( 'Y-m-d', $now - ( 120 * DAY_IN_SECONDS ) ),
				'date_end'   => gmdate( 'Y-m-d', $now ),
			)
		);

		$buckets = $result['pipeline']['age_buckets'];
		$this->assertSame( 1, (int) $buckets['0-7d'], '3-day-old order → 0-7d bucket.' );
		$this->assertSame( 1, (int) $buckets['8-30d'], '15-day-old order → 8-30d bucket.' );
		$this->assertSame( 1, (int) $buckets['31-60d'], '45-day-old order → 31-60d bucket.' );
		$this->assertSame( 1, (int) $buckets['60d+'], '90-day-old order → 60d+ bucket.' );

		$this->assertGreaterThanOrEqual(
			85,
			(int) $result['pipeline']['oldest_order_days'],
			'Oldest order is ~90 days; allow slack for tz/clock drift.'
		);
		$this->assertLessThanOrEqual(
			95,
			(int) $result['pipeline']['oldest_order_days'],
			'Oldest order is ~90 days; slack bounded.'
		);
	}
}
