<?php
/**
 * Integration tests — wc-analytics/totals.
 *
 * Pins the verb-tool contract — schema validation on `subject`, dispatch
 * routing to each underlying `AnalyticsController::fetch_X()` method, and
 * the enriched telemetry payload emitted at the verb-tool boundary.
 *
 * What's pinned:
 *
 *   - `subject` outside the six-value enum is rejected at the JSON schema
 *     layer (`ability_invalid_input`). The dispatch's own
 *     `invalid_totals_subject` branch is unreachable from the ability
 *     surface because the schema enum filters every other value out
 *     first; testing it would require bypassing schema validation, so
 *     the schema-layer rejection is the meaningful pin.
 *   - Each enum value reaches the matching `fetch_X()` — verified via the
 *     response envelope (`subject` echoed back) plus the
 *     `woocommerce_claude_skill_executed` event the verb tool emits.
 *   - The verb tool fires the action with `tool` / `subject` /
 *     `shape='aggregate'` populated on the payload. After the 0.2.0
 *     cutover this is the only emission point — legacy fetch-level
 *     `do_action` calls inside `AnalyticsController::fetch_X` were
 *     removed, so both direct `add_action` listeners and
 *     `SkillTelemetry::add_handler()` handlers see exactly one event
 *     per execute call.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Telemetry\SkillTelemetry;
use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

/**
 * Integration tests for the wc-analytics/totals verb-shaped ability.
 */
class Test_Totals extends WP_UnitTestCase {

	/**
	 * Direct `add_action` listener — captures every emission of
	 * `woocommerce_claude_skill_executed`. After the 0.2.0 cutover the
	 * verb tool's own `do_action` at the end of `execute()` is the only
	 * call, so the listener sees exactly one event per execute.
	 *
	 * @var array<int, array{skill: string, data: array}>
	 */
	private $direct_events = array();

	/**
	 * Closure stored so tear_down can remove the same callable.
	 *
	 * @var callable
	 */
	private $direct_listener;

	/**
	 * Spy handler registered via SkillTelemetry::add_handler().
	 *
	 * @var TelemetryHandlerInterface
	 */
	private $spy_handler;

	/**
	 * Wire up listeners and elevate to admin so permission_check passes.
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
	 * Remove the direct listener — the SkillTelemetry handler list is
	 * static, but each test's spy captures into its own instance, so
	 * leaving stale spies registered does not pollute later tests.
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
		$ability = wp_get_ability( 'wc-analytics/totals' );
		$this->assertNotNull( $ability, 'wc-analytics/totals ability not registered.' );
		return $ability->execute( $input );
	}

	/**
	 * Build the minimal input for an empty-period dispatch test — the
	 * fixture-free 2000-01 range is guaranteed to return zero rows so
	 * each subject can be exercised without seeding data.
	 *
	 * @param string $subject Analytics subject.
	 * @return array
	 */
	private function empty_period_input( $subject ) {
		return array(
			'subject'    => $subject,
			'period'     => 'custom',
			'date_start' => '2000-01-01',
			'date_end'   => '2000-01-31',
			'compare'    => false,
		);
	}

	/**
	 * Subject outside the enum is rejected at the JSON schema layer with
	 * the abilities-API `ability_invalid_input` code (the wrapping the
	 * Abilities API applies around `rest_validate_value_from_schema`).
	 */
	public function test_invalid_subject_rejected_by_schema() {
		$result = $this->invoke_ability(
			array(
				'subject'    => 'not_a_subject',
				'period'     => 'custom',
				'date_start' => '2000-01-01',
				'date_end'   => '2000-01-31',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Each subject routes to the matching `AnalyticsController::fetch_X()`
	 * method and emits exactly one enriched telemetry event.
	 *
	 * @dataProvider subject_routing_provider
	 *
	 * @param string $subject Analytics subject value.
	 */
	public function test_subject_routes_and_emits_enriched_telemetry( $subject ) {
		$result = $this->invoke_ability( $this->empty_period_input( $subject ) );

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			"Subject '{$subject}' returned WP_Error on empty period: "
				. ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);
		$this->assertSame( $subject, $result['subject'] );

		// After the 0.2.0 cutover the verb tool's own do_action at the end
		// of execute() is the only emission point — legacy fetch-level
		// emissions inside AnalyticsController::fetch_X were removed.
		// Direct listeners and SkillTelemetry-routed handlers each see
		// exactly one event per execute call.
		$this->assertCount(
			1,
			$this->direct_events,
			'Direct add_action listener must see exactly one emission per execute call.'
		);
		$this->assertSame( 'wc-analytics/totals', $this->direct_events[0]['skill'] );

		$this->assertCount(
			1,
			$this->spy_handler->events,
			'SkillTelemetry handler must see exactly one event per execute call.'
		);
		$event = $this->spy_handler->events[0];
		$this->assertSame( 'wc-analytics/totals', $event['skill'] );
		$this->assertSame( 'wc-analytics/totals', $event['data']['tool'] );
		$this->assertSame( $subject, $event['data']['subject'] );
		$this->assertSame( 'aggregate', $event['data']['shape'] );
		$this->assertArrayHasKey( 'duration_ms', $event['data'] );
		$this->assertIsInt( $event['data']['duration_ms'] );
	}

	/**
	 * Subjects covering every value of the totals enum. Adding a new
	 * subject is a one-row change; dropping one fails loudly here before
	 * the dispatch dies silently.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function subject_routing_provider() {
		return array(
			'revenue'        => array( 'revenue' ),
			'orders'         => array( 'orders' ),
			'customers'      => array( 'customers' ),
			'customer_value' => array( 'customer_value' ),
			'tax'            => array( 'tax' ),
			'refunds'        => array( 'refunds' ),
		);
	}
}
