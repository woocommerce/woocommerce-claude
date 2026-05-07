<?php
/**
 * Smoke test — every wc-analytics ability is registered with the WP Abilities
 * API AND backed by a non-empty PHPUnit test file.
 *
 * Two complementary guards:
 *
 *   1. Registration — asserts each expected ability ID is callable via
 *      wp_has_ability(). Catches missed require_once in class-plugin.php or
 *      a missing register() call in AbilitiesBootstrap::register_abilities().
 *
 *   2. Test coverage — for every registered wc-analytics/* ability, asserts
 *      a matching tests/integration/test-<slug>.php file exists AND contains
 *      at least one `public function test_*` method. Catches the regression
 *      where a new ability ships without integration tests (or ships with an
 *      empty-stub file that wouldn't actually exercise anything).
 *
 * The expected-ability list is a single constant used by both guards, so
 * wiring a new ability means touching exactly one place in this file.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Smoke-test class for ability registration + per-ability test coverage.
 */
class Test_Ability_Registration extends WP_UnitTestCase {

	/**
	 * Every analytics ability we expect the plugin to register on
	 * wp_abilities_api_init. Mirrors AbilitiesBootstrap::register_abilities().
	 *
	 * @var array<int, string>
	 */
	const EXPECTED_ABILITY_IDS = array(
		'wc-analytics/get-revenue-summary',
		'wc-analytics/get-orders-summary',
		'wc-analytics/get-product-performance',
		'wc-analytics/get-customer-overview',
		'wc-analytics/get-attribution',
		'wc-analytics/get-customer-value',
		'wc-analytics/get-revenue-breakdown',
		'wc-analytics/get-coupon-performance',
		'wc-analytics/get-refund-analysis',
		'wc-analytics/get-tax-summary',
		'wc-analytics/query-analytics',
	);

	/**
	 * Data-provider wrapper so PHPUnit produces one failure per missing
	 * ability ID (rather than one lumped assertion).
	 *
	 * @return array<int, array<int, string>>
	 */
	public function ability_id_provider() {
		return array_map(
			static function ( $id ) {
				return array( $id );
			},
			self::EXPECTED_ABILITY_IDS
		);
	}

	/**
	 * Every expected ability ID resolves via the Abilities API.
	 *
	 * @dataProvider ability_id_provider
	 *
	 * @param string $ability_id Fully-qualified ability ID (`namespace/name`).
	 */
	public function test_ability_is_registered( $ability_id ) {
		$this->assertTrue(
			function_exists( 'wp_has_ability' ),
			'wp_has_ability() is missing — Abilities API not loaded. Requires WordPress 6.9+.'
		);

		$this->assertTrue(
			wp_has_ability( $ability_id ),
			"Expected ability {$ability_id} to be registered by AbilitiesBootstrap::register_abilities()."
		);
	}

	/**
	 * Every expected ability has a matching integration test file
	 * AND that file contains at least one `public function test_*` method.
	 *
	 * Fails loudly (with actionable message) if a new ability ships without
	 * tests or with an empty-stub file. Pair with SKILL.md Step 5 — every
	 * build-skill run must add a non-empty test file before bin/check passes.
	 *
	 * @dataProvider ability_id_provider
	 *
	 * @param string $ability_id Fully-qualified ability ID (`namespace/name`).
	 */
	public function test_ability_has_test_file( $ability_id ) {
		$slug      = substr( $ability_id, strlen( 'wc-analytics/' ) );
		$test_file = dirname( __DIR__ ) . '/integration/test-' . $slug . '.php';

		$this->assertFileExists(
			$test_file,
			"Ability {$ability_id} is registered but has no integration test file. "
				. "Expected: tests/integration/test-{$slug}.php. "
				. 'See skills/build-skill/SKILL.md Step 5 — every new ability must ship with a PHPUnit test file.'
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test file by absolute path; wp_remote_get() is for HTTP.
		$source     = file_get_contents( $test_file );
		$test_count = preg_match_all( '/^\s*public\s+function\s+test_\w+\s*\(/m', $source );

		$this->assertGreaterThan(
			0,
			$test_count,
			"Test file {$test_file} exists but contains no `public function test_*` methods. "
				. 'An empty stub file defeats the coverage guard — add at least one test method.'
		);
	}
}
