<?php
/**
 * Integration tests for AnthropicClient.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\AnthropicClient;
use WooCommerce\Claude\Telemetry\TelemetryHandler;
use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

/**
 * Tests for AnthropicClient.
 */
class Test_Anthropic_Client extends WP_UnitTestCase {

	/**
	 * Restore global state after each test.
	 */
	public function tear_down() {
		parent::tear_down();
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		delete_option( 'hey_woo_anthropic_api_key' );
	}

	// ── get_api_key() priority chain ──────────────────────────────────────────

	/**
	 * Returns empty string when no key is configured.
	 */
	public function test_get_api_key_returns_empty_string_by_default() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		delete_option( 'hey_woo_anthropic_api_key' );
		$this->assertSame( '', AnthropicClient::get_api_key() );
	}

	/**
	 * Returns the option value when no constant is defined.
	 */
	public function test_get_api_key_returns_option_value() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-option-key' );
		$this->assertSame( 'sk-ant-option-key', AnthropicClient::get_api_key() );
	}

	/**
	 * Hey Woo owns its own BYOK option; WooCommerce for Claude must not use it.
	 */
	public function test_get_api_key_ignores_hey_woo_option_value() {
		update_option( 'hey_woo_anthropic_api_key', 'sk-ant-hey-woo-key' );
		$this->assertSame( '', AnthropicClient::get_api_key() );
	}

	/**
	 * Asserts has_api_key() returns false when no key is set.
	 */
	public function test_has_api_key_returns_false_without_key() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		delete_option( 'hey_woo_anthropic_api_key' );
		$this->assertFalse( AnthropicClient::has_api_key() );
	}

	/**
	 * Asserts has_api_key() returns true when the option is set.
	 */
	public function test_has_api_key_returns_true_with_option() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->assertTrue( AnthropicClient::has_api_key() );
	}

	// ── messages() — no API key ───────────────────────────────────────────────

	/**
	 * Asserts messages() returns WP_Error when no key is configured.
	 */
	public function test_messages_returns_wp_error_without_key() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		delete_option( 'hey_woo_anthropic_api_key' );
		$client = new AnthropicClient();
		$result = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_api_key', $result->get_error_code() );
	}

	// ── messages() — mocked HTTP ──────────────────────────────────────────────

	/**
	 * Asserts messages() returns the decoded response on a 200 success.
	 */
	public function test_messages_returns_decoded_response_on_success() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );

		$mock_response = array(
			'id'      => 'msg_123',
			'type'    => 'message',
			'content' => array(
				array(
					'type' => 'text',
					'text' => 'Hello!',
				),
			),
		);

		add_filter(
			'pre_http_request',
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- pre_http_request args; we only need the closure's return value.
			static function ( $preempt, $args, $url ) use ( $mock_response ) {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( $mock_response ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client = new AnthropicClient();
		$result = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertIsArray( $result );
		$this->assertSame( 'msg_123', $result['id'] );
	}

	/**
	 * Asserts messages() returns WP_Error when Anthropic returns an error type.
	 */
	public function test_messages_returns_wp_error_on_anthropic_error_type() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );

		$error_body = array(
			'type'  => 'error',
			'error' => array(
				'type'    => 'authentication_error',
				'message' => 'Invalid API key.',
			),
		);

		add_filter(
			'pre_http_request',
			static function () use ( $error_body ) {
				return array(
					'response' => array(
						'code'    => 401,
						'message' => 'Unauthorized',
					),
					'body'     => wp_json_encode( $error_body ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client = new AnthropicClient();
		$result = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'anthropic_error', $result->get_error_code() );
		$this->assertSame( 'Invalid API key.', $result->get_error_message() );
	}

	/**
	 * Messages emits redacted request diagnostics and actual token usage.
	 */
	public function test_messages_dispatches_request_metadata_and_actual_usage() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );

		$handler = new class() implements TelemetryHandlerInterface {
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
			static function () {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'      => 'msg_usage',
							'type'    => 'message',
							'content' => array(),
							'usage'   => array(
								'input_tokens'  => 42,
								'output_tokens' => 7,
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$client = new AnthropicClient();
		$client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => 'How much did I sell today?',
				),
			),
			'System prompt',
			array(
				array(
					'name'         => 'analytics_totals',
					'description'  => 'Totals tool.',
					'input_schema' => array(
						'type'       => 'object',
						'properties' => array(),
					),
				),
			),
			123,
			array(
				'surface'   => 'test',
				'iteration' => 2,
				'api_key'   => 'sk-ant-redacted',
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertGreaterThanOrEqual( 2, count( $handler->events ) );
		$this->assertSame( 'anthropic_request', $handler->events[0]['skill'] );
		$this->assertSame( 'anthropic_request', $handler->events[0]['data']['event'] );
		$this->assertSame( 'test', $handler->events[0]['data']['surface'] );
		$this->assertSame( 2, $handler->events[0]['data']['iteration'] );
		$this->assertSame( 'analytics_totals', $handler->events[0]['data']['tool_names'] );
		$this->assertGreaterThan( 0, (int) $handler->events[0]['data']['body_bytes'] );
		$this->assertArrayNotHasKey( 'api_key', $handler->events[0]['data'] );
		$this->assertArrayNotHasKey( 'estimated_input_tokens', $handler->events[0]['data'] );
		$this->assertArrayNotHasKey( 'estimated_total_tokens_if_full_out', $handler->events[0]['data'] );
		$this->assertArrayNotHasKey( 'max_output_tokens', $handler->events[0]['data'] );

		$this->assertSame( 'anthropic_response', $handler->events[1]['skill'] );
		$this->assertSame( 'anthropic_response', $handler->events[1]['data']['event'] );
		$this->assertSame( $handler->events[0]['data']['request_id'], $handler->events[1]['data']['request_id'] );
		$this->assertSame( 200, $handler->events[1]['data']['status_code'] );
		$this->assertSame( 42, $handler->events[1]['data']['usage_input_tokens'] );
		$this->assertSame( 7, $handler->events[1]['data']['usage_output_tokens'] );
	}

	// ── validate_key() — mocked HTTP ──────────────────────────────────────────

	/**
	 * Asserts validate_key() returns WP_Error for an empty key.
	 */
	public function test_validate_key_returns_error_for_empty_key() {
		$result = AnthropicClient::validate_key( '' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'empty_key', $result->get_error_code() );
	}

	/**
	 * Asserts validate_key() returns true on a 200 response.
	 */
	public function test_validate_key_returns_true_on_200() {
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
							'content' => array(),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = AnthropicClient::validate_key( 'sk-ant-valid' );

		remove_all_filters( 'pre_http_request' );

		$this->assertTrue( $result );
	}

	/**
	 * Asserts validate_key() returns WP_Error on a 401 response.
	 */
	public function test_validate_key_returns_error_on_401() {
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
							'error' => array( 'message' => 'Bad key.' ),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = AnthropicClient::validate_key( 'sk-ant-bad' );

		remove_all_filters( 'pre_http_request' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_key', $result->get_error_code() );
	}
}
