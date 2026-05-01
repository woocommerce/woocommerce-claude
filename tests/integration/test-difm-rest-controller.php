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
}
