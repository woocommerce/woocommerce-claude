<?php
/**
 * End-to-end regression tests for query_analytics filter-engine bugs.
 *
 * Each method here pins one specific bug from the 2026-04-28 Codex
 * full-plugin review against a deliberately-uncomfortable fixture —
 * shapes the dev store doesn't have (multi-line-item orders, mixed
 * storage modes, multi-tax-rate orders, etc.). The companion
 * `test-query-analytics-filters.php` file pins the same bugs at the
 * SQL-translation layer; this file pins them at the row-selection
 * layer where JOIN duplication, storage-mode branching, and EXISTS
 * semantics actually surface.
 *
 * The fixture period (2026-02-01..2026-02-28) is intentionally
 * disjoint from the existing `test-query-analytics.php` window
 * (2025-10) so the two test files can co-exist without their
 * fixtures interacting.
 *
 * Adding a regression test for a future filter-engine fix:
 *   1. Add the uncomfortable shape to the fixture (new orders /
 *      products / storage rows as needed) under the same period.
 *   2. Add a method named `test_<bug_shape>_post_fix_invariant()`
 *      that asserts the post-fix result.
 *   3. Reference the source-line range and Codex severity in the
 *      docblock so `git blame` traces back to the review note.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * End-to-end regression tests for query_analytics filter-engine bugs.
 */
class Test_Query_Analytics_Engine_Regressions extends WP_UnitTestCase {

	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * Period start used by every test in this class.
	 *
	 * @var string
	 */
	private $period_start = '2026-02-01';

	/**
	 * Period end used by every test in this class.
	 *
	 * @var string
	 */
	private $period_end = '2026-02-28';

	/**
	 * Product P1 — used as the "X" value in is_not subquery assertions.
	 *
	 * @var int
	 */
	private $p1;

	/**
	 * Product P2 — used as the secondary line item that triggers the
	 * pre-fix `EXISTS (... product_id != X)` to wrongly match orders
	 * containing both P1 and P2.
	 *
	 * @var int
	 */
	private $p2;

	/**
	 * Seed the multi-line-item fixture once per test.
	 *
	 * Three customers and three orders shaped to make the is_not subquery
	 * bug observable:
	 *
	 *   O_A: customer A, items = [P1]       — should match `is_not P2`,
	 *                                         not match `is_not P1`.
	 *   O_AB: customer B, items = [P1, P2]  — should match neither
	 *                                         `is_not P1` nor `is_not P2`.
	 *                                         Pre-fix the subquery walked
	 *                                         the multi-row product lookup
	 *                                         and matched on the "other"
	 *                                         product, so this order
	 *                                         wrongly satisfied both
	 *                                         is_not predicates.
	 *   O_B: customer C, items = [P2]       — should match `is_not P1`,
	 *                                         not match `is_not P2`.
	 *
	 * Pre-fix matched_count for `is_not P1` = 2 (O_AB wrongly included).
	 * Post-fix matched_count for `is_not P1` = 1.
	 */
	public function set_up() {
		parent::set_up();

		$p1 = new \WC_Product_Simple();
		$p1->set_name( 'Filter Engine Regression Product 1' );
		$p1->set_regular_price( '40' );
		$p1->save();
		$this->p1 = (int) $p1->get_id();

		$p2 = new \WC_Product_Simple();
		$p2->set_name( 'Filter Engine Regression Product 2' );
		$p2->set_regular_price( '50' );
		$p2->save();
		$this->p2 = (int) $p2->get_id();

		$customer_a = $this->seed_customer();
		$customer_b = $this->seed_customer();
		$customer_c = $this->seed_customer();

		$this->seed_paid_order(
			array(
				'customer_id' => $customer_a,
				'total'       => 40.0,
				'date'        => '2026-02-05 12:00:00',
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
				'customer_id' => $customer_b,
				'total'       => 90.0,
				'date'        => '2026-02-10 12:00:00',
				'items'       => array(
					array(
						'product_id' => $this->p1,
						'qty'        => 1,
					),
					array(
						'product_id' => $this->p2,
						'qty'        => 1,
					),
				),
			)
		);

		$this->seed_paid_order(
			array(
				'customer_id' => $customer_c,
				'total'       => 50.0,
				'date'        => '2026-02-15 12:00:00',
				'items'       => array(
					array(
						'product_id' => $this->p2,
						'qty'        => 1,
					),
				),
			)
		);
	}

