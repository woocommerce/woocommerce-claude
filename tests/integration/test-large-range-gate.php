<?php
/**
 * Integration tests — LargeRangeGate.
 *
 * Covers the reusable large-range confirmation gate: token minting, validation,
 * one-use semantics, bypass on valid token, and rejection of bogus tokens.
 * Also covers end-to-end wiring through fetch_product_performance and
 * fetch_customer_overview so regressions in the controller plumbing are caught
 * at the same layer as the unit-level gate tests.
 *
 * What's pinned:
 *
 *   - Short range (≤ 365 days): gate returns int (series cap), no error.
 *   - Long range (> 365 days) without token: gate returns extended_range_required
 *     WP_Error with confirmation_token in error data.
 *   - Long range with a valid token: gate returns int (range_days + 1), no error.
 *   - Token is one-use: second call with the same token re-fires the gate.
 *   - Bogus / empty token: gate fires as if no token was provided.
 *   - End-to-end: fetch_product_performance / fetch_customer_overview return
 *     extended_range_required for long ranges and succeed on confirmed follow-up.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Abilities\LargeRangeGate;
use WooCommerce\Claude\Abilities\GetDataAbility;
use WooCommerce\Claude\Abilities\ConfirmLargeRangeAbility;
use WooCommerce\Claude\API\AnalyticsController;

/**
 * Tests for LargeRangeGate.
 */
class Test_Large_Range_Gate extends WP_UnitTestCase {

	// Short range — within the 365-day threshold.
	const SHORT_START = '2025-01-01 00:00:00';
	const SHORT_END   = '2025-12-31 23:59:59';

	// Long range — well above the 365-day threshold.
	const LONG_START = '2023-01-01 00:00:00';
	const LONG_END   = '2025-01-01 23:59:59';

	// ─── Unit-level gate tests ────────────────────────────────────────

	/**
	 * Short range: gate does not fire; returns range_days + 1 as sentinel cap.
	 */
	public function test_short_range_passes_without_token() {
		$result = LargeRangeGate::check( self::SHORT_START, self::SHORT_END );

		$this->assertIsInt( $result, 'Short range should return int series cap, not WP_Error.' );
		$range_days = (int) ( new DateTime( self::SHORT_START ) )
			->diff( new DateTime( self::SHORT_END ) )->days + 1;
		$this->assertSame( $range_days + 1, $result, 'Short range cap should be range_days + 1 (sentinel).' );
	}

	/**
	 * Long range without token: gate fires with the correct error shape.
	 */
	public function test_long_range_fires_gate() {
		$result = LargeRangeGate::check( self::LONG_START, self::LONG_END );

		$this->assertWPError( $result );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertTrue( $data['confirmation_required'] );
		$this->assertNotEmpty( $data['confirmation_token'] );
		$this->assertSame( LargeRangeGate::TOKEN_TTL_SECONDS, $data['expires_in_seconds'] );
		$this->assertArrayHasKey( 'range_days', $data['cost_estimate'] );
		$this->assertGreaterThan( 365, $data['cost_estimate']['range_days'] );
	}

	/**
	 * Long range with a valid token: gate passes and returns range_days + 1.
	 */
	public function test_valid_token_bypasses_gate() {
		$gate_error = LargeRangeGate::check( self::LONG_START, self::LONG_END );
		$this->assertWPError( $gate_error );
		$token = $gate_error->get_error_data()['confirmation_token'];

		$result = LargeRangeGate::check( self::LONG_START, self::LONG_END, $token );

		$this->assertIsInt( $result, 'Valid token should return int series cap, not WP_Error.' );

		$range_days = (int) ( new DateTime( self::LONG_START ) )
			->diff( new DateTime( self::LONG_END ) )->days + 1;
		$this->assertSame( $range_days + 1, $result, 'Confirmed cap should be range_days + 1.' );
	}

	/**
	 * Token is one-use: second call with the same token re-fires the gate.
	 */
	public function test_token_is_one_use() {
		$gate_error = LargeRangeGate::check( self::LONG_START, self::LONG_END );
		$token      = $gate_error->get_error_data()['confirmation_token'];

		// First use — gate clears.
		$first = LargeRangeGate::check( self::LONG_START, self::LONG_END, $token );
		$this->assertIsInt( $first, 'First use of token should clear the gate.' );

		// Second use — transient was deleted; gate fires again.
		$second = LargeRangeGate::check( self::LONG_START, self::LONG_END, $token );
		$this->assertWPError( $second, 'Second use of same token should re-fire the gate.' );
		$this->assertSame( 'extended_range_required', $second->get_error_code() );
	}

