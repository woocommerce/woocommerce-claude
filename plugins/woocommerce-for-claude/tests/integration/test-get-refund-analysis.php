<?php
/**
 * Integration tests — wc-analytics/get-refund-analysis.
 *
 * Pins the invariants around AnalyticsController::fetch_refund_analysis()
 * and the two group_by query helpers it dispatches to
 * (product + country).
 *
 * What's pinned:
 *
 *   - Headline metrics: refunds_amount uses the canonical formula
 *     (net_total + tax_total + shipping_total on the refund sub-order
 *     row), refunds_count is the number of refund sub-orders,
 *     orders_refunded_count is DISTINCT parent_id touched.
 *   - Partial vs full split: full_refunds_count = refunds where the
 *     parent order's status is 'wc-refunded' (WC flips the parent
 *     automatically when a refund covers the whole order).
 *     partial_refunds_count = refunds_count − full_refunds_count.
 *   - Refund rate: refund_rate_percent = refunds_amount ÷
 *     paid_gross_revenue × 100. paid_gross_revenue is the
 *     sum of net_total on paid parent orders in the same window.
 *   - Days-to-refund: avg uses DATEDIFF(refund.date_created,
 *     parent.date_created). Median is computed in PHP (MySQL 5.7
 *     has no MEDIAN).
 *   - Timing buckets are returned in fixed order: Same day, 1–7
 *     days, 8–30 days, 31+ days. Each carries count +
 *     share_percent (pre-computed, sums to ~100%).
 *   - group_by=product attributes a refund to every product line on
 *     the refund sub-order via wc_order_product_lookup. Per-row
 *     gross_revenue is that product's paid gross in the same window.
 *     refund_rate_percent + share_of_refunds_percent are pre-computed
 *     in fetch_refund_analysis() (not the per-query helper).
 *   - group_by=country inherits the parent order's billing country
 *     via revenue_breakdown_country_join() — same rule as
 *     get_revenue_breakdown, because the address of a refund is
 *     plainly the parent's.
 *   - compare=true emits a comparison block with pre-computed
 *     percent/amount/direction per metric.
 *   - Empty period produces `note` + empty top_groups + zeroed
 *     metrics.
 *
 * Fixture shape (current period 2025-10-01..2025-10-31,
 * prior period 2025-09-01..2025-09-30, seeded via seed_paid_order
 * + seed_refund):
 *
 *   Categories:
 *     Apparel, Electronics.
 *
 *   Products:
 *     Hoodie      — £50, Apparel.
 *     Headphones  — £100, Electronics.
 *
 *   Current-period orders:
 *     A — 2025-10-05, Germany, 2 × Hoodie (£100), paid.
 *         Partial refund (1 × Hoodie = £50) on 2025-10-05 → Same day.
 *     B — 2025-10-07, France, 1 × Headphones (£100), paid then flipped
 *         to wc-refunded after a full refund (1 × Headphones = £100)
 *         on 2025-10-10 → 3 days. Parent flip is manual in the fixture
 *         because wc_create_refund does not auto-flip in the test
 *         harness.
 *     C — 2025-10-12, United Kingdom, 1 × Hoodie (£50), paid.
 *         Partial refund (1 × Hoodie = £50) on 2025-10-25 → 13 days.
 *     D — 2025-09-15, Germany, 1 × Headphones (£100), paid.
 *         Partial refund (1 × Headphones = £100) on 2025-10-20 → 35 days
 *         (parent from prior period; refund date in this period).
 *     E — 2025-10-20, Germany, 1 × Hoodie (£50), paid. NO refund.
 *         Keeps a paid-only order in the period so
 *         paid_gross_revenue has a denominator distinct from the
 *         refunded orders.
 *
 *   Prior-period orders (for the comparison block):
 *     F — 2025-09-10, France, 2 × Hoodie (£100), paid. Partial refund
 *         (1 × Hoodie = £50) on 2025-09-12 → 2 days.
 *
 * Expected current-period metrics:
 *   refunds_amount         = £300 (£50 A + £100 B + £50 C + £100 D)
 *   refunds_count          = 4
 *   orders_refunded_count  = 4 (A, B, C, D are distinct)
 *   full_refunds_count     = 1 (B flipped to wc-refunded manually)
 *   partial_refunds_count  = 3 (A, C, D are partials)
 *   paid_gross_revenue     = £200 (A £100 + C £50 + E £50;
 *                            B no longer paid after flip to refunded;
 *                            D's parent is in prior period and also
 *                            not in the current paid window)
 *   refund_rate_percent    = 150.0 (£300 refunds / £200 paid gross —
 *                            legitimate > 100% reading when refund
 *                            volume exceeds current paid gross, e.g.
 *                            refunds landing against prior-period
 *                            parents or on now-refunded parents)
 *   avg_days_to_refund     = (0 + 3 + 13 + 35) / 4 = 12.75
 *   median_days_to_refund  = (3 + 13) / 2 = 8.0
 *
 * Expected timing buckets:
 *   Same day     = 1 (A, refund on day of order)
 *   1–7 days     = 1 (B, 3 days)
 *   8–30 days    = 1 (C, 13 days)
 *   31+ days     = 1 (D, 35 days — parent was in prior period)
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the get-refund-analysis ability.
 */
