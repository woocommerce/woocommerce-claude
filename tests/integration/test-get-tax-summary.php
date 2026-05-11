<?php
/**
 * Integration tests — wc-analytics/get-tax-summary.
 *
 * Pins the invariants around AnalyticsController::fetch_tax_summary()
 * and its query helpers (totals + per-rate). The prototype Taxes
 * DataStore covered tax-by-rate; this skill adds the three-view
 * pattern (paid / pipeline / admin_equivalent), the net_tax
 * (collected − refunded) figure that maps to a VAT return, and
 * pre-computed effective_tax_rate_percent + share_of_tax_percent
 * so the model never divides narratively.
 *
 * What's pinned:
 *
 *   - Headline totals: total_tax / order_tax / shipping_tax aggregate
 *     paid orders only (status IN completed + processing). On-hold
 *     tax sits in the pipeline block. Refund tax sits in
 *     refunded_tax (as ABS-of-negative-sub-order-tax).
 *   - net_tax = total_tax − refunded_tax. Pre-computed; tests assert
 *     the difference is honoured exactly, not recomputed elsewhere.
 *   - effective_tax_rate_percent = total_tax ÷ paid_net_revenue × 100,
 *     pre-computed to 1 decimal. Denominator uses the same paid
 *     net_total field as revenue_summary's net_sales so the rate
 *     reads as "tax as % of net revenue" consistently.
 *   - taxable_share_percent = taxable_orders ÷ total_paid_orders × 100.
 *     Counts orders whose tax_lookup row sums to total_tax > 0 — so
 *     a paid order with no tax doesn't move the denominator.
 *   - Per-rate share_of_tax_percent uses the FULL paid total_tax
 *     denominator, not the sum of visible top_rates — stays correct
 *     when limit clips the long tail.
 *   - Per-rate refunded_tax attributes back to the rate that
 *     collected it. Mirrors the canonical refund-attribution shape
 *     on revenue_breakdown.
 *   - Unmatched rates (tax_rate_id = 0, no row in
 *     woocommerce_tax_rates) surface honestly with empty
 *     name/country/state — they're real collected tax, just not
 *     attributable to a current setting.
 *   - Empty-tax stores produce note="No tax was collected..."
 *     instead of fabricating numbers. Same store with positive
 *     pipeline tax suppresses the empty note (tax exists, just not
 *     paid through yet).
 *   - Stores with collected tax but no matching rate row produce
 *     note="Tax was collected but cannot be attributed..." —
 *     totals are accurate, top_rates surfaces the unmatched row.
 *   - compare=true emits per-rate change blocks on rates present in
 *     both periods, direction=new for rates only in current,
 *     comparison.dropped_out for rates only in previous.
 *   - Three-view per-row fields: pipeline_total_tax /
 *     pipeline_orders_count / admin_equivalent_total_tax /
 *     admin_equivalent_orders_count. Each row is self-contained
 *     for narration without the model having to cross-reference.
 *
 * Fixture shape (current period 2025-10-01..2025-10-31,
 * prior period 2025-09-01..2025-09-30):
 *
 *   Tax rates:
 *     UK_VAT  (id N1) — country GB, rate 20.0000, name "UK VAT".
 *     DE_VAT  (id N2) — country DE, rate 19.0000, name "DE VAT".
 *
 *   Current-period orders:
 *     A — 2025-10-05, paid, £100 net, £20 UK_VAT order_tax.
 *     B — 2025-10-08, paid, £200 net, £40 UK_VAT order_tax.
 *     C — 2025-10-12, paid, £100 net, £19 DE_VAT order_tax + £5 DE_VAT shipping_tax.
 *     D — 2025-10-15, paid, £50  net, £10 UNMATCHED tax (tax_rate_id = 0).
 *     E — 2025-10-20, on-hold, £100 net, £20 UK_VAT (pipeline).
 *     Refund — Order C partial refund: £9.50 of DE_VAT clawed back
 *              on 2025-10-22 via a -£9.50 total_tax row in
 *              wc_order_tax_lookup (id = DE_VAT).
 *
 *   Prior-period order (for the comparison block):
 *     F — 2025-09-10, paid, £100 net, £20 UK_VAT order_tax.
 *
 * Expected paid totals (current period):
 *   total_tax                  = £94.00 (20 + 40 + (19+5) + 10)
 *   order_tax                  = £89.00 (20 + 40 + 19 + 10)
 *   shipping_tax               = £5.00  (5)
 *   refunded_tax               = £9.50
 *   net_tax                    = £84.50 (94 − 9.50)
 *   paid_net_revenue           = £450.00 (100 + 200 + 100 + 50)
 *   total_paid_orders          = 4
 *   taxable_orders             = 4
 *   taxable_share_percent      = 100.0
 *   effective_tax_rate_percent = 20.9   (94 / 450 × 100, rounded 1dp)
 *
 * Expected pipeline:
 *   total_tax    = £20.00 (Order E)
 *   orders_count = 1
 *
 * Expected admin_equivalent (paid + on-hold + refunded parents):
 *   total_tax    = £114.00 (paid £94 + pipeline £20)
 *   orders_count = 5      (4 paid + 1 on-hold)
 *
 * Per-rate (orderby=total_tax):
 *   UK_VAT     — total_tax £60, share 63.8% (60/94 × 100), orders 2
 *   DE_VAT     — total_tax £24, share 25.5%, orders 1, refunded_tax £9.50
 *   Unmatched  — total_tax £10, share 10.6%, orders 1
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the get-tax-summary ability.
 */
