<?php
/**
 * Integration tests — wc-analytics/query-analytics (products entity).
 *
 * Sibling class to Test_Query_Analytics (which covers the orders entity).
 * The registered-ability guard in test-ability-registration.php only
 * requires test-query-analytics.php to exist; sibling files are allowed
 * and keep each entity's fixture self-contained.
 *
 * Fixture shape:
 *
 *   Categories: "Apparel", "Electronics"
 *
 *   Products (all published unless noted):
 *     P1: Blue T-Shirt,    Apparel,     £50,   stock=instock,    qty=20
 *     P2: Red Dress,       Apparel,     £120,  stock=outofstock, qty=0
 *     P3: Headphones,      Electronics, £80,   stock=instock,    qty=5
 *     P4: Premium Speaker, Electronics, £200,  stock=instock,    qty=10  (0 sales in period)
 *     P5: Cable,           Electronics, £30 (regular £40 — on sale), qty=50
 *     P6: Hoodie (draft),  Apparel,     £60,   stock=instock,    qty=15  (excluded from baseline — not published)
 *
 *   Period orders (all paid, 2025-10-01..2025-10-31):
 *     O1: P1 × 3, Customer A, 2025-10-05        → P1 units=3, revenue=£150
 *     O2: P1 × 1 + P3 × 1, Customer B, 2025-10-08 → P1 units=1 revenue=£50, P3 units=1 revenue=£80
 *     O3: P2 × 1, Customer C, 2025-10-12        → P2 units=1, revenue=£120
 *     O4: P5 × 2, Customer D, 2025-10-15        → P5 units=2, revenue=£60
 *     O5: P5 × 1, Customer E, 2025-10-20        → P5 units=1, revenue=£30
 *     O6: P5 × 3, Customer F, 2025-10-25        → P5 units=3, revenue=£90
 *
 *   Per-product totals in period:
 *     P1: units=4  revenue=£200 orders=2
 *     P2: units=1  revenue=£120 orders=1
 *     P3: units=1  revenue=£80  orders=1
 *     P4: units=0  revenue=£0   orders=0
 *     P5: units=6  revenue=£180 orders=3
 *
 * Published baseline (P1+P2+P3+P4+P5, P6 draft excluded):
 *   matched_count             = 5
 *   total_revenue_in_period   = £580
 *   total_units_sold_in_period = 12
 *   products_with_sales_count = 4 (P4 has zero sales)
 *   products_with_zero_sales  = 1 (P4)
 *
 * @package HeyWoo\Tests
 */

/**
 * Integration tests for the products-entity branch of query-analytics.
 */
class Test_Query_Analytics_Products extends WP_UnitTestCase {

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
	 * Fixture product IDs.
	 *
	 * @var array<string, int>
	 */
	private $products = array();