class Test_Get_Refund_Analysis extends WP_UnitTestCase {

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
	 * Hoodie product ID — retained for refund lookup.
	 *
	 * @var int
	 */
	private $hoodie;

	/**
	 * Headphones product ID — retained for refund lookup.
	 *
	 * @var int
	 */
	private $headphones;

	/**
	 * Seed the fixture shape described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		$apparel_id     = $this->seed_product_category( 'Apparel' );
		$electronics_id = $this->seed_product_category( 'Electronics' );

		$this->hoodie     = $this->seed_simple_product(
			array(
				'name'         => 'Hoodie',
				'sku'          => 'HOODIE-50',
				'price'        => 50,
				'category_ids' => array( $apparel_id ),
			)
		);
		$this->headphones = $this->seed_simple_product(
			array(
				'name'         => 'Headphones',
				'sku'          => 'HP-100',
				'price'        => 100,
				'category_ids' => array( $electronics_id ),
			)
		);

		// Order A — Germany / 2 × Hoodie / partial refund on day of order.
		$a       = $this->seed_customer();
		$order_a = $this->seed_refund_order(
			array(
				'customer_id'     => $a,
				'total'           => 100.00,
				'date'            => '2025-10-05 10:00:00',
				'items'           => array(
					array(
						'product_id' => $this->hoodie,
						'qty'        => 2,
					),
				),
				'billing_country' => 'DE',
			)
		);
		$this->seed_refund(
			$order_a,
			array( $this->hoodie => 1 ),
			array(
				'reason' => 'wrong size',
				'date'   => '2025-10-05 14:00:00',
			)
		);

		// Order B — France / 1 × Headphones / full refund 3 days later.
		// wc_create_refund doesn't auto-flip the parent to wc-refunded in
		// the test harness even when the refund covers the full order,
		// so we flip the status manually + re-sync wc_order_stats to
		// exercise the full-refund detection path (parent.status =
		// 'wc-refunded' in query_refund_metrics).
		$b       = $this->seed_customer();
		$order_b = $this->seed_refund_order(
			array(
				'customer_id'     => $b,
				'total'           => 100.00,
				'date'            => '2025-10-07 10:00:00',
				'items'           => array(
					array(
						'product_id' => $this->headphones,
						'qty'        => 1,
					),
				),
				'billing_country' => 'FR',
			)
		);
		$this->seed_refund(
			$order_b,
			array( $this->headphones => 1 ),
			array(
				'reason' => 'defective',
				'date'   => '2025-10-10 10:00:00',
			)
		);
		$order_b->set_status( 'refunded' );
		$order_b->save();
		\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $order_b->get_id() );

		// Order C — UK / 1 × Hoodie / partial refund 13 days later.
		$c       = $this->seed_customer();
		$order_c = $this->seed_refund_order(
			array(
				'customer_id'     => $c,
				'total'           => 50.00,
				'date'            => '2025-10-12 10:00:00',
				'items'           => array(
					array(
						'product_id' => $this->hoodie,
						'qty'        => 1,
					),
				),
				'billing_country' => 'GB',
			)
		);
		$this->seed_refund(
			$order_c,
			array( $this->hoodie => 1 ),
			array(
				'reason' => 'quality issue',
				'date'   => '2025-10-25 10:00:00',
			)
		);

		// Order D — Germany / 1 × Headphones placed prior period, refund
		// issued in current period (35 days later, so 31+ bucket).
		$d       = $this->seed_customer();
		$order_d = $this->seed_refund_order(
			array(
				'customer_id'     => $d,
				'total'           => 100.00,
				'date'            => '2025-09-15 10:00:00',
				'items'           => array(
					array(
						'product_id' => $this->headphones,
						'qty'        => 1,
					),
				),
				'billing_country' => 'DE',
			)
		);
		$this->seed_refund(
			$order_d,
			array( $this->headphones => 1 ),
			array(
				'reason' => 'late-dispute',
				'date'   => '2025-10-20 10:00:00',
			)
		);

		// Order E — Germany / 1 × Hoodie / NO refund. Paid-only order so
		// paid_gross_revenue has a distinct denominator component.
		$e = $this->seed_customer();
		$this->seed_refund_order(
			array(
				'customer_id'     => $e,
				'total'           => 50.00,
				'date'            => '2025-10-20 10:00:00',
				'items'           => array(
					array(
						'product_id' => $this->hoodie,
						'qty'        => 1,
					),
				),
				'billing_country' => 'DE',
			)
		);

		// Order F — France / 2 × Hoodie in prior period + partial refund
		// (1 × Hoodie = £50) in prior period. Populates comparison block.
		// Two-unit qty so the partial refund works out to £50, keeping
		// Order F's parent in the paid bucket for the comparison period.
		$f       = $this->seed_customer();
		$order_f = $this->seed_refund_order(
			array(
				'customer_id'     => $f,
				'total'           => 100.00,
				'date'            => '2025-09-10 10:00:00',
				'items'           => array(
					array(
						'product_id' => $this->hoodie,
						'qty'        => 2,
					),
				),
				'billing_country' => 'FR',
			)
		);
		$this->seed_refund(
			$order_f,
			array( $this->hoodie => 1 ),
			array(
				'reason' => 'prior period refund',
				'date'   => '2025-09-12 10:00:00',
			)
		);
	}

	/**
	 * Seed a paid order with billing country attached and a shipping
	 * line of £0 so the address save propagates cleanly into
	 * wc_order_addresses (HPOS) / postmeta (classic).
	 *
	 * @param array $args Same shape as seed_paid_order + billing_country.
	 * @return \WC_Order
	 */
	private function seed_refund_order( array $args ) {
		$order = $this->seed_paid_order( $args );

		if ( isset( $args['billing_country'] ) ) {
			$order->set_billing_country( (string) $args['billing_country'] );
			$order->save();
		}

		return $order;
	}

