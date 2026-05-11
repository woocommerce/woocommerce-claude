<?php
/**
 * Integration tests — wc-analytics/breakdown (verb-shape pivot, PR 1).
 *
 * Pins the verb-tool contract for the grouped/breakdown surface — schema
 * validation, the cross-field `(subject, dimension)` validation that
 * lives inside the ability, dispatch routing across the six subjects,
 * and the enriched telemetry payload.
 *
 * What's pinned:
 *
 *   - `subject` outside the six-value enum is rejected at the JSON schema
 *     layer (`ability_invalid_input`).
 *   - Cross-field validation: `(subject=revenue, dimension=channel)` —
 *     both values exist in their respective enums but `channel` is not
 *     allowed for the `revenue` subject — returns `WP_Error` with
 *     `invalid_breakdown_dimension`. The schema itself can't pin this
 *     because the dimension enum is the union across all subjects.
 *   - Each `(subject, dimension)` pair routes to the matching
 *     `AnalyticsController::fetch_X()` method (verified via the legacy
 *     skill name leaking through to the direct listener).
 *   - Enriched telemetry: `tool / subject / shape='groups'` populated;
 *     the dispatcher sees exactly ONE event per execute call (suppress/
 *     resume gates the legacy emission inside the fetch).
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Telemetry\SkillTelemetry;
use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

/**
 * Integration tests for the wc-analytics/breakdown verb-shaped ability.
 */
class Test_Analytics_Breakdown extends WP_UnitTestCase {

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
	 * Spy handler — sees only enriched (suppress_dispatch gates the rest).
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
		$ability = wp_get_ability( 'wc-analytics/breakdown' );
		$this->assertNotNull( $ability, 'wc-analytics/breakdown ability not registered.' );
		return $ability->execute( $input );
	}

	/**
	 * Empty-period input — guarantees the underlying fetch returns no
	 * rows without seeding fixtures.
	 *
	 * @param string $subject   Subject value.
	 * @param string $dimension Optional dimension override.
	 * @return array
	 */
	private function empty_period_input( $subject, $dimension = null ) {
		$input = array(
			'subject'    => $subject,
			'period'     => 'custom',
			'date_start' => '2000-01-01',
			'date_end'   => '2000-01-31',
			'compare'    => false,
		);
		if ( null !== $dimension ) {
			$input['dimension'] = $dimension;
		}
		return $input;
	}

	/**
	 * Subject outside the schema enum returns ability_invalid_input.
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
	 * Cross-field validation — `revenue` subject does not allow the
	 * `channel` dimension (channel belongs to `attribution`). Both values
	 * pass schema validation individually because the dimension enum is
	 * the union across all subjects; the per-subject allow-list is
	 * enforced inside the ability via `validate_dimension()`.
	 */
	public function test_dimension_invalid_for_subject_returns_validate_dimension_error() {
		$result = $this->invoke_ability( $this->empty_period_input( 'revenue', 'channel' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_breakdown_dimension', $result->get_error_code() );

		// The error message names the offending dimension and the valid
		// list, so a merchant retrying knows what's allowed.
		$message = $result->get_error_message();
		$this->assertStringContainsString( "'channel'", $message );
		$this->assertStringContainsString( "'revenue'", $message );

		// Validation fired before dispatch, so neither the legacy nor the
		// enriched event should have been emitted.
		$this->assertSame( array(), array_column( $this->direct_events, 'skill' ) );
		$this->assertCount( 0, $this->spy_handler->events );
	}

	/**
	 * Each `(subject, dimension)` pair routes to the matching
	 * `fetch_X()` and emits one enriched event with shape='groups'.
	 *
	 * The pairs use each subject's default dimension so the test exercises
	 * both the dispatch arm AND the per-subject `default_dimension_for()`
	 * fallback when no dimension is supplied.
	 *
	 * @dataProvider subject_routing_provider
	 *
	 * @param string      $subject               Subject value.
	 * @param string|null $dimension             Dimension override (null lets the ability pick the default).
	 * @param string      $expected_legacy_skill Legacy skill name fired by the inner fetch.
	 */
	public function test_subject_routes_and_emits_enriched_telemetry( $subject, $dimension, $expected_legacy_skill ) {
		$result = $this->invoke_ability( $this->empty_period_input( $subject, $dimension ) );

		$this->assertNotInstanceOf(
			WP_Error::class,
			$result,
			"Subject '{$subject}' returned WP_Error on empty period: "
				. ( is_wp_error( $result ) ? $result->get_error_message() : '' )
		);
		$this->assertSame( $subject, $result['subject'] );
		$this->assertArrayHasKey( 'dimension', $result, 'Verb tool decorates response with the resolved dimension.' );

		$direct_skills = array_column( $this->direct_events, 'skill' );
		$this->assertContains(
			$expected_legacy_skill,
			$direct_skills,
			"Direct listener must see the legacy '{$expected_legacy_skill}' emission fired inside the fetch."
		);
		$this->assertContains( 'wc-analytics/breakdown', $direct_skills );

		$this->assertCount(
			1,
			$this->spy_handler->events,
			'SkillTelemetry handler must see exactly one event per execute call (suppress_dispatch gates the legacy emission).'
		);
		$event = $this->spy_handler->events[0];
		$this->assertSame( 'wc-analytics/breakdown', $event['skill'] );
		$this->assertSame( 'wc-analytics/breakdown', $event['data']['tool'] );
		$this->assertSame( $subject, $event['data']['subject'] );
		$this->assertSame( 'groups', $event['data']['shape'] );
	}

	/**
	 * Subject → (dimension, legacy-skill) coverage for every value of the
	 * breakdown enum. Mix of explicit dimensions (revenue, attribution,
	 * coupons) and null-let-the-ability-default (products, refunds, tax)
	 * so the per-subject default-dimension fallback is exercised too.
	 *
	 * @return array<string, array{0: string, 1: ?string, 2: string}>
	 */
	public function subject_routing_provider() {
		return array(
			'revenue_with_explicit_dimension'   => array( 'revenue', 'category', 'get_revenue_breakdown' ),
			'attribution_with_explicit_channel' => array( 'attribution', 'channel', 'get_attribution' ),
			'products_with_default_dimension'   => array( 'products', null, 'get_product_performance' ),
			'refunds_with_default_dimension'    => array( 'refunds', null, 'get_refund_analysis' ),
			'tax_with_default_dimension'        => array( 'tax', null, 'get_tax_summary' ),
			'coupons_with_default_dimension'    => array( 'coupons', null, 'get_coupon_performance' ),
		);
	}
}
