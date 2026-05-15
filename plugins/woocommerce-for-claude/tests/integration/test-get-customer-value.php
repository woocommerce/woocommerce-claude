<?php
/**
 * Integration tests — wc-analytics/get-customer-value.
 *
 * Ports the SQL-vs-ability verification pattern into PHPUnit.
 * Unlike a manual verify script, these tests seed a deterministic
 * fixture set and
 * assert, so drift between the ability and the underlying SQL fails
 * the ./bin/check gate.
 *
 * Fixture covers the cases the verify script eyeballs:
 *   - Customer A: 1 paid order in period           → one-time, lifetime=100
 *   - Customer B: 2 in + 1 before period           → repeat,    lifetime=150
 *   - Customer C: 2 paid orders in period          → repeat,    lifetime=400
 *   - Customer D: only pre-period orders           → excluded from active base
 *   - Orphan stats row (customer_id = 0)           → excluded (customer_id > 0 gate)
 *
 * WC Analytics assigns `customer_id > 0` to every order including guests
 * (CustomersDataStore::get_or_create_customer_from_order creates a lookup
 * row off the billing email or an empty profile when missing). The
 * `customer_id > 0` gate in the ability's SQL is defensive against
 * orphaned stats rows (legacy data, broken imports) — not guests. We
 * exercise it by inserting a stats row directly.
 *
 * Expected active-base totals:
 *   active_customers   = 3
 *   avg_lifetime_spend = (100 + 150 + 400) / 3   = 216.67
 *   median             = 150.00
 *   max                = 400.00
 *   one_time customers = 1 (A)
 *   repeat customers   = 2 (B, C)
 *   top customer       = C at 400.00
 *
 * Enhancement invariants (E9 / E10 / E11, shipped 2026-04-21):
 *   opportunities.one_to_repeat_conversion:
 *     stranded_customers    = 1
 *     stranded_avg_lifetime = 100.00 (A only in one_time)
 *     repeat_avg_lifetime   = 275.00 ((150 + 400) / 2)
 *     uplift_per_conversion = 175.00
 *     scenarios             = 10% → 0 conversions (round(0.1)=0),
 *                             25% → 0 conversions (round(0.25)=0),
 *                             50% → 1 conversion  (round(0.5)=1 via HALF_UP)
 *   cohorts (2025-10):
 *     size                       = 2 (A, C — B's first order is Sep, pre-period)
 *     lifetime_repeaters         = 1 (C only; A has just 1 lifetime order)
 *     lifetime_retention_percent = 50.0
 *     maturity                   = "mature" (fixture is months older than threshold)
 *     months_since_acquisition   >= 2
 *     maturity_threshold_months  = 2 (structural constant, not data-driven)
 *     flips_to_mature_on         = null (mature cohorts don't need a flip date)
 *   cohorts (today's month, ongoing path):
 *     maturity                   = "ongoing"
 *     months_since_acquisition   = 0
 *     flips_to_mature_on         = first day of the month two months from now
 *                                  (e.g. seeded today=2026-04-21 → "2026-06-01")
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the get-customer-value ability.
 */
class Test_Get_Customer_Value extends WP_UnitTestCase {

	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * Period bounds used by every test. Fixed historical window so
	 * assertions are stable regardless of the clock.
	 *
	 * @var string
	 */
	private $period_start = '2025-10-01';

	/**
	 * Period end bound.
	 *
	 * @var string
	 */
	private $period_end = '2025-10-31';

