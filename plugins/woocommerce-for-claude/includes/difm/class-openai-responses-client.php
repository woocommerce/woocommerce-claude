<?php
/**
 * OpenAI Responses API client for WooCommerce for Claude AI Insights.
 *
 * @package WooCommerce\Claude\Difm
 */

namespace WooCommerce\Claude\Difm;

use WooCommerce\Claude\Telemetry\DifmAiTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI Responses API client.
 *
 * PHP 7.4 compatible - no union types, no match, no enums.
 */
class OpenAIResponsesClient implements DifmAiClientInterface {

	/**
	 * OpenAI API base URL.
	 */
	const API_BASE = 'https://api.openai.com/v1';

	/**
	 * Default model used for direct OpenAI AI Insights calls.
	 *
	 * Override via the `woocommerce_claude_difm_openai_model` filter.
	 */
	const MODEL = 'gpt-5.4-mini';

	/**
	 * Option name that stores the merchant's direct OpenAI API key.
	 */
	const API_KEY_OPTION = 'woocommerce_claude_openai_api_key';

	/**
	 * Server constant for the direct OpenAI key.
	 */
	const API_KEY_CONSTANT = 'WOOCOMMERCE_CLAUDE_OPENAI_KEY';

	/**
	 * HTTP request timeout in seconds.
	 */
	const REQUEST_TIMEOUT = 90;

	/**
	 * Return the OpenAI API key in use.
	 *
	 * @return string Empty string when no key is configured.
	 */
	public static function get_api_key() {
		if ( defined( self::API_KEY_CONSTANT ) ) {
			return (string) constant( self::API_KEY_CONSTANT );
		}

		return (string) get_option( self::API_KEY_OPTION, '' );
	}

	/**
	 * Whether an OpenAI API key is currently configured.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== self::get_api_key();
	}

	/**
	 * Return the configured model.
	 *
	 * @return string
	 */
	public function get_model() {
		/**
		 * Filter the OpenAI model used for WooCommerce for Claude AI Insights.
		 *
		 * @since 0.5.0
		 *
		 * @param string $model OpenAI model identifier.
		 */
		return (string) apply_filters( 'woocommerce_claude_difm_openai_model', self::MODEL );
	}

	/**
	 * Call the OpenAI Responses API.
	 *
	 * @param array  $messages   Conversation messages.
	 * @param string $system     System prompt.
	 * @param array  $tools      Provider-neutral tool definitions.
	 * @param int    $max_tokens Maximum output tokens.
	 * @param array  $context    Optional diagnostic context for logging.
	 * @return array|\WP_Error Normalised response array, or WP_Error on failure.
	 */
	public function messages( array $messages, $system = '', array $tools = array(), $max_tokens = 4096, array $context = array() ) {
		$api_key = self::get_api_key();
		if ( '' === $api_key ) {
			return new \WP_Error( 'no_api_key', __( 'No OpenAI API key is configured.', 'woocommerce-claude' ) );
		}

		$model = $this->get_model();
		$body  = array(
			'model'             => $model,
			'input'             => $this->build_responses_input( $messages ),
			'max_output_tokens' => $max_tokens,
			'store'             => false,
		);

		if ( '' !== $system ) {
			$body['instructions'] = $system;
		}

		if ( ! empty( $tools ) ) {
			$body['tools'] = $this->convert_tools( $tools );
		}

		$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'difm_', true );
		$body_json  = wp_json_encode( $body );
		$body_json  = is_string( $body_json ) ? $body_json : '';
		$start_ms   = microtime( true );

		DifmAiTelemetry::record_request( 'openai', $model, $body, $body_json, $request_id, $context );

		$response = wp_remote_post(
			self::API_BASE . '/responses',
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'authorization' => 'Bearer ' . $api_key,
					'content-type'  => 'application/json',
				),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			DifmAiTelemetry::record_transport_error(
				'openai',
				$model,
				$request_id,
				(int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				$response->get_error_code(),
				$context
			);
			return $response;
		}

		$status_code         = (int) wp_remote_retrieve_response_code( $response );
		$raw_body            = wp_remote_retrieve_body( $response );
		$decoded_body        = json_decode( $raw_body, true );
		$duration_ms         = (int) round( ( microtime( true ) - $start_ms ) * 1000 );
		$provider_request_id = (string) wp_remote_retrieve_header( $response, 'x-request-id' );
		$telemetry_body      = is_array( $decoded_body ) ? $decoded_body : array();

