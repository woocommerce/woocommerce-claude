<?php
/**
 * Integration tests — wc-analytics/series (verb-shape pivot, PR 1).
 *
 * Pins the verb-tool contract for the time-series surface — schema
 * validation (subject + interval are both required), dispatch routing
 * across the two subjects, the enriched telemetry payload that carries
 * `interval` + `bucket_count` so series-shape tools log a different
 * envelope than aggregate-shape tools.
 *
 * What's pinned:
 *
 *   - `subject` outside {customers, products} is rejected by the schema.
 *   - `interval` is required by the schema; an input missing it returns
 *     `ability_invalid_input` rather than silently defaulting.
 *   - `interval` outside {day, week, month, auto} is rejected by the
 *     schema.
 *   - Each subject routes to the matching `fetch_X()` (verified via the
 *     legacy skill name fired from inside that fetch).
 *   - Enriched telemetry: `tool / subject / shape='series' / interval /
 *     bucket_count` populated; the interval value is the literal value
 *     the merchant passed (no auto-resolution at the verb-tool layer —
 *     that happens inside fetch_X).
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Telemetry\SkillTelemetry;
use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

/**
 * Integration tests for the wc-analytics/series verb-shaped ability.
 */
class Test_Analytics_Series extends WP_UnitTestCase {

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
		$ability = wp_get_ability( 'wc-analytics/series' );
		$this->assertNotNull( $ability, 'wc-analytics/series ability not registered.' );
		return $ability->execute( $input );
	}

	/**
	 * Subject outside the schema enum returns ability_invalid_input.
	 */
	public function test_invalid_subject_rejected_by_schema() {
		$result = $this->invoke_ability(
			array(
				'subject'    => 'not_a_subject',
				'interval'   => 'day',
				'period'     => 'custom',
				'date_start' => '2000-01-01',
				'date_end'   => '2000-01-31',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Series schema marks `interval` as required — omitting it must hit
	 * the schema layer rather than silently routing through with a default.
	 * Series shape needs an explicit bucket size; defaulting it would mask
	 * the merchant's intent.
	 */
	public function test_missing_interval_rejected_by_schema() {
		$result = $this->invoke_ability(
			array(
				'subject'    => 'customers',
				'period'     => 'custom',
				'date_start' => '2000-01-01',
				'date_end'   => '2000-01-31',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Interval outside {day, week, month, auto} is rejected by the schema.
	 */
	public function test_invalid_interval_rejected_by_schema() {
		$result = $this->invoke_ability(
			array(
				'subject'    => 'customers',
				'interval'   => 'fortnight',
				'period'     => 'custom',
				'date_start' => '2000-01-01',
				'date_end'   => '2000-01-31',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Each subject routes to the matching `fetch_X()` and emits an
	 * enriched event with `shape='series'` and the `interval` /
	 * `bucket_count` fields populated.
	 *
	 * @dataProvider subject_routing_provider
	 *
	 * @param string $subject  Subject value.
	 * @param string $interval Interval value passed to the ability.
	 */
	public function test_subject_routes_and_emits_enriched_telemetry( $subject, $interval ) {
		$result = $this->invoke_ability(
			array(
				'subject'    => $subject,
				'interval'   => $interval,
				'period'     => 'custom',
				'date_start' => '2000-01-01',
				'date_end'   => '2000-01-31',
				'compare'    => false,
			)
		);

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			"Subject '{$subject}' / interval '{$interval}' returned WP_Error on empty period: "
				. ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);
		$this->assertSame( $subject, $result['subject'] );
		$this->assertSame( $interval, $result['interval'] );

		// After the 0.2.0 cutover the verb tool's own do_action is the only
		// emission point — legacy fetch-level emissions were removed.
		$this->assertCount(
			1,
			$this->direct_events,
			'Direct add_action listener must see exactly one emission per execute call.'
		);
		$this->assertSame( 'wc-analytics/series', $this->direct_events[0]['skill'] );

		$this->assertCount(
			1,
			$this->spy_handler->events,
			'SkillTelemetry handler must see exactly one event per execute call.'
		);
		$event = $this->spy_handler->events[0];
		$this->assertSame( 'wc-analytics/series', $event['skill'] );
		$this->assertSame( 'wc-analytics/series', $event['data']['tool'] );
		$this->assertSame( $subject, $event['data']['subject'] );
		$this->assertSame( 'series', $event['data']['shape'] );
		$this->assertSame(
			$interval,
			$event['data']['interval'],
			'Enriched payload carries the literal interval the merchant passed — bucket_count comes from calculate_bucket_count().'
		);
		$this->assertNotNull( $event['data']['bucket_count'] );
		$this->assertIsInt( $event['data']['bucket_count'] );
	}

	/**
	 * Subject + interval pairs covering every value of the series enum.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function subject_routing_provider() {
		return array(
			'customers_with_month_interval' => array( 'customers', 'month' ),
			'products_with_day_interval'    => array( 'products', 'day' ),
		);
	}
}
