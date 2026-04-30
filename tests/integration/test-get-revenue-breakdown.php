<?php
/**
 * Integration tests — wc-analytics/get-revenue-breakdown.
 *
 * Pins the invariants around AnalyticsController::fetch_revenue_breakdown()
 * and the four per-dimension query methods it dispatches to. Each
 * dimension has its own JOIN strategy but every top_groups row must
 * share the same shape.
 *
 * What's pinned:
 *
 *   - `group_by=category` joins wc_order_product_lookup →
 *     term_relationships → term_taxonomy (product_cat) → terms.
 *     Product-level roll-up, so an order with products in multiple
 *     categories contributes its subtotals to each category.
 *   - `group_by=country` joins wc_order_addresses (HPOS) on billing
 *     country. Refund sub-orders inherit the parent's country via a
 *     CASE on parent_id — different from attribution's refund-stays-
 *     unassigned default because the address of a refund is plainly
 *     the parent's.
 *   - `group_by=payment_method` joins wc_orders.payment_method_title
 *     (HPOS), falling back to the method slug when title is empty.
 *   - `group_by=shipping_method` joins a subquery over
 *     woocommerce_order_items (the shared items table) filtered by
 *     order_item_type='shipping', picking one method per order via
 *     MIN(order_item_name). Orders without a shipping line land in
 *     (Unassigned) — honest "no shipping charged" signal for digital
 *     / local-pickup orders.
 *   - Per-row three-view shape (paid / pipeline / admin_equivalent)
 *     applies uniformly across all four dimensions.
 *   - `share_of_revenue_percent` per row is pre-computed against the
 *     full paid revenue denominator — stays correct when
 *     include_unassigned flips or the long tail gets clipped by
 *     limit.
 *   - `totals.coverage_percent` = covered_orders / total_paid_orders,
 *     where covered_orders is dimension-specific (orders with a
 *     categorised product / a billing country / a payment method /
 *     a shipping line).
 *   - `compare=true` emits per-group change blocks on rows present in
 *     both periods, direction=new on rows unique to the current
 *     period, and a comparison.dropped_out array for rows that fell
 *     out.
 *   - `include_unassigned=false` hides the (Unassigned) row on the
 *     order-level dimensions (country / payment_method /
 *     shipping_method). Category ignores the flag — uncategorised
 *     products have no term_relationships row so they never land in
 *     (Unassigned) via an INNER JOIN.
 *   - Refund sub-orders (parent_id != 0) attribute their refund value
 *     to the PARENT order's dimension value for order-level
 *     dimensions (via the parent_id CASE in the JOIN). For category,
 *     the refund product_lookup row joins via its own product_id, so
 *     refund amounts land on the refunded product's categories.
 *
 * Fixture shape (current period 2025-10-01..2025-10-31,
 * prior period 2025-09-01..2025-09-30):
 *
 *   Categories:
 *     Apparel, Electronics (seeded via wp_insert_term).
 *
 *   Products:
 *     Hoodie      — £50, Apparel.
 *     Headphones  — £100, Electronics.
 *     Combo       — £30, in BOTH Apparel and Electronics (tests the
 *                   multi-category case: its product_net_revenue
 *                   contributes to each category's row).
 *
 *   Current-period orders:
 *     A — 2025-10-05, Germany, stripe ("Stripe"), Flat rate,
 *         2 × Hoodie (£100), paid. Two units so the partial refund
 *         below lands cleanly as £50 (1 out of 2) without flipping
 *         the parent order status.
 *     B — 2025-10-07, Germany, stripe ("Stripe"), Flat rate,
 *         1 × Headphones (£100), paid.
 *     C — 2025-10-10, France,  paypal ("PayPal"), Free shipping,
 *         1 × Combo (£30), paid.
 *     D — 2025-10-12, United Kingdom, bacs ("Bank transfer"),
 *         Flat rate, 1 × Hoodie (£50), ON-HOLD — exercises pipeline.
 *     E — 2025-10-15, United Kingdom, stripe ("Stripe"),
 *         Local pickup, 1 × Headphones (£100), paid.
 *     Refund — Order A partially refunded (1 × Hoodie = £50) on
 *              2025-10-20. Creates a real refund sub-order
 *              (parent_id != 0, product_net_revenue = −£50).
 *              Exercises refund attribution on Germany / stripe /
 *              Flat rate / Apparel.
 *
 *   Prior-period order (for the comparison block):
 *     F — 2025-09-10, Germany, stripe ("Stripe"), Flat rate,
 *         1 × Hoodie (£50), paid.
 *
 * Expected paid totals (current period):
 *   net_revenue       = £330 (A £100 + B £100 + C £30 + E £100, gross of refunds)
 *   refunds           = £50  (partial £50 off Order A)
 *   net_sales         = £280 (net_revenue − refunds — matches revenue_summary)
 *   total_paid_orders = 4    (D is on-hold, excluded)
 *   items_sold        = 5    (2+1+1+1)
 *   pipeline_revenue  = £50  (D)
 *   pipeline_orders   = 1
 *   admin_revenue     = £380 (paid £330 + pipeline £50; no refunded-status mains)
 *   admin_orders      = 5
 *
 * Per-country paid (group_by=country):
 *   Germany        = £200, 2 orders (A + B)
 *   France         = £30,  1 order  (C)
 *   United Kingdom = £100, 1 order  (E — D is on-hold, in pipeline bucket)
 *
 * Per-payment-method paid (group_by=payment_method):
 *   Stripe            = £300, 3 orders (A + B + E)
 *   PayPal            = £30,  1 order  (C)
 *   Bank transfer     = £0,   0 paid orders / 1 pipeline (D)
 *
 * Per-shipping-method paid (group_by=shipping_method):
 *   Flat rate     = £200, 2 orders (A + B — D is pipeline, excluded from paid)
 *   Free shipping = £30,  1 order  (C)
 *   Local pickup  = £100, 1 order  (E)
 *
 * Per-category paid (group_by=category, product-level):
 *   Apparel     = £130 (A Hoodie line £100 + C Combo £30), 2 orders (A, C)
 *   Electronics = £230 (B Headphones £100 + C Combo £30 + E Headphones £100),
 *                 3 orders (B, C, E)
 *
 * @package HeyWoo\Tests
 */