		if ( '' !== $provider_request_id ) {
			$telemetry_body['provider_request_id'] = $provider_request_id;
		}

		DifmAiTelemetry::record_response( 'openai', $model, $request_id, $status_code, $duration_ms, $telemetry_body, $raw_body );

		if ( ! is_array( $decoded_body ) ) {
			return new \WP_Error(
				'invalid_response',
				__( 'OpenAI returned an unexpected response format.', 'woocommerce-claude' )
			);
		}

		if ( isset( $decoded_body['error'] ) && is_array( $decoded_body['error'] ) ) {
			$error_message = isset( $decoded_body['error']['message'] )
				? (string) $decoded_body['error']['message']
				: __( 'Unknown OpenAI error.', 'woocommerce-claude' );

			return new \WP_Error(
				'openai_error',
				$error_message,
				array( 'status' => $status_code )
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new \WP_Error(
				'http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'OpenAI API returned HTTP %d.', 'woocommerce-claude' ),
					$status_code
				),
				array( 'status' => $status_code )
			);
		}

		return $this->normalise_response( $decoded_body, $model, $provider_request_id );
	}

	/**
	 * Validate an API key with a minimal Responses API call.
	 *
	 * Does not persist the key. Returns true on success, WP_Error on failure.
	 *
	 * @param string $key OpenAI API key to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return new \WP_Error( 'empty_key', __( 'API key must not be empty.', 'woocommerce-claude' ) );
		}

		$response = wp_remote_post(
			self::API_BASE . '/responses',
			array(
				'timeout' => 15,
				'headers' => array(
					'authorization' => 'Bearer ' . $key,
					'content-type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'             => self::MODEL,
						'input'             => 'hi',
						'max_output_tokens' => 1,
						'store'             => false,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code  = (int) wp_remote_retrieve_response_code( $response );
		$raw_body     = wp_remote_retrieve_body( $response );
		$decoded_body = json_decode( $raw_body, true );

		if ( 401 === $status_code ) {
			return new \WP_Error(
				'invalid_key',
				__( 'Invalid API key - OpenAI returned 401.', 'woocommerce-claude' )
			);
		}

		if ( is_array( $decoded_body ) && isset( $decoded_body['error'] ) && is_array( $decoded_body['error'] ) ) {
			$error_message = isset( $decoded_body['error']['message'] )
				? (string) $decoded_body['error']['message']
				: __( 'Unknown OpenAI error.', 'woocommerce-claude' );

			return new \WP_Error( 'openai_error', $error_message );
		}

		if ( $status_code >= 200 && $status_code < 300 ) {
			return true;
		}

		return new \WP_Error(
			'http_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'OpenAI API returned HTTP %d.', 'woocommerce-claude' ),
				$status_code
			)
		);
	}

	/**
	 * Convert controller messages to Responses API input items.
	 *
	 * @param array $messages Provider-neutral conversation messages.
	 * @return array
	 */
	private function build_responses_input( array $messages ) {
		$input = array();

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) ) {
				continue;
			}

			$role    = isset( $message['role'] ) ? (string) $message['role'] : 'user';
			$content = isset( $message['content'] ) ? $message['content'] : '';

			if ( is_array( $content ) ) {
				$input = array_merge( $input, $this->blocks_to_responses_input( $role, $content ) );
				continue;
			}

			$input[] = array(
				'role'    => 'assistant' === $role ? 'assistant' : 'user',
				'content' => (string) $content,
			);
		}

		return $input;
	}

	/**
	 * Convert content blocks to Responses API input items.
	 *
	 * @param string $role    Message role.
	 * @param array  $content Content blocks.
	 * @return array
	 */
	private function blocks_to_responses_input( $role, array $content ) {
		$input      = array();
		$text_parts = array();

		foreach ( $content as $block ) {
			if ( ! is_array( $block ) || ! isset( $block['type'] ) ) {
				continue;
			}

			if ( 'text' === $block['type'] ) {
				$text_parts[] = isset( $block['text'] ) ? (string) $block['text'] : '';
				continue;
			}

			if ( 'tool_use' === $block['type'] ) {
				$arguments = isset( $block['input'] ) && is_array( $block['input'] ) ? $block['input'] : array();
				$input[]   = array(
					'type'      => 'function_call',
					'call_id'   => isset( $block['id'] ) ? (string) $block['id'] : '',
					'name'      => isset( $block['name'] ) ? (string) $block['name'] : '',
					'arguments' => wp_json_encode( (object) $arguments ),
				);
				continue;
			}

			if ( 'tool_result' === $block['type'] ) {
				$input[] = array(
					'type'    => 'function_call_output',
					'call_id' => isset( $block['tool_use_id'] ) ? (string) $block['tool_use_id'] : '',
					'output'  => isset( $block['content'] ) ? (string) $block['content'] : '',
				);
			}
		}

		if ( ! empty( $text_parts ) ) {
			array_unshift(
				$input,
				array(
					'role'    => 'assistant' === $role ? 'assistant' : 'user',
					'content' => trim( implode( "\n\n", $text_parts ) ),
				)
			);
		}

		return $input;
	}

	/**
	 * Convert provider-neutral tools to OpenAI function tools.
	 *
	 * @param array $tools Provider-neutral tool definitions.
	 * @return array
	 */
	private function convert_tools( array $tools ) {
		$converted = array();

		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) ) {
				continue;
			}

			$parameters = isset( $tool['input_schema'] ) && is_array( $tool['input_schema'] )
				? $tool['input_schema']
				: array(
					'type'       => 'object',
					'properties' => (object) array(),
				);

			$converted[] = array(
				'type'        => 'function',
				'name'        => (string) $tool['name'],
				'description' => isset( $tool['description'] ) ? (string) $tool['description'] : '',
				'parameters'  => $parameters,
			);
		}

		return $converted;
	}

	/**
	 * Normalise a Responses API response into the controller contract.
	 *
	 * @param array  $response            Decoded OpenAI response.
	 * @param string $model               Model used for the request.
	 * @param string $provider_request_id OpenAI request ID header.
	 * @return array
	 */
	private function normalise_response( array $response, $model, $provider_request_id = '' ) {
		$content   = array();
		$tool_used = false;

		if ( isset( $response['output'] ) && is_array( $response['output'] ) ) {
			foreach ( $response['output'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				if ( isset( $item['type'] ) && 'function_call' === $item['type'] ) {
					$content[] = $this->normalise_function_call( $item );
					$tool_used = true;
					continue;
				}

				if ( isset( $item['type'] ) && 'message' === $item['type'] ) {
					$content = array_merge( $content, $this->normalise_message_item( $item ) );
				}
			}
		}

		if ( empty( $content ) && isset( $response['output_text'] ) && '' !== (string) $response['output_text'] ) {
			$content[] = array(
				'type' => 'text',
				'text' => (string) $response['output_text'],
			);
		}

		return array(
			'type'                => 'message',
			'content'             => $content,
			'stop_reason'         => $tool_used ? 'tool_use' : 'end_turn',
			'usage'               => isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array(),
			'provider'            => 'openai',
			'model'               => $model,
			'provider_request_id' => $provider_request_id,
		);
	}

	/**
	 * Normalise a message output item.
	 *
	 * @param array $item Responses API message item.
	 * @return array
	 */
	private function normalise_message_item( array $item ) {
		$content = array();

		if ( ! isset( $item['content'] ) || ! is_array( $item['content'] ) ) {
			return $content;
		}

		foreach ( $item['content'] as $part ) {
			if ( ! is_array( $part ) ) {
				continue;
			}

			if ( isset( $part['type'] ) && in_array( $part['type'], array( 'output_text', 'text' ), true ) ) {
				$content[] = array(
					'type' => 'text',
					'text' => isset( $part['text'] ) ? (string) $part['text'] : '',
				);
			}
		}

		return $content;
	}

	/**
	 * Normalise a function_call output item.
	 *
	 * @param array $item Responses API function_call item.
	 * @return array
	 */
	private function normalise_function_call( array $item ) {
		$arguments = isset( $item['arguments'] ) ? json_decode( (string) $item['arguments'], true ) : array();
		if ( ! is_array( $arguments ) ) {
			$arguments = array();
		}

		return array(
			'type'  => 'tool_use',
			'id'    => isset( $item['call_id'] ) ? (string) $item['call_id'] : ( isset( $item['id'] ) ? (string) $item['id'] : '' ),
			'name'  => isset( $item['name'] ) ? (string) $item['name'] : '',
			'input' => $arguments,
		);
	}
}
