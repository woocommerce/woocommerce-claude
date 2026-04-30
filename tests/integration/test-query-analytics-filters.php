<?php
/**
 * Pure clause-builder tests for the query_analytics filter engine.
 *
 * Pins the SQL output of `AnalyticsController::qa_build_where()` for one
 * filter at a time, regardless of which database fixtures are in place.
 * Reaches the private static method via Reflection — the function is
 * intentionally a pure transform (field def + operator + value → SQL
 * fragment + placeholders), so testing it directly catches translation
 * bugs without spinning up a fixture store.
 *
 * Companion to `test-query-analytics-engine-regressions.php`, which
 * exercises the same operators end-to-end against fixture orders. The
 * two layers complement each other:
 *
 *   - This file: cheap, fast, scales by adding rows to the data
 *     provider. Catches translation bugs (wrong SQL primitive, missing
 *     placeholders, forgotten EXISTS negation).
 *   - Engine layer: expensive, slow, but proves the SQL actually
 *     selects the right rows against a multi-line-item fixture. Some
 *     bugs (JOIN duplication, storage-mode branching) only surface
 *     against real data.
 *
 * Adding a new row: each entry in `clause_provider()` is keyed by a
 * descriptive name and yields `[ entity, field, filter_spec,
 * expected_assertions ]`. The assertion array uses string keys so the
 * test method's branches stay self-documenting. New operators or new
 * fields → new rows; no new test methods needed.
 *
 * Origin: 2026-04-28 Codex full-plugin review surfaced four query_analytics
 * bugs in a row (`is_not` subquery negation, `between` end-of-day, silent
 * filter omission, `is_in` placeholder leak). The clustering motivated a
 * data-driven harness rather than ad-hoc method-per-bug tests.
 *
 * @package HeyWoo\Tests
 */

/**
 * Data-driven tests for `AnalyticsController::qa_build_where()` —
 * one row per (entity, field, operator, value) tuple.
 */
class Test_Query_Analytics_Filters extends WP_UnitTestCase {

	/**
	 * Date strings used by the products / customers registries that take
	 * a period — only the structural shape of the registry matters here,
	 * not the actual data, so any in-range pair works.
	 */
	const FAKE_DATE_START = '2026-01-01';
	const FAKE_DATE_END   = '2026-01-31';

	/**
	 * Reflection cache so each test row doesn't pay the lookup cost.
	 *
	 * @var array<string, \ReflectionMethod>
	 */
	private $reflected_methods = array();

	/**
	 * Drive `qa_build_where` from a single-filter spec and run the row's
	 * assertions against the returned `[sql, values, joins]`, or against
	 * the returned WP_Error when a row asserts an error code.
	 *
	 * @dataProvider clause_provider
	 *
	 * @param string $entity      'orders' | 'products' | 'customers'.
	 * @param string $field       Field name in the entity registry.
	 * @param array  $filter_spec ['operator' => string, 'value' => mixed].
	 * @param array  $assertions  Either success-path assertions (
	 *                            'sql_starts_with' / 'sql_contains' /
	 *                            'sql_not_contains' / 'values_count' /
	 *                            'values') or an error-path assertion
	 *                            ('error_code' => string). The two
	 *                            modes are mutually exclusive.
	 */
	public function test_clause_builder_row( $entity, $field, $filter_spec, $assertions ) {
		$registry = $this->get_field_registry( $entity );
		$this->assertArrayHasKey(
			$field,
			$registry,
			"Field '{$field}' not in {$entity} registry — fixture row references a removed field?"
		);

		$filters     = array(
			array_merge(
				array( 'field' => $field ),
				$filter_spec
			),
		);
		$where_parts = $this->invoke_qa_build_where( $filters, 'all', $registry );

		// Error-path rows assert the exact WP_Error code surfaced when a
		// filter passes validation but can't be translated to SQL.
		if ( isset( $assertions['error_code'] ) ) {
			$this->assertInstanceOf(
				\WP_Error::class,
				$where_parts,
				'Expected qa_build_where to return WP_Error for an untranslatable filter; got an array instead.'
			);
			$this->assertSame(
				$assertions['error_code'],
				$where_parts->get_error_code(),
				'WP_Error code mismatch.'
			);
			return;
		}

		// Success-path rows: every other test row asserts an array shape.
		// Fail loudly if the function unexpectedly returned WP_Error so a
		// row that should succeed can't quietly skip its assertions.
		$this->assertNotInstanceOf(
			\WP_Error::class,
			$where_parts,
			'qa_build_where unexpectedly returned WP_Error: ' .
				( $where_parts instanceof \WP_Error ? $where_parts->get_error_message() : '' )
		);

		$sql = (string) ( $where_parts['sql'] ?? '' );

		if ( isset( $assertions['sql_starts_with'] ) ) {
			$this->assertStringStartsWith(
				$assertions['sql_starts_with'],
				$sql,
				"SQL should start with '{$assertions['sql_starts_with']}'. Got: {$sql}"
			);
		}

		if ( isset( $assertions['sql_contains'] ) ) {
			foreach ( (array) $assertions['sql_contains'] as $needle ) {
				$this->assertStringContainsString(
					$needle,
					$sql,
					"SQL missing required substring '{$needle}'. Got: {$sql}"
				);
			}
		}

		if ( isset( $assertions['sql_not_contains'] ) ) {
			foreach ( (array) $assertions['sql_not_contains'] as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$sql,
					"SQL contains forbidden substring '{$needle}'. Got: {$sql}"
				);
			}
		}

