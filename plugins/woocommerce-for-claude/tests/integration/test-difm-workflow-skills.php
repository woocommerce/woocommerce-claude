<?php
/**
 * Integration tests for AI Insights workflow skill selection.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\WorkflowSkills;
use WooCommerce\Claude\Telemetry\TelemetryHandler;
use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

/**
 * Tests for the BYOK workflow skill loader and prompt injection.
 */
class Test_Difm_Workflow_Skills extends WP_UnitTestCase {

	/**
	 * REST server instance used for dispatching test requests.
	 *
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * Set up a REST server for each test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- This action is documented in wp-includes/rest-api.php.
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down: remove API key option.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		parent::tear_down();
	}

	/**
	 * Every shipped workflow skill file should parse into a usable definition.
	 */
	public function test_all_workflow_skill_files_parse() {
		$workflows = WorkflowSkills::all();

		$this->assertGreaterThanOrEqual( 18, count( $workflows ) );
		$this->assertArrayHasKey( 'weekly-store-review', $workflows );
		$this->assertArrayHasKey( 'revenue-drop-triage', $workflows );
		$this->assertArrayHasKey( 'tax-reconciliation', $workflows );

		foreach ( $workflows as $slug => $workflow ) {
			$this->assertSame( $slug, $workflow['slug'] );
			$this->assertNotEmpty( $workflow['description'], "Missing description for {$slug}" );
			$this->assertNotEmpty( $workflow['body'], "Missing body for {$slug}" );
			$this->assertFileExists( $workflow['file'] );
		}
	}

	/**
	 * Slash-style workflow commands should select the matching workflow directly.
	 */
	public function test_slash_command_selects_workflow() {
		$workflow = WorkflowSkills::select_for_message( '/woocommerce-claude:weekly-store-review please' );

		$this->assertIsArray( $workflow );
		$this->assertSame( 'weekly-store-review', $workflow['slug'] );
		$this->assertSame( 'command', $workflow['match'] );
	}

	/**
	 * Natural merchant wording should route to the matching workflow.
	 */
	public function test_natural_language_selects_workflow() {
		$examples = array(
			'Give me my weekly store review'               => 'weekly-store-review',
			'Give me a weekly review'                      => 'weekly-store-review',
			'Revenue is down this month, triage it'        => 'revenue-drop-triage',
			'Triage refunds from the last 30 days'         => 'refund-triage',
			'Are my coupons working?'                      => 'coupon-performance-triage',
			'Review my customer value and repeat purchasing' => 'customer-value-review',
			'Review customer acquisition from the last 30 days' => 'customer-acquisition-review',
			'Review product performance from the last 30 days' => 'product-performance-review',
			'Review catalogue merchandising from the last 30 days' => 'catalogue-merchandising-review',
			'Review inventory risk from the last 30 days'  => 'inventory-risk-review',
			'Review channel performance from the last 30 days' => 'channel-performance-review',
			'Review geography performance from the last 30 days' => 'geography-performance-review',
			'Review shipping methods from the last 30 days' => 'shipping-method-review',
			'Review payment methods from the last 30 days' => 'payment-method-review',
			'Triage failed and on-hold orders'             => 'failed-order-triage',
			'Reconcile tax collected for last month'       => 'tax-reconciliation',
			'Run a catalogue audit'                        => 'catalog-audit',
			'Check store health'                           => 'store-health-monitor',
			'Help improve product content'                 => 'product-content-generator',
		);

		foreach ( $examples as $message => $expected_slug ) {
			$workflow = WorkflowSkills::select_for_message( $message );
			$this->assertIsArray( $workflow, $message );
			$this->assertSame( $expected_slug, $workflow['slug'], $message );
			$this->assertSame( 'intent', $workflow['match'], $message );
		}
	}

	/**
	 * Generic questions should not inject a workflow.
	 */
	public function test_generic_question_selects_no_workflow() {
		$this->assertNull( WorkflowSkills::select_for_message( 'What can you answer?' ) );
	}

	/**
	 * Workflow prompts are translated from MCP/client tool names to BYOK tool names.
	 */
	public function test_prompt_for_difm_translates_tool_references() {
		$workflow = WorkflowSkills::get( 'weekly-store-review' );
		$this->assertIsArray( $workflow );

		$prompt = WorkflowSkills::prompt_for_difm( $workflow );

		$this->assertStringContainsString( 'Selected workflow: weekly-store-review', $prompt );
		$this->assertStringContainsString( '`analytics_totals`', $prompt );
		$this->assertStringContainsString( '`analytics_breakdown`', $prompt );
		$this->assertStringContainsString( '`get_store_profile`', $prompt );
		$this->assertStringNotContainsString( '`wc-analytics-totals`', $prompt );
		$this->assertStringNotContainsString( '`wc-analytics-breakdown`', $prompt );
		$this->assertStringNotContainsString( '`store://profile`', $prompt );
	}

