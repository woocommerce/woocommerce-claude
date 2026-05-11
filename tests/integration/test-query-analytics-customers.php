<?php
/**
 * Integration tests — wc-analytics/query-analytics (customers entity).
 *
 * Sibling class to Test_Query_Analytics + Test_Query_Analytics_Products.
 *
 * Fixture shape (period 2025-10-01..2025-10-31):
 *
 *   Customers (all have ≥1 paid order IN period — active base):
 *     C1: US, 5 lifetime paid orders (4 prior + 1 in-period),
 *         lifetime_spend = £1500
 *     C2: DE, 2 lifetime paid orders (1 prior + 1 in-period),
 *         lifetime_spend = £250
 *     C3: GB, 8 lifetime paid orders (7 prior + 1 in-period),
 *         lifetime_spend = £800
 *     C4: FR, 1 lifetime paid order (just in-period),
 *         lifetime_spend = £50
 *     C5: US, 3 lifetime paid orders (2 prior + 1 in-period),
 *         lifetime_spend = £600
 *
 *   Inactive (no in-period order — should NOT appear in results):
 *     C6: GB, 4 lifetime paid orders all pre-period,
 *         lifetime_spend = £400
 *
 * Expected baseline (active-base, no filter):
 *   matched_count       = 5 (C1+C2+C3+C4+C5; C6 excluded)
 *   total_lifetime_spend = £3200 (1500+250+800+50+600)
 *   total_lifetime_orders = 19 (5+2+8+1+3)
 *   avg_lifetime_spend   = £640
 *
 * Country distribution (active base):
 *   US: C1, C5 (2 customers, lifetime_spend = £2100)
 *   DE: C2 (1, £250)
 *   GB: C3 (1, £800)
 *   FR: C4 (1, £50)
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the customers-entity branch of query-analytics.
 */
class Test_Query_Analytics_Customers extends WP_UnitTestCase {

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
	 * Fixture customer IDs (WP user IDs returned by seed_customer()).
	 *
	 * @var array<string, int>
	 */
	private $customers = array();

	/**
	 * Product id (every order uses the same one).
	 *
	 * @var int
	 */
	private $p1;

	/**
	 * Seed the customer history described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		$this->p1 = $this->seed_simple_product(
			array(
				'name'  => 'Customer Fixture Product',
				'sku'   => 'CUST-FIX-1',
				'price' => 50,
			)
		);

		$this->customers['c1'] = $this->seed_customer( 'c1@example.test' );
		$this->customers['c2'] = $this->seed_customer( 'c2@example.test' );
		$this->customers['c3'] = $this->seed_customer( 'c3@example.test' );
		$this->customers['c4'] = $this->seed_customer( 'c4@example.test' );
		$this->customers['c5'] = $this->seed_customer( 'c5@example.test' );
		$this->customers['c6'] = $this->seed_customer( 'c6@example.test' );

		// Qty 6 = £300/order for C1 so 5 orders total £1500.
		// C1: 4 prior + 1 in-period, each qty=6.
		$this->seed_customer_history( $this->customers['c1'], 4, 6, 'US', '2025-07' );
		$this->seed_in_period_order( $this->customers['c1'], 6 );

		// C2: 1 prior qty=2 (£100), 1 in-period qty=3 (£150) → £250 lifetime.
		$this->seed_customer_history( $this->customers['c2'], 1, 2, 'DE', '2025-08' );
		$this->seed_in_period_order( $this->customers['c2'], 3 );

		// C3: 7 prior + 1 in-period, each qty=2 (£100) → £800 lifetime.
		$this->seed_customer_history( $this->customers['c3'], 7, 2, 'GB', '2025-05' );
		$this->seed_in_period_order( $this->customers['c3'], 2 );

		// C4: 1 order (in-period only), qty=1 (£50).
		$this->seed_in_period_order( $this->customers['c4'], 1 );
		$this->set_customer_country( $this->customers['c4'], 'FR' );

		// C5: 2 prior qty=4 (£200) + 1 in-period qty=4 (£200) → £600 lifetime.
		$this->seed_customer_history( $this->customers['c5'], 2, 4, 'US', '2025-08' );
		$this->seed_in_period_order( $this->customers['c5'], 4 );

		// C6: 4 prior qty=2 (£100 each) → £400 lifetime. NO in-period order.
		$this->seed_customer_history( $this->customers['c6'], 4, 2, 'GB', '2025-06' );
	}

	/**
	 * Seed N paid orders for a customer, each with `qty` × product_price
	 * as its total. Dates are spread across the anchor month to give the
	 * lifetime aggregates a believable spread.
	 *
	 * @param int    $customer_id WP user ID.
	 * @param int    $count       How many orders to seed.
	 * @param int    $qty         Product qty per order.
	 * @param string $country     ISO-2 country code (stored on billing address).
	 * @param string $anchor      Year-month anchor ('YYYY-MM') — orders land on consecutive days within it.
	 */
	private function seed_customer_history( $customer_id, $count, $qty, $country, $anchor ) {
		for ( $i = 1; $i <= $count; $i++ ) {
			$day   = str_pad( (string) min( 28, $i ), 2, '0', STR_PAD_LEFT );
			$order = $this->seed_paid_order(
				array(
					'customer_id' => $customer_id,
					'total'       => 0, // ignored when items present.
					'date'        => "{$anchor}-{$day} 10:00:00",
					'items'       => array(
						array(
							'product_id' => $this->p1,
							'qty'        => $qty,
						),
					),
				)
			);
			$order->set_billing_country( $country );
			$order->save();
		}

		// Ensure the customer_lookup row carries the country.
		$this->set_customer_country( $customer_id, $country );
	}

