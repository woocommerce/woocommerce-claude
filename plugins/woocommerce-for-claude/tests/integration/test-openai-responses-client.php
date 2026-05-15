<?php
/**
 * Integration tests for OpenAIResponsesClient.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\OpenAIResponsesClient;
use WooCommerce\Claude\Telemetry\TelemetryHandler;
use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

/**
 * Tests for OpenAIResponsesClient.
 */
class Test_OpenAI_Responses_Client extends WP_UnitTestCase {

	/**
	 * Restore global state after each test.
	 */
	public function tear_down() {
		parent::tear_down();
		remove_all_filters( 'pre_http_request' );
		delete_option( OpenAIResponsesClient::API_KEY_OPTION );
	}

	/**
	 * Messages() returns WP_Error when no key is configured.
	 */
	public function test_messages_returns_wp_error_without_key() {
		$client = new OpenAIResponsesClient();
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

	/**
	 * Messages() sends the expected Responses API request shape.
	 */
	public function test_messages_sends_responses_request_shape() {
		update_option( OpenAIResponsesClient::API_KEY_OPTION, 'sk-openai-test' );

		$captured = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$captured ) {
				unset( $preempt );
				$captured = array(
					'args' => $args,
					'url'  => $url,
				);

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'id'          => 'resp_123',
							'output_text' => 'Hello from OpenAI.',
							'usage'       => array(
								'input_tokens'  => 12,
								'output_tokens' => 3,
							),
						)
					),
					'headers'  => array( 'x-request-id' => 'req_openai_123' ),
				);
			},
			10,
			3
		);

		$client = new OpenAIResponsesClient();
		$result = $client->messages(
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
						'properties' => array(
							'subject' => array( 'type' => 'string' ),
						),
					),
				),
			),
			123
		);

		$body = json_decode( $captured['args']['body'], true );

		$this->assertIsArray( $result );
		$this->assertSame( OpenAIResponsesClient::API_BASE . '/responses', $captured['url'] );
		$this->assertSame( 'Bearer sk-openai-test', $captured['args']['headers']['authorization'] );
		$this->assertSame( OpenAIResponsesClient::MODEL, $body['model'] );
		$this->assertSame( 'System prompt', $body['instructions'] );
		$this->assertSame( 123, $body['max_output_tokens'] );
		$this->assertFalse( $body['store'] );
		$this->assertSame( 'user', $body['input'][0]['role'] );
		$this->assertSame( 'How much did I sell today?', $body['input'][0]['content'] );
		$this->assertSame( 'function', $body['tools'][0]['type'] );
		$this->assertSame( 'analytics_totals', $body['tools'][0]['name'] );
		$this->assertSame( 'Hello from OpenAI.', $result['content'][0]['text'] );
		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'req_openai_123', $result['provider_request_id'] );
	}

	/**
	 * Function calls are normalised into provider-neutral tool_use blocks.
	 */
	public function test_function_call_response_is_normalised_to_tool_use() {
		update_option( OpenAIResponsesClient::API_KEY_OPTION, 'sk-openai-test' );

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
							'id'     => 'resp_tool',
							'output' => array(
								array(
									'type'      => 'function_call',
									'call_id'   => 'call_123',
									'name'      => 'analytics_totals',
									'arguments' => wp_json_encode( array( 'subject' => 'revenue' ) ),
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

		$result = ( new OpenAIResponsesClient() )->messages(
			array(
				array(
					'role'    => 'user',
					'content' => 'Revenue today?',
				),
			)
		);

		$this->assertSame( 'tool_use', $result['stop_reason'] );
		$this->assertSame( 'tool_use', $result['content'][0]['type'] );
		$this->assertSame( 'call_123', $result['content'][0]['id'] );
		$this->assertSame( 'analytics_totals', $result['content'][0]['name'] );
		$this->assertSame( array( 'subject' => 'revenue' ), $result['content'][0]['input'] );
	}

	/**
	 * Tool results are sent as function_call_output continuation items.
	 */
	public function test_tool_results_are_sent_as_function_call_outputs() {
		update_option( OpenAIResponsesClient::API_KEY_OPTION, 'sk-openai-test' );

		$captured_body = array();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$captured_body ) {
				unset( $preempt );
				$captured_body = json_decode( $args['body'], true );

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( array( 'output_text' => 'Done.' ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		( new OpenAIResponsesClient() )->messages(
			array(
				array(
					'role'    => 'assistant',
					'content' => array(
						array(
							'type'  => 'tool_use',
							'id'    => 'call_123',
							'name'  => 'analytics_totals',
							'input' => array( 'subject' => 'revenue' ),
						),
					),
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'        => 'tool_result',
							'tool_use_id' => 'call_123',
							'content'     => '{"ok":true}',
						),
					),
				),
			)
		);

		$this->assertSame( 'function_call', $captured_body['input'][0]['type'] );
		$this->assertSame( 'call_123', $captured_body['input'][0]['call_id'] );
		$this->assertSame( 'function_call_output', $captured_body['input'][1]['type'] );
		$this->assertSame( 'call_123', $captured_body['input'][1]['call_id'] );
		$this->assertSame( '{"ok":true}', $captured_body['input'][1]['output'] );
	}

	/**
	 * OpenAI API errors are returned as WP_Error.
	 */
	public function test_messages_returns_wp_error_on_api_error() {
		update_option( OpenAIResponsesClient::API_KEY_OPTION, 'sk-openai-test' );

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
							'error' => array(
								'type'    => 'invalid_request_error',
								'message' => 'Bad request.',
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = ( new OpenAIResponsesClient() )->messages(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'openai_error', $result->get_error_code() );
		$this->assertSame( 'Bad request.', $result->get_error_message() );
	}

	/**
	 * Validate_key() returns true on a 200 response and invalid_key on 401.
	 */
	public function test_validate_key() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode( array( 'output_text' => 'h' ) ),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$this->assertTrue( OpenAIResponsesClient::validate_key( 'sk-valid' ) );

		remove_all_filters( 'pre_http_request' );
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
							'error' => array(
								'type'    => 'authentication_error',
								'message' => 'Invalid key.',
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$result = OpenAIResponsesClient::validate_key( 'sk-invalid' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_key', $result->get_error_code() );
	}

	/**
	 * Response telemetry includes provider, usage, and OpenAI request ID.
	 */
	public function test_messages_dispatches_request_id_telemetry() {
		update_option( OpenAIResponsesClient::API_KEY_OPTION, 'sk-openai-test' );

		$handler = new class() implements TelemetryHandlerInterface {
			/**
			 * Captured telemetry events.
			 *
			 * @var array<int, array{skill: string, data: array}>
			 */
			public $events = array();

			/**
			 * Capture telemetry.
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
							'output_text' => 'Hello.',
							'usage'       => array(
								'input_tokens'  => 5,
								'output_tokens' => 2,
								'total_tokens'  => 7,
							),
						)
					),
					'headers'  => array( 'x-request-id' => 'req_openai_telemetry' ),
				);
			},
			10,
			3
		);

		( new OpenAIResponsesClient() )->messages(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);

		$this->assertSame( 'difm_ai_request', $handler->events[0]['skill'] );
		$this->assertSame( 'openai', $handler->events[0]['data']['provider'] );
		$this->assertArrayNotHasKey( 'api_key', $handler->events[0]['data'] );
		$this->assertSame( 'difm_ai_response', $handler->events[1]['skill'] );
		$this->assertSame( 'openai', $handler->events[1]['data']['provider'] );
		$this->assertSame( 'req_openai_telemetry', $handler->events[1]['data']['provider_request_id'] );
		$this->assertSame( 5, $handler->events[1]['data']['usage_input_tokens'] );
		$this->assertSame( 2, $handler->events[1]['data']['usage_output_tokens'] );
		$this->assertSame( 7, $handler->events[1]['data']['usage_total_tokens'] );
	}
}