	/**
	 * Invoke the ability over the fixture period with compare=false
	 * and group_by=none unless overridden.
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
				'group_by'           => 'none',
				'limit'              => 10,
				'include_unassigned' => true,
			),
			$overrides
		);

		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_refund_analysis(
			$input['period'],
			$input['date_start'],
			$input['date_end'],
			$input['compare'],
			$input['group_by'],
			$input['limit'],
			$input['include_unassigned']
		);
		$this->assertFalse(
			is_wp_error( $result ),
			'fetch_refund_analysis returned WP_Error: ' . ( is_wp_error( $result ) ? $result->get_error_message() : '' )
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

	// ─── Headline metrics ─────────────────────────────────────────

	/**
	 * Headline block totals match the fixture: £300 refunds across 4
	 * refund sub-orders (A, B, C, D) against 4 distinct parent orders.
	 * paid_gross = £200 (A £100 + C £50 + E £50 — B is no longer paid
	 * after its flip to refunded, D's parent is in the prior period).
	 * refund_rate_percent = £300 / £200 × 100 = 150% — the legitimate
	 * > 100% reading documented in the tool description (refunds can
	 * exceed current-period paid gross when parents sit outside the
	 * paid window).
	 */
	public function test_headline_metrics() {
		$result  = $this->run_ability();
		$metrics = $result['metrics'];

		$this->assertSame( 300.00, (float) $metrics['refunds_amount'] );
		$this->assertSame( 4, (int) $metrics['refunds_count'] );
		$this->assertSame( 4, (int) $metrics['orders_refunded_count'] );
		$this->assertSame( 200.00, (float) $metrics['paid_gross_revenue'] );
		$this->assertSame( 150.0, (float) $metrics['refund_rate_percent'] );
	}

