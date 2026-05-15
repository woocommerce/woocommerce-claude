<?php
/**
 * Integration tests — wc-analytics/get-revenue-summary.
 *
 * Pins the invariants around AnalyticsController::query_revenue_metrics() —
 * the principled three-view split (paid net of refunds / pipeline /
 * admin_equivalent), the refund netting driven by refund sub-orders,
 * and the comparison block's changes payload.
 *
 * What's pinned:
 *
 *   - `metrics.net_sales` nets paid parent-order totals against refund
 *     sub-orders (parent_id != 0, total_sales < 0), producing the
 *     principled "what did paid customers net pay us" figure.
 *   - `metrics.refunds` comes from refund sub-orders with total_sales
 *     < 0 — it's the absolute value of those negative sums. A
 *     status=wc-refunded main order does NOT populate this field.
 *   - `metrics.average_order_value` uses net_total > 0 as its
 *     denominator filter, so 100%-coupon paid orders don't drag the
 *     average down.
 *   - `metrics.total_customers` = COUNT(DISTINCT customer_id) across
 *     paid parent orders only.
 *   - `pipeline` block sums on-hold parent orders; `admin_equivalent`
 *     sums paid + on-hold + refunded parents + refund sub-orders
 *     (the WC Admin Reports sum convention).
 *   - `compare=true` emits a `comparison` block with `changes`
 *     carrying per-metric `direction / amount / percent` deltas.
 *   - Empty period produces a `note` and zero metrics.
 *
 * Fixture shape (period 2025-10-01..2025-10-31, prior 2025-08-31..2025-09-30):
 *
 *   One simple product P1 at £30.
 *
 *   Current-period orders:
 *     O1: 2×P1 (£60), paid, 2025-10-05, Customer A.
 *     O2: 1×P1 (£30), paid, 2025-10-10, Customer B.
 *     O3: 1×P1 (£30), paid, 2025-10-15, Customer C.
 *     O4: 1×P1 (£30), on-hold, 2025-10-18, Customer D.
 *     O5: 1×P1 (£30), refunded main-order, 2025-10-20, Customer E.
 *     O6: refund sub-order of O1, 1×P1 (−£30) via wc_create_refund, 2025-10-22.
 *     O7: 1×P1 (£30), pending, 2025-10-25, Customer F.
 *          Pending is outside the admin-equivalent status set, so it
 *          doesn't appear in primary/admin counts — it's here to
 *          exercise the WHERE's admin-or-refund-sub filter (pending
 *          should get excluded) without polluting totals.
 *
 *   Prior period: O8 1×P1 (£30), paid, 2025-09-15.
 *
 * Paid current-period totals:
 *   orders_count = 3    (O1+O2+O3)
 *   net_sales    = £90  (£60+£30+£30 paid + −£30 refund sub = £90)
 *   total_sales  = £90  (same — no tax/shipping in fixture)
 *   items_sold   = 4    (2+1+1)
 *   AOV          = £40  (£120 paid / 3 paid orders, refunds excluded)
 *   refunds      = £30  (abs of O6 negative)
 *   refund_count = 1    (O6)
 *   total_customers = 3 (A, B, C)
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the get-revenue-summary ability.
 */
class Test_Get_Revenue_Summary extends WP_UnitTestCase {

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
	 * O1 stored on the test instance so the refund seeder can reference
	 * it as the parent order.
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

		// Prior-period paid order (for compare baseline).
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

		// Refund sub-order against O1: 1 × P1.
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

		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_revenue_summary(
			$input['period'],
			$input['date_start'],
			$input['date_end'],
			$input['compare']
		);
		$this->assertFalse(
			is_wp_error( $result ),
			'fetch_revenue_summary returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		return $result;
	}

	/**
	 * Primary metrics cover the paid-orders aggregate plus refund
	 * netting at the order level. Pending (O7) and refunded main (O5)
	 * and on-hold (O4) stay out of orders_count / net_sales / AOV /
	 * total_customers.
	 */
	public function test_primary_metrics_cover_paid_with_refund_netting() {
		$result = $this->run_ability();

		$m = $result['metrics'];
		$this->assertSame( 3, (int) $m['orders_count'], 'Paid parent orders: O1, O2, O3.' );
		$this->assertSame(
			90.00,
			(float) $m['net_sales'],
			'Paid £120 (O1+O2+O3) + refund sub-order −£30 = £90 net.'
		);
		$this->assertSame( 90.00, (float) $m['total_sales'] );
		$this->assertSame( 4, (int) $m['items_sold'], '2 + 1 + 1 paid items.' );
		$this->assertSame(
			40.00,
			(float) $m['average_order_value'],
			'£120 / 3 paid orders. net_total > 0 filter excludes pending/refunds.'
		);
		$this->assertSame( 3, (int) $m['total_customers'], 'Distinct paid customer_ids: A, B, C.' );
		$this->assertSame( 0.00, (float) $m['taxes'] );
		$this->assertSame( 0.00, (float) $m['shipping'] );
	}