	/**
	 * Bogus token: gate fires as if no token was provided.
	 */
	public function test_bogus_token_is_rejected() {
		$result = LargeRangeGate::check( self::LONG_START, self::LONG_END, 'not-a-real-token-12345' );

		$this->assertWPError( $result );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );
	}

	/**
	 * Empty string token: treated same as no token.
	 */
	public function test_empty_token_is_rejected() {
		$result = LargeRangeGate::check( self::LONG_START, self::LONG_END, '' );

		$this->assertWPError( $result );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );
	}

	// ─── End-to-end controller wiring ────────────────────────────────

	/**
	 * Fetch_product_performance: long range fires extended_range_required.
	 */
	public function test_product_performance_long_range_fires_gate() {
		$result = AnalyticsController::fetch_product_performance(
			null,
			'2022-01-01',
			'2024-12-31',
			false,
			10,
			'net_revenue',
			'product',
			'day',
			null
		);

		$this->assertWPError( $result );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['confirmation_required'] );
	}

	/**
	 * Fetch_product_performance: confirmed token bypasses gate and returns data array.
	 */
	public function test_product_performance_confirmed_token_bypasses_gate() {
		$start = '2022-01-01';
		$end   = '2024-12-31';

		// First call — get a token.
		$gate = AnalyticsController::fetch_product_performance(
			null,
			$start,
			$end,
			false,
			10,
			'net_revenue',
			'product',
			'',
			null
		);
		$this->assertWPError( $gate );
		$token = $gate->get_error_data()['confirmation_token'];

		// Second call — with token, should return a data array (not WP_Error).
		$result = AnalyticsController::fetch_product_performance(
			null,
			$start,
			$end,
			false,
			10,
			'net_revenue',
			'product',
			'',
			$token
		);

		$this->assertFalse( is_wp_error( $result ), 'Confirmed call should return data, not WP_Error.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'period', $result );
	}

	/**
	 * Fetch_customer_overview: long range fires extended_range_required.
	 */
	public function test_customer_overview_long_range_fires_gate() {
		$result = AnalyticsController::fetch_customer_overview(
			null,
			'2022-01-01',
			'2024-12-31',
			false,
			'month',
			null
		);

		$this->assertWPError( $result );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['confirmation_required'] );
	}

	/**
	 * Fetch_customer_overview: confirmed token bypasses gate and returns data array.
	 */
	public function test_customer_overview_confirmed_token_bypasses_gate() {
		$start = '2022-01-01';
		$end   = '2024-12-31';

		// First call — get a token.
		$gate = AnalyticsController::fetch_customer_overview(
			null,
			$start,
			$end,
			false,
			'',
			null
		);
		$this->assertWPError( $gate );
		$token = $gate->get_error_data()['confirmation_token'];

		// Second call — with token, should return a data array (not WP_Error).
		$result = AnalyticsController::fetch_customer_overview(
			null,
			$start,
			$end,
			false,
			'',
			$token
		);

		$this->assertFalse( is_wp_error( $result ), 'Confirmed call should return data, not WP_Error.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'period', $result );
	}

	/**
	 * Gate does not fire for non-series aggregate queries within the threshold.
	 */
	public function test_aggregate_query_within_threshold_is_not_gated() {
		$result = AnalyticsController::fetch_product_performance(
			null,
			'2025-01-01',
			'2025-12-31',
			false,
			10,
			'net_revenue',
			'product',
			'',
			null
		);

		$this->assertFalse( is_wp_error( $result ), 'Short aggregate query should not be gated.' );
	}

	/**
	 * Gate fires for aggregate (non-series) queries beyond the threshold too.
	 */
	public function test_aggregate_query_beyond_threshold_is_gated() {
		$result = AnalyticsController::fetch_product_performance(
			null,
			'2022-01-01',
			'2024-12-31',
			false,
			10,
			'net_revenue',
			'product',
			'', // No interval — aggregate only.
			null
		);

		$this->assertWPError( $result );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );
	}

	// ─── Session-keyed gate (check_run / approve_scan) ────────────────

	/**
	 * Check_run: short range passes without any approval; returns range_days + 1.
	 */
	public function test_check_run_short_range_passes() {
		$result = LargeRangeGate::check_run( self::SHORT_START, self::SHORT_END );

		$this->assertIsInt( $result, 'Short range should return int series cap, not WP_Error.' );
		$range_days = (int) ( new DateTime( self::SHORT_START ) )
			->diff( new DateTime( self::SHORT_END ) )->days + 1;
		$this->assertSame( $range_days + 1, $result, 'Short range cap should be range_days + 1 (sentinel).' );
	}

	/**
	 * Check_run: long range fires the gate with the correct error shape.
	 */
	public function test_check_run_long_range_fires_gate() {
		$result = LargeRangeGate::check_run( self::LONG_START, self::LONG_END );

		$this->assertWPError( $result );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertTrue( $data['confirmation_required'] );
		$this->assertArrayHasKey( 'range_days', $data['cost_estimate'] );
		$this->assertGreaterThan( 365, $data['cost_estimate']['range_days'] );
		// Session gate has no token in the error data.
		$this->assertArrayNotHasKey( 'confirmation_token', $data );
	}

	/**
	 * Approve_scan: returns false when no pending scan exists.
	 */
	public function test_approve_scan_returns_false_when_no_pending() {
		$result = LargeRangeGate::approve_scan( '2022-01-01', '2024-12-31' );
		$this->assertFalse( $result );
	}

	/**
	 * Full session-keyed flow: check_run fires → approve_scan approves → check_run passes.
	 */
	public function test_session_keyed_full_flow() {
		$type = 'revenue_summary';

		// Step 1 — gate fires and mints a pending transient.
		$gate = LargeRangeGate::check_run( self::LONG_START, self::LONG_END, $type );
		$this->assertWPError( $gate );
		$this->assertSame( 'extended_range_required', $gate->get_error_code() );

		// Step 2 — approve_scan finds the pending transient and flips it to approved.
		$approved = LargeRangeGate::approve_scan( self::LONG_START, self::LONG_END, $type );
		$this->assertTrue( $approved, 'approve_scan should return true when a pending scan exists.' );

		// Step 3 — check_run consumes the approval and passes.
		$result = LargeRangeGate::check_run( self::LONG_START, self::LONG_END, $type );
		$this->assertIsInt( $result, 'After approval, check_run should return int series cap.' );

		$range_days = (int) ( new DateTime( self::LONG_START ) )
			->diff( new DateTime( self::LONG_END ) )->days + 1;
		$this->assertSame( $range_days + 1, $result, 'Confirmed cap should be range_days + 1.' );
	}

	/**
	 * ConfirmLargeRangeAbility accepts verb-shaped subject types.
	 */
	public function test_confirm_large_range_accepts_verb_subject_type() {
		$type = 'products';

		$gate = LargeRangeGate::check_run( self::LONG_START, self::LONG_END, $type );
		$this->assertWPError( $gate );

		$approval = ConfirmLargeRangeAbility::execute(
			array(
				'date_start'  => '2023-01-01',
				'date_end'    => '2025-01-01',
				'type'        => $type,
				'description' => 'Long product series',
			)
		);
		$this->assertFalse( is_wp_error( $approval ), 'Verb-shaped subject approval should be accepted.' );

		$result = LargeRangeGate::check_run( self::LONG_START, self::LONG_END, $type );
		$this->assertIsInt( $result, 'Approved verb-shaped subject should consume the session approval.' );
	}

	/**
	 * Session approval is one-use: second check_run after consumption re-fires gate.
	 */
	public function test_session_approval_is_one_use() {
		$type = 'orders_summary';

		// Mint + approve.
		LargeRangeGate::check_run( self::LONG_START, self::LONG_END, $type );
		LargeRangeGate::approve_scan( self::LONG_START, self::LONG_END, $type );

		// First consumption — passes.
		$first = LargeRangeGate::check_run( self::LONG_START, self::LONG_END, $type );
		$this->assertIsInt( $first, 'First check_run after approval should pass.' );

		// Second check_run — transient was deleted; gate fires again.
		$second = LargeRangeGate::check_run( self::LONG_START, self::LONG_END, $type );
		$this->assertWPError( $second, 'Second check_run without fresh approval should re-fire gate.' );
		$this->assertSame( 'extended_range_required', $second->get_error_code() );
	}

	/**
	 * Approval for one type cannot be consumed by a different type on the same dates.
	 */
	public function test_approval_is_scoped_to_type() {
		$start = self::LONG_START;
		$end   = self::LONG_END;

		// Mint + approve for revenue_summary.
		LargeRangeGate::check_run( $start, $end, 'revenue_summary' );
		LargeRangeGate::approve_scan( $start, $end, 'revenue_summary' );

		// Attempting to consume with a different type should re-fire the gate.
		$result = LargeRangeGate::check_run( $start, $end, 'customer_overview' );
		$this->assertWPError( $result, 'Different type should not consume the revenue_summary approval.' );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );

		// The original approval should still be consumable for the right type.
		$result = LargeRangeGate::check_run( $start, $end, 'revenue_summary' );
		$this->assertIsInt( $result, 'Correct type should consume the approval.' );
	}

	/**
	 * Approvals minted by one verb tool cannot be consumed by another verb tool
	 * that happens to share the same subject.
	 *
	 * Pre-fix the gate keyed transients on bare type strings, so verb tools that
	 * passed only `$subject` would collide whenever two of them dispatched on
	 * the same subject + overlapping date range (e.g. totals subject=revenue
	 * vs breakdown subject=revenue; totals subject=customers vs series
	 * subject=customers). A model approving a totals call could silently
	 * consume that approval from inside breakdown, producing the wrong
	 * response shape under the same merchant confirmation.
	 *
	 * Post-fix each verb tool prefixes its subject with its own slug
	 * (totals:revenue, breakdown:revenue, series:customers) before calling
	 * check_run, so the transient keys are disjoint.
	 */
	public function test_verb_tool_approvals_do_not_collide_across_tools_sharing_a_subject() {
		$start = self::LONG_START;
		$end   = self::LONG_END;

		// Mint + approve a totals subject=revenue scan.
		LargeRangeGate::check_run( $start, $end, 'totals:revenue' );
		LargeRangeGate::approve_scan( $start, $end, 'totals:revenue' );

		// A breakdown subject=revenue retry on the same dates must NOT consume
		// the totals approval — gate fires again with its own pending mint.
		$breakdown_result = LargeRangeGate::check_run( $start, $end, 'breakdown:revenue' );
		$this->assertWPError(
			$breakdown_result,
			'breakdown:revenue must not consume an approval minted under totals:revenue.'
		);
		$this->assertSame( 'extended_range_required', $breakdown_result->get_error_code() );

		// The totals approval must still be consumable by totals.
		$totals_result = LargeRangeGate::check_run( $start, $end, 'totals:revenue' );
		$this->assertIsInt(
			$totals_result,
			'totals:revenue must still consume its own approval after a non-matching breakdown retry.'
		);

		// Sanity: same shape for series subject=customers vs totals subject=customers.
		LargeRangeGate::check_run( $start, $end, 'totals:customers' );
		LargeRangeGate::approve_scan( $start, $end, 'totals:customers' );

		$series_result = LargeRangeGate::check_run( $start, $end, 'series:customers' );
		$this->assertWPError(
			$series_result,
			'series:customers must not consume an approval minted under totals:customers.'
		);
	}

	/**
	 * Session key normalises date+time to date-only so H:i:s suffix does not break matching.
	 */
	public function test_session_key_normalises_datetime_to_date() {
		$type = 'product_performance';

		// Mint with H:i:s timestamps (as resolve_dates produces).
		LargeRangeGate::check_run( self::LONG_START, self::LONG_END, $type );

		// Approve with date-only strings (as confirm-large-range sends).
		$approved = LargeRangeGate::approve_scan( '2023-01-01', '2025-01-01', $type );
		$this->assertTrue( $approved, 'approve_scan with date-only strings should find the pending transient.' );
	}

	// ─── End-to-end via GetDataAbility ───────────────────────────────

	/**
	 * GetDataAbility: long range fires extended_range_required with session gate shape.
	 */
	public function test_get_data_ability_long_range_fires_gate() {
		$result = GetDataAbility::execute(
			array(
				'type'   => 'product_performance',
				'params' => array(
					'date_start' => '2022-01-01',
					'date_end'   => '2024-12-31',
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'extended_range_required', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertTrue( $data['confirmation_required'] );
		// Session gate must not leak a confirmation_token.
		$this->assertArrayNotHasKey( 'confirmation_token', $data );
	}

	/**
	 * GetDataAbility: after approve_scan, the gate passes and data is returned.
	 */
	public function test_get_data_ability_passes_after_approval() {
		$start = '2022-01-01';
		$end   = '2024-12-31';

		// Trigger gate (mints pending transient).
		GetDataAbility::execute(
			array(
				'type'   => 'product_performance',
				'params' => array(
					'date_start' => $start,
					'date_end'   => $end,
				),
			)
		);

		// Approve (as confirm-large-range would — must pass the same type).
		LargeRangeGate::approve_scan( $start, $end, 'product_performance' );

		// Retry — gate should pass and return data.
		$result = GetDataAbility::execute(
			array(
				'type'   => 'product_performance',
				'params' => array(
					'date_start' => $start,
					'date_end'   => $end,
				),
			)
		);

		$this->assertFalse( is_wp_error( $result ), 'After approval, get-data should return data, not WP_Error.' );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'period', $result );
	}

	/**
	 * GetDataAbility: short range (≤ 365 days) never fires the gate.
	 */
	public function test_get_data_ability_short_range_never_gated() {
		$result = GetDataAbility::execute(
			array(
				'type'   => 'orders_summary',
				'params' => array(
					'date_start' => '2025-01-01',
					'date_end'   => '2025-12-31',
				),
			)
		);

		$this->assertFalse( is_wp_error( $result ), 'Short range via get-data should not be gated.' );
		$this->assertIsArray( $result );
	}

	/**
	 * GetDataAbility: unknown type returns invalid_analytics_type error.
	 */
	public function test_get_data_ability_unknown_type_returns_error() {
		$result = GetDataAbility::execute(
			array(
				'type'   => 'not_a_real_type',
				'params' => array(),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_analytics_type', $result->get_error_code() );
	}
}