	/**
	 * Regression: when the period contains refunds but zero paid gross
	 * revenue (refund-only window), the headline `refund_rate_percent`
	 * is mathematically undefined — refunds_amount / 0. Pre-fix the
	 * function returned 0.0 via a divide-by-zero guard, which read as
	 * "no refund problem" rather than "no denominator". Post-fix the
	 * field is null so the merchant sees the rate is unanswerable
	 * rather than misleadingly healthy.
	 *
	 * Fixture: parent paid order in December 2026 (outside any test
	 * period), refunded in January 2027. Querying January 2027 sees
	 * only the refund — paid_gross_revenue = 0, refunds_amount > 0.
	 *
	 * Bug source: class-analytics-controller.php query_refund_metrics()
	 * Codex severity: Medium.
	 */
	public function test_headline_refund_rate_is_null_when_paid_gross_zero() {
		$customer = $this->seed_customer();
		$parent   = $this->seed_refund_order(
			array(
				'customer_id'     => $customer,
				'total'           => 100.00,
				'date'            => '2026-12-15 10:00:00',
				'items'           => array(
					array(
						'product_id' => $this->hoodie,
						'qty'        => 1,
					),
				),
				'billing_country' => 'GB',
			)
		);
		$this->seed_refund(
			$parent,
			array( $this->hoodie => 1 ),
			array(
				'reason' => 'refund-only window regression',
				'date'   => '2027-01-15 10:00:00',
			)
		);

		$result  = $this->run_ability(
			array(
				'date_start' => '2027-01-01',
				'date_end'   => '2027-01-31',
			)
		);
		$metrics = $result['metrics'];

		$this->assertGreaterThan(
			0,
			(float) $metrics['refunds_amount'],
			'Sanity: the refund-only period should report the refund.'
		);
		$this->assertSame(
			0.0,
			(float) $metrics['paid_gross_revenue'],
			'Sanity: the refund-only period has no paid gross revenue.'
		);
		$this->assertNull(
			$metrics['refund_rate_percent'],
			'Refund rate must be null when paid_gross_revenue is 0 with refunds present. Pre-fix this returned 0.0 — read as "no refund problem" rather than "rate undefined."'
		);
	}

	/**
	 * Order B is the only full refund (£100 on a £100 order flips
	 * the parent's status to wc-refunded). A, C, D are partials.
	 */
	public function test_partial_vs_full_split() {
		$result  = $this->run_ability();
		$metrics = $result['metrics'];

		$this->assertSame( 1, (int) $metrics['full_refunds_count'], 'Order B is the only full refund.' );
		$this->assertSame( 3, (int) $metrics['partial_refunds_count'], 'A, C, D are partials.' );
		$this->assertSame(
			200.00,
			(float) $metrics['partial_refunds_amount'],
			'Partial amount = £50 A + £50 C + £100 D = £200.'
		);
	}

	/**
	 * Days-to-refund: A=0, B=3, C=13, D=35.
	 * avg = 51/4 = 12.75. Median = (3+13)/2 = 8.0 (even-count mid).
	 */
	public function test_days_to_refund_avg_and_median() {
		$result  = $this->run_ability();
		$metrics = $result['metrics'];

		$this->assertSame( 12.8, (float) $metrics['avg_days_to_refund'], 'avg = 51/4 = 12.75, rounded to 12.8.' );
		$this->assertSame( 8.0, (float) $metrics['median_days_to_refund'], 'median of [0,3,13,35] = (3+13)/2 = 8.' );
	}

	// ─── Timing buckets ───────────────────────────────────────────

	/**
	 * Buckets are returned in fixed order (Same day, 1–7, 8–30, 31+).
	 * Each refund lands in exactly one bucket: A=0 → Same day, B=3 →
	 * 1–7, C=13 → 8–30, D=35 → 31+. share_percent = count / 4 × 100,
	 * which is 25% for every bucket on our fixture.
	 */
	public function test_timing_buckets_fixed_order_and_counts() {
		$result  = $this->run_ability();
		$buckets = $result['timing']['buckets'];

		$this->assertCount( 4, $buckets );
		$this->assertSame( 'same_day', $buckets[0]['key'] );
		$this->assertSame( 'within_week', $buckets[1]['key'] );
		$this->assertSame( 'within_month', $buckets[2]['key'] );
		$this->assertSame( 'beyond_month', $buckets[3]['key'] );

		$this->assertSame( 1, (int) $buckets[0]['count'], 'Same day — Order A refunded on day of purchase.' );
		$this->assertSame( 1, (int) $buckets[1]['count'], '1–7 days — Order B at 3 days.' );
		$this->assertSame( 1, (int) $buckets[2]['count'], '8–30 days — Order C at 13 days.' );
		$this->assertSame( 1, (int) $buckets[3]['count'], '31+ days — Order D at 35 days (prior-period parent).' );

		// Each row carries share_percent summing to 100% (1/4 × 100).
		foreach ( $buckets as $bucket ) {
			$this->assertSame( 25.0, (float) $bucket['share_percent'] );
		}

		$this->assertSame( 4, (int) $result['timing']['total'] );
	}