	/**
	 * Per-period refunds come from refund sub-orders. The wc-refunded
	 * main-order status does NOT populate refunds — it's an
	 * admin_equivalent signal only.
	 */
	public function test_refunds_populated_from_sub_orders_only() {
		$result = $this->run_ability();

		$this->assertSame(
			30.00,
			(float) $result['metrics']['refunds'],
			'O6 refund sub-order (−£30) → refunds = abs(−£30).'
		);
		$this->assertSame(
			1,
			(int) $result['metrics']['refund_count'],
			'One refund sub-order row with total_sales < 0.'
		);

		// Sanity: O5 (status=wc-refunded main order) sits in admin_equivalent
		// but must NOT double-count into refunds.
		$this->assertNotSame(
			60.00,
			(float) $result['metrics']['refunds'],
			'refunds must NOT also sum O5 (the wc-refunded main order).'
		);
	}

	/**
	 * Three-view split: `pipeline` is on-hold-only, `admin_equivalent`
	 * sums paid + on-hold + refunded parents + refund sub-orders
	 * (WC Admin Reports convention).
	 *
	 * Pipeline: O4 only → £30 / 1 order.
	 * Admin equivalent: £60 (O1) + £30 (O2) + £30 (O3) + £30 (O4 on-hold)
	 *   + £30 (O5 refunded main) + (−£30) (O6 refund sub) = £150.
	 * Admin orders (parent_id=0 in admin statuses): O1–O5 = 5 orders.
	 * Pending (O7) is NOT in admin statuses so does not contribute.
	 */
	public function test_pipeline_and_admin_equivalent_blocks() {
		$result = $this->run_ability();

		$this->assertSame( 30.00, (float) $result['pipeline']['revenue'] );
		$this->assertSame( 1, (int) $result['pipeline']['orders_count'] );
		$this->assertArrayHasKey( 'definition', $result['pipeline'] );

		$this->assertSame(
			150.00,
			(float) $result['admin_equivalent']['revenue'],
			'£60 + £30 + £30 paid + £30 on-hold + £30 refunded main + (−£30) refund sub = £150.'
		);
		$this->assertSame(
			5,
			(int) $result['admin_equivalent']['orders_count'],
			'5 admin-status parent orders (paid, on-hold, refunded). Pending excluded.'
		);
	}

	/**
	 * Setting compare=true emits the comparison block with per-metric
	 * changes. Previous period has one paid order at £30. Current
	 * orders_count is 3; change.amount=2, .percent=200.0,
	 * .direction=up.
	 */
	public function test_comparison_changes_block_populated() {
		$result = $this->run_ability( array( 'compare' => true ) );

		$this->assertIsArray( $result['comparison'] );
		$this->assertSame( '2025-08-31', $result['comparison']['period']['start'] );
		$this->assertSame( '2025-09-30', $result['comparison']['period']['end'] );

		$prev = $result['comparison']['metrics'];
		$this->assertSame( 1, (int) $prev['orders_count'] );
		$this->assertSame( 30.00, (float) $prev['net_sales'] );

		$changes = $result['comparison']['changes'];
		$this->assertArrayHasKey( 'orders_count', $changes );
		$this->assertSame( 'up', $changes['orders_count']['direction'] );
		$this->assertSame( 2.0, (float) $changes['orders_count']['amount'] );
		$this->assertSame( 200.0, (float) $changes['orders_count']['percent'] );

		$this->assertArrayHasKey( 'net_sales', $changes );
		$this->assertSame( 'up', $changes['net_sales']['direction'] );
		$this->assertSame( 60.00, (float) $changes['net_sales']['amount'], '90 − 30 = 60.' );
		$this->assertSame( 200.0, (float) $changes['net_sales']['percent'] );
	}

	/**
	 * Empty date range produces a `note` with no completed orders text
	 * and zeroed primary metrics.
	 */
	public function test_empty_period_produces_note() {
		$result = $this->run_ability(
			array(
				'date_start' => '2024-01-01',
				'date_end'   => '2024-01-31',
			)
		);

		$this->assertArrayHasKey( 'note', $result );
		$this->assertStringContainsString( 'No completed orders', $result['note'] );
		$this->assertSame( 0, (int) $result['metrics']['orders_count'] );
		$this->assertSame( 0.00, (float) $result['metrics']['net_sales'] );
		$this->assertSame( 0, (int) $result['metrics']['total_customers'] );
	}
}
