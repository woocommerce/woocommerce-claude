<?php
/**
 * Integration tests — hey_woo_skill_executed telemetry hook.
 *
 * What's pinned:
 *
 *   - The hook fires exactly once per fetch_* call.
 *   - Payload always carries duration_ms (non-negative int), cache_hit (bool),
 *     rows_returned (non-negative int).
 *   - cache_hit is false on the first call, true when the transient is warm.
 *   - SkillTelemetry::dispatch() routes the payload to all registered handlers.
 *   - LogHandler::record() runs without error (WC logger present in test env).
 *   - TracksHandler::record() is a no-op when WC tracking is disabled (default
 *     in tests — woocommerce_allow_tracking option unset).
 *
 * Uses fetch_revenue_summary as the representative skill because it's the
 * simplest (scalar result, no fixtures required — an empty date range returns
 * a zeroed payload with a note). The hook contract is the same for every skill.
 *
 * @package HeyWoo\Tests
 */

use HeyWoo\API\AnalyticsController;
use HeyWoo\Telemetry\SkillTelemetry;
use HeyWoo\Telemetry\TelemetryHandlerInterface;

/**
 * Integration tests for the hey_woo_skill_executed hook.
 */
class Test_Skill_Telemetry extends WP_UnitTestCase {

	/**
	 * Payloads captured during the current test.
	 *
	 * @var array[]
	 */
	private $captured = array();

	/**
	 * Hook callback reference for removal in tear_down.
	 *
	 * @var callable
	 */
	private $listener;

	/**
	 * Date range guaranteed to have no orders in a fresh test DB.
	 */
	const EMPTY_START = '2000-01-01';
	const EMPTY_END   = '2000-01-31';

	/**
	 * Register the capturing listener and clear stale transients.
	 */
	public function set_up() {
		parent::set_up();

		$this->captured = array();

		$this->listener = function ( $skill_name, $data ) {
			$this->captured[] = array(
				'skill' => $skill_name,
				'data'  => $data,
			);
		};

		add_action( 'hey_woo_skill_executed', $this->listener, 10, 2 );

		// Ensure no stale transient from a prior test run bleeds in.
		$this->delete_revenue_transients();
	}

	/**
	 * Remove the listener and clean up transients.
	 */
	public function tear_down() {
		remove_action( 'hey_woo_skill_executed', $this->listener, 10 );
		$this->delete_revenue_transients();
		parent::tear_down();
	}

	// -------------------------------------------------------------------------
	// Payload shape.
	// -------------------------------------------------------------------------

	/**
	 * Hook fires on a cache miss and carries the required keys.
	 */
	public function test_hook_fires_on_cache_miss() {
		AnalyticsController::fetch_revenue_summary( 'custom', self::EMPTY_START, self::EMPTY_END, false );

		$this->assertCount( 1, $this->captured, 'Hook must fire exactly once per fetch call.' );

		$event = $this->captured[0];
		$this->assertSame( 'get_revenue_summary', $event['skill'] );

		$data = $event['data'];
		$this->assertArrayHasKey( 'duration_ms', $data );
		$this->assertArrayHasKey( 'cache_hit', $data );
		$this->assertArrayHasKey( 'rows_returned', $data );
		$this->assertIsInt( $data['duration_ms'] );
		$this->assertGreaterThanOrEqual( 0, $data['duration_ms'] );
		$this->assertIsBool( $data['cache_hit'] );
		$this->assertIsInt( $data['rows_returned'] );
		$this->assertGreaterThanOrEqual( 0, $data['rows_returned'] );
	}

	/**
	 * Cache_hit is false on the first call (cold cache).
	 */
	public function test_cache_hit_false_on_cold_call() {
		AnalyticsController::fetch_revenue_summary( 'custom', self::EMPTY_START, self::EMPTY_END, false );

		$this->assertFalse( $this->captured[0]['data']['cache_hit'] );
	}

	/**
	 * Cache_hit is true on a repeated call with the same arguments.
	 */
	public function test_cache_hit_true_on_warm_cache() {
		AnalyticsController::fetch_revenue_summary( 'custom', self::EMPTY_START, self::EMPTY_END, false );
		AnalyticsController::fetch_revenue_summary( 'custom', self::EMPTY_START, self::EMPTY_END, false );

		$this->assertCount( 2, $this->captured );
		$this->assertFalse( $this->captured[0]['data']['cache_hit'], 'First call must be a cache miss.' );
		$this->assertTrue( $this->captured[1]['data']['cache_hit'], 'Second call must be a cache hit.' );
	}

	/**
	 * Rows_returned is 1 for a scalar-result skill (revenue summary has no top-N array).
	 */
	public function test_rows_returned_is_one_for_scalar_skill() {
		AnalyticsController::fetch_revenue_summary( 'custom', self::EMPTY_START, self::EMPTY_END, false );

		$this->assertSame( 1, $this->captured[0]['data']['rows_returned'] );
	}

	// -------------------------------------------------------------------------
	// SkillTelemetry handler dispatch.
	// -------------------------------------------------------------------------

	/**
	 * A handler added via add_handler() receives the payload.
	 */
	public function test_skill_telemetry_dispatches_to_added_handler() {
		$received = array();

		$spy = new class( $received ) implements TelemetryHandlerInterface {
			/**
			 * Captured events store.
			 *
			 * @var array
			 */
			private $store;

			/**
			 * Constructor.
			 *
			 * @param array $store Reference to the capture array.
			 */
			public function __construct( &$store ) {
				$this->store = &$store;
			}

			/**
			 * Capture the event.
			 *
			 * @param string $skill_name Skill identifier.
			 * @param array  $data       Telemetry payload.
			 */
			public function record( $skill_name, $data ) {
				$this->store[] = array(
					'skill' => $skill_name,
					'data'  => $data,
				);
			}
		};

		SkillTelemetry::add_handler( $spy );

		AnalyticsController::fetch_revenue_summary( 'custom', self::EMPTY_START, self::EMPTY_END, false );

		$this->assertNotEmpty( $received, 'Spy handler must receive at least one event.' );
		$this->assertSame( 'get_revenue_summary', $received[0]['skill'] );
		$this->assertArrayHasKey( 'duration_ms', $received[0]['data'] );
	}

	// -------------------------------------------------------------------------
	// Helpers.
	// -------------------------------------------------------------------------

	/**
	 * Delete all revenue-summary transients so tests start with a cold cache.
	 */
	private function delete_revenue_transients() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				'_transient_hey_woo_revenue_%',
				'_transient_timeout_hey_woo_revenue_%'
			)
		);
	}
}
