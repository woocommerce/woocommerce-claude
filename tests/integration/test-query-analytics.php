<?php
/**
 * Integration tests — wc-analytics/query-analytics (orders entity).
 *
 * Pins the invariants for the orders-entity branch of the flexible
 * filter engine. Products + customers branches ship in later commits
 * with their own dedicated test classes alongside this one.
 *
 * What's pinned:
 *
 *   - Baseline aggregate envelope: summary / universe / share_of_universe /
 *     pipeline / admin_equivalent (pipeline + admin omitted when the
 *     merchant supplies an explicit status filter)
 *   - Single numeric filter (greater_than on order_total)
 *   - Multi-filter AND vs OR semantics via `match`
 *   - Set-membership (is_in) and boolean filters
 *   - Coupon JOIN fires only when coupon_code is filtered (no over-counting)
 *   - Attribution JOIN lands the right per-channel rows
 *   - Line-item product_id filter uses EXISTS (no row duplication)
 *   - Explicit status filter suppresses pipeline + admin_equivalent and
 *     sets a reconciliation note
 *   - share_of_universe is pre-computed against the paid-order universe
 *   - sample_size_caveat fires at matched_count ≤ 5
 *   - Rows mode returns pseudonymised customer ids; no email/name in default
 *   - Unknown field / invalid operator return WP_Error
 *
 * Fixture shape (period 2025-10-01..2025-10-31, prior 2025-09-01..2025-09-30):
 *
 *   One simple product P1 at £40. Order totals are qty × £40 —
 *   `seed_paid_order` recomputes from line items whenever `items` is
 *   supplied, which makes qty the single source of truth for each
 *   fixture row's total.
 *
 *   Prior-period paid orders (establish returning_customer flag):
 *     prior_B: Customer B, qty 1 (£40 total), paid, 2025-09-15
 *     prior_E: Customer E, qty 1 (£40 total), paid, 2025-09-18
 *     prior_F: Customer F, qty 1 (£40 total), paid, 2025-09-25
 *
 *   Current-period orders (qty → total):
 *     O1: Customer A, qty 3  (£120), paid,    DE, 2025-10-05,
 *         coupon SAVE15 (£10), channel "Organic Search", desktop, stripe
 *     O2: Customer B, qty 2  (£80),  paid,    DE, 2025-10-10,
 *         no coupon,           channel "Paid Search",    mobile,  paypal
 *     O3: Customer C, qty 4  (£160), paid,    GB, 2025-10-15,
 *         no coupon,           channel "Organic Search", desktop, stripe
 *     O4: Customer D, qty 1  (£40),  paid,    FR, 2025-10-18,
 *         coupon SAVE10 (£5),  channel "Direct",         mobile,  stripe
 *     O5: Customer E, qty 2  (£80),  on-hold, DE, 2025-10-20,
 *         no coupon,           channel "Email",          mobile,  bacs
 *     O6: Customer F, qty 10 (£400), paid,    US, 2025-10-25,
 *         no coupon,           channel "Paid Search",    desktop, stripe
 *
 * Paid-view totals:
 *   matched_count = 5   (O1+O2+O3+O4+O6)
 *   net_revenue   = £800 (120+80+160+40+400)
 *   AOV           = £160 (800/5)
 *   new_customer_orders       = 3 (A, C, D — no prior paid order)
 *   returning_customer_orders = 2 (B, F — had prior paid orders)
 *
 * Pipeline totals: 1 order (O5, £80).
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the orders-entity branch of query-analytics.
 */
class Test_Query_Analytics extends WP_UnitTestCase {

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
	 * Seed the fixture shape described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		$this->p1 = $this->seed_simple_product(
			array(
				'name'  => 'Blue T-Shirt',
				'sku'   => 'SHIRT-BLUE',
				'price' => 40,
			)
		);

		// Prior-period orders — give B / E / F a prior paid history so
		// their in-period orders show returning_customer = 1.
		$cust_a = $this->seed_customer();
		$cust_b = $this->seed_customer();
		$cust_c = $this->seed_customer();
		$cust_d = $this->seed_customer();
		$cust_e = $this->seed_customer();
		$cust_f = $this->seed_customer();