	// ─── group_by=product ─────────────────────────────────────────

	/**
	 * Grouping by product returns one row per product touched by a refund.
	 * Hoodie (A + C) = £100, 2 refunds. Headphones (B + D) = £200, 2
	 * refunds. Headphones outranks Hoodie on refunds_amount.
	 *
	 * Per-row gross_revenue is the product's paid gross in the same
	 * window: Hoodie paid in October = A (£100) + C (£50) + E (£50) =
	 * £200. Headphones paid in October = £0 (Order B flipped to
	 * wc-refunded; Order D parent is prior period). The £0 denominator
	 * exercises the divide-by-zero guard in the pre-computed
	 * refund_rate_percent.
	 */
	public function test_group_by_product() {
		$result = $this->run_ability( array( 'group_by' => 'product' ) );

		$this->assertSame( 'product', $result['group_by'] );
		$this->assertCount( 2, $result['top_groups'] );

		// Headphones first (£200 refunds > £100 Hoodie refunds).
		$headphones = $result['top_groups'][0];
		$hoodie     = $result['top_groups'][1];

		$this->assertSame( 'Headphones', $headphones['label'] );
		$this->assertSame( 200.00, (float) $headphones['refunds_amount'] );
		$this->assertSame( 2, (int) $headphones['refunds_count'] );
		$this->assertSame( 2, (int) $headphones['orders_refunded_count'] );
		$this->assertSame( 0.00, (float) $headphones['gross_revenue'], 'Paid Headphones in Oct = £0 (B flipped to refunded, D parent in prior period).' );

		$this->assertSame( 'Hoodie', $hoodie['label'] );
		$this->assertSame( 100.00, (float) $hoodie['refunds_amount'] );
		$this->assertSame( 2, (int) $hoodie['refunds_count'] );
		$this->assertSame( 200.00, (float) $hoodie['gross_revenue'], 'Paid Hoodie in Oct = £200 (A £100 + C £50 + E £50).' );
	}

	/**
	 * Each top_groups row carries share_of_refunds_percent +
	 * refund_rate_percent pre-computed by fetch_refund_analysis()
	 * (not the per-query helper). Against fixture totals:
	 *   Headphones share = £200 / £300 = 66.7%
	 *   Hoodie     share = £100 / £300 = 33.3%
	 *   Headphones rate  = null (£0 paid gross with £200 refunded —
	 *                     refunds landed against parents outside the
	 *                     current paid window, so the rate is
	 *                     mathematically undefined). Pre-fix the row
	 *                     reported 0.0, which read as "no refund
	 *                     problem" when the real signal was "we don't
	 *                     have a denominator to compute against."
	 *   Hoodie     rate  = £100 / £200 = 50%
	 */
	public function test_group_by_product_precomputed_ratios() {
		$result = $this->run_ability( array( 'group_by' => 'product' ) );

		$headphones = $result['top_groups'][0];
		$hoodie     = $result['top_groups'][1];

		$this->assertSame( 66.7, (float) $headphones['share_of_refunds_percent'] );
		$this->assertSame( 33.3, (float) $hoodie['share_of_refunds_percent'] );

		$this->assertNull(
			$headphones['refund_rate_percent'],
			'Zero-denominator guard: £0 paid gross with £200 refunded → null, not 0.0. Pre-fix this returned 0.0 and a merchant reading "0% refund rate" missed that the rate was undefined rather than healthy.'
		);
		$this->assertSame( 50.0, (float) $hoodie['refund_rate_percent'] );
	}

	// ─── group_by=country ─────────────────────────────────────────