	/**
	 * Seed an extra order at the very end of the fixture period, with no
	 * line items so it doesn't disturb product_id assertions in the
	 * sibling tests. Tests that need the end-of-day shape call this
	 * explicitly — `set_up()` keeps the multi-line-item fixture so the
	 * is_not assertions remain stable.
	 *
	 * Database state rolls back between PHPUnit tests (WP_UnitTestCase
	 * runs each method in a transaction), so this seeder's effects are
	 * scoped to the calling test only.
	 *
	 * @return \WC_Order The seeded order.
	 */
	private function seed_end_of_day_order_in_period() {
		$customer = $this->seed_customer();
		return $this->seed_paid_order(
			array(
				'customer_id' => $customer,
				'total'       => 25.0,
				'date'        => $this->period_end . ' 23:30:00',
			)
		);
	}

	/**
	 * Run the query-analytics ability against the regression fixture period.
	 *
	 * @param array $input Extra input fields merged on top of the period defaults.
	 * @return array Ability response.
	 */
	private function run_ability( array $input = array() ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$input = array_merge(
			array(
				'entity'     => 'orders',
				'date_start' => $this->period_start,
				'date_end'   => $this->period_end,
			),
			$input
		);

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
	 * Sanity baseline: `is product_id = P1` matches the two orders that
	 * contain P1. Pins the EXISTS-not-duplicating invariant for the
	 * positive-membership case and proves the fixture itself is shaped
	 * the way the regression assertions assume.
	 */
	public function test_is_product_id_matches_orders_containing_that_product() {
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

		$this->assertSame(
			2,
			$result['summary']['matched_count'],
			'Two orders contain P1 (O_A and O_AB) — neither should be duplicated by the EXISTS join.'
		);
	}

	/**
	 * Regression: `is_not product_id = P1` must exclude every order that
	 * contains P1, including orders that *also* contain other products.
	 *
	 * Pre-fix the operator translated to `EXISTS (... WHERE product_id !=
	 * %s)`, which matches any order whose line-item table has at least one
	 * non-P1 row. The mixed-cart order (O_AB) therefore wrongly satisfied
	 * the predicate via its P2 line item even though the order's basket
	 * contained P1. Post-fix the operator translates to `NOT EXISTS (...
	 * WHERE product_id = %s)` — no row with P1, so the order is excluded.
	 *
	 * Bug source: class-analytics-controller.php qa_build_filter_clause()
	 * Codex severity: P2.
	 */
	public function test_is_not_product_id_excludes_orders_containing_that_product() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'product_id',
						'operator' => 'is_not',
						'value'    => $this->p1,
					),
				),
			)
		);

		$this->assertSame(
			1,
			$result['summary']['matched_count'],
			'Only O_B (P2-only) should match `is_not P1`. The mixed-cart O_AB contains P1 ' .
			'and must be excluded — pre-fix it leaked through via the P2 row in the subquery.'
		);
	}

	/**
	 * Symmetric regression: same bug shape but with P2 as the excluded
	 * product. Pins the failure in both directions so a future "fix" that
	 * happens to coincidentally work for one product but not the other
	 * gets caught.
	 */
	public function test_is_not_product_id_excludes_in_both_directions() {
		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'product_id',
						'operator' => 'is_not',
						'value'    => $this->p2,
					),
				),
			)
		);

		$this->assertSame(
			1,
			$result['summary']['matched_count'],
			'Only O_A (P1-only) should match `is_not P2`. O_AB contains P2 and must be excluded.'
		);
	}

	/**
	 * Regression: `between` on a date field with `YYYY-MM-DD` values must
	 * cover the full calendar day at both ends, not just midnight.
	 *
	 * Pre-fix `between '2026-02-01' AND '2026-02-28'` translated to
	 * `BETWEEN '2026-02-01' AND '2026-02-28'`, which MySQL coerces to
	 * `BETWEEN '2026-02-01 00:00:00' AND '2026-02-28 00:00:00'` — so
	 * any row dated after midnight on the upper-bound day is silently
	 * excluded. A merchant filtering "Feb orders" loses every order
	 * placed during the afternoon of February 28th.
	 *
	 * The fixture's pre-existing orders are at midday so they're inside
	 * the buggy window; this test seeds an additional order at 23:30 on
	 * the period-end date, which the pre-fix filter excludes and the
	 * post-fix filter includes.
	 *
	 * Bug source: class-analytics-controller.php qa_build_filter_clause()
	 * Codex severity: P2.
	 */
	public function test_between_filter_on_date_field_includes_end_of_day() {
		$this->seed_end_of_day_order_in_period();

		$result = $this->run_ability(
			array(
				'filters' => array(
					array(
						'field'    => 'date_created',
						'operator' => 'between',
						'value'    => array( $this->period_start, $this->period_end ),
					),
				),
			)
		);

		$this->assertSame(
			4,
			$result['summary']['matched_count'],
			'All four orders fall inside the period — the 23:30 order on the upper-bound ' .
			'day must not be excluded by the BETWEEN clause. Pre-fix the upper bound was ' .
			'midnight on the end date, dropping it from the result.'
		);
	}

	/**
	 * Regression: a filter that passes validation but can't be translated
	 * to SQL must surface as a WP_Error all the way out to the ability
	 * caller, rather than being silently dropped from the WHERE clause.
	 *
	 * Pre-fix `qa_build_where` skipped filters that `qa_build_filter_clause`
	 * couldn't translate (`continue` on a null return), so a single-filter
	 * request fell through to an unfiltered query and returned the entire
	 * dataset. The merchant got a "looks-like-it-worked" response with the
	 * wrong row set. Post-fix `qa_build_where` returns
	 * `untranslatable_filter` WP_Error and each entity caller propagates
	 * it — the ability response is a WP_Error rather than a silently
	 * unfiltered result.
	 *
	 * The trigger here is `between` on `product_id`. `product_id` is a
	 * `numeric_subquery` field, and the qa_operators_for_type allowlist
	 * permits `between` for numeric_subquery, but the clause builder's
	 * EXISTS template doesn't support the BETWEEN shape — so the filter
	 * passes validation, fails translation, and used to drop silently.
	 *
	 * Bug source: class-analytics-controller.php qa_build_where()
	 * Codex severity: P2.
	 */
	public function test_untranslatable_filter_surfaces_as_wp_error() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = \WooCommerce\Claude\API\AnalyticsController::fetch_query_analytics(
			'orders',
			array(
				array(
					'field'    => 'product_id',
					'operator' => 'between',
					'value'    => array( $this->p1, $this->p2 ),
				),
			),
			'all',
			'last_30_days',
			$this->period_start,
			$this->period_end,
			'aggregate',
			25,
			'',
			'DESC'
		);

		$this->assertInstanceOf(
			\WP_Error::class,
			$result,
			'between on a subquery field is untranslatable; the ability must return WP_Error rather than ' .
			'silently dropping the filter and returning every order. Pre-fix this returned an unfiltered result.'
		);
		$this->assertSame(
			'untranslatable_filter',
			$result->get_error_code(),
			'Untranslatable filter must surface with a stable error code so callers can branch on it.'
		);
	}
}