	/**
	 * Matching workflow messages inject only the selected workflow into the Anthropic system prompt.
	 */
	public function test_chat_injects_selected_workflow_into_anthropic_prompt() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$captured_body = null;
		$handler       = new class() implements TelemetryHandlerInterface {
			/**
			 * Captured telemetry events.
			 *
			 * @var array<int, array{skill: string, data: array}>
			 */
			public $events = array();

			/**
			 * Capture the event dispatched through TelemetryHandler.
			 *
			 * @param string $event_name Event name.
			 * @param array  $data       Telemetry payload.
			 */
			public function record( $event_name, $data ) {
				$this->events[] = array(
					'skill' => $event_name,
					'data'  => $data,
				);
			}
		};
		TelemetryHandler::add_handler( $handler );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args ) use ( &$captured_body ) {
				$captured_body = json_decode( $parsed_args['body'], true );
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'        => 'message',
							'stop_reason' => 'end_turn',
							'content'     => array(
								array(
									'type' => 'text',
									'text' => 'Here is your weekly review.',
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$response = $this->dispatch_chat( 'Give me my weekly store review' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 'ok', $data['status'] );
		$this->assertNotNull( $captured_body );
		$this->assertStringContainsString( 'Selected workflow: weekly-store-review', $captured_body['system'] );
		$this->assertStringContainsString( '`analytics_totals`', $captured_body['system'] );
		$this->assertStringContainsString( '`get_store_profile`', $captured_body['system'] );
		$this->assertStringNotContainsString( '`wc-analytics-totals`', $captured_body['system'] );
		$this->assertStringNotContainsString( '`store://profile`', $captured_body['system'] );
		$this->assertStringNotContainsString( 'Selected workflow: refund-triage', $captured_body['system'] );

		$workflow_event = $this->find_event( $handler->events, 'difm_workflow_selected' );
		$this->assertNotNull( $workflow_event );
		$this->assertSame( 'weekly-store-review', $workflow_event['workflow'] );
		$this->assertSame( 'intent', $workflow_event['match'] );
	}

	/**
	 * Non-workflow messages keep the base prompt compact.
	 */
	public function test_chat_does_not_inject_workflow_for_generic_question() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$captured_body = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args ) use ( &$captured_body ) {
				$captured_body = json_decode( $parsed_args['body'], true );
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'        => 'message',
							'stop_reason' => 'end_turn',
							'content'     => array(
								array(
									'type' => 'text',
									'text' => 'I can help with store analytics.',
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$this->dispatch_chat( 'What can you answer?' );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotNull( $captured_body );
		$this->assertStringNotContainsString( 'Selected workflow:', $captured_body['system'] );
	}

	/**
	 * Large-range confirmation turns should preserve the originally selected workflow.
	 */
	public function test_large_range_confirmation_preserves_selected_workflow() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'        => 'message',
							'stop_reason' => 'tool_use',
							'content'     => array(
								array(
									'type'  => 'tool_use',
									'id'    => 'toolu_large_workflow',
									'name'  => 'analytics_series',
									'input' => array(
										'subject'    => 'products',
										'date_start' => '2024-01-01',
										'date_end'   => '2026-01-15',
										'interval'   => 'day',
									),
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$this->dispatch_chat( 'Give me my weekly store review since 2024' );
		remove_all_filters( 'pre_http_request' );

		$pending = get_transient( \WooCommerce\Claude\Difm\DifmRestController::PENDING_LARGE_RANGE_PREFIX . get_current_user_id() );
		$this->assertIsArray( $pending );
		$this->assertSame( 'weekly-store-review', $pending['workflow_slug'] );

		$captured_body = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args ) use ( &$captured_body ) {
				$captured_body = json_decode( $parsed_args['body'], true );
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'        => 'message',
							'stop_reason' => 'end_turn',
							'content'     => array(
								array(
									'type' => 'text',
									'text' => 'Here is the confirmed weekly review.',
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$response = $this->dispatch_chat( 'yes, proceed' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 'ok', $data['status'] );
		$this->assertNotNull( $captured_body );
		$this->assertStringContainsString( 'Selected workflow: weekly-store-review', $captured_body['system'] );
		$this->assertStringContainsString( '`analytics_totals`', $captured_body['system'] );
		$this->assertStringNotContainsString( '`wc-analytics-totals`', $captured_body['system'] );
		$this->assertFalse( get_transient( \WooCommerce\Claude\Difm\DifmRestController::PENDING_LARGE_RANGE_PREFIX . get_current_user_id() ) );
	}

	/**
	 * Set a store-admin user for the current test.
	 *
	 * @return void
	 */
	private function set_admin_user() {
		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
	}

	/**
	 * Dispatch a chat request.
	 *
	 * @param string $message Merchant message.
	 * @return \WP_REST_Response
	 */
	private function dispatch_chat( $message ) {
		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', $message );
		return $this->server->dispatch( $request );
	}

	/**
	 * Find a captured telemetry event by name.
	 *
	 * @param array  $events Captured events.
	 * @param string $event  Event name.
	 * @return array|null
	 */
	private function find_event( array $events, $event ) {
		foreach ( $events as $entry ) {
			if ( ! is_array( $entry ) || ( $entry['skill'] ?? '' ) !== $event ) {
				continue;
			}

			return isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
		}

		return null;
	}
}