	/**
	 * Grouping by country inherits the parent's billing country.
	 * Germany leads (£150 = £50 A + £100 D). France next (£100 = B).
	 * UK last (£50 = C). Per-row avg_days_to_refund uses the refunds
	 * that landed on that country.
	 */
	public function test_group_by_country() {
		$result = $this->run_ability( array( 'group_by' => 'country' ) );

		$this->assertSame( 'country', $result['group_by'] );
		$this->assertCount( 3, $result['top_groups'] );

		$de = $this->find_group( $result['top_groups'], 'DE' );
		$fr = $this->find_group( $result['top_groups'], 'FR' );
		$gb = $this->find_group( $result['top_groups'], 'GB' );

		$this->assertNotNull( $de );
		$this->assertSame( 150.00, (float) $de['refunds_amount'], 'DE: £50 (A) + £100 (D) = £150.' );
		$this->assertSame( 2, (int) $de['refunds_count'] );
		$this->assertSame( 2, (int) $de['orders_refunded_count'] );

		$this->assertNotNull( $fr );
		$this->assertSame( 100.00, (float) $fr['refunds_amount'], 'FR: £100 (B).' );
		$this->assertSame( 1, (int) $fr['refunds_count'] );

		$this->assertNotNull( $gb );
		$this->assertSame( 50.00, (float) $gb['refunds_amount'], 'GB: £50 (C).' );
	}

	/**
	 * Per-row refund_rate_percent uses each country's paid gross in
	 * the same window:
	 *   DE paid in Oct = A £100 + E £50 = £150
	 *                  → rate = £150 refunds / £150 paid = 100%
	 *   FR paid in Oct = £0 (B flipped to wc-refunded)
	 *                  → rate = null (no denominator to compute
	 *                    against; pre-fix this returned 0.0 and the
	 *                    merchant read "no refund problem in France"
	 *                    when the real signal was "we can't tell")
	 *   GB paid in Oct = C £50
	 *                  → rate = £50 refunds / £50 paid = 100%
	 *
	 * Share_of_refunds_percent: DE 50% (£150/£300), FR 33.3%
	 * (£100/£300), GB 16.7% (£50/£300).
	 */
	public function test_group_by_country_precomputed_ratios() {
		$result = $this->run_ability( array( 'group_by' => 'country' ) );

		$de = $this->find_group( $result['top_groups'], 'DE' );
		$fr = $this->find_group( $result['top_groups'], 'FR' );
		$gb = $this->find_group( $result['top_groups'], 'GB' );

		$this->assertSame( 50.0, (float) $de['share_of_refunds_percent'] );
		$this->assertSame( 33.3, (float) $fr['share_of_refunds_percent'] );
		$this->assertSame( 16.7, (float) $gb['share_of_refunds_percent'] );

		$this->assertSame( 100.0, (float) $de['refund_rate_percent'] );
		$this->assertNull(
			$fr['refund_rate_percent'],
			'FR paid gross = £0 after B flipped to refunded; the per-row rate is mathematically undefined. Pre-fix this returned 0.0 (zero-denominator guard) which read as "no refund problem" rather than "no denominator."'
		);
		$this->assertSame( 100.0, (float) $gb['refund_rate_percent'] );
	}

	// ─── Comparison ───────────────────────────────────────────────

	/**
	 * Setting compare=true populates the comparison block with
	 * prior-period metrics + pre-computed changes. Prior period =
	 * 2025-08-31..09-30 (the 31 calendar days ending just before our
	 * current period); Order F's £50 refund on 2025-09-12 lands in
	 * that window.
	 *
	 * Prior refunds_amount = £50, current = £300 → direction=up,
	 * amount=+£250, percent=+500%.
	 */
	public function test_comparison_block() {
		$result = $this->run_ability( array( 'compare' => true ) );

		$this->assertNotNull( $result['comparison'] );
		// 31-day window ⇒ prior is the 31 days preceding: Aug 31 → Sep 30.
		$this->assertSame( '2025-08-31', $result['comparison']['period']['start'] );
		$this->assertSame( '2025-09-30', $result['comparison']['period']['end'] );

		$prev = $result['comparison']['metrics'];
		$this->assertSame( 50.00, (float) $prev['refunds_amount'] );
		$this->assertSame( 1, (int) $prev['refunds_count'] );

		$changes = $result['comparison']['changes'];
		$this->assertSame( 'up', $changes['refunds_amount']['direction'] );
		$this->assertSame( 250.00, (float) $changes['refunds_amount']['amount'] );
		$this->assertSame( 500.0, (float) $changes['refunds_amount']['percent'] );
	}

