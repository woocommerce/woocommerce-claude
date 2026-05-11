<?php
/**
 * Integration tests — wc-analytics/get-coupon-performance.
 *
 * Pins the invariants around AnalyticsController::fetch_coupon_performance()
 * and the two underlying queries (totals + groups). The skill follows the
 * revenue-breakdown three-view pattern but groups on coupon_code via
 * wc_order_coupon_lookup + wp_posts (for code) + wp_postmeta
 * (for discount_type + coupon_amount).
 *
 * What's pinned:
 *
 *   - Each top_groups row carries three-view metrics (paid / pipeline /
 *     admin_equivalent), refunds attributed to the parent order's
 *     coupon(s), and pre-computed ratios: refund_rate_percent,
 *     avg_discount_per_order, new_customer_share_percent,
 *     share_of_coupon_revenue_percent, share_of_total_discount_percent.
 *   - Multi-coupon orders contribute their revenue to EACH coupon's
 *     row (documented double-count, same shape as multi-category in
 *     revenue_breakdown). The unduplicated figure lives on
 *     totals.revenue_with_coupon.
 *   - totals.coupon_attachment_rate_percent, totals.avg_discount_per_coupon_order,
 *     totals.avg_order_value_with_coupon, totals.avg_order_value_without_coupon
 *     are pre-computed on the totals block so the model never divides
 *     narratively.
 *   - Refund sub-orders (parent_id != 0) attribute their refund value
 *     to EACH coupon the parent order used, via the CASE parent_id
 *     trick — same refund-attribution pattern as
 *     revenue_breakdown_country_join.
 *   - On-hold orders using a coupon count in the per-row pipeline_revenue
 *     / pipeline_orders_count columns, not the paid columns. The HAVING
 *     clause keeps pipeline-only rows in top_groups so bacs/cheque
 *     merchants can see their coupon pipeline even before it clears.
 *   - compare=true emits per-row change blocks on coupons present in
 *     both periods, direction=new on rows unique to the current period,
 *     and a comparison.dropped_out array.
 *   - Coupon metadata: discount_type and configured coupon_amount come
 *     from wp_postmeta. Deleted coupons with no wp_posts row surface
 *     their numeric ID as the code and coupon_type='unknown'.
 *
 * Fixture shape (current period 2025-10-01..2025-10-31,
 * prior period is the 31 days preceding: 2025-08-31..2025-09-30):
 *
 *   Coupons:
 *     save15 — percent, 15% discount (post_title='save15',
 *              discount_type='percent', coupon_amount=15).
 *     flat5  — fixed_cart, £5 discount (post_title='flat5',
 *              discount_type='fixed_cart', coupon_amount=5).
 *
 *   Products:
 *     Widget @ £10 — every order uses this, varying qty to get the
 *                    per-order totals we want. (Using one product
 *                    keeps the fixture legible; the skill doesn't
 *                    look at products anyway.)
 *
 *   Current-period orders:
 *     A — 2025-10-05, £100, paid, used save15 (£15 discount),
 *         10 × Widget. Customer A is new.
 *     B — 2025-10-07, £60,  paid, used flat5 (£5), 6 × Widget.
 *         Customer B is new.
 *     C — 2025-10-10, £40,  paid, used BOTH save15 (£6) + flat5 (£5),
 *         4 × Widget. Customer C is new. Exercises multi-coupon
 *         double-count.
 *     D — 2025-10-12, £80,  paid, NO coupon, 8 × Widget.
 *         Customer D is a RETURNING customer (seeded via a
 *         prior order in the prior period).
 *     E — 2025-10-15, £50,  ON-HOLD, used save15 (£7.50),
 *         5 × Widget. Exercises pipeline attribution per coupon.
 *     Refund — Order A partially refunded (5 × Widget = £50) on
 *              2025-10-20. Creates a real refund sub-order
 *              (parent_id != 0). A stays `completed` since the
 *              refund is partial. Exercises refund attribution to
 *              save15.
 *
 *   Prior-period orders (for the comparison block):
 *     F — 2025-09-10, £70, paid, used save15 (£10.50), 7 × Widget,
 *         Customer D. (Customer D's prior-period order is what
 *         makes D returning when they place Order D above.)
 *     G — 2025-09-20, £20, paid, used a third coupon oldonly (£2),
 *         2 × Widget, Customer G. Customer G is not seen in current
 *         period, so the coupon `oldonly` is a drop-out candidate.
 *
 * Expected paid totals (current period):
 *   net_revenue                = £280   (A£100 + B£60 + C£40 + D£80)
 *   refunds                    = £50    (A's partial refund)
 *   net_sales                  = £230   (net_revenue − refunds)
 *   total_paid_orders          = 4
 *   items_sold                 = 28     (A 10 + B 6 + C 4 + D 8)
 *   orders_with_coupon         = 3      (A, B, C)
 *   orders_without_coupon      = 1      (D)
 *   coupon_attachment_rate_pct = 75.0   (3/4)
 *   revenue_with_coupon        = £200   (A + B + C, un-duplicated)
 *   revenue_without_coupon     = £80
 *   total_discount_amount      = £31    (A 15 + B 5 + C 11 = 15 + 5 + 6 + 5 = 31)
 *   avg_discount_per_coupon_ord= £10.33 (31/3)
 *   avg_order_value_w_coupon   = £66.67 (200/3)
 *   avg_order_value_wo_coupon  = £80    (80/1)
 *   distinct_coupons_used      = 2      (save15, flat5)
 *   _pipeline_revenue          = £50    (Order E on-hold)
 *   _pipeline_orders_count     = 1
 *   _admin_revenue             = £330   (paid £280 + pipeline £50)
 *   _admin_orders_count        = 5
 *
 * Expected per-coupon paid (save15):
 *   net_revenue                   = £140  (A 100 + C 40)
 *   orders_count                  = 2
 *   items_sold                    = 14    (A 10 + C 4 — E excluded as on-hold)
 *   discount_amount               = £21   (A 15 + C 6)
 *   avg_discount_per_order        = £10.50
 *   new_customers_count           = 2     (A, C both new)
 *   returning_customers_count     = 0
 *   new_customer_share_percent    = 100.0
 *   refunds                       = £50   (A's refund inherits save15)
 *   refund_rate_percent           = 35.7  (50/140 × 100)
 *   pipeline_revenue              = £50   (E)
 *   pipeline_orders_count         = 1
 *   admin_equivalent_revenue      = £190  (paid 140 + pipeline 50)
 *   admin_equivalent_orders_count = 3
 *   avg_order_value               = £70   (140/2)
 *   share_of_coupon_revenue_pct   = 70.0  (140/200)
 *   share_of_total_discount_pct   = 67.7  (21/31)
 *
 * Expected per-coupon paid (flat5):
 *   net_revenue                 = £100  (B 60 + C 40)
 *   orders_count                = 2
 *   discount_amount             = £10   (B 5 + C 5)
 *   avg_discount_per_order      = £5.00
 *   new_customers_count         = 2     (B, C)
 *   refunds                     = £0
 *   refund_rate_percent         = 0.0
 *   share_of_coupon_revenue_pct = 50.0  (100/200)
 *   share_of_total_discount_pct = 32.3  (10/31)
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the get-coupon-performance ability.
 */
