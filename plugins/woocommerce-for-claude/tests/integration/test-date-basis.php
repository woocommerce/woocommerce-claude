<?php
/**
 * Integration tests — analytics date basis (`woocommerce_date_type`).
 *
 * WC core Analytics falls back to `date_paid` when the merchant has never
 * saved Analytics > Settings (WC doesn't write the option on install). Our
 * queries must use the same fallback so headline figures reconcile with
 * the dashboard out of the box, and must still honour an explicit choice.
 *
 * Fixture (period 2025-10-01 → 2025-10-31):
 *
 *     A: £100, completed — placed 2025-09-30 (Tuesday), paid 2025-10-02.
 *     B: £50,  completed — placed and paid 2025-10-05.
 *     C: £30,  on-hold   — placed 2025-10-10, no paid date.
 *
 * Expected, by basis:
 *
 *     date_paid (default):  paid = A + B; pipeline = C (dated by
 *                           placement); dashboard-matching = A + B
 *                           (C has no paid date, so WC core leaves it out).
 *     date_created:         paid = B; pipeline = C; dashboard-matching = B + C.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\CommerceAbilities\Analytics\AnalyticsService;

/**
 * Integration tests for the analytics date basis.
 */
class Test_Date_Basis extends WP_UnitTestCase {

	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * Elevate to admin and seed the three-order fixture.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( 'woocommerce_date_type' );

		$customer = $this->seed_customer();

		$order_a = $this->seed_paid_order(
			array(
				'customer_id' => $customer,
				'total'       => 100,
				'status'      => 'completed',
				'date'        => '2025-09-30 10:00:00',
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $customer,
				'total'       => 50,
				'status'      => 'completed',
				'date'        => '2025-10-05 10:00:00',
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $customer,
				'total'       => 30,
				'status'      => 'on-hold',
				'date'        => '2025-10-10 10:00:00',
			)
		);

		// Order A: placed in September, paid in October.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture.
		$wpdb->update(
			$wpdb->prefix . 'wc_order_stats',
			array( 'date_paid' => '2025-10-02 09:00:00' ),
			array( 'order_id' => $order_a->get_id() )
		);
	}

	/**
	 * Restore the option so later suites start from WC's unset default.
	 */
	public function tear_down() {
		delete_option( 'woocommerce_date_type' );
		parent::tear_down();
	}

	/**
	 * Run wc-analytics/totals subject=revenue over October 2025.
	 *
	 * @return array
	 */
	private function run_revenue_totals() {
		$ability = wp_get_ability( 'wc-analytics/totals' );
		$this->assertNotNull( $ability, 'wc-analytics/totals ability not registered.' );

		$result = $ability->execute(
			array(
				'subject'    => 'revenue',
				'period'     => 'custom',
				'date_start' => '2025-10-01',
				'date_end'   => '2025-10-31',
				'compare'    => false,
			)
		);
		$this->assertIsArray( $result );

		return $result;
	}

	/**
	 * Option unset → date_paid basis, matching WC core Analytics.
	 */
	public function test_unset_option_uses_date_paid_basis() {
		$this->assertFalse( get_option( 'woocommerce_date_type' ), 'Precondition: option must be unset.' );
		$this->assertSame( 'date_paid', AnalyticsService::get_date_column() );

		$result = $this->run_revenue_totals();

		$this->assertSame( 'date_paid', $result['date_basis']['value'] );
		$this->assertNotEmpty( $result['date_basis']['definition'] );

		// A (placed September, paid October) counts; so does B.
		$this->assertSame( 2, (int) $result['metrics']['orders_count'] );
		$this->assertSame( 150.00, (float) $result['metrics']['net_sales'] );
	}

	/**
	 * Option explicitly date_created → honoured.
	 */
	public function test_explicit_date_created_is_honoured() {
		update_option( 'woocommerce_date_type', 'date_created' );
		$this->assertSame( 'date_created', AnalyticsService::get_date_column() );

		$result = $this->run_revenue_totals();

		$this->assertSame( 'date_created', $result['date_basis']['value'] );

		// A was placed in September, so only B counts.
		$this->assertSame( 1, (int) $result['metrics']['orders_count'] );
		$this->assertSame( 50.00, (float) $result['metrics']['net_sales'] );

		// On-hold C is placed in the period, so the dashboard-matching
		// view includes it on this basis.
		$this->assertSame( 1, (int) $result['pipeline']['orders_count'] );
		$this->assertSame( 2, (int) $result['admin_equivalent']['orders_count'] );
	}

	/**
	 * An unexpected option value falls back to date_paid, as WC core's
	 * default does.
	 */
	public function test_invalid_option_falls_back_to_date_paid() {
		update_option( 'woocommerce_date_type', 'date_shipped' );
		$this->assertSame( 'date_paid', AnalyticsService::get_date_column() );
	}

	/**
	 * On the date_paid basis, on-hold orders (no paid date) must not
	 * silently drop out of the pipeline view — they're dated by
	 * placement instead. The dashboard-matching view leaves them out,
	 * because WC core's strict `date_paid` filter does.
	 */
	public function test_pipeline_survives_date_paid_basis() {
		$result = $this->run_revenue_totals();

		$this->assertSame( 1, (int) $result['pipeline']['orders_count'] );
		$this->assertSame( 30.00, (float) $result['pipeline']['revenue'] );
		$this->assertSame( 2, (int) $result['admin_equivalent']['orders_count'] );
	}

	/**
	 * Orders subject: pipeline diagnostics (age buckets, payment methods)
	 * are populated for on-hold orders on the default basis.
	 */
	public function test_orders_pipeline_diagnostics_on_date_paid_basis() {
		$ability = wp_get_ability( 'wc-analytics/totals' );
		$result  = $ability->execute(
			array(
				'subject'    => 'orders',
				'period'     => 'custom',
				'date_start' => '2025-10-01',
				'date_end'   => '2025-10-31',
				'compare'    => false,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( 2, (int) $result['metrics']['orders_count'] );
		$this->assertSame( 1, (int) $result['pipeline']['orders_count'] );
		$this->assertNotNull( $result['pipeline']['oldest_order_days'] );
		$this->assertSame( 1, array_sum( $result['pipeline']['age_buckets'] ) );
	}
}