	// ─── Empty period ─────────────────────────────────────────────

	/**
	 * No refunds in range → note populated, top_groups empty,
	 * metrics zeroed.
	 */
	public function test_empty_period_produces_note() {
		$result = $this->run_ability(
			array(
				'date_start' => '2020-01-01',
				'date_end'   => '2020-01-31',
				'group_by'   => 'product',
			)
		);

		$this->assertStringContainsString( 'No refunds', (string) $result['note'] );
		$this->assertSame( array(), $result['top_groups'] );
		$this->assertSame( 0.00, (float) $result['metrics']['refunds_amount'] );
		$this->assertSame( 0, (int) $result['metrics']['refunds_count'] );
	}

	// ─── Date-type setting regression ─────────────────────────────

	/**
	 * Regression: when `woocommerce_date_type` is set to `date_paid`
	 * or `date_completed`, refund-side queries must still find the
	 * refunds. Pre-fix the refund-window filters used the configured
	 * date column on the refund sub-order row — but real-world stores
	 * commonly have refund sub-orders whose `date_paid` /
	 * `date_completed` columns weren't populated by their payment
	 * gateway / order pipeline (no payment was processed against the
	 * refund; the refund isn't "completed" in the order-flow sense).
	 * On those stores `refund.date_paid >= %s` matched zero rows and
	 * the merchant saw `refunds_amount = £0` / `refunds_count = 0`
	 * even when refunds were clearly visible in WC Admin.
	 *
	 * The wp-env test environment populates `date_paid` /
	 * `date_completed` for every refund (WC's `OrdersStatsStore::
	 * sync_order` copies the refund's own `date_created`), so the
	 * bug's natural shape doesn't reproduce here. The test forces it:
	 * NULL out `date_paid` on the fixture's refund sub-orders to
	 * simulate the stores Codex flagged. The fix (`refund.date_created`
	 * everywhere) keeps the refund visible regardless of the parent's
	 * configured date-type column.
	 *
	 * Bug source: class-analytics-controller.php query_refund_metrics()
	 * + the four group-by query helpers.
	 * Codex severity: P2.
	 */
	public function test_refund_metrics_under_date_paid_setting() {
		global $wpdb;

		// Simulate stores where refund sub-orders genuinely lack date_paid.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: simulating missing-date_paid refund rows.
		$wpdb->query(
			"UPDATE {$wpdb->prefix}wc_order_stats SET date_paid = NULL WHERE parent_id != 0"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test sanity check.
		$null_date_paid_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}wc_order_stats
			 WHERE parent_id != 0 AND date_paid IS NULL"
		);
		$this->assertGreaterThan(
			0,
			$null_date_paid_count,
			'Sanity precondition: at least one refund sub-order must have NULL date_paid after the simulation UPDATE — without that, the regression isn\'t actually exercising the bug path.'
		);

		$prior = get_option( 'woocommerce_date_type', 'date_created' );
		update_option( 'woocommerce_date_type', 'date_paid' );

		try {
			$result  = $this->run_ability();
			$metrics = $result['metrics'];

			// The fixture seeds 4 refunds totalling £300 (A 50 + B 100 + C 50 + D 100).
			// Pre-fix every one was dropped because refund.date_paid was NULL.
			$this->assertSame(
				300.00,
				(float) $metrics['refunds_amount'],
				'Refund amount must be visible regardless of woocommerce_date_type. Pre-fix `refund.date_paid >= ?` matched zero refund rows because refund sub-orders don\'t have date_paid populated.'
			);
			$this->assertSame(
				4,
				(int) $metrics['refunds_count'],
				'All 4 fixture refunds must be counted, not silently dropped by the date-column filter.'
			);
		} finally {
			update_option( 'woocommerce_date_type', $prior );
		}
	}

	// ─── Schema guard ─────────────────────────────────────────────

	/**
	 * Response carries the expected top-level keys so downstream code
	 * (MCP client, schema validators, tool description references)
	 * never hits an undefined index.
	 */
	public function test_response_schema_top_level_keys() {
		$result   = $this->run_ability();
		$expected = array(
			'period',
			'currency',
			'group_by',
			'limit',
			'include_unassigned',
			'metrics',
			'timing',
			'top_groups',
			'comparison',
			'note',
		);
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $result, "Missing top-level key: {$key}" );
		}
	}
}
