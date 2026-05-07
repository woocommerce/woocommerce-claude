<?php
/**
 * Integration tests — wc-analytics/get-customer-overview.
 *
 * Pins the invariants the ability relies on and that drift silently
 * when the SQL in AnalyticsController::query_customer_metrics() or
 * ::query_customer_series() is refactored. Written fresh from the
 * skill's known edge cases — there is no prior verify script to port.
 *
 * What's pinned:
 *
 *   - Primary view uses COUNT(DISTINCT customer_id) for totals.
 *     `total_customers` can be less than `new + returning` because a
 *     customer whose `returning_customer` flag flips inside the period
 *     lands in both distinct-count subqueries but once in the overall
 *     distinct count. `overlap_customers = new + returning − total`.
 *   - Three-view integrity: `metrics` is paid-only, `pipeline` is
 *     on-hold-only, `admin_equivalent` is paid + on-hold + refunded
 *     (the WC Admin Reports sum convention). A refunded-only customer
 *     appears only in admin_equivalent; an on-hold-only customer
 *     appears only in pipeline; paid customers appear in all three.
 *   - `interval=month` emits one series row per calendar month in
 *     range, with the same overlap invariant per bucket (the flag-flip
 *     can fire at any granularity).
 *   - Per-customer ratios (`new_customer_spend_per_customer`,
 *     `returning_customer_spend_per_customer`, and the orders-per
 *     siblings) are pre-computed so Claude doesn't derive them by
 *     hand. Canonical rule in ANALYTICS-SKILLS-MAP.md § Preventing AI
 *     Hallucination.
 *   - `compare=true` emits a `comparison` block with per-metric
 *     `direction / amount / percent` deltas.
 *
 * Fixture shape (seeded in chronological order so WC's first_order_id
 * tracking assigns flag=0 to each customer's first-ever order and
 * flag=1 to subsequent orders):
 *
 *   Customer B — pre-period completed order 2025-09-15, $50 (flag=0).
 *                Establishes B's first_order_id outside the period so
 *                the period's B order gets flag=1. Also gives the
 *                comparison test prior-period data and the month-interval
 *                test a September bucket.
 *   Customer C — in-period completed orders 2025-10-05 $50 (flag=0)
 *                and 2025-10-25 $75 (flag=1). This is the flag-flip
 *                overlap case: total=1 but new=1 AND returning=1.
 *   Customer A — in-period completed order 2025-10-10, $100 (flag=0).
 *   Customer D — in-period on-hold order 2025-10-12, $300 (flag=0).
 *                Appears only in `pipeline`.
 *   Customer E — in-period refunded order 2025-10-18, $80 (flag=0).
 *                Appears only in `admin_equivalent`.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the get-customer-overview ability.
 */
class Test_Get_Customer_Overview extends WP_UnitTestCase {

	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * Period start used by the default runs. Fixed historical window
	 * so assertions don't drift with the clock.
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
	 * Seed the fixture shape described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		// Seeded chronologically so each customer's first_order_id lands
		// on their earliest order — the ordering that gives the expected
		// returning_customer flag values on each subsequent order.