	/**
	 * Seed the catalog + 6 orders as described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		$apparel     = $this->seed_product_category( 'Apparel' );
		$electronics = $this->seed_product_category( 'Electronics' );

		$this->products['p1'] = $this->seed_product_with_details(
			array(
				'name'           => 'Blue T-Shirt',
				'sku'            => 'APP-SHIRT-BLUE',
				'price'          => 50,
				'stock_status'   => 'instock',
				'stock_quantity' => 20,
				'category_ids'   => array( $apparel ),
			)
		);

		$this->products['p2'] = $this->seed_product_with_details(
			array(
				'name'           => 'Red Dress',
				'sku'            => 'APP-DRESS-RED',
				'price'          => 120,
				'stock_status'   => 'outofstock',
				'stock_quantity' => 0,
				'category_ids'   => array( $apparel ),
			)
		);

		$this->products['p3'] = $this->seed_product_with_details(
			array(
				'name'           => 'Headphones',
				'sku'            => 'ELE-HEADPHONES',
				'price'          => 80,
				'stock_status'   => 'instock',
				'stock_quantity' => 5,
				'category_ids'   => array( $electronics ),
			)
		);

		$this->products['p4'] = $this->seed_product_with_details(
			array(
				'name'           => 'Premium Speaker',
				'sku'            => 'ELE-SPEAKER',
				'price'          => 200,
				'stock_status'   => 'instock',
				'stock_quantity' => 10,
				'category_ids'   => array( $electronics ),
			)
		);

		$this->products['p5'] = $this->seed_product_with_details(
			array(
				'name'           => 'Charging Cable',
				'sku'            => 'ELE-CABLE',
				'price'          => 40,
				'sale_price'     => 30,
				'stock_status'   => 'instock',
				'stock_quantity' => 50,
				'category_ids'   => array( $electronics ),
			)
		);

		$this->products['p6'] = $this->seed_product_with_details(
			array(
				'name'           => 'Hoodie Draft',
				'sku'            => 'APP-HOODIE-DRAFT',
				'price'          => 60,
				'stock_status'   => 'instock',
				'stock_quantity' => 15,
				'status'         => 'draft',
				'category_ids'   => array( $apparel ),
			)
		);

		// Orders — line-item quantities drive the revenue numbers above.
		$cust_a = $this->seed_customer();
		$cust_b = $this->seed_customer();
		$cust_c = $this->seed_customer();
		$cust_d = $this->seed_customer();
		$cust_e = $this->seed_customer();
		$cust_f = $this->seed_customer();

		$this->seed_paid_order(
			array(
				'customer_id' => $cust_a,
				'total'       => 150.00,
				'date'        => '2025-10-05 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->products['p1'],
						'qty'        => 3,
					),
				),
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_b,
				'total'       => 130.00,
				'date'        => '2025-10-08 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->products['p1'],
						'qty'        => 1,
					),
					array(
						'product_id' => $this->products['p3'],
						'qty'        => 1,
					),
				),
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_c,
				'total'       => 120.00,
				'date'        => '2025-10-12 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->products['p2'],
						'qty'        => 1,
					),
				),
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_d,
				'total'       => 60.00,
				'date'        => '2025-10-15 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->products['p5'],
						'qty'        => 2,
					),
				),
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_e,
				'total'       => 30.00,
				'date'        => '2025-10-20 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->products['p5'],
						'qty'        => 1,
					),
				),
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_f,
				'total'       => 90.00,
				'date'        => '2025-10-25 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->products['p5'],
						'qty'        => 3,
					),
				),
			)
		);
	}

	/**
	 * Catalog seeder — extends seed_simple_product to cover stock_quantity,
	 * sale_price, and status, which the trait doesn't support directly.
	 * The trait defaults everything to "publish"; this helper overrides
	 * after save for the draft / variable-price cases.
	 *
	 * @param array $args Args dict (name, sku, price, stock_status,
	 *                   stock_quantity, sale_price, category_ids, status).
	 * @return int Product ID.
	 */
	private function seed_product_with_details( array $args ) {
		$id = $this->seed_simple_product(
			array(
				'name'         => $args['name'],
				'sku'          => $args['sku'],
				'price'        => $args['price'],
				'stock_status' => $args['stock_status'] ?? 'instock',
				'category_ids' => $args['category_ids'] ?? array(),
			)
		);

		$product = wc_get_product( $id );
		if ( isset( $args['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $args['stock_quantity'] );
		}
		if ( isset( $args['sale_price'] ) ) {
			$product->set_sale_price( (string) $args['sale_price'] );
			$product->set_price( (string) $args['sale_price'] );
		}
		if ( isset( $args['status'] ) ) {
			$product->set_status( (string) $args['status'] );
		}
		$product->save();

		return $id;
	}

	/**
	 * Ability-invocation helper. Fixes entity=products and the period
	 * window so tests don't depend on wall-clock.
	 *
	 * @param array $input Extra input.
	 * @return array
	 */
	private function run_ability( array $input = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$defaults = array(
			'entity'     => 'products',
			'date_start' => $this->period_start,
			'date_end'   => $this->period_end,
		);
		$input    = array_merge( $defaults, $input );

		$ability = wp_get_ability( 'wc-analytics/query-analytics' );
		$this->assertNotNull( $ability, 'query-analytics ability not registered' );

		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) ) {
			$this->fail( 'query-analytics returned WP_Error: ' . $result->get_error_code() . ' — ' . $result->get_error_message() );
		}

		return $result;
	}

	/**
	 * Baseline — 5 published products (P6 is draft, excluded by default
	 * status=publish filter). Sales totals reconcile to the fixture math.
	 */
	public function test_baseline_published_only() {
		$result = $this->run_ability();

		$this->assertSame( 'products', $result['entity'] );
		$this->assertSame( 5, $result['summary']['matched_count'] );
		$this->assertSame( 580.0, $result['summary']['total_revenue_in_period'] );
		$this->assertSame( 12, $result['summary']['total_units_sold_in_period'] );
		$this->assertSame( 4, $result['summary']['products_with_sales_count'] );
		$this->assertSame( 1, $result['summary']['products_with_zero_sales'] );
		$this->assertSame( 1, $result['summary']['out_of_stock_count'] );

		$this->assertNull( $result['pipeline'] );
		$this->assertNull( $result['admin_equivalent'] );
	}