class Test_Get_Coupon_Performance extends WP_UnitTestCase {

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
	 * Coupon post ID for `save15`.
	 *
	 * @var int
	 */
	private $coupon_save15;

	/**
	 * Coupon post ID for `flat5`.
	 *
	 * @var int
	 */
	private $coupon_flat5;

	/**
	 * Coupon post ID for `oldonly` — seeded only in the prior period so
	 * it surfaces as a drop-out in the comparison block.
	 *
	 * @var int
	 */
	private $coupon_oldonly;

	/**
	 * Customer D's WP user ID — seeded in the prior period so they're
	 * a returning customer in the current period.
	 *
	 * @var int
	 */
	private $customer_d;

	/**
	 * Widget product ID used by every seeded order. Priced at £10 so
	 * per-order totals fall out of qty (A 10 × £10 = £100, etc.).
	 *
	 * @var int
	 */
	private $widget;

	/**
	 * Customer A's order — retained so the partial refund can be
	 * generated against it in set_up().
	 *
	 * @var \WC_Order
	 */
	private $order_a;

	/**
	 * Seed the fixture shape described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		$this->coupon_save15  = $this->seed_coupon( 'save15', 'percent', 15 );
		$this->coupon_flat5   = $this->seed_coupon( 'flat5', 'fixed_cart', 5 );
		$this->coupon_oldonly = $this->seed_coupon( 'oldonly', 'fixed_cart', 2 );

		$this->widget = $this->seed_simple_product(
			array(
				'name'  => 'Widget',
				'sku'   => 'WIDGET-10',
				'price' => 10,
			)
		);

		// Customer D — seeded FIRST with a prior-period order so they
		// register as returning when they place the current-period
		// Order D below. wc_order_stats.returning_customer is set at
		// sync time by comparing to prior orders for the customer.
		$this->customer_d  = $this->seed_customer();
		$prior_order_for_d = $this->seed_paid_order(
			array(
				'customer_id' => $this->customer_d,
				'total'       => 70.00,
				'date'        => '2025-09-10 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->widget,
						'qty'        => 7,
					),
				),
			)
		);
		$this->attach_coupon_to_order( $prior_order_for_d, $this->coupon_save15, 10.50 );

		// Prior-period Order G — uses `oldonly`, customer not seen again.
		// Creates a drop-out candidate for the comparison block.
		$g       = $this->seed_customer();
		$prior_g = $this->seed_paid_order(
			array(
				'customer_id' => $g,
				'total'       => 20.00,
				'date'        => '2025-09-20 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->widget,
						'qty'        => 2,
					),
				),
			)
		);
		$this->attach_coupon_to_order( $prior_g, $this->coupon_oldonly, 2.00 );

		// Current-period Order A — new customer, save15 £15. qty=10 so
		// total lands at £100 and a 5-unit refund hits £50 cleanly without
		// flipping A's status to wc-refunded.
		$a             = $this->seed_customer();
		$this->order_a = $this->seed_paid_order(
			array(
				'customer_id' => $a,
				'total'       => 100.00,
				'date'        => '2025-10-05 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->widget,
						'qty'        => 10,
					),
				),
			)
		);
		$this->attach_coupon_to_order( $this->order_a, $this->coupon_save15, 15.00 );

		// Current-period Order B — new customer, flat5 £5. qty=6 → £60.
		$b       = $this->seed_customer();
		$order_b = $this->seed_paid_order(
			array(
				'customer_id' => $b,
				'total'       => 60.00,
				'date'        => '2025-10-07 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->widget,
						'qty'        => 6,
					),
				),
			)
		);
		$this->attach_coupon_to_order( $order_b, $this->coupon_flat5, 5.00 );

		// Current-period Order C — new customer, BOTH save15 £6 + flat5 £5.
		// qty=4 → £40.
		$c       = $this->seed_customer();
		$order_c = $this->seed_paid_order(
			array(
				'customer_id' => $c,
				'total'       => 40.00,
				'date'        => '2025-10-10 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->widget,
						'qty'        => 4,
					),
				),
			)
		);
		$this->attach_coupon_to_order( $order_c, $this->coupon_save15, 6.00 );
		$this->attach_coupon_to_order( $order_c, $this->coupon_flat5, 5.00 );

		// Current-period Order D — returning customer (already seen in prior
		// period), NO coupon. qty=8 → £80.
		$this->seed_paid_order(
			array(
				'customer_id' => $this->customer_d,
				'total'       => 80.00,
				'date'        => '2025-10-12 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->widget,
						'qty'        => 8,
					),
				),
			)
		);

		// Current-period Order E — ON-HOLD, uses save15 (£7.50). qty=5 → £50.
		// Exercises pipeline attribution to a coupon.
		$e       = $this->seed_customer();
		$order_e = $this->seed_paid_order(
			array(
				'customer_id' => $e,
				'total'       => 50.00,
				'date'        => '2025-10-15 10:00:00',
				'status'      => 'on-hold',
				'items'       => array(
					array(
						'product_id' => $this->widget,
						'qty'        => 5,
					),
				),
			)
		);
		$this->attach_coupon_to_order( $order_e, $this->coupon_save15, 7.50 );

		// Partial refund on Order A — 5 × Widget = £50 on 2025-10-20.
		// Creates a wc_order_stats row with parent_id = A's order_id.
		$this->seed_refund(
			$this->order_a,
			array( $this->widget => 5 ),
			array(
				'reason' => 'test refund — coupon performance',
				'date'   => '2025-10-20 10:00:00',
			)
		);
	}

	/**
	 * Create a `shop_coupon` post with the discount_type + coupon_amount
	 * postmeta our query reads.
	 *
	 * @param string $code   Coupon code (goes into post_title).
	 * @param string $type   WC discount_type slug (percent / fixed_cart / fixed_product).
	 * @param float  $amount Configured discount value.
	 * @return int Coupon post ID.
	 */
	private function seed_coupon( $code, $type, $amount ) {
		$coupon_id = wp_insert_post(
			array(
				'post_title'  => $code,
				'post_status' => 'publish',
				'post_type'   => 'shop_coupon',
			)
		);
		if ( is_wp_error( $coupon_id ) || 0 === $coupon_id ) {
			$this->fail( 'seed_coupon: wp_insert_post failed.' );
		}
		update_post_meta( $coupon_id, 'discount_type', $type );
		update_post_meta( $coupon_id, 'coupon_amount', (string) $amount );
		return (int) $coupon_id;
	}

