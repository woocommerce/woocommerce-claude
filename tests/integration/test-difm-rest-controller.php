<?php
/**
 * Integration tests for DifmRestController.
 *
 * @package HeyWoo\Tests
 */

use HeyWoo\Difm\DifmRestController;

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
		parent::tear_down();
		delete_option( 'hey_woo_anthropic_api_key' );
	}

	// ── Route registration ────────────────────────────────────────────────────

	/**
	 * Both DIFM routes are registered.
	 */
	public function test_routes_are_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/hey-woo/v1/difm/chat', $routes );
		$this->assertArrayHasKey( '/hey-woo/v1/difm/key/validate', $routes );
	}

	// ── Permission callbacks ──────────────────────────────────────────────────

	/**
	 * Unauthenticated requests to POST /difm/chat receive a 403.
	 */
	public function test_chat_requires_manage_woocommerce() {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/chat' );
		$request->set_param( 'message', 'Hello' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Unauthenticated requests to POST /difm/key/validate receive a 403.
	 */
	public function test_validate_key_requires_manage_woocommerce() {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/key/validate' );
		$request->set_param( 'key', 'sk-ant-test' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	// ── POST /difm/chat — no key ──────────────────────────────────────────────

	/**
	 * POST /difm/chat returns {'status':'no_key'} when no API key is configured.
	 */
	public function test_chat_returns_no_key_when_unconfigured() {
		delete_option( 'hey_woo_anthropic_api_key' );

		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/chat' );
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
		update_option( 'hey_woo_anthropic_api_key', 'sk-ant-test' );
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

		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/chat' );
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
		update_option( 'hey_woo_anthropic_api_key', 'sk-ant-test' );
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

		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/chat' );
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

	// ── POST /difm/key/validate ───────────────────────────────────────────────

	/**
	 * POST /difm/key/validate with a mocked successful Anthropic response
	 * returns {'valid':true}.
	 */
	public function test_validate_key_returns_valid_true_on_success() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( array( 'type' => 'message' ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/key/validate' );
		$request->set_param( 'key', 'sk-ant-valid' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['valid'] );
	}

	/**
	 * POST /difm/key/validate with a 401 Anthropic response returns
	 * {'valid':false, 'message':'...'}.
	 */
	public function test_validate_key_returns_valid_false_on_401() {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 401,
						'message' => 'Unauthorized',
					),
					'body'     => wp_json_encode(
						array(
							'type'  => 'error',
							'error' => array( 'message' => 'Invalid API key.' ),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/key/validate' );
		$request->set_param( 'key', 'sk-ant-bad' );
		$response = $this->server->dispatch( $request );
		$data     = $response->get_data();

		remove_all_filters( 'pre_http_request' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $data['valid'] );
		$this->assertArrayHasKey( 'message', $data );
	}

	// ── POST /difm/chat — history capping ────────────────────────────────────

	/**
	 * POST /difm/chat caps conversation history to MAX_HISTORY_TURNS * 2 messages.
	 */
	public function test_chat_caps_history_to_max_turns() {
		update_option( 'hey_woo_anthropic_api_key', 'sk-ant-test' );
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

		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/chat' );
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
		update_option( 'hey_woo_anthropic_api_key', 'sk-ant-test' );
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

		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/chat' );
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
		update_option( 'hey_woo_anthropic_api_key', 'sk-ant-test' );
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );

		$call_count       = 0;
		$second_call_body = null;

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
										'name'  => 'get_revenue_summary',
										'input' => array( 'period' => 'last_7_days' ),
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

		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/chat' );
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
	}
}