/**
 * Integration tests for the get-revenue-breakdown ability.
 */
class Test_Get_Revenue_Breakdown extends WP_UnitTestCase {

	use \HeyWoo\Tests\Integration\AnalyticsFixtures;

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
	 * Customer A's order — retained so the refund sub-order can be
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

		// Categories.
		$apparel_id     = $this->seed_product_category( 'Apparel' );
		$electronics_id = $this->seed_product_category( 'Electronics' );

		// Products.
		$hoodie     = $this->seed_simple_product(
			array(
				'name'         => 'Hoodie',
				'sku'          => 'HOODIE-50',
				'price'        => 50,
				'category_ids' => array( $apparel_id ),
			)
		);
		$headphones = $this->seed_simple_product(
			array(
				'name'         => 'Headphones',
				'sku'          => 'HP-100',
				'price'        => 100,
				'category_ids' => array( $electronics_id ),
			)
		);
		$combo      = $this->seed_simple_product(
			array(
				'name'         => 'Combo',
				'sku'          => 'COMBO-30',
				'price'        => 30,
				'category_ids' => array( $apparel_id, $electronics_id ),
			)
		);

		// Order A — Germany / stripe / Flat rate / 2 × Hoodie.
		// Two units so the partial refund below lands at £50 (1 out
		// of 2) rather than flipping the parent to wc-refunded.
		$a             = $this->seed_customer();
		$this->order_a = $this->seed_breakdown_order(
			array(
				'customer_id'          => $a,
				'total'                => 100.00,
				'date'                 => '2025-10-05 10:00:00',
				'items'                => array(
					array(
						'product_id' => $hoodie,
						'qty'        => 2,
					),
				),
				'billing_country'      => 'DE',
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Stripe',
				'shipping_method'      => 'Flat rate',
			)
		);