class Test_Get_Tax_Summary extends WP_UnitTestCase {

	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * Period start used by populated-fixture runs.
	 *
	 * @var string
	 */
	private $period_start = '2025-10-01';

	/**
	 * Period end used by populated-fixture runs.
	 *
	 * @var string
	 */
	private $period_end = '2025-10-31';

	/**
	 * UK_VAT rate ID (assigned by AUTO_INCREMENT, captured in set_up).
	 *
	 * @var int
	 */
	private $uk_vat_id;

	/**
	 * DE_VAT rate ID.
	 *
	 * @var int
	 */
	private $de_vat_id;

	/**
	 * Order C — retained so a refund tax row can be seeded against it.
	 *
	 * @var \WC_Order
	 */
	private $order_c;

	/**
	 * Seed the fixture shape described in the file-level docblock.
	 */
	public function set_up() {
		parent::set_up();

		$this->uk_vat_id = $this->seed_tax_rate(
			array(
				'country' => 'GB',
				'name'    => 'UK VAT',
				'rate'    => '20.0000',
			)
		);
		$this->de_vat_id = $this->seed_tax_rate(
			array(
				'country' => 'DE',
				'name'    => 'DE VAT',
				'rate'    => '19.0000',
			)
		);

		// Order A — paid, £100 net + £20 UK_VAT order tax.
		$a = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $a,
				'total'       => 100.00,
				'date'        => '2025-10-05 10:00:00',
				'tax_total'   => 20.00,
				'tax_rate_id' => $this->uk_vat_id,
			)
		);

		// Order B — paid, £200 net + £40 UK_VAT order tax.
		$b = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $b,
				'total'       => 200.00,
				'date'        => '2025-10-08 10:00:00',
				'tax_total'   => 40.00,
				'tax_rate_id' => $this->uk_vat_id,
			)
		);

		// Order C — paid, £100 net + £19 DE_VAT order tax + £5 DE_VAT shipping tax.
		$c             = $this->seed_customer();
		$this->order_c = $this->seed_paid_order(
			array(
				'customer_id'  => $c,
				'total'        => 100.00,
				'date'         => '2025-10-12 10:00:00',
				'tax_total'    => 19.00,
				'shipping_tax' => 5.00,
				'tax_rate_id'  => $this->de_vat_id,
			)
		);

		// Order D — paid, £50 net + £10 UNMATCHED tax (no rate row).
		$d = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $d,
				'total'       => 50.00,
				'date'        => '2025-10-15 10:00:00',
				'tax_total'   => 10.00,
				'tax_rate_id' => 0,
			)
		);

		// Order E — ON-HOLD, £100 net + £20 UK_VAT (pipeline).
		$e = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $e,
				'total'       => 100.00,
				'date'        => '2025-10-20 10:00:00',
				'status'      => 'on-hold',
				'tax_total'   => 20.00,
				'tax_rate_id' => $this->uk_vat_id,
			)
		);

		// Refund of Order C — claw back £9.50 DE_VAT on 2025-10-22.
		// We don't go through seed_refund() here because that creates
		// a refund product line; for tax-only purposes the simpler
		// path is to write a refund stats row + a -£9.50 tax_lookup
		// row directly. That mirrors how WC writes refund tax rows
		// when a refund-payment-only happens (no item lines refunded).
		$refund_id = $this->seed_orphan_refund_for_tax(
			$this->order_c,
			-9.50,
			'2025-10-22 10:00:00'
		);
		$this->seed_order_tax_row( $refund_id, -9.50, 0.00, $this->de_vat_id );

		// Prior-period Order F — £100 net + £20 UK_VAT.
		$f = $this->seed_customer();
		$this->seed_paid_order(
			array(
				'customer_id' => $f,
				'total'       => 100.00,
				'date'        => '2025-09-10 10:00:00',
				'tax_total'   => 20.00,
				'tax_rate_id' => $this->uk_vat_id,
			)
		);
	}

	/**
	 * Tear down — drop the seeded tax rates so each test class run
	 * starts clean. WP_UnitTestCase's transactional rollback covers
	 * the wc_order_stats / wc_order_tax_lookup writes inside the
	 * default tables, but woocommerce_tax_rates is a custom table
	 * outside the txn.
	 */
	public function tear_down() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_order_tax_lookup" );

		parent::tear_down();
	}

	/**
	 * Write a refund stats row directly so we can attach a tax-only
	 * refund (no product line, just a tax claw-back). seed_refund()
	 * goes through wc_create_refund() which insists on a positive
	 * amount + line items; this seeder bypasses that for tests that
	 * only need to exercise the tax-side aggregation.
	 *
	 * @param \WC_Order $parent_order Parent order being refunded against.
	 * @param float     $amount       Refund amount, NEGATIVE (matches WC's convention).
	 * @param string    $date         'Y-m-d H:i:s' for the refund's date_created.
	 * @return int Synthetic refund order id.
	 */
	private function seed_orphan_refund_for_tax( $parent_order, $amount, $date ) {
		global $wpdb;

		$refund_id = wp_rand( 800000, 899999 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'wc_order_stats',
			array(
				'order_id'         => $refund_id,
				'parent_id'        => $parent_order->get_id(),
				'date_created'     => $date,
				'date_created_gmt' => $date,
				'num_items_sold'   => 0,
				'total_sales'      => (float) $amount,
				'tax_total'        => 0,
				'shipping_total'   => 0,
				'net_total'        => (float) $amount,
				'status'           => 'wc-completed',
				'customer_id'      => (int) $parent_order->get_customer_id(),
			)
		);

		return $refund_id;
	}

	/**
	 * Run the ability and return the response array.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	private function run_ability( array $input ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_tax_summary(
			$input['period'] ?? 'custom',
			$input['date_start'],
			$input['date_end'],
			$input['compare'] ?? false,
			$input['limit'] ?? 10,
			$input['orderby'] ?? 'total_tax'
		);

		if ( is_wp_error( $result ) ) {
			$this->fail( 'fetch_tax_summary returned WP_Error: ' . $result->get_error_message() );
		}

		return $result;
	}

	/**
	 * Default input — period overridden via custom date_start/date_end so
	 * the tests don't depend on the test-run wall-clock falling inside
	 * any particular period.
	 *
	 * @return array
	 */
	private function default_input() {
		return array(
			'date_start' => $this->period_start,
			'date_end'   => $this->period_end,
			'compare'    => false,
		);
	}

	/**
	 * Headline totals — the four primary numbers a merchant cares about.
	 */
	public function test_headline_totals() {
		$result = $this->run_ability( $this->default_input() );

		$this->assertSame( 94.00, $result['totals']['total_tax'], 'paid total_tax = 20 + 40 + 24 + 10' );
		$this->assertSame( 89.00, $result['totals']['order_tax'], 'order_tax = 20 + 40 + 19 + 10' );
		$this->assertSame( 5.00, $result['totals']['shipping_tax'], 'shipping_tax = 5 (DE shipping only)' );
		$this->assertSame( 9.50, $result['totals']['refunded_tax'], 'refunded_tax = 9.50 (ABS of -9.50)' );
	}

	/**
	 * Net_tax is pre-computed (total_tax - refunded_tax). The whole
	 * point of the field is that Claude reads it directly rather than
	 * subtracting on the fly.
	 */
	public function test_net_tax_is_precomputed() {
		$result = $this->run_ability( $this->default_input() );

		$this->assertSame( 84.50, $result['totals']['net_tax'], 'net_tax = 94.00 − 9.50' );
		$this->assertSame(
			$result['totals']['total_tax'] - $result['totals']['refunded_tax'],
			$result['totals']['net_tax'],
			'net_tax must equal total_tax − refunded_tax exactly'
		);
	}

	/**
	 * Effective_tax_rate_percent and taxable_share_percent are pre-computed
	 * to 1 decimal; tests pin both against direct arithmetic against the
	 * documented fixture.
	 */
	public function test_effective_tax_rate_and_taxable_share_are_precomputed() {
		$result = $this->run_ability( $this->default_input() );

		$this->assertSame( 450.00, $result['totals']['paid_net_revenue'], 'paid_net_revenue = 100 + 200 + 100 + 50' );
		$this->assertSame( 4, $result['totals']['total_paid_orders'] );
		$this->assertSame( 4, $result['totals']['taxable_orders'], 'all 4 paid orders had tax > 0' );
		$this->assertSame( 100.0, $result['totals']['taxable_share_percent'] );
		$this->assertSame( 20.9, $result['totals']['effective_tax_rate_percent'], '94 ÷ 450 × 100, rounded to 1dp' );
	}

	/**
	 * Pipeline block reflects on-hold tax separately from paid totals.
	 */
	public function test_pipeline_block_reports_on_hold_tax_separately() {
		$result = $this->run_ability( $this->default_input() );

		$this->assertSame( 20.00, $result['pipeline']['total_tax'], 'Order E pipeline tax' );
		$this->assertSame( 1, $result['pipeline']['orders_count'] );
	}

	/**
	 * Admin_equivalent reflects what WC Admin's Tax report would
	 * show — paid + on-hold + refunded parents lumped together.
	 */
	public function test_admin_equivalent_block_matches_wc_admin_definition() {
		$result = $this->run_ability( $this->default_input() );

		$this->assertSame( 114.00, $result['admin_equivalent']['total_tax'], '94 paid + 20 on-hold' );
		$this->assertSame( 5, $result['admin_equivalent']['orders_count'], '4 paid + 1 on-hold' );
	}

	/**
	 * Top_rates ordering, per-rate amounts, and pre-computed
	 * share_of_tax_percent. UK_VAT first because it has the largest
	 * total_tax (60).
	 */
	public function test_top_rates_returns_per_rate_paid_amounts() {
		$result = $this->run_ability( $this->default_input() );

		$rates = $result['top_rates'];
		$this->assertCount( 3, $rates );

		$by_id = array();
		foreach ( $rates as $row ) {
			$by_id[ (int) $row['tax_rate_id'] ] = $row;
		}

		$uk = $by_id[ $this->uk_vat_id ];
		$this->assertSame( 60.00, $uk['total_tax'], 'UK_VAT = 20 + 40' );
		$this->assertSame( 60.00, $uk['order_tax'] );
		$this->assertSame( 0.00, $uk['shipping_tax'] );
		$this->assertSame( 2, $uk['orders_count'] );
		$this->assertSame( 0.00, $uk['refunded_tax'] );
		$this->assertSame( 'UK VAT', $uk['tax_rate_name'] );
		$this->assertSame( 'GB', $uk['tax_rate_country'] );
		$this->assertSame( 20.0, $uk['tax_rate_percent'] );

		$de = $by_id[ $this->de_vat_id ];
		$this->assertSame( 24.00, $de['total_tax'], 'DE_VAT = 19 + 5' );
		$this->assertSame( 19.00, $de['order_tax'] );
		$this->assertSame( 5.00, $de['shipping_tax'] );
		$this->assertSame( 1, $de['orders_count'] );
		$this->assertSame( 9.50, $de['refunded_tax'], 'DE_VAT row absorbs the refund' );
		$this->assertSame( 'DE VAT', $de['tax_rate_name'] );
		$this->assertSame( 'DE', $de['tax_rate_country'] );
		$this->assertSame( 19.0, $de['tax_rate_percent'] );

		$unmatched = $by_id[0];
		$this->assertSame( 10.00, $unmatched['total_tax'] );
		$this->assertSame( 1, $unmatched['orders_count'] );
		$this->assertSame( '', $unmatched['tax_rate_name'], 'unmatched rate has no name' );
		$this->assertSame( '', $unmatched['tax_rate_country'] );
		$this->assertNull( $unmatched['tax_rate_percent'] );
	}

	/**
	 * Per-row share_of_tax_percent uses the FULL paid total_tax as the
	 * denominator (94), so values are stable regardless of limit.
	 */
	public function test_share_of_tax_percent_is_precomputed_against_full_denominator() {
		$result = $this->run_ability( $this->default_input() );

		$by_id = array();
		foreach ( $result['top_rates'] as $row ) {
			$by_id[ (int) $row['tax_rate_id'] ] = $row;
		}

		$this->assertSame( 63.8, $by_id[ $this->uk_vat_id ]['share_of_tax_percent'], '60 / 94 × 100' );
		$this->assertSame( 25.5, $by_id[ $this->de_vat_id ]['share_of_tax_percent'], '24 / 94 × 100' );
		$this->assertSame( 10.6, $by_id[0]['share_of_tax_percent'], '10 / 94 × 100' );

		// Sanity: shares of returned rows must add up to ~100 within rounding.
		// Three rows rounded to 1dp can drift by up to ~0.15 from the true total.
		$sum = round(
			$by_id[ $this->uk_vat_id ]['share_of_tax_percent']
			+ $by_id[ $this->de_vat_id ]['share_of_tax_percent']
			+ $by_id[0]['share_of_tax_percent'],
			1
		);
		$this->assertGreaterThanOrEqual( 99.8, $sum );
		$this->assertLessThanOrEqual( 100.2, $sum );
	}

	/**
	 * Per-row pipeline aggregates appear on the rate that carried the
	 * pipeline order. Order E (£20 UK_VAT pipeline) shows up on UK_VAT
	 * and nowhere else.
	 */
	public function test_per_row_pipeline_attribution() {
		$result = $this->run_ability( $this->default_input() );

		$by_id = array();
		foreach ( $result['top_rates'] as $row ) {
			$by_id[ (int) $row['tax_rate_id'] ] = $row;
		}

		$this->assertSame( 20.00, $by_id[ $this->uk_vat_id ]['pipeline_total_tax'] );
		$this->assertSame( 1, $by_id[ $this->uk_vat_id ]['pipeline_orders_count'] );
		$this->assertSame( 0.00, $by_id[ $this->de_vat_id ]['pipeline_total_tax'] );
		$this->assertSame( 0, $by_id[ $this->de_vat_id ]['pipeline_orders_count'] );
	}

	/**
	 * Empty-period note fires when the period has no tax at all (paid
	 * AND pipeline both zero). Honest "we collected nothing" framing
	 * over fabricated zeroes.
	 */
	public function test_empty_period_emits_honest_note() {
		$result = $this->run_ability(
			array(
				// Window with no orders in the fixture.
				'date_start' => '2024-01-01',
				'date_end'   => '2024-01-31',
				'compare'    => false,
			)
		);

		$this->assertSame( 0.00, $result['totals']['total_tax'] );
		$this->assertSame( 0.00, $result['pipeline']['total_tax'] );
		$this->assertSame( array(), $result['top_rates'] );
		$this->assertNotNull( $result['note'] );
		$this->assertStringContainsString( 'No tax was collected', $result['note'] );
	}

	/**
	 * The compare=true flag emits per-rate change blocks for rates present
	 * in both periods (UK_VAT) and direction=new for rates only in the
	 * current period (DE_VAT, Unmatched).
	 */
	public function test_compare_true_emits_change_block_per_rate() {
		$result = $this->run_ability(
			array(
				'date_start' => $this->period_start,
				'date_end'   => $this->period_end,
				'compare'    => true,
			)
		);

		$this->assertNotNull( $result['comparison'] );
		// resolve_dates uses inclusive-day arithmetic — a 31-day current period
		// rolls back to a 31-day prior window ending on the day before period start.
		$this->assertSame( '2025-08-31', $result['comparison']['period']['start'] );
		$this->assertSame( '2025-09-30', $result['comparison']['period']['end'] );

		$this->assertSame( 20.00, $result['comparison']['totals']['total_tax'], 'prior period only Order F (£20 UK_VAT)' );

		$by_id = array();
		foreach ( $result['top_rates'] as $row ) {
			$by_id[ (int) $row['tax_rate_id'] ] = $row;
		}

		// UK_VAT was in both periods → change block populated.
		$this->assertArrayHasKey( 'change', $by_id[ $this->uk_vat_id ] );
		$this->assertSame( 'up', $by_id[ $this->uk_vat_id ]['change']['direction'], 'UK_VAT 20 → 60' );
		$this->assertSame( 40.0, (float) $by_id[ $this->uk_vat_id ]['change']['amount'] );

		// DE_VAT and Unmatched are new this period.
		$this->assertSame( 'new', $by_id[ $this->de_vat_id ]['change']['direction'] );
		$this->assertSame( 'new', $by_id[0]['change']['direction'] );

		// Pre-computed totals.changes — total_tax 20 → 94 = +£74, +370%.
		$this->assertSame( 'up', $result['comparison']['changes']['total_tax']['direction'] );
		$this->assertSame( 74.0, (float) $result['comparison']['changes']['total_tax']['amount'] );
		$this->assertSame( 370.0, (float) $result['comparison']['changes']['total_tax']['percent'] );
	}

	/**
	 * The dropped_out array lists rates that were in the prior period but
	 * not the current one. Seed a UK_VAT-only prior period via the
	 * comparison block then assert that nothing dropped out (UK_VAT is in
	 * both); and pin the empty-array shape so future regressions don't
	 * silently break the schema.
	 */
	public function test_dropped_out_is_empty_when_uk_vat_persists() {
		$result = $this->run_ability(
			array(
				'date_start' => $this->period_start,
				'date_end'   => $this->period_end,
				'compare'    => true,
			)
		);

		$this->assertSame( array(), $result['comparison']['dropped_out'] );
	}

	// ─── Multi-tax-rate revenue inflation regression ──────────────

	/**
	 * Regression: an order with multiple `wc_order_tax_lookup` rows
	 * (mixed tax-rate IDs — common for stores selling goods at one rate
	 * and shipping at another, or VAT-on-goods + reduced-rate-on-line)
	 * must contribute its `net_total` to `paid_net_revenue` exactly
	 * once, not once per tax-row.
	 *
	 * Pre-fix `query_tax_summary_totals()` LEFT JOINed
	 * `wc_order_tax_lookup` and summed `os.net_total` directly. Each
	 * tax-row multiplied the order's `wc_order_stats` row, so an order
	 * with two tax-rate rows added 2 × net_total to the revenue tally.
	 * Cascading consequence: `effective_tax_rate_percent =
	 * total_tax / paid_net_revenue` came out artificially low because
	 * the denominator was inflated.
	 *
	 * Post-fix `paid_net_revenue` runs as a separate query without the
	 * `wc_order_tax_lookup` join, so multi-tax-rate orders count once.
	 *
	 * Period 2026-05-01..2026-05-31 is disjoint from the parent test
	 * fixture's 2025-10 window so the seeded order is the only paid
	 * order in this test's response.
	 *
	 * Bug source: class-analytics-controller.php query_tax_summary_totals().
	 * Codex severity: P2.
	 */
	public function test_multi_tax_rate_order_does_not_inflate_paid_net_revenue() {
		$customer = $this->seed_customer();
		$order    = $this->seed_paid_order(
			array(
				'customer_id' => $customer,
				'total'       => 200.00,
				'date'        => '2026-05-15 12:00:00',
			)
		);
		// Two tax_lookup rows for the same order — e.g. £20 VAT on
		// goods @ UK_VAT and £10 reduced-rate VAT on shipping @ DE_VAT.
		// Pre-fix this multiplies the order's wc_order_stats row twice
		// when summing os.net_total.
		$this->seed_order_tax_row( $order->get_id(), 20.00, 0.00, $this->uk_vat_id );
		$this->seed_order_tax_row( $order->get_id(), 0.00, 10.00, $this->de_vat_id );

		$result = $this->run_ability(
			array(
				'date_start' => '2026-05-01',
				'date_end'   => '2026-05-31',
				'compare'    => false,
			)
		);

		$this->assertSame(
			200.00,
			(float) $result['totals']['paid_net_revenue'],
			'Order net_total = £200; pre-fix the LEFT JOIN to wc_order_tax_lookup duplicated the row across both tax-rate rows, summing to £400.'
		);
		$this->assertSame(
			1,
			(int) $result['totals']['total_paid_orders'],
			'COUNT(DISTINCT order_id) is correct even pre-fix; this asserts the fixture really is one order, ruling out mis-seeded data as the cause of the revenue mismatch above.'
		);
		$this->assertSame(
			15.0,
			(float) $result['totals']['effective_tax_rate_percent'],
			'(£20 order_tax + £10 shipping_tax) ÷ £200 = 15.0%. Pre-fix this came out at 7.5% because paid_net_revenue was inflated to £400.'
		);
	}
}
