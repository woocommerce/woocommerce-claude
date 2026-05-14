<?php
/**
 * Integration tests for DifmRestController.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\DifmRestController;
use WooCommerce\Claude\Telemetry\TelemetryHandler;
use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

/**
 * Tests for DifmRestController.
 */
class Test_Difm_Rest_Controller extends WP_UnitTestCase {

	/**
	 * REST server instance used for dispatching test requests.
	 *
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * Admin user ID used by tests that need user-scoped transients.
	 *
	 * @var int
	 */
	protected $admin_user_id = 0;

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
		if ( $this->admin_user_id ) {
			delete_transient( DifmRestController::PENDING_LARGE_RANGE_PREFIX . $this->admin_user_id );
		}
		parent::tear_down();
	}

	// ── Route registration ────────────────────────────────────────────────────

	/**
	 * The chat route is registered and the unused browser key-validation route is not.
	 */
	public function test_routes_are_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/woocommerce-claude/v1/difm/chat', $routes );
		$this->assertArrayNotHasKey( '/woocommerce-claude/v1/difm/key/validate', $routes );
	}

	/**
	 * The DIFM tool allowlist must stay in sync with registered abilities.
	 */
	public function test_tool_allowlist_matches_registered_abilities() {
		$map = DifmRestController::get_tool_ability_map();

		$this->assertCount( 10, $map );
		$this->assertSame(
			array(
				'analytics_totals',
				'analytics_breakdown',
				'analytics_series',
				'analytics_rows',
				'get_product_details',
				'search_products',
				'get_store_profile',
				'get_readiness_score',
				'get_recommendations',
				'suggest_improvements',
			),
			array_keys( $map )
		);
		$this->assertArrayNotHasKey( 'confirm_large_range', $map );
		$this->assertArrayNotHasKey( 'get_revenue_summary', $map );
		$this->assertArrayNotHasKey( 'query_analytics', $map );

		foreach ( $map as $ability_id ) {
			$this->assertTrue( wp_has_ability( $ability_id ), "Missing registered ability: {$ability_id}" );
		}
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	/**
	 * Unauthenticated requests to POST /difm/chat receive a 403.
	 */
	public function test_chat_requires_manage_woocommerce() {
		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', 'Hello' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// ── POST /difm/chat — no key ──────────────────────────────────────────────

	/**
	 * POST /difm/chat returns {'status':'no_key'} when no API key is configured.
	 */
	public function test_chat_returns_no_key_when_unconfigured() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', 'Hello' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no_key', $data['status'] );
	}

	// ── POST /difm/chat — success ─────────────────────────────────────────────

	/**
	 * POST /difm/chat with a mocked Anthropic response returns {'status':'ok','reply':'...'}.
	 */
	public function test_chat_returns_reply_on_success() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

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
							'type'    => 'message',
							'content' => array(
								array(
									'type' => 'text',
									'text' => 'Hello! How can I help?',
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

		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', 'Hi there' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'Hello! How can I help?', $data['reply'] );
	}

	/**
	 * POST /difm/chat passes conversation history to the API.
	 */
	public function test_chat_passes_history_to_api() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

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
							'type'    => 'message',
							'content' => array(
								array(
									'type' => 'text',
									'text' => 'I see you asked before.',
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

		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', 'And now?' );
		$request->set_param(
			'history',
			array(
				array(
					'role'    => 'user',
					'content' => 'Previous question',
				),
				array(
					'role'    => 'assistant',
					'content' => 'Previous answer',
				),
			)
		);
		$this->server->dispatch( $request );

		remove_all_filters( 'pre_http_request' );

		// The API payload should contain all 3 messages (2 history + new user turn).
		$this->assertNotNull( $captured_body );
		$this->assertCount( 3, $captured_body['messages'] );
		$this->assertSame( 'And now?', $captured_body['messages'][2]['content'] );
	}

	/**
	 * Chat sends compact Anthropic tools instead of the full MCP descriptions.
	 */
	public function test_chat_sends_compact_tool_definitions_to_api() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$captured_body     = null;
		$captured_raw_body = '';
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args ) use ( &$captured_body, &$captured_raw_body ) {
				$captured_raw_body = isset( $parsed_args['body'] ) ? (string) $parsed_args['body'] : '';
				$captured_body     = json_decode( $captured_raw_body, true );
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
									'text' => 'Done.',
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

		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', 'What can you answer?' );
		$this->server->dispatch( $request );

		remove_all_filters( 'pre_http_request' );

		$this->assertNotNull( $captured_body );
		$this->assertArrayHasKey( 'tools', $captured_body );
		$this->assertCount( 11, $captured_body['tools'] );

		$tool_names = wp_list_pluck( $captured_body['tools'], 'name' );
		$this->assertNotContains( 'confirm_large_range', $tool_names );
		$this->assertNotContains( 'get_revenue_summary', $tool_names );
		$this->assertNotContains( 'query_analytics', $tool_names );
		$this->assertContains( 'analytics_totals', $tool_names );
		$this->assertContains( 'analytics_breakdown', $tool_names );
		$this->assertContains( 'analytics_series', $tool_names );
		$this->assertContains( 'analytics_rows', $tool_names );
		$this->assertContains( 'render_chart', $tool_names );
		$this->assertLessThan( 25000, strlen( $captured_raw_body ), 'The first Anthropic request must stay comfortably below low-tier token limits.' );
		$this->assertLessThan(
			6000,
			array_sum( array_map( 'strlen', wp_list_pluck( $captured_body['tools'], 'description' ) ) ),
			'Tool descriptions must stay compact; full MCP ability descriptions belong on the MCP surface only.'
		);

		$rows_tool = null;
		foreach ( $captured_body['tools'] as $tool ) {
			if ( 'analytics_rows' === $tool['name'] ) {
				$rows_tool = $tool;
				break;
			}
		}

		$this->assertNotNull( $rows_tool );
		$this->assertStringContainsString( 'Flexible filtered analytics', $rows_tool['description'] );
		$this->assertStringNotContainsString( 'ENTITIES + FIELD REGISTRIES', $rows_tool['description'] );
		$this->assertArrayHasKey( 'input_schema', $rows_tool );
		$this->assertArrayHasKey( 'filters', $rows_tool['input_schema']['properties'] );
		$this->assertArrayNotHasKey( 'description', $rows_tool['input_schema']['properties']['filters'] );
		$this->assertStringContainsString( '"name":"get_store_profile"', $captured_raw_body );
		$this->assertStringContainsString( '"input_schema":{"type":"object","properties":{}}', $captured_raw_body );
		$this->assertStringContainsString( 'analytics_series', $captured_body['system'] );
		$this->assertStringContainsString( 'last two weeks', $captured_body['system'] );
		$this->assertStringContainsString( 'not period=last_7_days', $captured_body['system'] );
	}

	// ── POST /difm/chat — history capping ────────────────────────────────────

	/**
	 * POST /difm/chat caps conversation history to MAX_HISTORY_TURNS * 2 messages.
	 */
	public function test_chat_caps_history_to_max_turns() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

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
									'text' => 'Done.',
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

		// Build 42 history items (21 user + 21 assistant pairs).
		$history = array();
		for ( $i = 0; $i < 42; $i++ ) {
			$history[] = array(
				'role'    => 0 === $i % 2 ? 'user' : 'assistant',
				'content' => "Message {$i}",
			);
		}

		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', 'Final question' );
		$request->set_param( 'history', $history );
		$this->server->dispatch( $request );

		remove_all_filters( 'pre_http_request' );

		// Expect 40 capped history messages + 1 new user message = 41 total.
		$this->assertNotNull( $captured_body );
		$this->assertCount( 41, $captured_body['messages'] );
		$this->assertSame( 'Final question', $captured_body['messages'][40]['content'] );
	}

	// ── POST /difm/chat — Anthropic error ────────────────────────────────────

	/**
	 * POST /difm/chat returns HTTP 200 {'status':'error','message':'...'} when
	 * Anthropic rejects the request, rather than an HTTP 500.
	 */
	public function test_chat_returns_json_error_on_anthropic_failure() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 400,
						'message' => 'Bad Request',
					),
					'body'     => wp_json_encode(
						array(
							'type'  => 'error',
							'error' => array( 'message' => 'Invalid request body.' ),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', 'Hello' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'error', $data['status'] );
		$this->assertArrayHasKey( 'message', $data );
	}

	// ── POST /difm/chat — tool use loop ──────────────────────────────────────

	/**
	 * POST /difm/chat executes a tool-use round-trip: first Anthropic call
	 * returns tool_use, PHP executes the tool, second call returns the final reply.
	 */
	public function test_chat_executes_tool_use_loop() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$call_count       = 0;
		$second_call_body = null;
		$handler          = new class() implements TelemetryHandlerInterface {
			/**
			 * Captured telemetry events.
			 *
			 * @var array<int, array{skill: string, data: array}>
			 */
			public $events = array();

			/**
			 * Capture the event dispatched through TelemetryHandler.
			 *
			 * @param string $skill_name Event name.
			 * @param array  $data       Telemetry payload.
			 */
			public function record( $skill_name, $data ) {
				$this->events[] = array(
					'skill' => $skill_name,
					'data'  => $data,
				);
			}
		};
		TelemetryHandler::add_handler( $handler );

		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args ) use ( &$call_count, &$second_call_body ) {
				++$call_count;

				if ( 1 === $call_count ) {
					// First call: Claude requests a tool.
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
										'id'    => 'toolu_01',
										'name'  => 'analytics_totals',
										'input' => array(
											'subject' => 'revenue',
											'period'  => 'last_7_days',
										),
									),
								),
							)
						),
						'headers'  => array(),
					);
				}

				// Second call: capture the body and return the final answer.
				$second_call_body = json_decode( $parsed_args['body'], true );
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
									'text' => 'Your revenue last week was great.',
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

		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', 'What were my sales last week?' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		// Two Anthropic calls were made.
		$this->assertSame( 2, $call_count );

		// The second call must include a tool_result in the message history.
		$this->assertNotNull( $second_call_body );
		$messages       = $second_call_body['messages'];
		$last_user_turn = end( $messages );
		$this->assertIsArray( $last_user_turn['content'] );
		$this->assertSame( 'tool_result', $last_user_turn['content'][0]['type'] );
		$this->assertSame( 'toolu_01', $last_user_turn['content'][0]['tool_use_id'] );

		// The final response is a successful reply.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'Your revenue last week was great.', $data['reply'] );

		$tool_call = $this->find_diagnostic_event( $handler->events, 'difm_tool_call', 'analytics_totals' );
		$this->assertNotNull( $tool_call );
		$this->assertSame( 'difm_tool_call', $tool_call['event'] );
		$this->assertSame( 'wc-analytics/totals', $tool_call['ability_id'] );
		$this->assertSame( 'revenue', $tool_call['subject'] );
		$this->assertSame( 'last_7_days', $tool_call['period'] );

		$tool_result = $this->find_diagnostic_event( $handler->events, 'difm_tool_result', 'analytics_totals' );
		$this->assertNotNull( $tool_result );
		$this->assertSame( 'difm_tool_result', $tool_result['event'] );
		$this->assertSame( 'ok', $tool_result['status'] );
		$this->assertArrayHasKey( 'output_bytes', $tool_result );
	}

	/**
	 * Text written before the final render_chart tool call is returned with the chart.
	 */
	public function test_chat_returns_text_and_chart_from_final_render_chart_call() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				if ( 1 === $call_count ) {
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
										'id'    => 'toolu_series',
										'name'  => 'analytics_series',
										'input' => array(
											'subject'  => 'revenue',
											'period'   => 'last_7_days',
											'interval' => 'day',
										),
									),
								),
							)
						),
						'headers'  => array(),
					);
				}

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
									'type' => 'text',
									'text' => 'Net sales were steady across the last week.',
								),
								array(
									'type'  => 'tool_use',
									'id'    => 'toolu_chart',
									'name'  => 'render_chart',
									'input' => array(
										'type'    => 'line',
										'title'   => 'Net Sales — Last 7 Days',
										'series'  => array(
											array(
												'name' => 'Net Sales',
												'data' => array(
													array(
														'x' => '2026-05-06',
														'y' => 12.5,
													),
													array(
														'x' => '2026-05-07',
														'y' => 18.0,
													),
												),
											),
										),
										'y_label' => 'Net sales',
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

		$response = $this->dispatch_chat( 'Show net sales by day for the last 7 days. Include a chart.' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 2, $call_count );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'Net sales were steady across the last week.', $data['reply'] );
		$this->assertArrayHasKey( 'charts', $data );
		$this->assertCount( 1, $data['charts'] );
		$this->assertSame( 'line', $data['charts'][0]['type'] );
		$this->assertSame( 'Net Sales — Last 7 Days', $data['charts'][0]['title'] );
		$this->assertSame( 18.0, $data['charts'][0]['series'][0]['data'][1]['y'] );
	}

	/**
	 * A chart-only model response still returns visible text instead of an empty bubble.
	 */
	public function test_chat_adds_fallback_text_when_render_chart_has_no_text() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				if ( 1 === $call_count ) {
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
										'id'    => 'toolu_chart_only',
										'name'  => 'render_chart',
										'input' => array(
											'type'   => 'line',
											'title'  => 'Net Sales — Last 7 Days',
											'series' => array(
												array(
													'name' => 'Net Sales',
													'data' => array(
														array(
															'x' => '2026-05-06',
															'y' => 12.5,
														),
													),
												),
											),
										),
									),
								),
							)
						),
						'headers'  => array(),
					);
				}

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'        => 'message',
							'stop_reason' => 'end_turn',
							'content'     => array(),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$response = $this->dispatch_chat( 'Show net sales by day for the last 7 days. Include a chart.' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 2, $call_count );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'I have added the Net Sales — Last 7 Days chart below.', $data['reply'] );
		$this->assertArrayHasKey( 'charts', $data );
		$this->assertSame( 'Net Sales — Last 7 Days', $data['charts'][0]['title'] );
	}

	/**
	 * A text answer that claims a chart exists must be retried until render_chart is called.
	 */
	public function test_chat_retries_when_reply_claims_chart_without_render_chart() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$call_count       = 0;
		$second_call_body = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args ) use ( &$call_count, &$second_call_body ) {
				++$call_count;

				if ( 1 === $call_count ) {
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
										'text' => 'The chart above shows Premium Speaker generated nearly 3x more than your next product.',
									),
								),
							)
						),
						'headers'  => array(),
					);
				}

				$second_call_body = json_decode( $parsed_args['body'], true );
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
									'type' => 'text',
									'text' => 'Premium Speaker leads product revenue, followed by Wireless Headphones.',
								),
								array(
									'type'  => 'tool_use',
									'id'    => 'toolu_product_chart',
									'name'  => 'render_chart',
									'input' => array(
										'type'   => 'bar',
										'title'  => 'Product Revenue Comparison',
										'series' => array(
											array(
												'name' => 'Revenue',
												'data' => array(
													array(
														'x' => 'Premium Speaker',
														'y' => 199.75,
													),
													array(
														'x' => 'Wireless Headphones',
														'y' => 79.95,
													),
												),
											),
										),
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

		$response = $this->dispatch_chat( 'Can you show this in a chart to compare against other products?' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 2, $call_count );
		$this->assertNotNull( $second_call_body );
		$messages        = $second_call_body['messages'];
		$last_user_turn  = end( $messages );
		$previous_answer = $messages[1];
		$this->assertStringContainsString( 'no render_chart tool call was made', $last_user_turn['content'] );
		$this->assertStringContainsString( 'The chart above shows', $previous_answer['content'][0]['text'] );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'Premium Speaker leads product revenue, followed by Wireless Headphones.', $data['reply'] );
		$this->assertArrayHasKey( 'charts', $data );
		$this->assertSame( 'Product Revenue Comparison', $data['charts'][0]['title'] );
	}

	// ── POST /difm/chat — large-range confirmation ───────────────────────────

	/**
	 * A large-range tool error stops the current tool loop and asks for approval.
	 */
	public function test_large_range_tool_error_returns_confirmation_without_second_anthropic_call() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;
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
									'id'    => 'toolu_large',
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

		$response = $this->dispatch_chat( 'Show product performance since 2024.' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $call_count );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertStringContainsString( 'yes, proceed', $data['reply'] );

		$pending = get_transient( DifmRestController::PENDING_LARGE_RANGE_PREFIX . $this->admin_user_id );
		$this->assertIsArray( $pending );
		$this->assertSame( 'analytics_series', $pending['tool_name'] );
		$this->assertSame( 'wc-analytics/series', $pending['ability_id'] );
		$this->assertSame( 'products', $pending['input']['subject'] );
		$this->assertArrayHasKey( 'cost_estimate', $pending['error_data'] );
	}

	/**
	 * A following affirmative merchant reply executes the stored request server-side.
	 */
	public function test_affirmative_large_range_reply_executes_pending_request() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();
		$this->create_pending_large_range_request();

		$call_count    = 0;
		$captured_body = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args ) use ( &$call_count, &$captured_body ) {
				++$call_count;
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
									'text' => 'Here is the full range.',
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

		$this->assertSame( 1, $call_count );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'Here is the full range.', $data['reply'] );
		$this->assertFalse( get_transient( DifmRestController::PENDING_LARGE_RANGE_PREFIX . $this->admin_user_id ) );
		$this->assertNotNull( $captured_body );
		$this->assertArrayHasKey( 'tools', $captured_body );
		$this->assertSame( array( 'render_chart' ), wp_list_pluck( $captured_body['tools'], 'name' ) );
		$messages     = $captured_body['messages'];
		$last_message = end( $messages );
		$this->assertStringContainsString( 'explicitly confirmed', $last_message['content'] );
	}

	/**
	 * A confirmed large-range answer can return a chart as its final action.
	 */
	public function test_affirmative_large_range_reply_can_return_chart() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();
		$this->create_pending_large_range_request();

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;
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
									'type' => 'text',
									'text' => 'Here is the full product trend.',
								),
								array(
									'type'  => 'tool_use',
									'id'    => 'toolu_large_chart',
									'name'  => 'render_chart',
									'input' => array(
										'type'   => 'bar',
										'title'  => 'Product Performance',
										'series' => array(
											array(
												'name' => 'Net Sales',
												'data' => array(
													array(
														'x' => 'Hoodie',
														'y' => 42,
													),
												),
											),
										),
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

		$response = $this->dispatch_chat( 'yes, proceed' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $call_count );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'Here is the full product trend.', $data['reply'] );
		$this->assertArrayHasKey( 'charts', $data );
		$this->assertSame( 'Product Performance', $data['charts'][0]['title'] );
		$this->assertFalse( get_transient( DifmRestController::PENDING_LARGE_RANGE_PREFIX . $this->admin_user_id ) );
	}

	/**
	 * Negative and ambiguous replies do not execute the pending request.
	 */
	public function test_negative_large_range_reply_does_not_execute_pending_request() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();
		$this->create_pending_large_range_request();

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;
				return new \WP_Error( 'unexpected_http_call', 'Unexpected Anthropic call.' );
			},
			10,
			3
		);

		$response = $this->dispatch_chat( 'no thanks' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 0, $call_count );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertStringContainsString( 'will not load', $data['reply'] );
		$this->assertFalse( get_transient( DifmRestController::PENDING_LARGE_RANGE_PREFIX . $this->admin_user_id ) );
	}

	/**
	 * Ambiguous replies preserve the pending request and ask for explicit consent.
	 */
	public function test_ambiguous_large_range_reply_preserves_pending_request() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();
		$this->create_pending_large_range_request();

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;
				return new \WP_Error( 'unexpected_http_call', 'Unexpected Anthropic call.' );
			},
			10,
			3
		);

		$response = $this->dispatch_chat( 'what does that mean?' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 0, $call_count );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertStringContainsString( 'explicit confirmation', $data['reply'] );
		$this->assertIsArray( get_transient( DifmRestController::PENDING_LARGE_RANGE_PREFIX . $this->admin_user_id ) );
	}

	/**
	 * Set a store-admin user for the current test.
	 *
	 * @return void
	 */
	private function set_admin_user() {
		$this->admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	/**
	 * Dispatch a chat request.
	 *
	 * @param string $message Merchant message.
	 * @param array  $history Optional chat history.
	 * @return \WP_REST_Response
	 */
	private function dispatch_chat( $message, array $history = array() ) {
		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/chat' );
		$request->set_param( 'message', $message );
		if ( ! empty( $history ) ) {
			$request->set_param( 'history', $history );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Find a diagnostic telemetry event by event name and optional tool.
	 *
	 * @param array  $events Captured diagnostic events.
	 * @param string $event  Event name.
	 * @param string $tool   Optional tool name.
	 * @return array|null
	 */
	private function find_diagnostic_event( array $events, $event, $tool = '' ) {
		foreach ( $events as $entry ) {
			if ( ! is_array( $entry ) || ( $entry['skill'] ?? '' ) !== $event ) {
				continue;
			}

			$data = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
			if ( '' !== $tool && ( $data['tool'] ?? '' ) !== $tool ) {
				continue;
			}

			return $data;
		}

		return null;
	}

	/**
	 * Create a pending large-range request by letting Claude request a gated tool.
	 *
	 * @return void
	 */
	private function create_pending_large_range_request() {
		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;
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
									'id'    => 'toolu_large',
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

		$response = $this->dispatch_chat( 'Show product performance since 2024.' );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 1, $call_count );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertIsArray( get_transient( DifmRestController::PENDING_LARGE_RANGE_PREFIX . $this->admin_user_id ) );
	}
}