		if ( isset( $assertions['values_count'] ) ) {
			$this->assertCount(
				$assertions['values_count'],
				$where_parts['values'],
				'Placeholder count mismatch.'
			);
		}

		if ( isset( $assertions['values'] ) ) {
			$this->assertSame(
				$assertions['values'],
				array_values( $where_parts['values'] ),
				'Bound values mismatch.'
			);
		}
	}

	/**
	 * Test rows. Each entry is keyed by a descriptive name — PHPUnit prints
	 * the key as the data-set identifier, so a failing row is named in the
	 * test output ("clause_builder_row with data set 'is_not_subquery_…').
	 *
	 * @return array<string, array{0: string, 1: string, 2: array, 3: array}>
	 */
	public function clause_provider() {
		return array(

			// ─── Baseline rows for already-correct operators (sanity, not regressions) ───

			'is_on_column_field_emits_equals_predicate'    => array(
				'orders',
				'order_total',
				array(
					'operator' => 'is',
					'value'    => '100.00',
				),
				array(
					'sql_contains' => array( 'os.net_total', '%s' ),
					'values'       => array( '100.00' ),
				),
			),

			'is_on_subquery_field_emits_EXISTS_with_equality' => array(
				'orders',
				'product_id',
				array(
					'operator' => 'is',
					'value'    => 42,
				),
				array(
					'sql_starts_with'  => '(EXISTS',
					'sql_contains'     => array( 'product_id =', 'order_id' ),
					'sql_not_contains' => array( 'NOT EXISTS' ),
					'values_count'     => 1,
				),
			),

			'is_in_on_column_field_emits_IN_clause'        => array(
				'orders',
				'order_total',
				array(
					'operator' => 'is_in',
					'value'    => array( '40.00', '80.00', '120.00' ),
				),
				array(
					'sql_contains'     => array( 'IN (%s,%s,%s)' ),
					'sql_not_contains' => array( 'NOT IN' ),
					'values_count'     => 3,
				),
			),

			'is_not_in_on_subquery_field_emits_NOT_EXISTS' => array(
				'orders',
				'product_id',
				array(
					'operator' => 'is_not_in',
					'value'    => array( 42, 99 ),
				),
				array(
					'sql_starts_with' => '(NOT EXISTS',
					'sql_contains'    => array( 'IN (%s,%s)' ),
					'values_count'    => 2,
				),
			),

			// ─── Regression: is_not on subquery field MUST negate at the EXISTS level ───
			//
			// Pre-fix this row produced `EXISTS (... product_id != %s)`, which matches
			// any order with at least one line item whose product_id is anything-but-X
			// — so an order containing both X and Y wrongly satisfies "is_not X". The
			// correct shape is `NOT EXISTS (... product_id = %s)` (no row with X).
			//
			// See class-analytics-controller.php qa_build_filter_clause() — the bug
			// was leaving $sql=null for is_not on subquery fields, which then fell
			// through to the generic post-processing block that substitutes the
			// `!=` primitive into the template instead of negating the wrapper.

			'is_not_on_subquery_field_emits_NOT_EXISTS_with_equality' => array(
				'orders',
				'product_id',
				array(
					'operator' => 'is_not',
					'value'    => 42,
				),
				array(
					'sql_starts_with'  => '(NOT EXISTS',
					'sql_contains'     => array( 'product_id =', '%s' ),
					'sql_not_contains' => array(
						// Must not produce the inner-predicate negation.
						'product_id !=',
						'product_id <>',
						// Must not be a bare EXISTS without the NOT wrapper.
						'(EXISTS',
					),
					'values_count'     => 1,
					'values'           => array( 42 ),
				),
			),

			// ─── Regression: between on date fields must expand YYYY-MM-DD to full-day bounds ───
			//
			// Pre-fix `between '2026-01-01' AND '2026-01-31'` on a date column
			// passed both values straight through. MySQL coerces each to
			// midnight on that day, dropping every row dated after the upper
			// bound's midnight — i.e. the merchant loses everything from
			// the afternoon of January 31st. Post-fix the lower value is
			// expanded to ` 00:00:00` and the upper to ` 23:59:59`, covering
			// the full calendar day at both ends.
			//
			// The expansion must be type-aware: numeric `between` (e.g.
			// `order_total BETWEEN 100 AND 500`) must NOT have time strings
			// appended. The next row pins that.

			'between_on_date_field_expands_to_full_day_bounds' => array(
				'orders',
				'date_created',
				array(
					'operator' => 'between',
					'value'    => array( '2026-01-01', '2026-01-31' ),
				),
				array(
					'sql_contains' => array( 'BETWEEN %s AND %s' ),
					'values'       => array( '2026-01-01 00:00:00', '2026-01-31 23:59:59' ),
				),
			),

			'between_on_numeric_field_passes_values_through_unchanged' => array(
				'orders',
				'order_total',
				array(
					'operator' => 'between',
					'value'    => array( '100', '500' ),
				),
				array(
					'sql_contains'     => array( 'BETWEEN %s AND %s' ),
					'sql_not_contains' => array( '00:00:00', '23:59:59' ),
					'values'           => array( '100', '500' ),
				),
			),

			'between_on_date_field_with_explicit_time_passes_through_unchanged' => array(
				'orders',
				'date_created',
				array(
					'operator' => 'between',
					'value'    => array( '2026-01-01 06:00:00', '2026-01-31 18:00:00' ),
				),
				array(
					'sql_contains' => array( 'BETWEEN %s AND %s' ),
					'values'       => array( '2026-01-01 06:00:00', '2026-01-31 18:00:00' ),
				),
			),

			// ─── Regression: untranslatable filters must surface as WP_Error ───
			//
			// Pre-fix `qa_build_where` silently dropped any filter whose
			// `qa_build_filter_clause` returned null — including `between` on
			// a subquery field (the template doesn't substitute the BETWEEN
			// shape). When such a filter was the only filter in the request,
			// the WHERE clause came out empty and the query returned the
			// entire dataset unfiltered. Post-fix the function returns a
			// `untranslatable_filter` WP_Error so callers fail loudly rather
			// than acting on silently wrong data.
			//
			// Bug source: class-analytics-controller.php qa_build_where()
			// Codex severity: P2.

			'between_on_subquery_field_returns_untranslatable_filter_error' => array(
				'orders',
				'product_id',
				array(
					'operator' => 'between',
					'value'    => array( 1, 100 ),
				),
				array(
					'error_code' => 'untranslatable_filter',
				),
			),

			'is_in_with_empty_array_returns_untranslatable_filter_error' => array(
				'orders',
				'order_total',
				array(
					'operator' => 'is_in',
					'value'    => array(),
				),
				array(
					'error_code' => 'untranslatable_filter',
				),
			),
		);
	}

	/**
	 * Storage-mode regression: when HPOS is enabled, the orders registry's
	 * `payment_method` and `currency` fields target the `wc_orders` columns.
	 * Pins the existing (correct) HPOS shape so a future shuffle of the
	 * branching can't silently lose the HPOS path.
	 */
	public function test_payment_method_and_currency_use_wc_orders_when_hpos_enabled() {
		$this->with_hpos_option_set(
			'yes',
			function () {
				$registry = $this->get_field_registry( 'orders' );

				$this->assertSame( 'o.payment_method', $registry['payment_method']['column'] );
				$this->assertStringContainsString( 'wc_orders', $registry['payment_method']['join'] );

				$this->assertSame( 'o.currency', $registry['currency']['column'] );
				$this->assertStringContainsString( 'wc_orders', $registry['currency']['join'] );
			}
		);
	}

	/**
	 * Storage-mode regression: when HPOS is disabled, the orders registry's
	 * `payment_method` and `currency` fields must NOT target `wc_orders` —
	 * that table is empty on classic-storage stores, so the JOIN matches
	 * zero rows and the filter silently returns nothing. Post-fix the
	 * fields branch to a postmeta JOIN under the well-known classic keys
	 * (`_payment_method`, `_order_currency`).
	 *
	 * Pre-fix `payment_method`'s join string was hard-coded to
	 * `LEFT JOIN {wc_orders} AS o ON os.order_id = o.id` regardless of
	 * storage mode, producing an o.payment_method column that's NULL for
	 * every row on a classic-storage store. The post-fix branching mirrors
	 * `qa_orders_address_fields()` for the address fields, which already
	 * handles HPOS / classic per-mode.
	 *
	 * Bug source: class-analytics-controller.php qa_orders_field_registry()
	 * Codex severity: P2.
	 */
	public function test_payment_method_and_currency_use_postmeta_when_hpos_disabled() {
		$this->with_hpos_option_set(
			'no',
			function () {
				$registry = $this->get_field_registry( 'orders' );

				// Column points at meta_value via a per-field alias, not at o.<col>.
				$this->assertStringContainsString( 'meta_value', $registry['payment_method']['column'] );
				$this->assertStringNotContainsString( 'o.payment_method', $registry['payment_method']['column'] );

				$this->assertStringContainsString( 'meta_value', $registry['currency']['column'] );
				$this->assertStringNotContainsString( 'o.currency', $registry['currency']['column'] );

				// Join is against postmeta (NOT wc_orders) and pins the meta_key.
				$this->assertStringNotContainsString( 'wc_orders', $registry['payment_method']['join'] );
				$this->assertStringContainsString( "'_payment_method'", $registry['payment_method']['join'] );

				$this->assertStringNotContainsString( 'wc_orders', $registry['currency']['join'] );
				$this->assertStringContainsString( "'_order_currency'", $registry['currency']['join'] );

				// Per-field alias (not the shared 'wc_orders' join_key) so the
				// two fields don't collide when filtered together.
				$this->assertNotSame( $registry['payment_method']['join_key'], $registry['currency']['join_key'] );
			}
		);
	}

	/**
	 * Run a closure with the HPOS option flipped to a specific value, then
	 * restore the original value regardless of whether the closure throws.
	 * `OrderUtil::custom_orders_table_usage_is_enabled()` reads the option
	 * fresh each call, so toggling it inside a test changes the registry's
	 * branching path without restarting the WC bootstrap.
	 *
	 * @param string   $value 'yes' or 'no'.
	 * @param callable $body  Test body to run while the option is set.
	 */
	private function with_hpos_option_set( $value, $body ) {
		$option_name = 'woocommerce_custom_orders_table_enabled';
		$prior       = get_option( $option_name, 'no' );
		update_option( $option_name, $value );
		try {
			$body();
		} finally {
			update_option( $option_name, $prior );
		}
	}

	/**
	 * Pull the field registry for an entity via Reflection, so a single test
	 * row can target any of the three entity branches without a separate
	 * helper per entity.
	 *
	 * @param string $entity 'orders' | 'products' | 'customers'.
	 * @return array
	 */
	private function get_field_registry( $entity ) {
		$method_name = "qa_{$entity}_field_registry";

		$method = $this->reflect_method( $method_name );

		// Products registry takes period dates; orders + customers take none.
		if ( 'products' === $entity ) {
			return $method->invoke( null, self::FAKE_DATE_START, self::FAKE_DATE_END );
		}
		return $method->invoke( null );
	}

	/**
	 * Reflection-invoke the private static `qa_build_where()`.
	 *
	 * @param array  $filters    Filter spec array.
	 * @param string $match_mode 'all' | 'any'.
	 * @param array  $registry   Field registry for the active entity.
	 * @return array{sql: string, values: array, joins: array}
	 */
	private function invoke_qa_build_where( $filters, $match_mode, $registry ) {
		$method = $this->reflect_method( 'qa_build_where' );
		return $method->invoke( null, $filters, $match_mode, $registry );
	}

	/**
	 * Get a Reflection handle on a private static method on AnalyticsController.
	 *
	 * @param string $name Method name.
	 * @return \ReflectionMethod
	 */
	private function reflect_method( $name ) {
		if ( ! isset( $this->reflected_methods[ $name ] ) ) {
			$ref = new \ReflectionMethod( \HeyWoo\API\AnalyticsController::class, $name );
			$ref->setAccessible( true );
			$this->reflected_methods[ $name ] = $ref;
		}
		return $this->reflected_methods[ $name ];
	}
}