		// Order B — Germany / stripe / Flat rate / 1 × Headphones.
		$b = $this->seed_customer();
		$this->seed_breakdown_order(
			array(
				'customer_id'          => $b,
				'total'                => 100.00,
				'date'                 => '2025-10-07 10:00:00',
				'items'                => array(
					array(
						'product_id' => $headphones,
						'qty'        => 1,
					),
				),
				'billing_country'      => 'DE',
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Stripe',
				'shipping_method'      => 'Flat rate',
			)
		);

		// Order C — France / paypal / Free shipping / 1 × Combo.
		$c = $this->seed_customer();
		$this->seed_breakdown_order(
			array(
				'customer_id'          => $c,
				'total'                => 30.00,
				'date'                 => '2025-10-10 10:00:00',
				'items'                => array(
					array(
						'product_id' => $combo,
						'qty'        => 1,
					),
				),
				'billing_country'      => 'FR',
				'payment_method'       => 'paypal',
				'payment_method_title' => 'PayPal',
				'shipping_method'      => 'Free shipping',
			)
		);

		// Order D — UK / bacs / Flat rate / 1 × Hoodie / ON-HOLD.
		$d = $this->seed_customer();
		$this->seed_breakdown_order(
			array(
				'customer_id'          => $d,
				'total'                => 50.00,
				'date'                 => '2025-10-12 10:00:00',
				'status'               => 'on-hold',
				'items'                => array(
					array(
						'product_id' => $hoodie,
						'qty'        => 1,
					),
				),
				'billing_country'      => 'GB',
				'payment_method'       => 'bacs',
				'payment_method_title' => 'Bank transfer',
				'shipping_method'      => 'Flat rate',
			)
		);

		// Order E — UK / stripe / Local pickup / 1 × Headphones.
		$e = $this->seed_customer();
		$this->seed_breakdown_order(
			array(
				'customer_id'          => $e,
				'total'                => 100.00,
				'date'                 => '2025-10-15 10:00:00',
				'items'                => array(
					array(
						'product_id' => $headphones,
						'qty'        => 1,
					),
				),
				'billing_country'      => 'GB',
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Stripe',
				'shipping_method'      => 'Local pickup',
			)
		);

		// Refund — 1 × Hoodie (£50) off Order A on 2025-10-20.
		// seed_refund computes refund = (full_subtotal / full_qty) × qty
		// = (£100 / 2) × 1 = £50. Partial, so Order A stays `completed`.
		$this->seed_refund(
			$this->order_a,
			array( $hoodie => 1 ),
			array(
				'reason' => 'test refund — revenue breakdown',
				'date'   => '2025-10-20 10:00:00',
			)
		);