	/**
	 * Seed the fixture shape described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		// Customer A — 1 paid order in period, lifetime = 100.
		$a = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $a,
				'total'       => 100.00,
				'date'        => '2025-10-10 10:00:00',
			)
		);

		// Customer B — 2 in period + 1 before period, lifetime = 150.
		$b = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $b,
				'total'       => 50.00,
				'date'        => '2025-10-05 10:00:00',
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $b,
				'total'       => 50.00,
				'date'        => '2025-10-20 10:00:00',
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $b,
				'total'       => 50.00,
				'date'        => '2025-09-15 10:00:00',
			)
		);

		// Customer C — 2 paid orders in period, lifetime = 400.
		$c = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $c,
				'total'       => 200.00,
				'date'        => '2025-10-03 10:00:00',
			)
		);
		$this->seed_paid_order(
			array(
				'customer_id' => $c,
				'total'       => 200.00,
				'date'        => '2025-10-25 10:00:00',
			)
		);

		// Customer D — only pre-period orders, excluded from active base.
		$d = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $d,
				'total'       => 50.00,
				'date'        => '2025-09-01 10:00:00',
			)
		);

		// Orphan stats row — customer_id = 0, excluded by the > 0 gate.
		// Bypasses WC's sync_order (which always assigns customer_id > 0 via
		// the customer_lookup get-or-create path) and writes directly.
		$this->seed_orphan_order_stats_row( 999.00, '2025-10-15 10:00:00' );
	}

	/**
	 * Invoke the ability over the fixture period with comparison +
	 * cohorts off (not exercised here).
	 *
	 * @param array $overrides Override input keys.
	 * @return array Ability result.
	 */
	private function run_ability( array $overrides = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$input = array_merge(
			array(
				'period'          => 'last_30_days',
				'date_start'      => $this->period_start,
				'date_end'        => $this->period_end,
				'compare'         => false,
				'limit'           => 10,
				'include_cohorts' => false,
			),
			$overrides
		);

		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_customer_value(
			$input['period'],
			$input['date_start'],
			$input['date_end'],
			$input['compare'],
			$input['limit'],
			$input['include_cohorts']
		);
		$this->assertFalse(
			is_wp_error( $result ),
			'fetch_customer_value returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);

		return $result;
	}

	/**
	 * Active-base count excludes customers whose only orders fall before
	 * the period AND orphaned stats rows with customer_id = 0.
	 */
	public function test_active_customers_excludes_out_of_period_and_orphans() {
		$result = $this->run_ability();

		$this->assertSame(
			3,
			$result['metrics']['active_customers'],
			'Expected A, B, C only — D (pre-period) and the orphan row (customer_id=0) should be excluded.'
		);
	}

	/**
	 * Lifetime spend summary stats reflect the full per-customer
	 * history (including B's pre-period order), rolled up across
	 * only the active-base customers.
	 */
	public function test_lifetime_spend_metrics() {
		$result = $this->run_ability();
		$m      = $result['metrics'];

		$this->assertSame( 216.67, (float) $m['avg_lifetime_spend'] );
		$this->assertSame( 150.00, (float) $m['median_lifetime_spend'] );
		$this->assertSame( 400.00, (float) $m['max_lifetime_spend'] );
	}

	/**
	 * One-time vs repeat segmentation uses lifetime order count, not
	 * period order count — so B (2 in period, 1 before = 3 lifetime)
	 * lands in the repeat bucket alongside C.
	 */
	public function test_one_time_vs_repeat_segmentation() {
		$result = $this->run_ability();
		$s      = $result['segments'];

		$this->assertSame( 1, $s['one_time']['customers'], 'Expected A only in one_time.' );
		$this->assertSame( 2, $s['repeat']['customers'], 'Expected B and C in repeat.' );
	}

	/**
	 * Top-customer list is sorted by lifetime_spend desc.
	 */
	public function test_top_customer_is_highest_lifetime_spend() {
		$result = $this->run_ability();

		$this->assertNotEmpty( $result['top_customers'], 'Expected top_customers to contain rows.' );

		$top = $result['top_customers'][0];
		$this->assertSame( 400.00, (float) $top['lifetime_spend'], 'Customer C should lead with lifetime = 400.' );
		$this->assertSame( 2, (int) $top['lifetime_orders'] );
	}

	/**
	 * Ability output matches raw SQL on the same fixture set — the
	 * drift check between the ability and raw SQL on the same fixture set.
	 * A regression in AnalyticsController's response assembly (rounding,
	 * status filter, date filter) shows up here before the value-based
	 * tests even run.
	 */
	public function test_ability_output_matches_direct_sql() {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify-script-parity SQL: hardcoded identifiers, hardcoded statuses, seeded fixture dates. No user input.
		$result = $this->run_ability();

		global $wpdb;
		$t     = $wpdb->prefix . 'wc_order_stats';
		$paid  = "('wc-completed', 'wc-processing')";
		$start = $this->period_start . ' 00:00:00';
		$end   = $this->period_end . ' 23:59:59';

		$range    = "date_created BETWEEN '{$start}' AND '{$end}'";
		$active   = "(SELECT DISTINCT customer_id FROM {$t} WHERE parent_id=0 AND status IN {$paid} AND customer_id>0 AND {$range})";
		$lifetime = "SELECT SUM(net_total) AS lt, COUNT(DISTINCT order_id) AS oc FROM {$t} WHERE parent_id=0 AND status IN {$paid} AND customer_id IN {$active} GROUP BY customer_id";

		// V1 — active customer count.
		$sql_active = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT customer_id) FROM {$t} WHERE parent_id=0 AND status IN {$paid} AND customer_id>0 AND {$range}" );
		$this->assertSame( $sql_active, (int) $result['metrics']['active_customers'], 'V1: active_customers drifted from raw SQL.' );

		// V2 — avg lifetime spend across active customers.
		$sql_avg = $wpdb->get_var( "SELECT ROUND(AVG(lt),2) FROM ({$lifetime}) x" );
		$this->assertSame( (float) $sql_avg, (float) $result['metrics']['avg_lifetime_spend'], 'V2: avg_lifetime_spend drifted from raw SQL.' );

		// V3 — one-time / repeat split by lifetime order count.
		$sql_segments = $wpdb->get_row(
			"SELECT
				SUM(CASE WHEN oc=1 THEN 1 ELSE 0 END) AS one_time,
				SUM(CASE WHEN oc>1 THEN 1 ELSE 0 END) AS repeat_c
			FROM ({$lifetime}) x",
			ARRAY_A
		);
		$this->assertSame( (int) $sql_segments['one_time'], (int) $result['segments']['one_time']['customers'], 'V3: one_time customers drifted from raw SQL.' );
		$this->assertSame( (int) $sql_segments['repeat_c'], (int) $result['segments']['repeat']['customers'], 'V3: repeat customers drifted from raw SQL.' );

		// V5 — top customer lifetime spend.
		$sql_top = $wpdb->get_row(
			"SELECT customer_id, ROUND(SUM(net_total),2) AS lifetime_spend
			FROM {$t}
			WHERE parent_id=0 AND status IN {$paid} AND customer_id IN {$active}
			GROUP BY customer_id
			ORDER BY lifetime_spend DESC
			LIMIT 1",
			ARRAY_A
		);
		$this->assertSame( (float) $sql_top['lifetime_spend'], (float) $result['top_customers'][0]['lifetime_spend'], 'V5: top_customer lifetime_spend drifted from raw SQL.' );
		$this->assertSame( 'Customer #' . (int) $sql_top['customer_id'], $result['top_customers'][0]['id'], 'V5: top_customer handle should be pseudonymised.' );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * E10 — opportunities.one_to_repeat_conversion pre-computes the
	 * stranded-customer lever so Claude doesn't do arithmetic. Scenario
	 * math: conversions = round(stranded × rate/100);
	 * estimated_uplift = conversions × uplift_per_conversion.
	 */
	public function test_opportunities_one_to_repeat_conversion_scenarios() {
		$result = $this->run_ability();

		$this->assertArrayHasKey( 'opportunities', $result, 'Expected opportunities block on the response.' );
		$opp = $result['opportunities']['one_to_repeat_conversion'];

		$this->assertSame( 1, $opp['stranded_customers'], 'Stranded = one_time.customers from fixture (A only).' );
		$this->assertSame( 100.00, (float) $opp['stranded_avg_lifetime'] );
		$this->assertSame( 275.00, (float) $opp['repeat_avg_lifetime'] );
		$this->assertSame( 175.00, (float) $opp['uplift_per_conversion'] );

		$this->assertCount( 3, $opp['scenarios'] );
		$this->assertSame( 10, (int) $opp['scenarios'][0]['conversion_rate_percent'] );
		$this->assertSame( 25, (int) $opp['scenarios'][1]['conversion_rate_percent'] );
		$this->assertSame( 50, (int) $opp['scenarios'][2]['conversion_rate_percent'] );

		// 1 × 0.10 = 0.1 → round → 0 conversions → £0 uplift.
		$this->assertSame( 0, (int) $opp['scenarios'][0]['conversions'] );
		$this->assertSame( 0.00, (float) $opp['scenarios'][0]['estimated_uplift'] );
		// 1 × 0.25 = 0.25 → round → 0 conversions → £0 uplift.
		$this->assertSame( 0, (int) $opp['scenarios'][1]['conversions'] );
		$this->assertSame( 0.00, (float) $opp['scenarios'][1]['estimated_uplift'] );
		// 1 × 0.50 = 0.5 → round HALF_UP → 1 conversion → £175 uplift.
		$this->assertSame( 1, (int) $opp['scenarios'][2]['conversions'] );
		$this->assertSame( 175.00, (float) $opp['scenarios'][2]['estimated_uplift'] );
	}

	/**
	 * E11 — per-cohort `lifetime_retention_percent` counts cohort members
	 * with ≥ 2 lifetime paid orders. Fixture's 2025-10 cohort: A (1 order,
	 * non-repeater) + C (2 orders, repeater) → 1/2 = 50%. B is NOT in this
	 * cohort — B's first paid order was 2025-09-15, outside date_start.
	 */
	public function test_cohort_lifetime_retention_percent() {
		$result = $this->run_ability( array( 'include_cohorts' => true ) );

		$this->assertNotNull( $result['cohorts'], 'Expected cohorts block with include_cohorts=true.' );

		$cohort = $this->find_cohort( $result['cohorts'], '2025-10' );
		$this->assertNotNull( $cohort, 'Expected a 2025-10 cohort row (A, C).' );

		$this->assertSame( 2, (int) $cohort['size'], 'Cohort size should be A + C (B excluded — first order pre-period).' );
		$this->assertSame( 50.0, (float) $cohort['lifetime_retention_percent'], 'Only C has ≥ 2 lifetime orders; 1/2 = 50%.' );
	}

	/**
	 * E9 — mature path. The October 2025 fixture is guaranteed to be at
	 * least 2 months old at the point any test run could reach it, so
	 * `maturity` must be "mature" and `months_since_acquisition` must be
	 * ≥ 2. Exact month count is clock-dependent; greater-than-or-equal is
	 * the stable assertion. Mature cohorts carry `flips_to_mature_on: null`
	 * — the threshold has already been crossed, no future flip to report.
	 */
	public function test_mature_cohort_flag_on_fixture() {
		$result = $this->run_ability( array( 'include_cohorts' => true ) );

		$cohort = $this->find_cohort( $result['cohorts'], '2025-10' );
		$this->assertNotNull( $cohort );

		$this->assertSame( 'mature', $cohort['maturity'] );
		$this->assertGreaterThanOrEqual( 2, (int) $cohort['months_since_acquisition'] );
		$this->assertSame( 2, (int) $cohort['maturity_threshold_months'] );
		$this->assertNull( $cohort['flips_to_mature_on'], 'Mature cohorts have no future flip date.' );
	}

	/**
	 * E9 + E12 — ongoing path. Seed a customer whose first paid order is
	 * today and run the ability over today's date range; the resulting
	 * cohort for the current month must be "ongoing" with
	 * months_since_acquisition = 0 and `flips_to_mature_on` set to the
	 * first day of the month two months from this month's anchor — that
	 * pre-computed date is the E12 fix that removes Claude's narrative
	 * "end of April" / "mid-June" drift.
	 */
	public function test_ongoing_cohort_flag_when_first_order_is_today() {
		$recent = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $recent,
				'total'       => 42.00,
				'date'        => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		$today  = gmdate( 'Y-m-d' );
		$result = $this->run_ability(
			array(
				'date_start'      => $today,
				'date_end'        => $today,
				'include_cohorts' => true,
			)
		);

		$today_month = gmdate( 'Y-m' );
		$cohort      = $this->find_cohort( $result['cohorts'], $today_month );

		$this->assertNotNull( $cohort, "Expected a cohort row for the current month ({$today_month})." );
		$this->assertSame( 'ongoing', $cohort['maturity'] );
		$this->assertSame( 0, (int) $cohort['months_since_acquisition'] );
		$this->assertSame( 2, (int) $cohort['maturity_threshold_months'] );

		// Expected flip date: current month's first day + threshold months.
		$expected_flip = ( new \DateTime( $today_month . '-01' ) )
			->modify( '+2 months' )
			->format( 'Y-m-d' );
		$this->assertSame(
			$expected_flip,
			$cohort['flips_to_mature_on'],
			'flips_to_mature_on must be the first-of-month two months after the cohort anchor.'
		);
	}

	/**
	 * Scan a cohorts array for a given YYYY-MM label. Keeps per-test code
	 * readable — we only need the matching row.
	 *
	 * @param array  $cohorts Cohort rows.
	 * @param string $label   YYYY-MM cohort month to find.
	 * @return array|null
	 */
	private function find_cohort( $cohorts, $label ) {
		foreach ( $cohorts as $cohort ) {
			if ( $cohort['cohort'] === $label ) {
				return $cohort;
			}
		}
		return null;
	}
}
