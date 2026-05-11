<?php
/**
 * Integration tests — wc-analytics/rows (verb-shape pivot, PR 1).
 *
 * Pins the verb-tool contract for the row-level surface — schema
 * validation across the entity / mode enums, a smoke check that the
 * thin wrapper still surfaces the underlying `fetch_query_analytics()`
 * filter-validation errors, and the enriched telemetry payload.
 *
 * Most of the row-mode behaviour (filter operators, JOIN duplication,
 * pseudonymisation, share_of_universe, sample_size_caveat) is already
 * pinned end-to-end in `test-query-analytics*.php` — those tests run
 * against the same `fetch_query_analytics()` method this verb tool
 * delegates to. This file deliberately stays scoped to the wrapper:
 * does the verb tool route correctly, and does its telemetry envelope
 * reflect the merchant's mode choice?
 *
 * What's pinned:
 *
 *   - `entity` outside {orders, products, customers} is rejected at the
 *     schema layer (`ability_invalid_input`).
 *   - `mode` outside {aggregate, rows} is rejected at the schema layer.
 *   - With default inputs the verb tool dispatches to
 *     `fetch_query_analytics()` (legacy skill `query_analytics` fires)
 *     and re-emits with `tool='wc-analytics/rows'` /
 *     `subject=<entity>` / `shape=<mode>`.
 *   - `mode='rows'` produces an enriched event with `shape='rows'`;
 *     `mode='aggregate'` produces `shape='aggregate'`.
 *   - WP_Error from inside `fetch_query_analytics()` (e.g. unknown
 *     filter field) propagates back through the wrapper — the wrapper
 *     does not swallow it and the dispatcher sees no enriched event in
 *     that case, since the verb tool short-circuits before re-emit.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Telemetry\SkillTelemetry;
use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

/**
 * Integration tests for the wc-analytics/rows verb-shaped ability.
 */
class Test_Analytics_Rows extends WP_UnitTestCase {

	/**
	 * Direct listener — sees legacy + enriched.
	 *
	 * @var array<int, array{skill: string, data: array}>
	 */
	private $direct_events = array();

	/**
	 * Stored closure for tear_down removal.
	 *
	 * @var callable
	 */
	private $direct_listener;

	/**
	 * Spy handler — sees only enriched.
	 *
	 * @var TelemetryHandlerInterface
	 */
	private $spy_handler;

	/**
	 * Wire up listeners and elevate to admin.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->direct_events   = array();
		$this->direct_listener = function ( $skill_name, $data ) {
			$this->direct_events[] = array(
				'skill' => $skill_name,
				'data'  => $data,
			);
		};
		add_action( 'woocommerce_claude_skill_executed', $this->direct_listener, 10, 2 );

		$this->spy_handler = new class() implements TelemetryHandlerInterface {
			/**
			 * Captured events.
			 *
			 * @var array<int, array{skill: string, data: array}>
			 */
			public $events = array();