	/**
	 * Attach a coupon to an order by writing a row directly into
	 * wc_order_coupon_lookup — the table our query reads.
	 *
	 * We don't use WC's `apply_coupon()` because that recomputes order
	 * totals from the coupon's configured value, which would fight our
	 * explicit per-order total in the fixture. Writing the lookup row
	 * directly lets us express "order X discounted Y by coupon Z" without
	 * retotalling.
	 *
	 * @param \WC_Order $order     The order to attach the coupon to.
	 * @param int       $coupon_id Coupon post ID.
	 * @param float     $discount  Discount amount attributed to this coupon on this order.
	 */
	private function attach_coupon_to_order( $order, $coupon_id, $discount ) {
		global $wpdb;

		$date     = $order->get_date_created();
		$date_str = $date ? $date->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'wc_order_coupon_lookup',
			array(
				'order_id'        => $order->get_id(),
				'coupon_id'       => (int) $coupon_id,
				'date_created'    => $date_str,
				'discount_amount' => (float) $discount,
			),
			array( '%d', '%d', '%s', '%f' )
		);
	}

	/**
	 * Invoke the ability over the fixture period with compare=false
	 * unless overridden.
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
				'limit'      => 10,
				'orderby'    => 'discount_amount',
			),
			$overrides
		);

		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_coupon_performance(
			$input['period'],
			$input['date_start'],
			$input['date_end'],
			$input['compare'],
			$input['limit'],
			$input['orderby']
		);
		$this->assertFalse(
			is_wp_error( $result ),
			'fetch_coupon_performance returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		return $result;
	}

	/**
	 * Locate a top_groups row by coupon_code. Returns null when absent.
	 *
	 * @param array  $groups Top_groups array from the ability result.
	 * @param string $code   Coupon code to locate.
	 * @return array|null
	 */
	private function find_coupon( array $groups, $code ) {
		foreach ( $groups as $row ) {
			if ( isset( $row['coupon_code'] ) && (string) $row['coupon_code'] === $code ) {
				return $row;
			}
		}
		return null;
	}

	// ─── Totals ────────────────────────────────────────────────────

	/**
	 * Top-level totals block carries every pre-computed ratio the
	 * description promises. Pins the full paid totals for the fixture
	 * described in the docblock.
	 */
	public function test_top_level_totals() {
		$result = $this->run_ability();

		$totals = $result['totals'];

		$this->assertSame( 280.00, (float) $totals['net_revenue'], 'Paid net_revenue = A100 + B60 + C40 + D80 = £280.' );
		$this->assertSame( 50.00, (float) $totals['refunds'], 'Refunds = £50 (A partial).' );
		$this->assertSame( 230.00, (float) $totals['net_sales'], 'net_sales = net_revenue − refunds = £230.' );
		$this->assertSame( 4, (int) $totals['total_paid_orders'] );
		$this->assertSame( 28, (int) $totals['items_sold'], 'A 10 + B 6 + C 4 + D 8 = 28.' );

		$this->assertSame( 3, (int) $totals['orders_with_coupon'], 'A, B, C used coupons.' );
		$this->assertSame( 1, (int) $totals['orders_without_coupon'], 'Only D used no coupon.' );
		$this->assertSame( 75.0, (float) $totals['coupon_attachment_rate_percent'], '3/4 paid orders used a coupon.' );

		$this->assertSame( 200.00, (float) $totals['revenue_with_coupon'], 'Un-duplicated A + B + C = £200 (C counts once here).' );
		$this->assertSame( 80.00, (float) $totals['revenue_without_coupon'] );

		$this->assertSame( 31.00, (float) $totals['total_discount_amount'], 'A 15 + B 5 + C (6 + 5) = £31.' );
		$this->assertSame( 10.33, (float) $totals['avg_discount_per_coupon_order'], '31 / 3 = £10.33.' );

		$this->assertSame( 66.67, (float) $totals['avg_order_value_with_coupon'], '200 / 3 = £66.67.' );
		$this->assertSame( 80.00, (float) $totals['avg_order_value_without_coupon'], '80 / 1 = £80.' );

		$this->assertSame( 2, (int) $totals['distinct_coupons_used'], 'save15 + flat5 (on-hold E save15 is already counted via save15).' );
	}

	/**
	 * Pipeline + admin_equivalent sibling blocks capture Order E's
	 * on-hold save15 revenue separately from paid figures. Order E is
	 * £50 on-hold so pipeline sits at £50; admin_equivalent lumps paid +
	 * pipeline = £330 / 5 orders.
	 */
	public function test_pipeline_and_admin_equivalent_sibling_totals() {
		$result = $this->run_ability();

		$this->assertSame( 50.00, (float) $result['pipeline']['revenue'], 'Order E on-hold.' );
		$this->assertSame( 1, (int) $result['pipeline']['orders_count'] );

		$this->assertSame( 330.00, (float) $result['admin_equivalent']['revenue'], 'Paid 280 + pipeline 50.' );
		$this->assertSame( 5, (int) $result['admin_equivalent']['orders_count'] );
	}

	// ─── Per-coupon rows ───────────────────────────────────────────

	/**
	 * The save15 coupon is the biggest by discount (£21) so with the default
	 * orderby=discount_amount it leads the list. Its per-row shape pins
	 * net_revenue, orders_count, items_sold, new-customer counts, and
	 * refund attribution.
	 */
	public function test_save15_row_shape() {
		$result = $this->run_ability();

		$save15 = $this->find_coupon( $result['top_groups'], 'save15' );
		$this->assertNotNull( $save15, 'save15 row missing.' );

		$this->assertSame( 'percent', $save15['coupon_type'], 'discount_type from postmeta.' );
		$this->assertSame( 15.00, (float) $save15['coupon_amount'], 'coupon_amount from postmeta.' );

		$this->assertSame( 140.00, (float) $save15['net_revenue'], 'A100 + C40 = £140 (E excluded as on-hold).' );
		$this->assertSame( 2, (int) $save15['orders_count'] );
		$this->assertSame( 14, (int) $save15['items_sold'], 'A 10 + C 4 (E 5 excluded as on-hold).' );
		$this->assertSame( 70.00, (float) $save15['avg_order_value'], '140 / 2.' );

		$this->assertSame( 21.00, (float) $save15['discount_amount'], 'A 15 + C 6.' );
		$this->assertSame( 10.50, (float) $save15['avg_discount_per_order'], '21 / 2.' );

		$this->assertSame( 2, (int) $save15['new_customers_count'], 'A, C both new.' );
		$this->assertSame( 0, (int) $save15['returning_customers_count'] );
		$this->assertSame( 100.0, (float) $save15['new_customer_share_percent'] );
	}

	/**
	 * Refund attribution — a refund on an order that used save15
	 * contributes to save15's refunds column via the CASE parent_id
	 * trick in the JOIN. Pre-computed refund_rate_percent is £50/£140.
	 */
	public function test_save15_refund_attribution_and_rate() {
		$result = $this->run_ability();

		$save15 = $this->find_coupon( $result['top_groups'], 'save15' );
		$this->assertNotNull( $save15 );
		$this->assertSame( 50.00, (float) $save15['refunds'], 'Order A refund inherits save15 from parent.' );
		$this->assertSame( 35.7, (float) $save15['refund_rate_percent'], '50/140 × 100, pre-computed.' );

		$flat5 = $this->find_coupon( $result['top_groups'], 'flat5' );
		$this->assertNotNull( $flat5 );
		$this->assertSame( 0.00, (float) $flat5['refunds'] );
		$this->assertSame( 0.0, (float) $flat5['refund_rate_percent'], 'flat5 has no refunds — rate=0.' );
	}

	/**
	 * Pipeline attribution — Order E (on-hold, save15) lands in save15's
	 * pipeline_revenue / pipeline_orders_count columns. Paid columns for
	 * save15 stay at the A+C figure (does not include E).
	 */
	public function test_save15_pipeline_attribution() {
		$result = $this->run_ability();

		$save15 = $this->find_coupon( $result['top_groups'], 'save15' );
		$this->assertNotNull( $save15 );
		$this->assertSame( 50.00, (float) $save15['pipeline_revenue'], 'Order E on-hold on save15.' );
		$this->assertSame( 1, (int) $save15['pipeline_orders_count'] );

		// admin_equivalent = paid + pipeline + refunded = 140 + 50 + 0.
		$this->assertSame( 190.00, (float) $save15['admin_equivalent_revenue'] );
		$this->assertSame( 3, (int) $save15['admin_equivalent_orders_count'], 'A + C paid + E on-hold.' );
	}

	// ─── Multi-coupon double-count ─────────────────────────────────

	/**
	 * Order C used BOTH save15 + flat5, so C's £40 revenue appears in
	 * both rows' net_revenue. Sum of per-coupon rows = £240 vs
	 * totals.revenue_with_coupon = £200 — the £40 delta is C's
	 * double-attribution, intentional and documented.
	 */
	public function test_multi_coupon_order_double_counts() {
		$result = $this->run_ability();

		$save15 = $this->find_coupon( $result['top_groups'], 'save15' );
		$flat5  = $this->find_coupon( $result['top_groups'], 'flat5' );
		$this->assertNotNull( $save15 );
		$this->assertNotNull( $flat5 );

		$sum_of_rows = (float) $save15['net_revenue'] + (float) $flat5['net_revenue'];
		$this->assertSame( 240.00, $sum_of_rows, 'save15 140 + flat5 100 = 240 — C double-counted.' );

		$this->assertSame( 200.00, (float) $result['totals']['revenue_with_coupon'], 'revenue_with_coupon is un-duplicated — C counts once.' );
	}

	// ─── Pre-computed shares ───────────────────────────────────────

	/**
	 * The share_of_coupon_revenue_percent field is pre-computed against
	 * the un-duplicated totals.revenue_with_coupon (£200), not against the
	 * sum of visible top_groups. save15 £140 / £200 = 70%. flat5
	 * £100 / £200 = 50%. They sum to >100% because of the multi-coupon
	 * double-count — expected and correct.
	 */
	public function test_share_of_coupon_revenue_percent_is_precomputed() {
		$result = $this->run_ability();

		$save15 = $this->find_coupon( $result['top_groups'], 'save15' );
		$flat5  = $this->find_coupon( $result['top_groups'], 'flat5' );

		$this->assertSame( 70.0, (float) $save15['share_of_coupon_revenue_percent'] );
		$this->assertSame( 50.0, (float) $flat5['share_of_coupon_revenue_percent'] );
	}

	/**
	 * The share_of_total_discount_percent field is pre-computed against
	 * totals.total_discount_amount (£31). save15 £21 / £31 ≈ 67.7%,
	 * flat5 £10 / £31 ≈ 32.3%.
	 */
	public function test_share_of_total_discount_percent_is_precomputed() {
		$result = $this->run_ability();

		$save15 = $this->find_coupon( $result['top_groups'], 'save15' );
		$flat5  = $this->find_coupon( $result['top_groups'], 'flat5' );

		$this->assertSame( 67.7, (float) $save15['share_of_total_discount_percent'] );
		$this->assertSame( 32.3, (float) $flat5['share_of_total_discount_percent'] );
	}

	// ─── Effective campaign cost (discount + refunds) ──────────────

	/**
	 * `effective_campaign_cost` per row = discount_amount + refunds —
	 * the total cash outflow from offering the coupon. Pre-computed so
	 * Claude never narrates the addition ("save15 gave away £21 plus
	 * took back £50 in refunds = £71 effective cost"). See the
	 * EFFECTIVE CAMPAIGN COST block in the ability description.
	 *
	 * Expected: save15 = £21 discount + £50 refunds = £71. flat5 =
	 * £10 discount + £0 refunds = £10.
	 */
	public function test_effective_campaign_cost_is_precomputed() {
		$result = $this->run_ability();

		$save15 = $this->find_coupon( $result['top_groups'], 'save15' );
		$flat5  = $this->find_coupon( $result['top_groups'], 'flat5' );
		$this->assertNotNull( $save15 );
		$this->assertNotNull( $flat5 );

		$this->assertSame( 71.00, (float) $save15['effective_campaign_cost'], 'save15: £21 discount + £50 refunds.' );
		$this->assertSame( 10.00, (float) $flat5['effective_campaign_cost'], 'flat5: £10 discount + £0 refunds.' );
	}

	/**
	 * `effective_cost_to_paid_revenue_percent` per row =
	 * effective_campaign_cost ÷ net_revenue × 100. Pre-computed so Claude
	 * never derives the ratio ("£71 / £140 = 50.7%"). Same zero-guard
	 * convention as refund_rate_percent — paid-revenue-less rows return
	 * 0.0, never "0% cost to revenue".
	 *
	 * Expected: save15 = £71 / £140 × 100 = 50.7. flat5 = £10 / £100
	 * × 100 = 10.0.
	 */
	public function test_effective_cost_to_paid_revenue_percent_is_precomputed() {
		$result = $this->run_ability();

		$save15 = $this->find_coupon( $result['top_groups'], 'save15' );
		$flat5  = $this->find_coupon( $result['top_groups'], 'flat5' );

		$this->assertSame( 50.7, (float) $save15['effective_cost_to_paid_revenue_percent'], '71 / 140 × 100, pre-computed.' );
		$this->assertSame( 10.0, (float) $flat5['effective_cost_to_paid_revenue_percent'], '10 / 100 × 100, pre-computed.' );
	}

	/**
	 * Zero-guard — a pipeline-only coupon (net_revenue = 0) returns
	 * 0.0 for `effective_cost_to_paid_revenue_percent` rather than
	 * exploding on division. `effective_campaign_cost` itself is the
	 * raw sum and is always safe. The SQL's discount_amount aggregate
	 * is gated on paid-status, so a pipeline-only coupon shows
	 * discount_amount = 0 too — which means effective_campaign_cost =
	 * 0 on this row shape, but the pre-compute still executes safely
	 * (no division blow-up).
	 *
	 * Attach a second coupon (`pipelineonly`) to the existing on-hold
	 * Order E so it surfaces as a top_groups row with pipeline revenue
	 * but no paid revenue. Paid totals don't move — save15/flat5
	 * assertions in other tests stay green.
	 */
	public function test_effective_cost_percent_zero_guards_on_pipeline_only_row() {
		$pipeline_only = $this->seed_coupon( 'pipelineonly', 'fixed_cart', 3 );

		// Find the on-hold fixture order via wc_order_stats.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Test fixture lookup.
		$order_e_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT s.order_id FROM {$wpdb->prefix}wc_order_stats s WHERE s.status = %s LIMIT 1",
				'wc-on-hold'
			)
		);
		$this->assertGreaterThan( 0, $order_e_id, 'Expected an on-hold order in the fixture.' );

		$order_e = wc_get_order( $order_e_id );
		$this->attach_coupon_to_order( $order_e, $pipeline_only, 3.00 );

		$result = $this->run_ability();

		$pipeline_row = $this->find_coupon( $result['top_groups'], 'pipelineonly' );
		$this->assertNotNull( $pipeline_row, 'pipelineonly row missing — HAVING clause should keep pipeline-only rows.' );

		// Paid columns are zero — the coupon was only ever attached to
		// an on-hold order, and the SQL's paid-status gates mean discount,
		// refunds, and net_revenue all fall to 0 on the paid view.
		$this->assertSame( 0.00, (float) $pipeline_row['net_revenue'], 'Pipeline-only coupon has no paid revenue.' );
		$this->assertSame( 0.00, (float) $pipeline_row['discount_amount'], 'Pipeline discount is gated out of paid aggregate.' );
		$this->assertSame( 0.00, (float) $pipeline_row['refunds'] );
		$this->assertSame( 50.00, (float) $pipeline_row['pipeline_revenue'], 'Pipeline revenue from Order E (£50 on-hold).' );

		// effective_campaign_cost = discount_amount + refunds = 0 + 0 = 0.
		// The raw sum is defined on any row shape — the real work of
		// the zero-guard is on the percent field, where net_revenue = 0
		// would otherwise divide by zero.
		$this->assertSame( 0.00, (float) $pipeline_row['effective_campaign_cost'], 'Raw sum is safe — 0 + 0.' );
		$this->assertSame( 0.0, (float) $pipeline_row['effective_cost_to_paid_revenue_percent'], 'Zero-guard on denominator.' );
	}

	// ─── Orderby ───────────────────────────────────────────────────

	/**
	 * With orderby=net_revenue save15 still leads (£140 > £100) but the
	 * response's `orderby` field flips to the requested column.
	 */
	public function test_orderby_net_revenue() {
		$result = $this->run_ability( array( 'orderby' => 'net_revenue' ) );

		$this->assertSame( 'net_revenue', $result['orderby'] );
		$this->assertSame( 'save15', $result['top_groups'][0]['coupon_code'] );
		$this->assertSame( 'flat5', $result['top_groups'][1]['coupon_code'] );
	}

	/**
	 * With orderby=orders_count save15 (2 orders) and flat5 (2 orders)
	 * tie — but orderby still whitelists and the sort stays deterministic
	 * via the implicit tie-breaker on the ORDER BY (MySQL's stable
	 * ordering is preserved here by GROUP BY order). Assert only that
	 * both rows appear and the orderby field echoes correctly.
	 */
	public function test_orderby_orders_count() {
		$result = $this->run_ability( array( 'orderby' => 'orders_count' ) );

		$this->assertSame( 'orders_count', $result['orderby'] );
		$codes = array_map(
			function ( $row ) {
				return $row['coupon_code'];
			},
			$result['top_groups']
		);
		$this->assertContains( 'save15', $codes );
		$this->assertContains( 'flat5', $codes );
	}

	// ─── Comparison ────────────────────────────────────────────────

	/**
	 * With compare=true the response emits a comparison block. save15
	 * (present in both periods — F in prior, A+C+E in current) carries a
	 * change block. flat5 (current only) carries direction=new. The
	 * prior-period coupon `oldonly` falls into comparison.dropped_out.
	 */
	public function test_compare_block() {
		$result = $this->run_ability( array( 'compare' => true ) );

		$this->assertNotNull( $result['comparison'] );

		// 31-day current period → prior is the 31 days preceding:
		// 2025-08-31..2025-09-30.
		$this->assertSame( '2025-08-31', $result['comparison']['period']['start'] );
		$this->assertSame( '2025-09-30', $result['comparison']['period']['end'] );

		$save15 = $this->find_coupon( $result['top_groups'], 'save15' );
		$this->assertArrayHasKey( 'change', $save15 );
		// Prior discount (F) = £10.50, current save15 discount = £21,
		// delta = +£10.50, direction = up. Default orderby is
		// discount_amount so the change metric is the discount column.
		$this->assertSame( 'up', $save15['change']['direction'] );
		$this->assertSame( 10.50, (float) $save15['change']['amount'] );

		$flat5 = $this->find_coupon( $result['top_groups'], 'flat5' );
		$this->assertSame( 'new', $flat5['change']['direction'], 'flat5 wasn\'t used in prior period.' );

		// oldonly dropped out of the current period.
		$dropped_codes = array_map(
			function ( $row ) {
				return $row['coupon_code'];
			},
			$result['comparison']['dropped_out']
		);
		$this->assertContains( 'oldonly', $dropped_codes, 'oldonly must appear in dropped_out.' );
	}

	/**
	 * Compare totals.changes pins per-key direction + amount for the
	 * totals block. Prior totals: £90 revenue (F + G), 2 orders, 2
	 * orders-with-coupon, £12.50 total discount. Current totals:
	 * £280 / 4 / 3 / £31. Biggest delta — orders_with_coupon goes 2→3
	 * (+1 / +50%).
	 */
	public function test_compare_totals_changes() {
		$result = $this->run_ability( array( 'compare' => true ) );

		$this->assertSame( 90.00, (float) $result['comparison']['totals']['net_revenue'], 'Prior F70 + G20 = £90.' );
		$this->assertSame( 2, (int) $result['comparison']['totals']['total_paid_orders'] );
		$this->assertSame( 2, (int) $result['comparison']['totals']['orders_with_coupon'] );
		$this->assertSame( 12.50, (float) $result['comparison']['totals']['total_discount_amount'] );

		$changes = $result['comparison']['changes'];

		$this->assertSame( 'up', $changes['orders_with_coupon']['direction'] );
		$this->assertSame( 1.00, (float) $changes['orders_with_coupon']['amount'] );

		// total_discount_amount delta: 12.50 → 31 = +18.50.
		$this->assertSame( 'up', $changes['total_discount_amount']['direction'] );
		$this->assertSame( 18.50, (float) $changes['total_discount_amount']['amount'] );
	}

	// ─── Empty period ──────────────────────────────────────────────

	/**
	 * No data in range → note is populated, top_groups is empty, and
	 * totals.net_revenue is 0. Mirrors the revenue-breakdown empty-case.
	 */
	public function test_empty_period_produces_note() {
		$result = $this->run_ability(
			array(
				'date_start' => '2020-01-01',
				'date_end'   => '2020-01-31',
			)
		);

		$this->assertStringContainsString( 'No paid orders found', (string) $result['note'] );
		$this->assertSame( array(), $result['top_groups'] );
		$this->assertSame( 0.00, (float) $result['totals']['net_revenue'] );
	}

	// ─── Distinct-coupon counting regression ──────────────────────

	/**
	 * Regression: `distinct_coupons_used` must count every coupon the
	 * period saw, including high-id coupons that only appear alongside
	 * lower-id coupons on multi-coupon orders.
	 *
	 * Pre-fix the totals query collapsed the wc_order_coupon_lookup
	 * rows into one row per order via `MIN(coupon_id) AS coupon_id`,
	 * then counted DISTINCT against that aggregated value. Any coupon
	 * that never appeared as the per-order minimum was silently
	 * dropped from the count.
	 *
	 * Fixture: a coupon created early ("low_id_solo") and a coupon
	 * created later ("high_id_paired"). The first is always alone; the
	 * second never appears alone, only on a multi-coupon order beside
	 * the low-id one. Pre-fix `distinct_coupons_used` returned 1
	 * (high_id_paired was never the MIN, so the count missed it);
	 * post-fix returns 2.
	 *
	 * Period 2025-11-01..2025-11-30 is disjoint from the parent test
	 * fixture's 2025-10 window so this test's orders don't touch any
	 * other assertion.
	 *
	 * Bug source: class-analytics-controller.php query_coupon_totals()
	 * — MIN(coupon_id) per-order subquery.
	 * Codex severity: P3.
	 */
	public function test_distinct_coupons_used_counts_high_id_coupons_in_multi_coupon_orders() {
		// Insert order in IDs in deterministic order so we know which is the
		// "low id" coupon and which is the "high id" — auto_increment moves
		// forward, so the first wp_insert_post call gets the lower ID.
		$low_id_coupon  = $this->seed_coupon( 'low_id_solo', 'fixed_cart', 5 );
		$high_id_coupon = $this->seed_coupon( 'high_id_paired', 'fixed_cart', 3 );
		$this->assertLessThan(
			$high_id_coupon,
			$low_id_coupon,
			'Sanity: low_id_solo must have been created first so its post_id is the smaller of the two — the bug only manifests when the missing coupon is the higher of the two ids.'
		);

		$customer1 = $this->seed_customer();
		$customer2 = $this->seed_customer();

		// P1 — low coupon alone: low_id_solo will be the MIN of its own row.
		$p1 = $this->seed_paid_order(
			array(
				'customer_id' => $customer1,
				'total'       => 50.0,
				'date'        => '2025-11-05 12:00:00',
				'items'       => array(
					array(
						'product_id' => $this->widget,
						'qty'        => 5,
					),
				),
			)
		);
		$this->attach_coupon_to_order( $p1, $low_id_coupon, 5.0 );

		// P2 — both coupons. Pre-fix MIN(coupon_id) collapses to low_id_coupon
		// and high_id_paired never appears in the COUNT(DISTINCT) input.
		$p2 = $this->seed_paid_order(
			array(
				'customer_id' => $customer2,
				'total'       => 80.0,
				'date'        => '2025-11-10 12:00:00',
				'items'       => array(
					array(
						'product_id' => $this->widget,
						'qty'        => 8,
					),
				),
			)
		);
		$this->attach_coupon_to_order( $p2, $low_id_coupon, 5.0 );
		$this->attach_coupon_to_order( $p2, $high_id_coupon, 3.0 );

		$result = $this->run_ability(
			array(
				'date_start' => '2025-11-01',
				'date_end'   => '2025-11-30',
			)
		);

		$this->assertSame(
			2,
			(int) $result['totals']['distinct_coupons_used'],
			'Both coupons used in the window — pre-fix the per-order MIN(coupon_id) aggregation hid high_id_paired because it never appeared as the row minimum, so the COUNT(DISTINCT) only saw low_id_solo and reported 1.'
		);
	}
}