		$this->seed_paid_order(
			array(
				'customer_id' => $cust_b,
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
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_e,
				'total'       => 30.00,
				'date'        => '2025-09-18 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
				),
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $cust_f,
				'total'       => 30.00,
				'date'        => '2025-09-25 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
				),
			)
		);

		// Current-period orders.
		$o1 = $this->seed_paid_order(
			array(
				'customer_id'          => $cust_a,
				'total'                => 120.00,
				'date'                 => '2025-10-05 10:00:00',
				'items'                => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 3,
					),
				),
				'attribution'          => array(
					'channel' => 'Organic Search',
					'device'  => 'desktop',
				),
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit Card (Stripe)',
			)
		);
		$this->set_billing_country( $o1, 'DE' );
		$this->attach_coupon( $o1, 'SAVE15', 10.00 );

		$o2 = $this->seed_paid_order(
			array(
				'customer_id'          => $cust_b,
				'total'                => 80.00,
				'date'                 => '2025-10-10 10:00:00',
				'items'                => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 2,
					),
				),
				'attribution'          => array(
					'channel' => 'Paid Search',
					'device'  => 'mobile',
				),
				'payment_method'       => 'paypal',
				'payment_method_title' => 'PayPal',
			)
		);
		$this->set_billing_country( $o2, 'DE' );

		$o3 = $this->seed_paid_order(
			array(
				'customer_id'          => $cust_c,
				'total'                => 180.00,
				'date'                 => '2025-10-15 10:00:00',
				'items'                => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 4,
					),
				),
				'attribution'          => array(
					'channel' => 'Organic Search',
					'device'  => 'desktop',
				),
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit Card (Stripe)',
			)
		);
		$this->set_billing_country( $o3, 'GB' );

		$o4 = $this->seed_paid_order(
			array(
				'customer_id'          => $cust_d,
				'total'                => 40.00,
				'date'                 => '2025-10-18 10:00:00',
				'items'                => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
				),
				'attribution'          => array(
					'channel' => 'Direct',
					'device'  => 'mobile',
				),
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit Card (Stripe)',
			)
		);
		$this->set_billing_country( $o4, 'FR' );
		$this->attach_coupon( $o4, 'SAVE10', 5.00 );

		$o5 = $this->seed_paid_order(
			array(
				'customer_id'          => $cust_e,
				'total'                => 80.00,
				'date'                 => '2025-10-20 10:00:00',
				'status'               => 'on-hold',
				'items'                => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 2,
					),
				),
				'attribution'          => array(
					'channel' => 'Email',
					'device'  => 'mobile',
				),
				'payment_method'       => 'bacs',
				'payment_method_title' => 'Direct Bank Transfer',
			)
		);
		$this->set_billing_country( $o5, 'DE' );

		$o6 = $this->seed_paid_order(
			array(
				'customer_id'          => $cust_f,
				'total'                => 500.00,
				'date'                 => '2025-10-25 10:00:00',
				'items'                => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 10,
					),
				),
				'attribution'          => array(
					'channel' => 'Paid Search',
					'device'  => 'desktop',
				),
				'payment_method'       => 'stripe',
				'payment_method_title' => 'Credit Card (Stripe)',
			)
		);
		$this->set_billing_country( $o6, 'US' );
	}

	/**
	 * Ability-invocation helper. Fixes the period to the docblock window so
	 * the tests don't depend on the test-run wall-clock falling inside any
	 * particular calendar month.
	 *
	 * @param array $input Extra input fields.
	 * @return array
	 */
	private function run_ability( array $input = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$defaults = array(
			'entity'     => 'orders',
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
	 * Write billing_country on the HPOS wc_order_addresses row. The
	 * `seed_paid_order` trait helper doesn't hydrate addresses, so tests
	 * that care about geography write the country directly after seeding.
	 *
	 * @param \WC_Order $order   Order instance returned by seed_paid_order.
	 * @param string    $country ISO-2 country code.
	 */
	private function set_billing_country( $order, $country ) {
		$order->set_billing_country( $country );
		$order->save();
	}

	/**
	 * Insert a coupon post + attach it to the order via wc_order_coupon_lookup.
	 * Same pattern used by the coupon-performance test.
	 *
	 * @param \WC_Order $order    Order to attach coupon to.
	 * @param string    $code     Coupon code.
	 * @param float     $discount Discount amount attributed to this order.
	 */
	private function attach_coupon( $order, $code, $discount ) {
		global $wpdb;

		$coupon_id = wp_insert_post(
			array(
				'post_title'  => $code,
				'post_status' => 'publish',
				'post_type'   => 'shop_coupon',
			)
		);
		if ( is_wp_error( $coupon_id ) || 0 === $coupon_id ) {
			$this->fail( 'attach_coupon: wp_insert_post failed.' );
		}
		update_post_meta( $coupon_id, 'discount_type', 'fixed_cart' );
		update_post_meta( $coupon_id, 'coupon_amount', (string) $discount );

		$date     = $order->get_date_created();
		$date_str = $date ? $date->date( 'Y-m-d H:i:s' ) : current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture; see test-get-coupon-performance.php for the same pattern.
		$wpdb->insert(
			$wpdb->prefix . 'wc_order_coupon_lookup',
			array(
				'order_id'        => $order->get_id(),
				'coupon_id'       => (int) $coupon_id,
				'date_created'    => $date_str,
				'discount_amount' => (float) $discount,
			)
		);
	}

	/**
	 * Baseline aggregate with no filters — the paid-view universe IS
	 * the matched set, so share_of_universe reads 100%.
	 */
	public function test_baseline_no_filters() {
		$result = $this->run_ability();

		$this->assertSame( 'orders', $result['entity'] );
		$this->assertSame( 'aggregate', $result['mode'] );
		$this->assertSame( 'all', $result['match'] );

		$this->assertSame( 5, $result['summary']['matched_count'] );
		$this->assertSame( 800.0, $result['summary']['net_revenue'] );
		$this->assertSame( 3, $result['summary']['new_customer_orders'] );
		$this->assertSame( 2, $result['summary']['returning_customer_orders'] );
		$this->assertSame( 160.0, $result['summary']['avg_order_value'] );

		$this->assertSame( 5, $result['universe']['orders_count_in_period'] );
		$this->assertSame( 800.0, $result['universe']['revenue_in_period'] );

		$this->assertSame( 100.0, $result['share_of_universe']['share_of_orders_percent'] );
		$this->assertSame( 100.0, $result['share_of_universe']['share_of_revenue_percent'] );

		$this->assertIsArray( $result['pipeline'] );
		$this->assertSame( 1, $result['pipeline']['matched_count'] );
		$this->assertSame( 80.0, $result['pipeline']['net_revenue'] );

		$this->assertIsArray( $result['admin_equivalent'] );
		$this->assertSame( 6, $result['admin_equivalent']['matched_count'] );

		// sample_size_caveat fires at matched_count ≤ 5 — the fixture happens
		// to sit at exactly 5 paid orders. Assert the presence not the
		// absence; the dedicated small-N test below pins threshold behaviour.
		$this->assertNotNull( $result['sample_size_caveat'] );
		$this->assertNull( $result['note'] );
	}

	/**
	 * Single numeric greater_than filter — three orders over £100.
	 */
	public function test_numeric_greater_than_filter() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'order_total',
						'operator' => 'greater_than',
						'value'    => 100,
					),
				),
			)
		);

		$this->assertSame( 3, $result['summary']['matched_count'] );
		$this->assertSame( 680.0, $result['summary']['net_revenue'] );
		$this->assertSame( 60.0, $result['share_of_universe']['share_of_orders_percent'] );
		$this->assertSame( 85.0, $result['share_of_universe']['share_of_revenue_percent'] );
	}

	/**
	 * Match=all — DE AND order_total > 50. Matches O1 (£120) + O2 (£80).
	 */
	public function test_multi_filter_and() {
		$result = $this->run_ability(
			array(
				'match'   => 'all',
				'filters' => array(
					array(
						'field'    => 'billing_country',
						'operator' => 'is',
						'value'    => 'DE',
					),
					array(
						'field'    => 'order_total',
						'operator' => 'greater_than',
						'value'    => 50,
					),
				),
			)
		);

		$this->assertSame( 2, $result['summary']['matched_count'] );
		$this->assertSame( 200.0, $result['summary']['net_revenue'] );
	}

	/**
	 * Match=any — DE OR GB. Matches O1+O2+O3.
	 */
	public function test_multi_filter_or() {
		$result = $this->run_ability(
			array(
				'match'   => 'any',
				'filters' => array(
					array(
						'field'    => 'billing_country',
						'operator' => 'is',
						'value'    => 'DE',
					),
					array(
						'field'    => 'billing_country',
						'operator' => 'is',
						'value'    => 'GB',
					),
				),
			)
		);

		$this->assertSame( 3, $result['summary']['matched_count'] );
		$this->assertSame( 360.0, $result['summary']['net_revenue'] );
	}

	/**
	 * Set-membership via is_in — country ∈ {DE, FR}. Matches O1(120)+O2(80)+O4(40).
	 */
	public function test_is_in_operator() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'billing_country',
						'operator' => 'is_in',
						'value'    => array( 'DE', 'FR' ),
					),
				),
			)
		);

		$this->assertSame( 3, $result['summary']['matched_count'] );
		$this->assertSame( 240.0, $result['summary']['net_revenue'] );
	}

	/**
	 * Boolean filter — returning_customer = true. Matches O2(80)+O6(400).
	 */
	public function test_boolean_returning_customer_filter() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'returning_customer',
						'operator' => 'is',
						'value'    => true,
					),
				),
			)
		);

		$this->assertSame( 2, $result['summary']['matched_count'] );
		$this->assertSame( 480.0, $result['summary']['net_revenue'] );
	}

	/**
	 * Coupon JOIN fires only when referenced — non-coupon filter should
	 * NOT double-count any multi-coupon orders (our fixture has one coupon
	 * per order, but the aggregated subquery pattern is what prevents
	 * row duplication).
	 */
	public function test_coupon_code_filter() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'coupon_code',
						'operator' => 'is',
						'value'    => 'SAVE15',
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertSame( 120.0, $result['summary']['net_revenue'] );
		$this->assertStringContainsString( 'Small sample', (string) $result['sample_size_caveat'] );
	}

	/**
	 * Attribution channel — Organic Search. Matches O1+O3.
	 */
	public function test_attribution_channel_filter() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'attribution_channel',
						'operator' => 'is',
						'value'    => 'Organic Search',
					),
				),
			)
		);

		$this->assertSame( 2, $result['summary']['matched_count'] );
		$this->assertSame( 280.0, $result['summary']['net_revenue'] );
	}

	/**
	 * Attribution channel is_in [Paid Search, Direct] — O2(80)+O4(40)+O6(400).
	 */
	public function test_attribution_channel_is_in() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'attribution_channel',
						'operator' => 'is_in',
						'value'    => array( 'Paid Search', 'Direct' ),
					),
				),
			)
		);

		$this->assertSame( 3, $result['summary']['matched_count'] );
		$this->assertSame( 520.0, $result['summary']['net_revenue'] );
	}

	/**
	 * The product_id filter uses EXISTS — a single order is never counted twice
	 * even when it has multiple line items for the same product.
	 */
	public function test_product_id_filter_does_not_duplicate() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'product_id',
						'operator' => 'is',
						'value'    => $this->p1,
					),
				),
			)
		);

		// Every paid order has p1 line items — expect all 5 with no duplication.
		$this->assertSame( 5, $result['summary']['matched_count'] );
		$this->assertSame( 800.0, $result['summary']['net_revenue'] );
	}

	/**
	 * Explicit status filter — pipeline + admin_equivalent sibling blocks
	 * must be suppressed (they'd conflict with the merchant's explicit
	 * status choice) and a note must explain why.
	 */
	public function test_explicit_status_filter_suppresses_siblings() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'status',
						'operator' => 'is',
						'value'    => 'on-hold',
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertSame( 80.0, $result['summary']['net_revenue'] );

		$this->assertNull( $result['pipeline'] );
		$this->assertNull( $result['admin_equivalent'] );

		$this->assertNotNull( $result['note'] );
		$this->assertStringContainsString( 'Explicit status filter', $result['note'] );
	}

	/**
	 * Filter that matches nothing — summary comes back with zeros, note
	 * explains "no matches", pipeline/admin sibling blocks still fire
	 * (they're the filter applied against different status sets, which
	 * may still return 0 too — see the assertion).
	 */
	public function test_empty_match_returns_note() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'order_total',
						'operator' => 'greater_than',
						'value'    => 10000,
					),
				),
			)
		);

		$this->assertSame( 0, $result['summary']['matched_count'] );
		$this->assertSame( 0.0, $result['summary']['net_revenue'] );
		$this->assertNotNull( $result['note'] );
		$this->assertStringContainsString( 'No orders matched', $result['note'] );
		$this->assertNull( $result['sample_size_caveat'] );
	}

	/**
	 * Small-N caveat fires when matched_count ∈ [1, 5]. France has 1 order.
	 */
	public function test_small_n_caveat_fires_at_one_order() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'billing_country',
						'operator' => 'is',
						'value'    => 'FR',
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertNotNull( $result['sample_size_caveat'] );
		$this->assertStringContainsString( 'Small sample', $result['sample_size_caveat'] );
	}

	/**
	 * Rows mode returns a row-per-order list with pseudonymised customer
	 * id ("Customer #N") — billing email / name are never returned.
	 */
	public function test_rows_mode_pseudonymisation() {
		$result = $this->run_ability(
			array(
				'mode'  => 'rows',
				'limit' => 10,
			)
		);

		$this->assertSame( 'rows', $result['mode'] );
		$this->assertSame( 'pseudonymised', $result['privacy_mode'] );
		$this->assertIsArray( $result['rows'] );
		$this->assertCount( 5, $result['rows'] );

		foreach ( $result['rows'] as $row ) {
			$this->assertArrayHasKey( 'order_id', $row );
			$this->assertArrayHasKey( 'order_ref', $row );
			$this->assertArrayHasKey( 'customer_id_pseudo', $row );
			$this->assertArrayNotHasKey( 'billing_email', $row );
			$this->assertArrayNotHasKey( 'billing_first_name', $row );
			$this->assertMatchesRegularExpression( '/^(Customer #\d+|Guest)$/', $row['customer_id_pseudo'] );
		}
	}

	/**
	 * Unknown field returns WP_Error with the available fields in the
	 * data payload.
	 */
	public function test_unknown_field_returns_wp_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$ability = wp_get_ability( 'wc-analytics/query-analytics' );
		$result  = $ability->execute(
			array(
				'entity'  => 'orders',
				'filters' => array(
					array(
						'field'    => 'this_field_does_not_exist',
						'operator' => 'is',
						'value'    => 'x',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'unknown_field', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'available_fields', $data );
		$this->assertContains( 'order_total', $data['available_fields'] );
	}

	/**
	 * Operator that's invalid for the field's type returns WP_Error.
	 * `contains` is a string-only operator; applying it to `order_total`
	 * (numeric) should be rejected.
	 */
	public function test_invalid_operator_for_type_returns_wp_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$ability = wp_get_ability( 'wc-analytics/query-analytics' );
		$result  = $ability->execute(
			array(
				'entity'  => 'orders',
				'filters' => array(
					array(
						'field'    => 'order_total',
						'operator' => 'contains',
						'value'    => 'foo',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_operator', $result->get_error_code() );
	}

	/**
	 * Products entity smoke test — the fixture seeded one product that
	 * sold across 6 orders. A no-filter products call should find it and
	 * include its period-scoped sales aggregates in the summary.
	 * Full products suite lives in a sibling test class.
	 */
	public function test_products_entity_smoke() {
		$result = $this->run_ability(
			array(
				'entity'     => 'products',
				'date_start' => $this->period_start,
				'date_end'   => $this->period_end,
			)
		);

		$this->assertSame( 'products', $result['entity'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'matched_count', $result['summary'] );
		$this->assertArrayHasKey( 'total_revenue_in_period', $result['summary'] );
		$this->assertGreaterThanOrEqual( 1, $result['summary']['matched_count'] );
		$this->assertNull( $result['pipeline'] );
		$this->assertNull( $result['admin_equivalent'] );
	}

	/**
	 * Customers entity smoke test — fixture seeds 6 distinct customers
	 * with in-period paid orders (A/B/C/D/E/F on O1-O6; E's is on-hold
	 * so doesn't count toward active base). Active-base frame returns
	 * at most 5 active customers for last-30-days windows that catch
	 * O1-O4+O6. Full customers suite lives in a sibling test class.
	 */
	public function test_customers_entity_smoke() {
		$result = $this->run_ability(
			array(
				'entity'     => 'customers',
				'date_start' => $this->period_start,
				'date_end'   => $this->period_end,
			)
		);

		$this->assertSame( 'customers', $result['entity'] );
		$this->assertIsArray( $result['summary'] );
		$this->assertArrayHasKey( 'matched_count', $result['summary'] );
		$this->assertArrayHasKey( 'total_lifetime_spend', $result['summary'] );
		$this->assertGreaterThanOrEqual( 1, $result['summary']['matched_count'] );
		$this->assertNull( $result['pipeline'] );
		$this->assertNull( $result['admin_equivalent'] );
	}

	/**
	 * Default-state (HPOS-enabled via bootstrap) → get_order_addresses_source()
	 * returns the HPOS shape + wc_order_addresses table.
	 *
	 * Sibling to test-get-attribution.php's meta-source contract test — pins
	 * the detection helper that qa_orders_field_registry() delegates to for
	 * the six address-field registry entries.
	 */
	public function test_addresses_source_hpos_path_returns_wc_order_addresses() {
		global $wpdb;

		$this->assertSame(
			'yes',
			get_option( 'woocommerce_custom_orders_table_enabled', 'no' ),
			'Bootstrap enables HPOS — failure here means the bootstrap regressed.'
		);

		$source = \WooCommerce\Claude\API\AnalyticsController::get_order_addresses_source();
		$this->assertSame( 'hpos', $source['mode'] );
		$this->assertSame( $wpdb->prefix . 'wc_order_addresses', $source['table'] );
		$this->assertSame( 'order_id', $source['id_column'] );
	}

	/**
	 * Forced-classic path → get_order_addresses_source() returns the postmeta
	 * shape + post_id join column.
	 *
	 * Uses `pre_option_woocommerce_custom_orders_table_enabled` filter rather
	 * than update_option — CustomOrdersTableController throws on the
	 * pre-update hook when orders are out of sync. OrderUtil reads the option
	 * fresh each call so the filter takes effect immediately.
	 */
	public function test_addresses_source_classic_path_returns_postmeta() {
		global $wpdb;

		$force_no = function () {
			return 'no';
		};
		add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $force_no );

		try {
			$source = \WooCommerce\Claude\API\AnalyticsController::get_order_addresses_source();
			$this->assertSame( 'classic', $source['mode'] );
			$this->assertSame( $wpdb->prefix . 'postmeta', $source['table'] );
			$this->assertSame( 'post_id', $source['id_column'] );
		} finally {
			remove_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $force_no );
		}
	}

	/**
	 * Regression — filtering by billing_country under forced-classic storage
	 * executes cleanly.
	 *
	 * Bug (pre-fix): qa_orders_field_registry() hardcoded the HPOS
	 * `wc_order_addresses` table regardless of storage mode. On classic
	 * stores, any billing_country / billing_state / billing_city /
	 * billing_postcode / shipping_country / shipping_state filter generated
	 * a JOIN to a non-existent table → "Table doesn't exist" SQL error,
	 * which the MCP bridge swallowed as a 60s hang.
	 *
	 * Fix: address-field registry entries delegate to
	 * qa_orders_address_fields($mode, ...) which branches on
	 * `get_order_addresses_source()['mode']` — HPOS uses the shared
	 * `ba` / `sa` aliases; classic joins per-field to postmeta with the
	 * `_billing_*` / `_shipping_*` meta keys.
	 *
	 * Expected behaviour here: forced-classic mode; matched_count is 0
	 * because the fixture's addresses were written via WC's API under HPOS
	 * (data lives in wc_order_addresses, not postmeta). What's being pinned
	 * is "no error, query runs, returns zero matches" — not "correct count
	 * on classic data." The latter would require re-seeding in classic mode
	 * at test boot, which is out of scope for a targeted regression.
	 */
	public function test_billing_country_filter_in_classic_mode_does_not_error() {
		$force_no = function () {
			return 'no';
		};
		add_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $force_no );

		try {
			$result = $this->run_ability(
				array(
					'filters' => array(
						array(
							'field'    => 'billing_country',
							'operator' => 'is',
							'value'    => 'DE',
						),
					),
				)
			);

			$this->assertIsArray( $result, 'Ability must return an array, not WP_Error.' );
			$this->assertArrayHasKey( 'summary', $result );
			$this->assertSame( 0, (int) $result['summary']['matched_count'], 'Classic-mode query against postmeta returns zero (fixture data lives in wc_order_addresses) — but critically, it runs cleanly rather than failing with "table doesn\'t exist".' );
		} finally {
			remove_filter( 'pre_option_woocommerce_custom_orders_table_enabled', $force_no );
		}
	}
}
