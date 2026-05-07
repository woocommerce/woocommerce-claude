<?php
/**
 * Integration tests — wc-analytics/get-product-performance.
 *
 * Pins the invariants that drift when the SQL in
 * AnalyticsController::query_top_products() /
 * ::query_product_totals() / ::query_top_categories() /
 * ::query_product_series() is refactored. Richest skill on the plugin
 * — simple + variable products, refund sub-orders, on-hold pipeline,
 * admin_equivalent netting, category taxonomy, per-product time
 * series, and the dropped_out comparison.
 *
 * What's pinned:
 *
 *   - `top_products` returns one row per distinct id (product or
 *     variation), sorted by net_revenue desc (default orderby).
 *   - `group_by=product` (default) rolls variations up under the
 *     parent product_id — one row per parent even when multiple
 *     variations sold.
 *   - `group_by=variation` splits variations into their own rows
 *     keyed by variation_id; simple products still key on
 *     product_id (the CASE WHEN variation_id > 0 branch).
 *   - Per-product `refunds` + `refund_count` come from refund
 *     sub-order rows in wc_order_product_lookup (product_net_revenue
 *     < 0), created via wc_create_refund(). A status=wc-refunded
 *     main order does NOT feed refunds — it's an admin_equivalent
 *     signal, not a refund-line signal.
 *   - Per-product `pipeline_revenue` + `pipeline_quantity` pick up
 *     on-hold orders at the line-item level.
 *   - Per-product `admin_equivalent_revenue` nets in paid + on-hold
 *     + status=refunded main-order items AND the negative
 *     product_net_revenue from refund sub-orders, matching WC
 *     Admin Products report reconciliation.
 *   - `orderby` whitelist (net_revenue / gross_revenue / quantity /
 *     orders_count) moves the top-of-list deterministically.
 *   - Totals `distinct_products_sold` counts parent product_id;
 *     `distinct_skus_sold` treats each variation as a distinct SKU
 *     and falls back to product_id for simple products.
 *   - `top_categories` sums across every category each product
 *     belongs to (multi-category products count in each).
 *   - `compare=true` stamps a per-product `change` sub-object
 *     (metric/amount/percent/direction) on every top_products row
 *     that existed in both periods; new-this-period gets direction=
 *     new; prior-only products land in `comparison.dropped_out`.
 *   - `interval=day` produces a per-bucket series on the top
 *     products, keyed by paid line-item contributions.
 *   - `catalogue_coverage_percent` = distinct parent products sold /
 *     published parents × 100. `sku_coverage_percent` treats each
 *     variation as a distinct SKU and divides against the sellable-
 *     SKU denominator (simples with no variation children + published
 *     variations; variable parents excluded). Denominators cache
 *     via 1-hour transients keyed `woocommerce_claude_catalogue_size` /
 *     `woocommerce_claude_sku_count` — InnoDB-backed wp_options gets
 *     rolled back per test by WP_UnitTestCase's transaction wrapper,
 *     so the transients reset cleanly without explicit eviction.
 *
 * Known gaps still uncovered (deliberate, flagged for follow-up):
 *
 *   - `note` on empty period ('No product sales found...') — trivial
 *     but requires a second fixture-less test class or a wrapper.
 *   - `admin_url` decoration — cosmetic; no SQL drift risk.
 *
 * Fixture shape (period 2025-10-01..2025-10-31, prior 2025-08-31..2025-09-30):
 *
 *   Categories: Apparel, Accessories, Drinkware.
 *
 *   Products:
 *     P1 (simple)  "Blue T-Shirt", £30, [Apparel].
 *     P2 (simple)  "Leather Belt", £50, [Apparel, Accessories].
 *     P3 (variable) "Mug", [Drinkware] with two variations:
 *       V3a "Small" £15
 *       V3b "Large" £25
 *     P4 (simple)  "Widget", £20, no category — only sells in the
 *                  prior period so appears in comparison.dropped_out.
 *
 *   Orders (chronological):
 *     O7 prior: 2025-09-15, 2 × P1            (£60, paid)
 *     O8 prior: 2025-09-20, 1 × P4            (£20, paid — dropped_out)
 *     O1: 2025-10-05, 2 × P1 + 1 × V3a        (£75, paid)
 *     O2: 2025-10-10, 1 × P2 + 1 × V3b        (£75, paid)
 *     O3: 2025-10-15, 1 × P1                  (£30, paid)
 *     O4: 2025-10-18, 1 × P2                  (£50, on-hold)
 *     O5: 2025-10-20, 1 × P1                  (£30, refunded main-order)
 *     O6: 2025-10-22, refund of 1 P1 from O1  (−£30 via wc_create_refund)
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the get-product-performance ability.
 */
