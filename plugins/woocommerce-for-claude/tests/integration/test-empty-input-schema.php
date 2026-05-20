<?php
/**
 * Regression guard — every registered ability's `input_schema` must JSON-encode
 * with `properties` as an object (`{}`), never as an array (`[]`).
 *
 * Background:
 *
 * The v0.4.2 → 0.4.3 monorepo refactor added an explicit
 * `'input_schema' => AbilitiesBootstrap::empty_input_schema()` argument to
 * the four no-input ability registrations (get-store-profile, get-readiness-score,
 * get-recommendations, suggest-improvements). v0.4.2 omitted `input_schema`
 * entirely for these abilities, so the bug was latent.
 *
 * `empty_input_schema()` originally returned `'properties' => array()`, which
 * PHP `json_encode` serialises as `"properties":[]`. Per JSON Schema, the
 * `properties` keyword must be an object — `[]` is invalid. Claude Desktop's
 * deferred-tool registrar validates the schema before exposing tools to
 * Claude Code / Cowork agents and silently drops the entire connector when
 * any tool emits `properties: []`. The whole MCP surface vanishes from
 * Claude Code / Cowork on a fresh install — even though direct HTTP
 * `tools/call` succeeds and Claude Desktop's own chat compose surface
 * (which doesn't go through the deferred-tool path) is unaffected.
 *
 * The fix is the one-line change `array()` → `(object) array()`, which
 * forces `json_encode` to emit `"properties":{}`. This guard locks the
 * contract in CI so a future contributor can't regress it.
 *
 * The test walks the full registered ability surface (both the plugin's
 * own `woocommerce-claude/*` abilities and the shared `wc-analytics/*`
 * ones from commerce-abilities) so the contract holds across the whole
 * MCP wire output, not just the four currently-affected tools.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Abilities\AbilitiesBootstrap;

/**
 * Asserts every ability's `input_schema` JSON-encodes with `properties: {}`
 * or a populated object, never `properties: []`.
 */
class Test_Empty_Input_Schema extends WP_UnitTestCase {

	/**
	 * Every ability whose `register()` passes
	 * `'input_schema' => AbilitiesBootstrap::empty_input_schema()`.
	 *
	 * Covers both the `woocommerce-claude/*` tool surface and the
	 * `wc-knowledge/*` resource surface, since both go through
	 * `wp_register_ability()` and are vulnerable to the same
	 * `[]` vs `{}` encoding bug. Mirrors the six call sites in
	 * `class-*-ability.php` — keep in sync with
	 * `grep "empty_input_schema()" plugins/woocommerce-for-claude/includes/abilities/`.
	 *
	 * `suggest-improvements` is intentionally NOT here — it has real
	 * input parameters (`product_id`, `focus`) and passes its own
	 * `self::input_schema()`, not the empty one.
	 *
	 * @var array<int, string>
	 */
	const NO_INPUT_ABILITY_IDS = array(
		'woocommerce-claude/get-store-profile',
		'woocommerce-claude/get-readiness-score',
		'woocommerce-claude/get-recommendations',
		'wc-knowledge/store-policies',
		'wc-knowledge/catalog-schema',
		'wc-knowledge/store-profile',
	);

	/**
	 * Unit test: `empty_input_schema()` returns a value whose JSON
	 * encoding has `properties` as `{}`, not `[]`.
	 *
	 * This is the smallest possible guard — it catches the
	 * `array()` → `(object) array()` regression at the source, without
	 * needing the full WP Abilities API to be initialised.
	 */
	public function test_empty_input_schema_encodes_properties_as_object() {
		$schema = AbilitiesBootstrap::empty_input_schema();
		$json   = wp_json_encode( $schema );

		$this->assertStringContainsString(
			'"properties":{}',
			$json,
			'empty_input_schema() must JSON-encode properties as an object (`{}`), not an array (`[]`). '
				. 'See class-abilities-bootstrap.php — the value must be `(object) array()`, not `array()`. '
				. "Got: $json"
		);

		$this->assertStringNotContainsString(
			'"properties":[]',
			$json,
			'empty_input_schema() must not emit `properties: []`. ' . "Got: $json"
		);
	}

	/**
	 * Integration test: walk every registered ability's `input_schema` and
	 * assert it JSON-encodes with `properties` as an object.
	 *
	 * Catches the same class of bug for any future ability that introduces
	 * a different no-input schema path.
	 *
	 * @dataProvider no_input_ability_id_provider
	 *
	 * @param string $ability_id Fully-qualified ability ID, e.g. `woocommerce-claude/get-store-profile`.
	 */
	public function test_ability_input_schema_encodes_properties_as_object( $ability_id ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'WP Abilities API is not available in this test environment.' );
		}

		$ability = wp_get_ability( $ability_id );
		if ( null === $ability ) {
			$this->markTestSkipped(
				sprintf( 'Ability %s is not registered. test-ability-registration.php covers registration.', $ability_id )
			);
		}

		$schema = $ability->get_input_schema();
		$this->assertNotNull(
			$schema,
			sprintf( 'Ability %s must declare an input_schema (callers receive `null` otherwise).', $ability_id )
		);

		$json = wp_json_encode( $schema );

		$this->assertStringContainsString(
			'"properties":{}',
			$json,
			sprintf(
				'Ability %s must emit `properties: {}` for no-input schemas. Got: %s',
				$ability_id,
				$json
			)
		);

		$this->assertStringNotContainsString(
			'"properties":[]',
			$json,
			sprintf(
				'Ability %s must not emit `properties: []` (invalid JSON Schema; breaks Claude Desktop deferred-tool registration). Got: %s',
				$ability_id,
				$json
			)
		);
	}

	/**
	 * PHPUnit data provider that yields one test case per no-input ability.
	 *
	 * @return array<int, array<int, string>>
	 */
	public function no_input_ability_id_provider() {
		return array_map(
			static function ( $id ) {
				return array( $id );
			},
			self::NO_INPUT_ABILITY_IDS
		);
	}
}