		// Customer B — pre-period order (B's first-ever → flag=0).
		$b = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $b,
				'total'       => 50.00,
				'date'        => '2025-09-15 10:00:00',
			)
		);

		// Customer C — first in-period order (C's first-ever → flag=0).
		$c = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $c,
				'total'       => 50.00,
				'date'        => '2025-10-05 10:00:00',
			)
		);

		// Customer A — single in-period order (A's first-ever → flag=0).
		$a = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $a,
				'total'       => 100.00,
				'date'        => '2025-10-10 10:00:00',
			)
		);

		// Customer D — on-hold order, pipeline-only (D's first → flag=0).
		$d = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $d,
				'total'       => 300.00,
				'date'        => '2025-10-12 10:00:00',
				'status'      => 'on-hold',
			)
		);

		// Customer E — refunded order, admin_equivalent-only (E's first → flag=0).
		$e = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $e,
				'total'       => 80.00,
				'date'        => '2025-10-18 10:00:00',
				'status'      => 'refunded',
			)
		);

		// Customer B — second order (prior row exists → flag=1).
		$this->seed_paid_order(
			array(
				'customer_id' => $b,
				'total'       => 200.00,
				'date'        => '2025-10-20 10:00:00',
			)
		);

		// Customer C — second order (flag-flip; prior row → flag=1).
		$this->seed_paid_order(
			array(
				'customer_id' => $c,
				'total'       => 75.00,
				'date'        => '2025-10-25 10:00:00',
			)
		);
	}

	/**
	 * Invoke the ability. Defaults to the fixture window with
	 * compare=false and no series.
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
				'interval'   => '',
			),
			$overrides
		);

		$ability = wp_get_ability( 'wc-analytics/get-customer-overview' );
		$this->assertNotNull( $ability, 'get-customer-overview ability was not registered.' );

		$result = $ability->execute( $input );
		$this->assertFalse(
			is_wp_error( $result ),
			'Ability returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		return $result;
	}

	/**
	 * Primary view exposes distinct-customer totals for paid orders
	 * and separates the new / returning breakdown by the creation-time
	 * flag.
	 */
	public function test_primary_new_returning_split() {
		$result = $this->run_ability();

		// No interval → series_cap must be null.
		$this->assertNull( $result['series_cap'] );

		$m = $result['metrics'];
		$this->assertSame( 3, (int) $m['total_customers'], 'Paid distinct customers: A, B, C.' );
		$this->assertSame( 2, (int) $m['new_customers'], 'Flag=0 paid rows cover A and C-first.' );
		$this->assertSame( 2, (int) $m['returning_customers'], 'Flag=1 paid rows cover B-Oct and C-second.' );
		$this->assertSame( 4, (int) $m['orders_count'], 'Paid orders: A, B-Oct, C-first, C-second.' );
		$this->assertSame( 425.00, (float) $m['net_sales'], 'Paid net_sales: $100 + $200 + $50 + $75.' );
	}

	/**
	 * Surfaces the flag-flip edge case via overlap_customers —
	 * customer C places a first and a second paid order inside the
	 * period. The distinct-customer total counts them once; the
	 * per-flag distinct subqueries count them in both buckets.
	 */
	public function test_overlap_customers_from_flag_flip() {
		$result = $this->run_ability();

		$m = $result['metrics'];
		$this->assertSame(
			1,
			(int) $m['overlap_customers'],
			'Customer C flips the flag inside the period: overlap = (2 + 2) − 3 = 1.'
		);
		$this->assertSame(
			( (int) $m['new_customers'] + (int) $m['returning_customers'] ) - (int) $m['total_customers'],
			(int) $m['overlap_customers'],
			'Invariant: overlap_customers = new + returning − total.'
		);
	}

	/**
	 * Three-view integrity — `metrics` is paid-only, `pipeline` is
	 * on-hold-only, `admin_equivalent` is paid + on-hold + refunded.
	 * A pipeline-only customer (D) and a refunded-only customer (E)
	 * must not leak into the paid view.
	 */
	public function test_three_view_integrity_no_leak() {
		$result = $this->run_ability();

		// Primary (paid-only) — D and E must not leak in.
		$this->assertSame( 3, (int) $result['metrics']['total_customers'], 'D (on-hold) and E (refunded) excluded.' );
		$this->assertSame( 4, (int) $result['metrics']['orders_count'], '4 paid orders.' );

		// Pipeline (on-hold-only) — D only.
		$p = $result['pipeline'];
		$this->assertSame( 1, (int) $p['pipeline_customers'], 'D is the only on-hold customer.' );
		$this->assertSame( 1, (int) $p['pipeline_new_customers'], 'D is a new customer (first-ever).' );
		$this->assertSame( 0, (int) $p['pipeline_returning_customers'] );
		$this->assertSame( 1, (int) $p['pipeline_orders'] );

		// Admin equivalent — paid + on-hold + refunded, sum convention.
		$adm = $result['admin_equivalent'];
		$this->assertSame(
			4,
			(int) $adm['new_customers'],
			'A, C, D, E all placed a flag=0 order under admin statuses.'
		);
		$this->assertSame(
			2,
			(int) $adm['returning_customers'],
			'B-Oct and C-second placed flag=1 orders under admin statuses.'
		);
		$this->assertSame(
			6,
			(int) $adm['total_customers'],
			'admin_total = new + returning (WC Admin sum convention; can double-count flag-flippers).'
		);
		$this->assertSame(
			6,
			(int) $adm['orders_count'],
			'6 admin-status orders: A + B-Oct + C-first + C-second + D + E.'
		);
	}

	/**
	 * Month-interval series emits one row per calendar month in range
	 * and carries paid + pipeline aggregates per bucket. The overlap
	 * invariant fires at bucket granularity too.
	 */
	public function test_interval_month_series_emits_buckets() {
		$result = $this->run_ability(
			array(
				'date_start' => '2025-09-01',
				'date_end'   => '2025-10-31',
				'interval'   => 'month',
			)
		);

		$this->assertSame( 'month', $result['interval'] );
		$this->assertGreaterThan( 0, $result['series_cap'], 'series_cap must be a positive int (range_days + 1 sentinel).' );
		$this->assertIsArray( $result['series'] );
		$this->assertCount( 2, $result['series'], 'Sept + Oct → two monthly buckets.' );

		list( $sept, $oct ) = $result['series'];

		$this->assertSame( '2025-09-01', $sept['bucket'] );
		$this->assertSame( 1, (int) $sept['total_customers'], 'Sept sees B only.' );
		$this->assertSame( 1, (int) $sept['new_customers'], 'B-Sept is B\'s first order.' );
		$this->assertSame( 0, (int) $sept['returning_customers'] );
		$this->assertSame( 0, (int) $sept['overlap_customers'] );
		$this->assertSame( 1, (int) $sept['orders_count'] );
		$this->assertSame( 50.00, (float) $sept['net_sales'] );
		$this->assertSame( 0, (int) $sept['pipeline_customers'], 'No on-hold orders in Sept.' );

		$this->assertSame( '2025-10-01', $oct['bucket'] );
		$this->assertSame( 3, (int) $oct['total_customers'], 'Oct paid customers: A, B, C.' );
		$this->assertSame( 2, (int) $oct['new_customers'] );
		$this->assertSame( 2, (int) $oct['returning_customers'] );
		$this->assertSame( 1, (int) $oct['overlap_customers'], 'C flips the flag inside Oct.' );
		$this->assertSame( 4, (int) $oct['orders_count'] );
		$this->assertSame( 1, (int) $oct['pipeline_customers'], 'D\'s on-hold order falls in Oct.' );
		$this->assertSame( 1, (int) $oct['pipeline_orders'] );
	}

	/**
	 * Pre-computed per-customer ratios. Canonical rule in
	 * ANALYTICS-SKILLS-MAP.md § Preventing AI Hallucination — any
	 * ratio between two returned fields must be pre-computed rather
	 * than left for Claude to derive.
	 */
	public function test_per_customer_ratios_precomputed_and_correct() {
		$result = $this->run_ability();
		$m      = $result['metrics'];

		$this->assertArrayHasKey( 'new_customer_spend_per_customer', $m );
		$this->assertArrayHasKey( 'returning_customer_spend_per_customer', $m );
		$this->assertArrayHasKey( 'new_customer_orders_per_customer', $m );
		$this->assertArrayHasKey( 'returning_customer_orders_per_customer', $m );

		// New: A $100 + C-first $50 = $150 across 2 customers (A, C).
		$this->assertSame( 75.00, (float) $m['new_customer_spend_per_customer'], '$150 / 2.' );
		$this->assertSame( 1.0, (float) $m['new_customer_orders_per_customer'], '2 orders / 2 customers.' );

		// Returning: B-Oct $200 + C-second $75 = $275 across 2 customers (B, C).
		$this->assertSame( 137.50, (float) $m['returning_customer_spend_per_customer'], '$275 / 2.' );
		$this->assertSame( 1.0, (float) $m['returning_customer_orders_per_customer'], '2 orders / 2 customers.' );
	}

	/**
	 * Comparison block populates with direction / amount / percent
	 * deltas for the headline metrics when compare=true. Canonical
	 * rule — Claude reports deltas, doesn't calculate them.
	 */
	public function test_comparison_block_populated_when_compare_true() {
		$result = $this->run_ability( array( 'compare' => true ) );

		$this->assertNotNull( $result['comparison'], 'comparison block must be populated when compare=true.' );

		$prev = $result['comparison'];

		// Previous period for 2025-10-01..2025-10-31 (31 days inclusive)
		// is 2025-08-31..2025-09-30 — includes B's $50 Sept order.
		$this->assertSame( '2025-08-31', $prev['period']['start'] );
		$this->assertSame( '2025-09-30', $prev['period']['end'] );
		$this->assertSame( 1, (int) $prev['metrics']['total_customers'] );
		$this->assertSame( 1, (int) $prev['metrics']['new_customers'] );
		$this->assertSame( 0, (int) $prev['metrics']['returning_customers'] );
		$this->assertSame( 50.00, (float) $prev['metrics']['net_sales'] );

		$changes = $prev['changes'];
		$this->assertArrayHasKey( 'total_customers', $changes );
		$this->assertArrayHasKey( 'direction', $changes['total_customers'] );
		$this->assertArrayHasKey( 'amount', $changes['total_customers'] );
		$this->assertArrayHasKey( 'percent', $changes['total_customers'] );

		// 1 → 3 customers: up by 2, +200%.
		$this->assertSame( 'up', $changes['total_customers']['direction'] );
		$this->assertSame( 2.0, (float) $changes['total_customers']['amount'] );
		$this->assertSame( 200.0, (float) $changes['total_customers']['percent'] );
	}

	/**
	 * When the date range exceeds the series cap the ability returns a
	 * WP_Error — no data at all — so Claude cannot analyse trends before
	 * asking the merchant for their choice.
	 */
	public function test_series_returns_wp_error_when_range_exceeded() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// 731-day range → heavy-scan gate fires (threshold is 365 days).
		// No fixture data needed — the gate fires before any SQL runs.
		$ability = wp_get_ability( 'wc-analytics/get-customer-overview' );
		$this->assertNotNull( $ability );

		$result = $ability->execute(
			array(
				'date_start' => '2023-01-01',
				'date_end'   => '2025-01-01',
				'interval'   => 'month',
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