	/**
	 * Seed a single in-period paid order for a customer.
	 *
	 * @param int $customer_id WP user ID.
	 * @param int $qty         Product qty.
	 */
	private function seed_in_period_order( $customer_id, $qty ) {
		$this->seed_paid_order(
			array(
				'customer_id' => $customer_id,
				'total'       => 0,
				'date'        => '2025-10-15 10:00:00',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => $qty,
					),
				),
			)
		);
	}

	/**
	 * Write the country onto the wc_customer_lookup row directly —
	 * WC derives this on order creation but the signalling isn't stable
	 * in the test environment, so we pin it explicitly for the tests
	 * that filter on country.
	 *
	 * @param int    $customer_id WP user ID.
	 * @param string $country     ISO-2 code.
	 */
	private function set_customer_country( $customer_id, $country ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture; customer_lookup is a WC analytics table.
		$wpdb->update(
			$wpdb->prefix . 'wc_customer_lookup',
			array( 'country' => $country ),
			array( 'user_id' => $customer_id )
		);
	}

	/**
	 * Ability-invocation helper.
	 *
	 * @param array $input Extra input.
	 * @return array
	 */
	private function run_ability( array $input = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$defaults = array(
			'entity'     => 'customers',
			'date_start' => $this->period_start,
			'date_end'   => $this->period_end,
		);
		$input    = array_merge( $defaults, $input );

		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_query_analytics(
			$input['entity'],
			$input['filters'] ?? array(),
			$input['match'] ?? 'all',
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			$input['mode'] ?? 'aggregate',
			$input['limit'] ?? 25,
			$input['orderby'] ?? '',
			$input['order'] ?? 'DESC'
		);
		if ( is_wp_error( $result ) ) {
			$this->fail( 'fetch_query_analytics returned WP_Error: ' . $result->get_error_code() . ' — ' . $result->get_error_message() );
		}

		return $result;
	}

	/**
	 * Baseline active-base — 5 customers (C1..C5, C6 has no in-period order).
	 */
	public function test_baseline_active_base() {
		$result = $this->run_ability();

		$this->assertSame( 'customers', $result['entity'] );
		$this->assertSame( 5, $result['summary']['matched_count'] );
		$this->assertSame( 3200.0, $result['summary']['total_lifetime_spend'] );
		$this->assertSame( 19, $result['summary']['total_lifetime_orders'] );
		$this->assertSame( 640.0, $result['summary']['avg_lifetime_spend'] );

		$this->assertSame( 5, $result['universe']['active_customers_in_period'] );
		$this->assertSame( 3200.0, $result['universe']['total_lifetime_spend_active_base'] );

		$this->assertNull( $result['pipeline'] );
		$this->assertNull( $result['admin_equivalent'] );
	}

	/**
	 * Lifetime-spend filter — LTV > £500. C1 (£1500), C3 (£800), C5 (£600).
	 */
	public function test_lifetime_spend_filter() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'lifetime_spend',
						'operator' => 'greater_than',
						'value'    => 500,
					),
				),
			)
		);

		$this->assertSame( 3, $result['summary']['matched_count'] );
		$this->assertSame( 2900.0, $result['summary']['total_lifetime_spend'] );
	}

	/**
	 * Country filter — US (C1 + C5).
	 */
	public function test_country_filter() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'country',
						'operator' => 'is',
						'value'    => 'US',
					),
				),
			)
		);

		$this->assertSame( 2, $result['summary']['matched_count'] );
		$this->assertSame( 2100.0, $result['summary']['total_lifetime_spend'] );
	}

	/**
	 * Match=all: US AND LTV > £1000 → C1 only.
	 */
	public function test_country_and_ltv_filter() {
		$result = $this->run_ability(
			array(
				'match'   => 'all',
				'filters' => array(
					array(
						'field'    => 'country',
						'operator' => 'is',
						'value'    => 'US',
					),
					array(
						'field'    => 'lifetime_spend',
						'operator' => 'greater_than',
						'value'    => 1000,
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertSame( 1500.0, $result['summary']['total_lifetime_spend'] );
	}

	/**
	 * Lifetime orders ≥ 5 — C1 (5), C3 (8).
	 */
	public function test_lifetime_orders_filter() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'lifetime_orders_count',
						'operator' => 'greater_than_or_equal',
						'value'    => 5,
					),
				),
			)
		);

		$this->assertSame( 2, $result['summary']['matched_count'] );
		$this->assertSame( 2300.0, $result['summary']['total_lifetime_spend'] );
	}

	/**
	 * Match=any: DE OR LTV > £1000 → C2 (DE) + C1 (LTV > 1000).
	 */
	public function test_match_any_de_or_big_spender() {
		$result = $this->run_ability(
			array(
				'match'   => 'any',
				'filters' => array(
					array(
						'field'    => 'country',
						'operator' => 'is',
						'value'    => 'DE',
					),
					array(
						'field'    => 'lifetime_spend',
						'operator' => 'greater_than',
						'value'    => 1000,
					),
				),
			)
		);

		$this->assertSame( 2, $result['summary']['matched_count'] );
	}

	/**
	 * Inactive customers excluded — C6 has 4 lifetime orders, lifetime_spend £400,
	 * but no in-period order. A filter on country=GB should find C3 only,
	 * NOT C6.
	 */
	public function test_inactive_customers_excluded() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'country',
						'operator' => 'is',
						'value'    => 'GB',
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertSame( 800.0, $result['summary']['total_lifetime_spend'] );
	}

	/**
	 * Rows mode — always pseudonymised. No name / email / first_name
	 * fields ever appear in the response.
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
			$this->assertArrayHasKey( 'customer_id_pseudo', $row );
			$this->assertArrayHasKey( 'country', $row );
			$this->assertArrayHasKey( 'lifetime_spend', $row );
			$this->assertArrayHasKey( 'lifetime_orders_count', $row );
			$this->assertArrayNotHasKey( 'email', $row );
			$this->assertArrayNotHasKey( 'first_name', $row );
			$this->assertArrayNotHasKey( 'last_name', $row );
			$this->assertMatchesRegularExpression( '/^Customer #\d+$/', $row['customer_id_pseudo'] );
		}

		// Default orderby: lifetime_spend DESC. C1 (£1500) leads.
		$this->assertSame( 1500.0, $result['rows'][0]['lifetime_spend'] );
	}

	/**
	 * Privacy invariant — even if a leftover `woocommerce_claude_allow_customer_pii`
	 * option exists from a previous version of the plugin (truthy or
	 * otherwise), it must not revive PII surfacing. Defends against a
	 * future refactor that accidentally restores the gate by reading the
	 * stale option.
	 */
	public function test_legacy_truthy_option_does_not_leak_pii() {
		update_option( 'woocommerce_claude_allow_customer_pii', true );

		$result = $this->run_ability(
			array(
				'mode'  => 'rows',
				'limit' => 10,
			)
		);

		$this->assertSame( 'pseudonymised', $result['privacy_mode'] );
		$this->assertIsArray( $result['rows'] );

		foreach ( $result['rows'] as $row ) {
			$this->assertArrayHasKey( 'customer_id_pseudo', $row );
			$this->assertArrayNotHasKey( 'email', $row );
			$this->assertArrayNotHasKey( 'first_name', $row );
			$this->assertArrayNotHasKey( 'last_name', $row );
			$this->assertMatchesRegularExpression( '/^Customer #\d+$/', $row['customer_id_pseudo'] );
		}

		delete_option( 'woocommerce_claude_allow_customer_pii' );
	}

	/**
	 * Email / first_name / last_name are NOT in the filter registry —
	 * filtering on them returns an unknown_field WP_Error. They are a
	 * leak surface (equality probe) and intentionally never filterable.
	 */
	public function test_email_not_filterable() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_query_analytics(
			'customers',
			array(
				array(
					'field'    => 'email',
					'operator' => 'is',
					'value'    => 'c1@example.test',
				),
			),
			'all',
			'last_30_days',
			null,
			null,
			'aggregate',
			25,
			'',
			'DESC'
		);

		$this->assertWPError( $result );
		$this->assertSame( 'unknown_field', $result->get_error_code() );
	}

	/**
	 * Small-N caveat fires when ≤ 5 customers match. FR has 1 (C4).
	 */
	public function test_small_n_caveat() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'country',
						'operator' => 'is',
						'value'    => 'FR',
					),
				),
			)
		);

		$this->assertSame( 1, $result['summary']['matched_count'] );
		$this->assertNotNull( $result['sample_size_caveat'] );
	}
}