		// Order F — prior period, Germany / stripe / Flat rate / 1 × Hoodie.
		$f = $this->seed_customer();
		$this->seed_breakdown_order(
			array(
				'customer_id'          => $f,
				'total'                => 50.00,
				'date'                 => '2025-09-10 10:00:00',
				'items'                => array(
					array(
						'product_id' => $hoodie,
						'qty'        => 1,
					),
				),
				'billing_country'      => 'DE',
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Stripe',
				'shipping_method'      => 'Flat rate',
			)
		);
	}

	/**
	 * Seed a paid / on-hold order with billing country, payment method,
	 * and shipping method set. Extends the shared fixture trait by
	 * layering the breakdown-specific fields onto the returned order
	 * object and re-syncing wc_order_stats so the items + addresses
	 * + shipping lines are committed.
	 *
	 * @param array $args Seed shape — customer_id, total, date,
	 *                    status, items as per seed_paid_order(), plus
	 *                    billing_country (2-letter code),
	 *                    payment_method (slug), payment_method_title
	 *                    (human label), shipping_method (title).
	 * @return \WC_Order
	 */
	private function seed_breakdown_order( array $args ) {
		$order = $this->seed_paid_order( $args );

		$dirty = false;

		if ( isset( $args['billing_country'] ) ) {
			$order->set_billing_country( (string) $args['billing_country'] );
			$dirty = true;
		}
		if ( isset( $args['payment_method'] ) ) {
			$order->set_payment_method( (string) $args['payment_method'] );
			$dirty = true;
		}
		if ( isset( $args['payment_method_title'] ) ) {
			$order->set_payment_method_title( (string) $args['payment_method_title'] );
			$dirty = true;
		}
		if ( isset( $args['shipping_method'] ) ) {
			$shipping_item = new \WC_Order_Item_Shipping();
			$shipping_item->set_method_title( (string) $args['shipping_method'] );
			$shipping_item->set_method_id(
				strtolower( str_replace( ' ', '_', (string) $args['shipping_method'] ) )
			);
			$shipping_item->set_total( '0' );
			$order->add_item( $shipping_item );
			$dirty = true;
		}

		if ( $dirty ) {
			$order->save();
		}

		return $order;
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
				'group_by'           => 'category',
				'include_unassigned' => true,
			),
			$overrides
		);

		$ability = wp_get_ability( 'wc-analytics/get-revenue-breakdown' );
		$this->assertNotNull( $ability, 'get-revenue-breakdown ability was not registered.' );

		$result = $ability->execute( $input );
		$this->assertFalse(
			is_wp_error( $result ),
			'Ability returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
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
			if ( isset( $row['key'] ) && (string) $row['key'] === $key ) {
				return $row;
			}
		}
		return null;
	}

	// ─── Category ─────────────────────────────────────────────────

	/**
	 * Grouping by category returns one row per product category
	 * reached by any paid / pipeline / admin_equivalent order.
	 * Electronics outranks Apparel on net_revenue (£230 vs £130).
	 */
	public function test_group_by_category_basic() {
		$result = $this->run_ability( array( 'group_by' => 'category' ) );

		$this->assertSame( 'category', $result['group_by'] );
		$this->assertCount( 2, $result['top_groups'], 'Apparel + Electronics = 2 rows.' );
		$this->assertSame( 'Electronics', $result['top_groups'][0]['label'] );
		$this->assertSame( 'Apparel', $result['top_groups'][1]['label'] );

		$electronics = $this->find_group( $result['top_groups'], 'Electronics' );
		$apparel     = $this->find_group( $result['top_groups'], 'Apparel' );

		$this->assertSame( 230.00, (float) $electronics['net_revenue'] );
		$this->assertSame( 3, (int) $electronics['orders_count'] );
		$this->assertSame( 3, (int) $electronics['items_sold'] );

		// Apparel paid = Order A's Hoodie line (£100 for 2 units) + Order C's
		// Combo (£30) = £130. Items sold = 2 (A Hoodie) + 1 (C Combo) = 3.
		$this->assertSame( 130.00, (float) $apparel['net_revenue'] );
		$this->assertSame( 2, (int) $apparel['orders_count'] );
		$this->assertSame( 3, (int) $apparel['items_sold'] );
	}

	/**
	 * Apparel pipeline = £50 from Order D's on-hold Hoodie;
	 * Apparel refunds = £50 from Order A's partial refund on Hoodie.
	 * Electronics pipeline and refunds stay at 0 since no on-hold or
	 * refunded product landed in that category.
	 */
	public function test_category_pipeline_and_refunds_per_row() {
		$result = $this->run_ability( array( 'group_by' => 'category' ) );

		$apparel     = $this->find_group( $result['top_groups'], 'Apparel' );
		$electronics = $this->find_group( $result['top_groups'], 'Electronics' );

		$this->assertSame( 50.00, (float) $apparel['pipeline_revenue'] );
		$this->assertSame( 1, (int) $apparel['pipeline_orders_count'] );
		$this->assertSame( 50.00, (float) $apparel['refunds'] );

		$this->assertSame( 0.00, (float) $electronics['pipeline_revenue'] );
		$this->assertSame( 0, (int) $electronics['pipeline_orders_count'] );
		$this->assertSame( 0.00, (float) $electronics['refunds'] );
	}

	/**
	 * Multi-category products contribute subtotals to EACH of their
	 * categories. Combo (£30, in Apparel + Electronics) adds £30 to
	 * both — documented in the tool description and the reason
	 * per-category totals don't sum to order totals when baskets span
	 * categories.
	 */
	public function test_category_multi_category_product_double_counts() {
		$result = $this->run_ability( array( 'group_by' => 'category' ) );

		// Sum of category net_revenue: £230 + £130 = £360.
		// Sum of paid order revenue:   £330.
		// The £30 difference is Combo's double-attribution to Apparel
		// + Electronics, which is the documented behaviour.
		$sum_of_categories = 0.00;
		foreach ( $result['top_groups'] as $row ) {
			$sum_of_categories += (float) $row['net_revenue'];
		}
		$this->assertSame( 360.00, $sum_of_categories, 'Sum exceeds paid revenue by Combo\'s £30 double-counting.' );

		$this->assertSame( 330.00, (float) $result['totals']['net_revenue'], 'Totals use order-level figures — no double counting.' );
	}

	// ─── Country ──────────────────────────────────────────────────

	/**
	 * Grouping by country returns one row per billing country.
	 * Germany leads (£200, 2 orders), UK (£100), France (£30).
	 */
	public function test_group_by_country_basic() {
		$result = $this->run_ability( array( 'group_by' => 'country' ) );

		$this->assertSame( 'country', $result['group_by'] );

		$this->assertSame( 'DE', $result['top_groups'][0]['key'] );
		$this->assertSame( 200.00, (float) $result['top_groups'][0]['net_revenue'] );
		$this->assertSame( 2, (int) $result['top_groups'][0]['orders_count'] );

		$this->assertSame( 'GB', $result['top_groups'][1]['key'] );
		$this->assertSame( 100.00, (float) $result['top_groups'][1]['net_revenue'] );
		$this->assertSame( 1, (int) $result['top_groups'][1]['orders_count'] );

		$this->assertSame( 'FR', $result['top_groups'][2]['key'] );
		$this->assertSame( 30.00, (float) $result['top_groups'][2]['net_revenue'] );
	}

	/**
	 * UK's pipeline = £50 (Order D on-hold) even though UK's paid
	 * revenue is £100 (Order E). Refund attribution lands on Germany
	 * because the refund's parent order was A (Germany).
	 */
	public function test_country_pipeline_inherits_parent_on_refunds() {
		$result = $this->run_ability( array( 'group_by' => 'country' ) );

		$de = $this->find_group( $result['top_groups'], 'DE' );
		$gb = $this->find_group( $result['top_groups'], 'GB' );

		$this->assertSame( 50.00, (float) $de['refunds'], 'Refund of Order A inherits DE from parent.' );
		$this->assertSame( 0.00, (float) $de['pipeline_revenue'] );

		$this->assertSame( 50.00, (float) $gb['pipeline_revenue'], 'Order D on-hold in UK.' );
		$this->assertSame( 1, (int) $gb['pipeline_orders_count'] );
		$this->assertSame( 0.00, (float) $gb['refunds'] );
	}

	// ─── Payment method ───────────────────────────────────────────

	/**
	 * Grouping by payment_method reads payment_method_title from the
	 * JOIN. Stripe leads (£300, 3 orders), PayPal (£30, 1 order).
	 * Bank transfer has £0 paid + £50 pipeline — stays in top_groups
	 * because the HAVING clause keeps rows with non-zero pipeline or
	 * admin_equivalent figures.
	 */
	public function test_group_by_payment_method_basic() {
		$result = $this->run_ability( array( 'group_by' => 'payment_method' ) );

		$this->assertSame( 'payment_method', $result['group_by'] );

		$stripe = $this->find_group( $result['top_groups'], 'Stripe' );
		$paypal = $this->find_group( $result['top_groups'], 'PayPal' );
		$bacs   = $this->find_group( $result['top_groups'], 'Bank transfer' );

		$this->assertNotNull( $stripe, 'Stripe row missing.' );
		$this->assertSame( 300.00, (float) $stripe['net_revenue'] );
		$this->assertSame( 3, (int) $stripe['orders_count'] );
		$this->assertSame( 50.00, (float) $stripe['refunds'], 'Refund of Order A inherits stripe from parent.' );

		$this->assertNotNull( $paypal, 'PayPal row missing.' );
		$this->assertSame( 30.00, (float) $paypal['net_revenue'] );
		$this->assertSame( 1, (int) $paypal['orders_count'] );

		$this->assertNotNull( $bacs, 'Bank transfer row missing — HAVING clause should keep pipeline-only rows.' );
		$this->assertSame( 0.00, (float) $bacs['net_revenue'] );
		$this->assertSame( 0, (int) $bacs['orders_count'] );
		$this->assertSame( 50.00, (float) $bacs['pipeline_revenue'], 'Order D pipeline on bacs.' );
	}

	// ─── Shipping method ──────────────────────────────────────────

	/**
	 * Grouping by shipping_method reads from a subquery over
	 * woocommerce_order_items. Flat rate leads (£200, 2 orders —
	 * A + B; D's on-hold Flat rate is in pipeline), Local pickup
	 * (£100, 1 order), Free shipping (£30, 1 order).
	 */
	public function test_group_by_shipping_method_basic() {
		$result = $this->run_ability( array( 'group_by' => 'shipping_method' ) );

		$this->assertSame( 'shipping_method', $result['group_by'] );

		$flat  = $this->find_group( $result['top_groups'], 'Flat rate' );
		$local = $this->find_group( $result['top_groups'], 'Local pickup' );
		$free  = $this->find_group( $result['top_groups'], 'Free shipping' );

		$this->assertNotNull( $flat, 'Flat rate row missing.' );
		$this->assertSame( 200.00, (float) $flat['net_revenue'] );
		$this->assertSame( 2, (int) $flat['orders_count'] );
		$this->assertSame( 50.00, (float) $flat['pipeline_revenue'], 'Order D pipeline on Flat rate.' );
		$this->assertSame( 50.00, (float) $flat['refunds'], 'Refund of Order A inherits Flat rate.' );

		$this->assertNotNull( $local, 'Local pickup row missing.' );
		$this->assertSame( 100.00, (float) $local['net_revenue'] );
		$this->assertSame( 1, (int) $local['orders_count'] );

		$this->assertNotNull( $free, 'Free shipping row missing.' );
		$this->assertSame( 30.00, (float) $free['net_revenue'] );
		$this->assertSame( 1, (int) $free['orders_count'] );
	}

	// ─── Totals / coverage / share ────────────────────────────────

	/**
	 * Top-level totals match the store-wide paid-revenue aggregate
	 * (£330 / 4 orders / 5 items). Pipeline + admin_equivalent sibling
	 * blocks carry the on-hold + paid+pipeline+refunded lumped figures.
	 * Also pins the post-E14 totals.refunds / totals.net_sales pair
	 * (see test_totals_net_sales_reconciles_with_refunds for the
	 * reconciliation assertion).
	 */
	public function test_top_level_totals() {
		$result = $this->run_ability( array( 'group_by' => 'country' ) );

		$this->assertSame( 330.00, (float) $result['totals']['net_revenue'] );
		$this->assertSame( 4, (int) $result['totals']['total_paid_orders'] );
		$this->assertSame( 5, (int) $result['totals']['items_sold'] );
		$this->assertSame( 82.50, (float) $result['totals']['avg_order_value'] );

		$this->assertSame( 50.00, (float) $result['pipeline']['revenue'] );
		$this->assertSame( 1, (int) $result['pipeline']['orders_count'] );

		$this->assertSame( 380.00, (float) $result['admin_equivalent']['revenue'] );
		$this->assertSame( 5, (int) $result['admin_equivalent']['orders_count'] );
	}

	/**
	 * E14a — totals.refunds and totals.net_sales land alongside
	 * totals.net_revenue so Claude doesn't cross-reach into
	 * revenue_summary to reconcile. net_sales = net_revenue − refunds.
	 * Fixture: £330 − £50 = £280.
	 */
	public function test_totals_net_sales_reconciles_with_refunds() {
		$result = $this->run_ability( array( 'group_by' => 'country' ) );

		$this->assertSame( 50.00, (float) $result['totals']['refunds'], 'totals.refunds mirrors the £50 partial refund off Order A.' );
		$this->assertSame( 280.00, (float) $result['totals']['net_sales'], 'net_sales = net_revenue (£330) − refunds (£50).' );
		$this->assertSame(
			round( $result['totals']['net_revenue'] - $result['totals']['refunds'], 2 ),
			(float) $result['totals']['net_sales'],
			'net_sales must always equal net_revenue − refunds.'
		);
	}

	/**
	 * E14b — every top_groups row carries refund_rate_percent
	 * pre-computed. DE's row has £50 refunds / £200 net_revenue = 25%.
	 * Pipeline-only rows (Bank transfer: £0 paid, £50 pipeline) return
	 * 0.0 rather than dividing by zero — that's the guard in
	 * shape_revenue_group_row.
	 */
	public function test_refund_rate_percent_is_precomputed_per_row() {
		$result = $this->run_ability( array( 'group_by' => 'country' ) );

		$de = $this->find_group( $result['top_groups'], 'DE' );
		$this->assertSame( 25.0, (float) $de['refund_rate_percent'], 'DE: £50 refunds / £200 revenue = 25%.' );

		$fr = $this->find_group( $result['top_groups'], 'FR' );
		$this->assertSame( 0.0, (float) $fr['refund_rate_percent'], 'FR has no refunds.' );

		// Pipeline-only row guard.
		$pm_result = $this->run_ability( array( 'group_by' => 'payment_method' ) );
		$bacs      = $this->find_group( $pm_result['top_groups'], 'Bank transfer' );
		$this->assertSame( 0.0, (float) $bacs['refund_rate_percent'], 'Pipeline-only rows must not divide by zero.' );
	}

	/**
	 * Coverage percent = covered_orders / total_paid_orders × 100.
	 * On our fixture every paid order has a country, payment method,
	 * shipping line, and categorised product — so all four dimensions
	 * land at 100% coverage.
	 */
	public function test_coverage_percent_per_dimension() {
		foreach ( array( 'category', 'country', 'payment_method', 'shipping_method' ) as $dim ) {
			$result = $this->run_ability( array( 'group_by' => $dim ) );
			$this->assertSame(
				100.0,
				(float) $result['totals']['coverage_percent'],
				"coverage_percent should be 100% on {$dim} — every paid order has a value on all four dimensions."
			);
		}
	}

	/**
	 * Distinct_groups counts non-empty dimension values with at least
	 * one paid order. Country = 3 (DE, FR, GB); payment_method = 2
	 * (Stripe, PayPal — bacs has no paid orders); shipping_method = 3
	 * (Flat rate, Free shipping, Local pickup); category = 2 (Apparel,
	 * Electronics).
	 */
	public function test_distinct_groups_per_dimension() {
		$expected = array(
			'category'        => 2,
			'country'         => 3,
			'payment_method'  => 2,
			'shipping_method' => 3,
		);
		foreach ( $expected as $dim => $count ) {
			$result = $this->run_ability( array( 'group_by' => $dim ) );
			$this->assertSame(
				$count,
				(int) $result['totals']['distinct_groups'],
				"distinct_groups mismatch on {$dim}."
			);
		}
	}

	/**
	 * Share_of_revenue_percent on each top_groups row is pre-computed
	 * against the full paid-revenue denominator (£330), not the sum
	 * of visible top_groups. Germany's £200 / £330 = 60.6%.
	 */
	public function test_share_of_revenue_percent_is_precomputed() {
		$result = $this->run_ability( array( 'group_by' => 'country' ) );

		$de = $this->find_group( $result['top_groups'], 'DE' );
		$this->assertSame( 60.6, (float) $de['share_of_revenue_percent'] );

		$gb = $this->find_group( $result['top_groups'], 'GB' );
		$this->assertSame( 30.3, (float) $gb['share_of_revenue_percent'] );

		$fr = $this->find_group( $result['top_groups'], 'FR' );
		$this->assertSame( 9.1, (float) $fr['share_of_revenue_percent'] );
	}

	// ─── Comparison ───────────────────────────────────────────────

	/**
	 * With compare=true the response emits a comparison block.
	 * Germany (present in both periods) gets direction=up with
	 * pre-computed percent/amount. The prior period has only Germany;
	 * France/UK carry direction=new on the current-period rows. No
	 * dropped_out entries because every prior-period group (Germany)
	 * also appears in the current period.
	 */
	public function test_compare_block_germany_delta() {
		$result = $this->run_ability(
			array(
				'group_by' => 'country',
				'compare'  => true,
			)
		);

		$this->assertNotNull( $result['comparison'] );
		// 31-day current period ⇒ prior period is the 31 days preceding:
		// 2025-08-31..2025-09-30 (not calendar September).
		$this->assertSame( '2025-08-31', $result['comparison']['period']['start'] );
		$this->assertSame( '2025-09-30', $result['comparison']['period']['end'] );

		$de = $this->find_group( $result['top_groups'], 'DE' );
		$this->assertArrayHasKey( 'change', $de );
		$this->assertSame( 'up', $de['change']['direction'] );
		// Prior DE = £50 (Order F), current DE = £200, delta = +£150, percent = +300%.
		$this->assertSame( 150.00, (float) $de['change']['amount'] );
		$this->assertSame( 300.0, (float) $de['change']['percent'] );

		$fr = $this->find_group( $result['top_groups'], 'FR' );
		$this->assertSame( 'new', $fr['change']['direction'] );
	}

	/**
	 * Prior-period Germany / stripe / Flat rate / Apparel all have
	 * entries, so switching compare to those dimensions still yields
	 * a populated comparison block with a change on the carried-over
	 * group. comparison.totals.net_revenue matches the prior-period
	 * totals (£50).
	 */
	public function test_compare_totals_changes() {
		$result = $this->run_ability(
			array(
				'group_by' => 'payment_method',
				'compare'  => true,
			)
		);

		$this->assertSame( 50.00, (float) $result['comparison']['totals']['net_revenue'] );
		$this->assertSame( 1, (int) $result['comparison']['totals']['total_paid_orders'] );

		// Current = £330, prior = £50 → +£280.
		$this->assertSame( 'up', $result['comparison']['changes']['net_revenue']['direction'] );
		$this->assertSame( 280.00, (float) $result['comparison']['changes']['net_revenue']['amount'] );
	}

	// ─── include_unassigned ───────────────────────────────────────

	/**
	 * Setting include_unassigned=false hides (Unassigned) rows on
	 * the three order-level dimensions. Category ignores the flag
	 * (no JOIN row = no result, regardless of the flag). With our
	 * fixture every paid order has a value on every dimension, so
	 * (Unassigned) wouldn't appear anyway — assert we don't
	 * accidentally inject it.
	 */
	public function test_include_unassigned_false_on_order_level_dimensions() {
		foreach ( array( 'country', 'payment_method', 'shipping_method' ) as $dim ) {
			$result = $this->run_ability(
				array(
					'group_by'           => $dim,
					'include_unassigned' => false,
				)
			);
			$this->assertNull(
				$this->find_group( $result['top_groups'], '(Unassigned)' ),
				"({$dim}) (Unassigned) should not appear when include_unassigned=false."
			);
		}
	}

	// ─── orderby ──────────────────────────────────────────────────

	/**
	 * Setting orderby=orders_count re-sorts top_groups by order count
	 * desc. On category, Electronics (3 orders) still leads, but the
	 * metric used for sort changes the explicit comparison key.
	 */
	public function test_orderby_orders_count() {
		$result = $this->run_ability(
			array(
				'group_by' => 'category',
				'orderby'  => 'orders_count',
			)
		);

		$this->assertSame( 'orders_count', $result['orderby'] );
		$this->assertSame( 'Electronics', $result['top_groups'][0]['label'] );
		$this->assertSame( 3, (int) $result['top_groups'][0]['orders_count'] );
	}

	// ─── Empty period ─────────────────────────────────────────────

	/**
	 * No data in range → note is populated, top_groups is empty, and
	 * totals.net_revenue is 0.
	 */
	public function test_empty_period_produces_note() {
		$result = $this->run_ability(
			array(
				'date_start' => '2020-01-01',
				'date_end'   => '2020-01-31',
				'group_by'   => 'country',
			)
		);

		$this->assertStringContainsString( 'No paid orders found', (string) $result['note'] );
		$this->assertSame( array(), $result['top_groups'] );
		$this->assertSame( 0.00, (float) $result['totals']['net_revenue'] );
	}
}