class Test_Get_Product_Performance extends WP_UnitTestCase {

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
	 * Map of fixture-key → product/variation ID, populated in set_up().
	 *
	 * @var array<string,int>
	 */
	private $ids = array();

	/**
	 * Seed the fixture shape described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		// Categories.
		$apparel     = $this->seed_product_category( 'Apparel' );
		$accessories = $this->seed_product_category( 'Accessories' );
		$drinkware   = $this->seed_product_category( 'Drinkware' );

		// Products.
		$this->ids['p1']  = $this->seed_simple_product(
			array(
				'name'         => 'Blue T-Shirt',
				'sku'          => 'SHIRT-BLUE',
				'price'        => 30,
				'category_ids' => array( $apparel ),
			)
		);
		$this->ids['p2']  = $this->seed_simple_product(
			array(
				'name'         => 'Leather Belt',
				'sku'          => 'BELT-LTH',
				'price'        => 50,
				'category_ids' => array( $apparel, $accessories ),
			)
		);
		$this->ids['p3']  = $this->seed_variable_product(
			array(
				'name'              => 'Mug',
				'attribute_options' => array( 'Small', 'Large' ),
				'category_ids'      => array( $drinkware ),
			)
		);
		$this->ids['v3a'] = $this->seed_variation(
			$this->ids['p3'],
			array(
				'option' => 'Small',
				'sku'    => 'MUG-S',
				'price'  => 15,
			)
		);
		$this->ids['v3b'] = $this->seed_variation(
			$this->ids['p3'],
			array(
				'option' => 'Large',
				'sku'    => 'MUG-L',
				'price'  => 25,
			)
		);
		$this->ids['p4']  = $this->seed_simple_product(
			array(
				'name'  => 'Widget',
				'sku'   => 'WDG',
				'price' => 20,
			)
		);

		// Customers + orders (chronological).
		$cust_f = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_f,
				'total'       => 60.00,
				'date'        => '2025-09-15 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->ids['p1'],
						'qty'        => 2,
					),
				),
			)
		);

		$cust_g = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_g,
				'total'       => 20.00,
				'date'        => '2025-09-20 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->ids['p4'],
						'qty'        => 1,
					),
				),
			)
		);

		$cust_a         = $this->seed_customer();
		$this->order_o1 = $this->seed_paid_order(
			array(
				'customer_id' => $cust_a,
				'total'       => 75.00,
				'date'        => '2025-10-05 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->ids['p1'],
						'qty'        => 2,
					),
					array(
						'product_id' => $this->ids['v3a'],
						'qty'        => 1,
					),
				),
			)
		);

		$cust_b = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_b,
				'total'       => 75.00,
				'date'        => '2025-10-10 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->ids['p2'],
						'qty'        => 1,
					),
					array(
						'product_id' => $this->ids['v3b'],
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
						'product_id' => $this->ids['p1'],
						'qty'        => 1,
					),
				),
			)
		);

		$cust_d = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_d,
				'total'       => 50.00,
				'date'        => '2025-10-18 10:00:00',
				'status'      => 'on-hold',
				'items'       => array(
					array(
						'product_id' => $this->ids['p2'],
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
						'product_id' => $this->ids['p1'],
						'qty'        => 1,
					),
				),
			)
		);

		// Refund sub-order: 1 × P1 refunded off O1. Produces a
		// wc_order_product_lookup row with product_net_revenue < 0.
		$this->seed_refund(
			$this->order_o1,
			array( $this->ids['p1'] => 1 ),
			array(
				'reason' => 'Wrong size',
				'date'   => '2025-10-22 10:00:00',
			)
		);
	}

	/**
	 * Invoke the ability. Defaults to the fixture window with
	 * compare=false, no interval, default orderby / group_by.
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
				'orderby'    => 'net_revenue',
				'group_by'   => 'product',
				'interval'   => '',
			),
			$overrides
		);

		$ability = wp_get_ability( 'wc-analytics/get-product-performance' );
		$this->assertNotNull( $ability, 'get-product-performance ability was not registered.' );

		$result = $ability->execute( $input );
		$this->assertFalse(
			is_wp_error( $result ),
			'Ability returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		return $result;
	}

	/**
	 * Locate a top_products row by product_id. Returns null when absent.
	 *
	 * @param array $products top_products array.
	 * @param int   $pid      Product or variation ID to find.
	 * @return array|null
	 */
	private function find_product( array $products, $pid ) {
		foreach ( $products as $row ) {
			if ( (int) $row['product_id'] === (int) $pid ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Locate a top_categories row by name. Returns null when absent.
	 *
	 * @param array  $categories top_categories array.
	 * @param string $name       Category name.
	 * @return array|null
	 */
	private function find_category( array $categories, $name ) {
		foreach ( $categories as $row ) {
			if ( $row['name'] === $name ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Basic top_products: three rows (P1, P2, P3), sorted by net_revenue
	 * desc, each with the expected paid-only aggregates.
	 *
	 * P1: O1 2×£30 + O3 1×£30 = £90 / qty 3 / 2 orders.
	 * P2: O2 1×£50 = £50 / qty 1 / 1 order.
	 * P3: V3a £15 + V3b £25 = £40 / qty 2 / 2 orders (rolled up at parent).
	 */
	public function test_top_products_basic() {
		$result = $this->run_ability();

		// No interval → series_cap must be null.
		$this->assertNull( $result['series_cap'] );

		$this->assertSame( 'product', $result['group_by'] );
		$this->assertCount( 3, $result['top_products'], 'Three parent products sold: P1, P2, P3.' );

		$this->assertSame( $this->ids['p1'], (int) $result['top_products'][0]['product_id'] );
		$this->assertSame( 'Blue T-Shirt', $result['top_products'][0]['product_name'] );
		$this->assertSame( 'SHIRT-BLUE', $result['top_products'][0]['sku'] );
		$this->assertSame( 90.00, (float) $result['top_products'][0]['net_revenue'] );
		$this->assertSame( 3, (int) $result['top_products'][0]['quantity'] );
		$this->assertSame( 2, (int) $result['top_products'][0]['orders_count'] );

		$this->assertSame( $this->ids['p2'], (int) $result['top_products'][1]['product_id'] );
		$this->assertSame( 50.00, (float) $result['top_products'][1]['net_revenue'] );

		$this->assertSame( $this->ids['p3'], (int) $result['top_products'][2]['product_id'] );
		$this->assertSame(
			40.00,
			(float) $result['top_products'][2]['net_revenue'],
			'P3 = V3a £15 + V3b £25 rolled up at parent.'
		);
		$this->assertSame( 2, (int) $result['top_products'][2]['quantity'] );
	}

	/**
	 * Grouping by variation splits P3's variations into their own rows,
	 * keyed on variation_id. Simple products P1/P2 still key on
	 * product_id because their variation_id is 0 in the lookup.
	 */
	public function test_group_by_variation_splits_variations() {
		$result = $this->run_ability( array( 'group_by' => 'variation' ) );

		$this->assertSame( 'variation', $result['group_by'] );
		$this->assertCount( 4, $result['top_products'], 'Simple P1, P2, and two variations V3a/V3b.' );

		// P1 should still be keyed on product_id (simple product).
		$p1 = $this->find_product( $result['top_products'], $this->ids['p1'] );
		$this->assertNotNull( $p1 );
		$this->assertSame( 90.00, (float) $p1['net_revenue'] );

		// V3a / V3b each appear as their own rows.
		$v3a = $this->find_product( $result['top_products'], $this->ids['v3a'] );
		$this->assertNotNull( $v3a, 'V3a variation should surface under group_by=variation.' );
		$this->assertSame( 15.00, (float) $v3a['net_revenue'] );
		$this->assertSame( 1, (int) $v3a['quantity'] );

		$v3b = $this->find_product( $result['top_products'], $this->ids['v3b'] );
		$this->assertNotNull( $v3b );
		$this->assertSame( 25.00, (float) $v3b['net_revenue'] );

		// Parent P3 should NOT appear in variation mode — it's not a
		// sellable SKU of its own.
		$this->assertNull(
			$this->find_product( $result['top_products'], $this->ids['p3'] ),
			'Variable parent P3 is not itself a sellable SKU and must not appear under group_by=variation.'
		);
	}

	/**
	 * Per-product refunds come from refund sub-orders
	 * (product_net_revenue < 0 rows in wc_order_product_lookup), NOT
	 * from status=wc-refunded main orders. O6 refunded 1 × P1 from
	 * O1; that populates P1.refunds. O5 (status=refunded main order
	 * for 1 × P1) does NOT feed refunds — it's an admin signal only.
	 */
	public function test_per_product_refunds_from_refund_sub_order() {
		$result = $this->run_ability();

		$p1 = $this->find_product( $result['top_products'], $this->ids['p1'] );
		$this->assertNotNull( $p1 );
		$this->assertSame(
			30.00,
			(float) $p1['refunds'],
			'One P1 refunded via wc_create_refund at £30.'
		);
		$this->assertSame( 1, (int) $p1['refund_count'], 'One refund line.' );

		// P2 had an on-hold and a paid order but no refund sub-order.
		$p2 = $this->find_product( $result['top_products'], $this->ids['p2'] );
		$this->assertNotNull( $p2 );
		$this->assertSame( 0.00, (float) $p2['refunds'] );
		$this->assertSame( 0, (int) $p2['refund_count'] );
	}

	/**
	 * On-hold orders surface as per-product pipeline_revenue + quantity
	 * without polluting the paid net_revenue. Top-level pipeline block
	 * carries the totals summed across all products.
	 */
	public function test_per_product_pipeline_from_on_hold() {
		$result = $this->run_ability();

		$p2 = $this->find_product( $result['top_products'], $this->ids['p2'] );
		$this->assertNotNull( $p2 );
		$this->assertSame( 50.00, (float) $p2['pipeline_revenue'], 'O4 on-hold 1 × P2 at £50.' );
		$this->assertSame( 1, (int) $p2['pipeline_quantity'] );
		$this->assertSame( 50.00, (float) $p2['net_revenue'], 'Pipeline must NOT leak into paid net_revenue.' );

		// P1 has no on-hold line items.
		$p1 = $this->find_product( $result['top_products'], $this->ids['p1'] );
		$this->assertSame( 0.00, (float) $p1['pipeline_revenue'] );
		$this->assertSame( 0, (int) $p1['pipeline_quantity'] );

		// Top-level pipeline block = sum across products.
		$this->assertSame( 50.00, (float) $result['pipeline']['revenue'] );
		$this->assertSame( 1, (int) $result['pipeline']['quantity'] );
	}

	/**
	 * Per-product admin_equivalent_revenue nets in (a) paid line items,
	 * (b) on-hold line items, (c) status=refunded main-order line items,
	 * and (d) negative product_net_revenue from refund sub-orders.
	 *
	 * P1:
	 *   Paid         O1 2×P1=£60 + O3 1×P1=£30          = £90
	 *   Refunded     O5 1×P1=£30 (wc-refunded is admin) = £30
	 *   Refund sub   O6 −1×P1=−£30                      = −£30
	 *   ────────────────────────────────────────────────── £90 (qty 3)
	 *
	 * P2:
	 *   Paid         O2 1×P2=£50                        = £50
	 *   On-hold      O4 1×P2=£50 (wc-on-hold is admin)  = £50
	 *   ────────────────────────────────────────────────── £100 (qty 2)
	 */
	public function test_per_product_admin_equivalent_netting() {
		$result = $this->run_ability();

		$p1 = $this->find_product( $result['top_products'], $this->ids['p1'] );
		$this->assertSame(
			90.00,
			(float) $p1['admin_equivalent_revenue'],
			'P1 admin: £90 paid + £30 refunded main − £30 refund sub = £90.'
		);
		$this->assertSame( 3, (int) $p1['admin_equivalent_quantity'] );

		$p2 = $this->find_product( $result['top_products'], $this->ids['p2'] );
		$this->assertSame(
			100.00,
			(float) $p2['admin_equivalent_revenue'],
			'P2 admin: £50 paid + £50 on-hold = £100.'
		);
		$this->assertSame( 2, (int) $p2['admin_equivalent_quantity'] );

		// Top-level admin_equivalent = sum across products:
		// P1 £90 + P2 £100 + P3 £40 = £230, qty 3+2+2 = 7.
		$this->assertSame( 230.00, (float) $result['admin_equivalent']['revenue'] );
		$this->assertSame( 7, (int) $result['admin_equivalent']['quantity'] );
	}

	/**
	 * The orderby whitelist moves the top of list. orderby=quantity
	 * ranks P1 first (qty 3); orderby=orders_count also P1 (2 paid
	 * orders vs 1 for each of P2/P3).
	 */
	public function test_orderby_variants_move_top_of_list() {
		$by_qty = $this->run_ability( array( 'orderby' => 'quantity' ) );
		$this->assertSame( 'quantity', $by_qty['orderby'] );
		$this->assertSame(
			$this->ids['p1'],
			(int) $by_qty['top_products'][0]['product_id'],
			'P1 has the highest paid quantity (3).'
		);

		$by_orders = $this->run_ability( array( 'orderby' => 'orders_count' ) );
		$this->assertSame( 'orders_count', $by_orders['orderby'] );
		$this->assertSame(
			$this->ids['p1'],
			(int) $by_orders['top_products'][0]['product_id'],
			'P1 appears in 2 paid orders (O1, O3); P2/P3 each appear in 1.'
		);

		$by_gross = $this->run_ability( array( 'orderby' => 'gross_revenue' ) );
		$this->assertSame( 'gross_revenue', $by_gross['orderby'] );
		$this->assertSame(
			$this->ids['p1'],
			(int) $by_gross['top_products'][0]['product_id']
		);
	}

	/**
	 * Totals.distinct_products_sold counts parent product IDs;
	 * totals.distinct_skus_sold treats each variation as its own SKU
	 * and falls back to product_id for simples.
	 *
	 * Fixture paid sales: P1 (simple) + P2 (simple) + V3a + V3b.
	 *   distinct_products_sold = 3 (P1, P2, P3 parent).
	 *   distinct_skus_sold     = 4 (P1, P2, V3a, V3b).
	 */
	public function test_totals_distinct_products_vs_skus() {
		$result = $this->run_ability();

		$this->assertSame( 3, (int) $result['totals']['distinct_products_sold'] );
		$this->assertSame( 4, (int) $result['totals']['distinct_skus_sold'] );
		$this->assertSame(
			6,
			(int) $result['totals']['total_items_sold'],
			'Paid items: 2 P1 + 1 V3a + 1 P2 + 1 V3b + 1 P1 = 6.'
		);
		$this->assertSame( 180.00, (float) $result['totals']['total_net_revenue'] );
		$this->assertSame( 180.00, (float) $result['totals']['total_gross_revenue'] );
		$this->assertSame( 30.00, (float) $result['totals']['total_refunds'] );
	}

	/**
	 * Totals.catalogue_coverage_percent and totals.sku_coverage_percent
	 * divide distinct-sold counts by catalogue denominators.
	 *
	 * Catalogue denominator (query_catalogue_size) — distinct published
	 * parent products: P1, P2, P3, P4 = 4.
	 *
	 * SKU denominator (query_sellable_sku_count) — simple products with
	 * no published variation children PLUS published variations of
	 * published parents: P1 + P2 + P4 (simple, no children) + V3a + V3b
	 * (variations of P3) = 5. P3 is excluded from the simple count
	 * because it has published variation children; P3 is also never
	 * itself a sellable SKU.
	 *
	 * Both denominators are cached via 1-hour transients keyed
	 * `woocommerce_claude_catalogue_size` / `woocommerce_claude_sku_count`. WP
	 * wraps every test in a DB transaction that rolls back wp_options
	 * in tear_down (InnoDB engine confirmed at write time), so the
	 * transients reset cleanly between tests without explicit eviction.
	 * Eviction would be required if a future harness change moved
	 * transients out of wp_options or persisted the object cache
	 * across tests — flag for a trait helper at that point.
	 */
	public function test_totals_catalogue_and_sku_coverage_percent() {
		$result = $this->run_ability();

		$this->assertSame(
			4,
			(int) $result['totals']['products_in_catalogue'],
			'Published parent products: P1, P2, P3, P4.'
		);
		$this->assertSame(
			5,
			(int) $result['totals']['skus_in_catalogue'],
			'Sellable SKUs: P1 + P2 + P4 (no-variation simples) + V3a + V3b (variations); P3 excluded.'
		);

		$this->assertSame(
			75.0,
			(float) $result['totals']['catalogue_coverage_percent'],
			'3 distinct parents sold (P1, P2, P3) / 4 catalogue parents = 75.0%.'
		);
		$this->assertSame(
			80.0,
			(float) $result['totals']['sku_coverage_percent'],
			'4 distinct SKUs sold (P1, P2, V3a, V3b) / 5 catalogue SKUs = 80.0%.'
		);
	}

	/**
	 * The top_categories rollup sums net_revenue across every category
	 * each product belongs to. P2 is in two categories (Apparel +
	 * Accessories) — it contributes to both.
	 *
	 * Apparel:     P1 £90 + P2 £50 = £140 / qty 4 / orders O1, O2, O3 = 3
	 * Accessories: P2 £50          = £50  / qty 1 / orders O2         = 1
	 * Drinkware:   P3 £40          = £40  / qty 2 / orders O1, O2     = 2
	 */
	public function test_top_categories_include_multi_category_product() {
		$result = $this->run_ability();

		$this->assertCount( 3, $result['top_categories'], 'Three categories with sales.' );

		// Sorted desc by net_revenue.
		$this->assertSame( 'Apparel', $result['top_categories'][0]['name'] );
		$this->assertSame( 140.00, (float) $result['top_categories'][0]['net_revenue'] );
		$this->assertSame( 4, (int) $result['top_categories'][0]['quantity'] );
		$this->assertSame(
			3,
			(int) $result['top_categories'][0]['orders_count'],
			'Apparel touches O1, O2, O3.'
		);

		$accessories = $this->find_category( $result['top_categories'], 'Accessories' );
		$this->assertNotNull( $accessories );
		$this->assertSame(
			50.00,
			(float) $accessories['net_revenue'],
			'P2 contributes to Accessories AND Apparel — multi-category double-count is by design.'
		);

		$drinkware = $this->find_category( $result['top_categories'], 'Drinkware' );
		$this->assertNotNull( $drinkware );
		$this->assertSame( 40.00, (float) $drinkware['net_revenue'] );
	}

	/**
	 * Setting compare=true stamps a per-product `change` sub-object on
	 * every row that exists in both periods; new rows get direction=new;
	 * prior-only products land in comparison.dropped_out.
	 *
	 * Prior period: P1 £60 (O7), P4 £20 (O8).
	 * Current P1 £90 → change +£30 / +50%% / up.
	 * Current P2 £50 → direction=new.
	 * Current P3 £40 → direction=new.
	 * P4 (prior only) → dropped_out.
	 */
	public function test_compare_per_product_change_and_dropped_out() {
		$result = $this->run_ability( array( 'compare' => true ) );

		$this->assertIsArray( $result['comparison'] );
		$this->assertSame( '2025-08-31', $result['comparison']['period']['start'] );
		$this->assertSame( '2025-09-30', $result['comparison']['period']['end'] );

		$p1 = $this->find_product( $result['top_products'], $this->ids['p1'] );
		$this->assertNotNull( $p1 );
		$this->assertArrayHasKey( 'change', $p1 );
		$this->assertSame( 'net_revenue', $p1['change']['metric'] );
		$this->assertSame( 'up', $p1['change']['direction'] );
		$this->assertSame( 30.00, (float) $p1['change']['amount'] );
		$this->assertSame( 50.0, (float) $p1['change']['percent'] );

		$p2 = $this->find_product( $result['top_products'], $this->ids['p2'] );
		$this->assertSame( 'new', $p2['change']['direction'] );

		$p3 = $this->find_product( $result['top_products'], $this->ids['p3'] );
		$this->assertSame( 'new', $p3['change']['direction'] );

		// P4 sold in prior only → dropped_out.
		$this->assertNotEmpty( $result['comparison']['dropped_out'] );
		$dropped = null;
		foreach ( $result['comparison']['dropped_out'] as $row ) {
			if ( (int) $row['product_id'] === $this->ids['p4'] ) {
				$dropped = $row;
				break;
			}
		}
		$this->assertNotNull( $dropped, 'P4 should appear in comparison.dropped_out.' );
		$this->assertSame( 'Widget', $dropped['product_name'] );
		$this->assertSame( 20.00, (float) $dropped['previous_net_revenue'] );
	}

	/**
	 * Setting interval=day produces a per-bucket series on each top
	 * product. P1 has paid-only sales on two distinct days: O1 on
	 * 2025-10-05 (2 × £30 = £60) and O3 on 2025-10-15 (1 × £30 = £30).
	 *
	 * O5 (wc-refunded status) is excluded by the WHERE's
	 * `status IN paid_statuses` filter. O6 (refund sub-order) inherits
	 * its parent's wc-completed status, so its date leaks in as a
	 * third bucket on 2025-10-22 — but the CASE WHEN parent_id = 0
	 * filter inside the SUMs returns zero values for that row, so the
	 * bucket is present but empty. Asserting both shapes here so this
	 * invariant is loud if the series SQL tightens the WHERE later.
	 */
	public function test_interval_day_series_paid_only() {
		$result = $this->run_ability( array( 'interval' => 'day' ) );

		$this->assertSame( 'day', $result['interval'] );
		$this->assertGreaterThan( 0, $result['series_cap'], 'series_cap must be a positive int (range_days + 1 sentinel).' );

		$p1 = $this->find_product( $result['top_products'], $this->ids['p1'] );
		$this->assertNotNull( $p1 );
		$this->assertArrayHasKey( 'series', $p1 );
		$this->assertCount(
			3,
			$p1['series'],
			'O1 + O3 paid days, plus O6 refund-sub day (zero-valued bucket).'
		);

		$this->assertSame( '2025-10-05', $p1['series'][0]['date'] );
		$this->assertSame( 60.00, (float) $p1['series'][0]['net_revenue'] );
		$this->assertSame( 2, (int) $p1['series'][0]['quantity'] );

		$this->assertSame( '2025-10-15', $p1['series'][1]['date'] );
		$this->assertSame( 30.00, (float) $p1['series'][1]['net_revenue'] );
		$this->assertSame( 1, (int) $p1['series'][1]['quantity'] );

		$this->assertSame( '2025-10-22', $p1['series'][2]['date'] );
		$this->assertSame(
			0.00,
			(float) $p1['series'][2]['net_revenue'],
			'Refund sub-order date shows up as a bucket; CASE zeroes the values.'
		);
		$this->assertSame( 0, (int) $p1['series'][2]['quantity'] );
	}

	/**
	 * When the date range exceeds the series cap the ability returns a
	 * WP_Error — no data at all — so Claude cannot analyse or chart
	 * anything before asking the merchant for their choice.
	 */
	public function test_series_returns_wp_error_when_range_exceeded() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// 731-day range → heavy-scan gate fires (threshold is 365 days).
		// No fixture data needed — the gate fires before any SQL runs.
		$ability = wp_get_ability( 'wc-analytics/get-product-performance' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'date_start' => '2023-01-01',
				'date_end'   => '2025-01-01',
				'interval'   => 'day',
			)
		);

		$this->assertTrue( is_wp_error( $result ), 'Expected WP_Error when date range exceeds 365-day threshold.' );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertTrue( $data['confirmation_required'] );
		$this->assertNotEmpty( $data['confirmation_token'] );
		$this->assertSame( 400, $data['status'] );
		$this->assertGreaterThan( 365, $data['cost_estimate']['range_days'] );
	}
}