	/**
	 * Numeric filter price > 50 — P2 (£120) + P3 (£80) + P4 (£200) = 3 published
	 * products. Their in-period revenue sums to £120 + £80 + £0 = £200.
	 */
	public function test_price_greater_than_50() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'price',
						'operator' => 'greater_than',
						'value'    => 50,
					),
				),
			)
		);

		$this->assertSame( 3, $result['summary']['matched_count'] );
		$this->assertSame( 200.0, $result['summary']['total_revenue_in_period'] );
		$this->assertSame( 2, $result['summary']['products_with_sales_count'] );
		$this->assertSame( 1, $result['summary']['products_with_zero_sales'] );
	}

	/**
	 * Category filter via term-relationship subquery. Apparel published
	 * products: P1 + P2 (P6 is draft).
	 */
	public function test_category_filter() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'category',
						'operator' => 'is',
						'value'    => 'apparel',
					),
				),
			)
		);

		$this->assertSame( 2, $result['summary']['matched_count'] );
		$this->assertSame( 320.0, $result['summary']['total_revenue_in_period'] );
	}

	/**
	 * Filter stock_status = outofstock — only P2. Catalog attribute as-of-now,
	 * unaffected by period.
	 */
	public function test_stock_status_outofstock() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'stock_status',
						'operator' => 'is',
						'value'    => 'outofstock',
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertSame( 120.0, $result['summary']['total_revenue_in_period'] );
		$this->assertNotNull( $result['sample_size_caveat'] );
	}

	/**
	 * Zero-sales filter (units_sold_in_period = 0) — "catalog but not moving". P4 only
	 * (the only published product with zero sales in the period).
	 */
	public function test_products_with_zero_sales() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'units_sold_in_period',
						'operator' => 'is',
						'value'    => 0,
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertSame( 0.0, $result['summary']['total_revenue_in_period'] );
	}

	/**
	 * Combined catalog + velocity filter — price > 100 AND zero sales.
	 * P4 (£200, 0 sales). This is the flagship "expensive but not moving"
	 * question the products entity earns.
	 */
	public function test_expensive_and_not_moving() {
		$result = $this->run_ability(
			array(
				'match'   => 'all',
				'filters' => array(
					array(
						'field'    => 'price',
						'operator' => 'greater_than',
						'value'    => 100,
					),
					array(
						'field'    => 'units_sold_in_period',
						'operator' => 'is',
						'value'    => 0,
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertSame( 0.0, $result['summary']['total_revenue_in_period'] );
	}

	/**
	 * Boolean onsale = true — P5 only (has sale_price < regular_price).
	 */
	public function test_onsale_filter() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'onsale',
						'operator' => 'is',
						'value'    => true,
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertSame( 180.0, $result['summary']['total_revenue_in_period'] );
	}

	/**
	 * Rows mode — returns per-product rows with catalog + period fields.
	 * Default ordering is revenue_in_period DESC, so P1 (£200) comes first.
	 */
	public function test_rows_mode_returns_product_details() {
		$result = $this->run_ability(
			array(
				'mode'  => 'rows',
				'limit' => 10,
			)
		);

		$this->assertSame( 'rows', $result['mode'] );
		$this->assertIsArray( $result['rows'] );
		$this->assertCount( 5, $result['rows'] );

		// Default sort: revenue_in_period DESC. P1 (£200) leads.
		$this->assertSame( $this->products['p1'], $result['rows'][0]['product_id'] );
		$this->assertSame( 200.0, $result['rows'][0]['revenue_in_period'] );
		$this->assertSame( 4, $result['rows'][0]['units_sold_in_period'] );

		foreach ( $result['rows'] as $row ) {
			$this->assertArrayHasKey( 'name', $row );
			$this->assertArrayHasKey( 'sku', $row );
			$this->assertArrayHasKey( 'price', $row );
			$this->assertArrayHasKey( 'stock_status', $row );
			$this->assertArrayHasKey( 'onsale', $row );
		}
	}

	/**
	 * Status filter — including draft products. Asking for
	 * status=draft bypasses the default publish-only filter and
	 * returns P6 only.
	 */
	public function test_status_filter_includes_draft() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'status',
						'operator' => 'is',
						'value'    => 'draft',
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
	}
}