			/**
			 * Capture the event.
			 *
			 * @param string $skill_name Skill identifier.
			 * @param array  $data       Telemetry payload.
			 */
			public function record( $skill_name, $data ) {
				$this->events[] = array(
					'skill' => $skill_name,
					'data'  => $data,
				);
			}
		};
		SkillTelemetry::add_handler( $this->spy_handler );
	}

	/**
	 * Remove the direct listener.
	 */
	public function tear_down() {
		remove_action( 'woocommerce_claude_skill_executed', $this->direct_listener, 10 );
		parent::tear_down();
	}

	/**
	 * Invoke the verb tool with the given input.
	 *
	 * @param array $input Ability input.
	 * @return mixed Ability result, or WP_Error.
	 */
	private function invoke_ability( array $input ) {
		$ability = wp_get_ability( 'wc-analytics/rows' );
		$this->assertNotNull( $ability, 'wc-analytics/rows ability not registered.' );
		return $ability->execute( $input );
	}

	/**
	 * Entity outside the schema enum returns ability_invalid_input.
	 */
	public function test_invalid_entity_rejected_by_schema() {
		$result = $this->invoke_ability(
			array(
				'entity'     => 'not_an_entity',
				'date_start' => '2000-01-01',
				'date_end'   => '2000-01-31',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Mode outside {aggregate, rows} returns ability_invalid_input.
	 */
	public function test_invalid_mode_rejected_by_schema() {
		$result = $this->invoke_ability(
			array(
				'entity'     => 'orders',
				'mode'       => 'summary',
				'date_start' => '2000-01-01',
				'date_end'   => '2000-01-31',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Each (entity, mode) pair routes to fetch_query_analytics, the
	 * dispatcher sees one enriched event with shape matching the mode,
	 * and the legacy `query_analytics` emission fires inside the fetch.
	 *
	 * @dataProvider entity_mode_provider
	 *
	 * @param string $entity Entity slug.
	 * @param string $mode   Aggregate or rows.
	 */
	public function test_dispatch_routes_and_emits_enriched_telemetry( $entity, $mode ) {
		$result = $this->invoke_ability(
			array(
				'entity'     => $entity,
				'mode'       => $mode,
				'date_start' => '2000-01-01',
				'date_end'   => '2000-01-31',
			)
		);

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			"Entity '{$entity}' / mode '{$mode}' returned WP_Error on empty period: "
				. ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);
		$this->assertSame( $entity, $result['entity'] );
		$this->assertSame( $mode, $result['mode'] );

		$direct_skills = array_column( $this->direct_events, 'skill' );
		$this->assertContains(
			'query_analytics',
			$direct_skills,
			'Direct listener must see the legacy `query_analytics` emission fired from inside fetch_query_analytics().'
		);
		$this->assertContains( 'wc-analytics/rows', $direct_skills );

		$this->assertCount(
			1,
			$this->spy_handler->events,
			'SkillTelemetry handler must see exactly one event per execute call (suppress_dispatch gates the legacy emission).'
		);
		$event = $this->spy_handler->events[0];
		$this->assertSame( 'wc-analytics/rows', $event['skill'] );
		$this->assertSame( 'wc-analytics/rows', $event['data']['tool'] );
		$this->assertSame( $entity, $event['data']['subject'], 'rows tool maps subject ← entity.' );
		$this->assertSame( $mode, $event['data']['shape'], 'rows tool maps shape ← mode.' );
	}

	/**
	 * Entity / mode coverage. Three entities × two modes = six rows;
	 * keeping all six exercises every dispatch arm and both shape values.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function entity_mode_provider() {
		return array(
			'orders_aggregate'    => array( 'orders', 'aggregate' ),
			'orders_rows'         => array( 'orders', 'rows' ),
			'products_aggregate'  => array( 'products', 'aggregate' ),
			'products_rows'       => array( 'products', 'rows' ),
			'customers_aggregate' => array( 'customers', 'aggregate' ),
			'customers_rows'      => array( 'customers', 'rows' ),
		);
	}

	/**
	 * Filter-validation errors from `fetch_query_analytics()` propagate
	 * through the verb tool. The wrapper does not swallow the WP_Error
	 * and does not re-emit a telemetry event, since the enriched event
	 * is fired only on the success path (after the fetch returns a
	 * non-error result).
	 */
	public function test_filter_error_propagates_and_skips_enriched_emission() {
		$result = $this->invoke_ability(
			array(
				'entity'     => 'orders',
				'date_start' => '2000-01-01',
				'date_end'   => '2000-01-31',
				'filters'    => array(
					array(
						'field'    => 'this_field_does_not_exist',
						'operator' => 'is',
						'value'    => 'x',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'unknown_field', $result->get_error_code() );

		// Direct listener saw the legacy emission only if fetch_query_analytics
		// got far enough to fire it. With unknown_field surfacing during
		// filter parsing the fetch short-circuits and never fires the
		// post-execute action, so neither the legacy nor enriched event
		// reaches any listener.
		$this->assertSame( array(), array_column( $this->direct_events, 'skill' ) );
		$this->assertCount( 0, $this->spy_handler->events );
	}
}
