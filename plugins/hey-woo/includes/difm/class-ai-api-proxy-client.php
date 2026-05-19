<?php
/**
 * AI API Proxy client for Hey Woo test/dev usage.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

use WooCommerce\HeyWoo\Telemetry\DifmAiTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI-compatible client for WordPress.com's AI API Proxy.
 *
 * This is intentionally server-side only. It is meant for test/dev usage with
 * a configured proxy token, not for exposing a shared token to merchant browsers.
 */
class AiApiProxyClient implements DifmAiClientInterface {

	/**
	 * Provider label for telemetry.
	 */
	const PROVIDER = 'ai_api_proxy';

	/**
	 * Proxy base URL.
	 */
	const API_BASE = 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1';

	/**
	 * Default OpenAI-compatible model for local testing.
	 */
	const MODEL = 'gpt-oss-120b';

	/**
	 * HTTP request timeout in seconds.
	 */
	const REQUEST_TIMEOUT = 90;

	/**
	 * Return the configured proxy token.
	 *
	 * Priority:
	 * 1. HEY_WOO_AI_API_PROXY_KEY constant.
	 * 2. HEY_WOO_AI_API_PROXY_KEY environment variable.
	 * 3. hey_woo_ai_api_proxy_key option for local wp-env testing.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		if ( defined( 'HEY_WOO_AI_API_PROXY_KEY' ) && '' !== (string) HEY_WOO_AI_API_PROXY_KEY ) {
			return (string) HEY_WOO_AI_API_PROXY_KEY;
		}

		$env_key = getenv( 'HEY_WOO_AI_API_PROXY_KEY' );
		if ( is_string( $env_key ) && '' !== $env_key ) {
			return $env_key;
		}

		return (string) get_option( 'hey_woo_ai_api_proxy_key', '' );
	}

	/**
	 * Whether the proxy token is configured.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== self::get_api_key();
	}

	/**
	 * Return the tracking feature header value.
	 *
	 * @return string
	 */
	public function get_feature() {
		$feature = '';

		if ( defined( 'HEY_WOO_AI_API_PROXY_FEATURE' ) ) {
			$feature = (string) HEY_WOO_AI_API_PROXY_FEATURE;
		}

		if ( '' === $feature ) {
			$env_feature = getenv( 'HEY_WOO_AI_API_PROXY_FEATURE' );
			$feature     = is_string( $env_feature ) ? $env_feature : '';
		}

		if ( '' === $feature ) {
			$feature = (string) get_option( 'hey_woo_ai_api_proxy_feature', 'hey-woo-testing' );
		}

		/**
		 * Filter the AI API Proxy feature header value.
		 *
		 * @since 0.5.0
		 *
		 * @param string $feature Feature name.
		 */
		return (string) apply_filters( 'hey_woo_difm_ai_api_proxy_feature', sanitize_key( $feature ) );
	}

	/**
	 * Return the configured OpenAI-compatible model.
	 *
	 * @return string
	 */
	public function get_model() {
		$model = '';

		if ( defined( 'HEY_WOO_AI_API_PROXY_MODEL' ) ) {
			$model = (string) HEY_WOO_AI_API_PROXY_MODEL;
		}

		if ( '' === $model ) {
			$env_model = getenv( 'HEY_WOO_AI_API_PROXY_MODEL' );
			$model     = is_string( $env_model ) ? $env_model : '';
		}

		if ( '' === $model ) {
			$model = (string) get_option( 'hey_woo_ai_api_proxy_model', self::MODEL );
		}

		/**
		 * Filter the AI API Proxy model used for Hey Woo calls.
		 *
		 * @since 0.5.0
		 *
		 * @param string $model Model identifier.
		 */
		return (string) apply_filters( 'hey_woo_difm_ai_api_proxy_model', sanitize_text_field( $model ) );
	}

	/**
	 * Call the AI API Proxy chat completions endpoint.
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
			return new \WP_Error( 'no_api_key', __( 'No AI API Proxy token is configured.', 'hey-woo' ) );
		}

		$model = $this->get_model();
		if ( '' === $model ) {
			return new \WP_Error( 'no_model', __( 'No AI API Proxy model is configured.', 'hey-woo' ) );
		}

		$proxy_messages = $this->build_messages( $messages, $system );
		$body           = array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => 0.2,
			'messages'    => $proxy_messages,
		);

		$proxy_tools = $this->build_tools( $tools );
		if ( ! empty( $proxy_tools ) ) {
			$body['tools']       = $proxy_tools;
			$body['tool_choice'] = 'auto';
		}

		$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'difm_', true );
		$body_json  = wp_json_encode( $body );
		$body_json  = is_string( $body_json ) ? $body_json : '';
		$start_ms   = microtime( true );

		DifmAiTelemetry::record_request( self::PROVIDER, $model, $body, $body_json, $request_id, $context );

		$response = wp_remote_post(
			self::API_BASE . '/chat/completions',
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'Authorization'        => 'Bearer ' . $api_key,
					'Content-Type'         => 'application/json',
					'X-WPCOM-AI-Feature'   => $this->get_feature(),
				),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			DifmAiTelemetry::record_transport_error(
				self::PROVIDER,
				$model,
				$request_id,
				(int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				$response->get_error_code(),
				$context
			);
			return $response;
		}

		$status_code  = (int) wp_remote_retrieve_response_code( $response );
		$raw_body     = wp_remote_retrieve_body( $response );
		$decoded_body = json_decode( $raw_body, true );
		$duration_ms  = (int) round( ( microtime( true ) - $start_ms ) * 1000 );

		DifmAiTelemetry::record_response( self::PROVIDER, $model, $request_id, $status_code, $duration_ms, $decoded_body, $raw_body );

		if ( ! is_array( $decoded_body ) ) {
			return new \WP_Error(
				'invalid_response',
				__( 'AI API Proxy returned an unexpected response format.', 'hey-woo' )
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = isset( $decoded_body['error']['message'] )
				? (string) $decoded_body['error']['message']
				: sprintf(
					/* translators: %d: HTTP status code */
					__( 'AI API Proxy returned HTTP %d.', 'hey-woo' ),
					$status_code
				);

			return new \WP_Error( 'ai_api_proxy_error', $message, array( 'status' => $status_code ) );
		}

		return $this->normalise_response( $decoded_body, $model );
	}

	/**
	 * Convert provider-neutral messages into OpenAI-compatible messages.
	 *
	 * @param array  $messages Conversation messages.
	 * @param string $system   System prompt.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_messages( array $messages, $system ) {
		$proxy_messages = array();

		if ( '' !== (string) $system ) {
			$proxy_messages[] = array(
				'role'    => 'system',
				'content' => (string) $system,
			);
		}

		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || empty( $message['role'] ) ) {
				continue;
			}

			$role    = (string) $message['role'];
			$content = isset( $message['content'] ) ? $message['content'] : '';

			if ( 'assistant' === $role && is_array( $content ) ) {
				$proxy_messages[] = $this->assistant_message_from_blocks( $content );
				continue;
			}

			if ( 'user' === $role && is_array( $content ) ) {
				foreach ( $content as $block ) {
					if ( ! is_array( $block ) || ! isset( $block['type'] ) || 'tool_result' !== $block['type'] ) {
						continue;
					}

					$proxy_messages[] = array(
						'role'         => 'tool',
						'tool_call_id' => isset( $block['tool_use_id'] ) ? (string) $block['tool_use_id'] : '',
						'content'      => isset( $block['content'] ) ? (string) $block['content'] : '',
					);
				}
				continue;
			}

			if ( in_array( $role, array( 'user', 'assistant' ), true ) ) {
				$encoded_content = is_string( $content ) ? $content : wp_json_encode( $content );
				$proxy_messages[] = array(
					'role'    => $role,
					'content' => is_string( $encoded_content ) ? $encoded_content : '',
				);
			}
		}

		return $proxy_messages;
	}

	/**
	 * Convert assistant Anthropic-style blocks to an OpenAI assistant message.
	 *
	 * @param array $content Assistant content blocks.
	 * @return array<string,mixed>
	 */
	private function assistant_message_from_blocks( array $content ) {
		$text       = '';
		$tool_calls = array();

		foreach ( $content as $block ) {
			if ( ! is_array( $block ) || empty( $block['type'] ) ) {
				continue;
			}

			if ( 'text' === $block['type'] && isset( $block['text'] ) ) {
				$text .= (string) $block['text'];
				continue;
			}

			if ( 'tool_use' === $block['type'] ) {
				$encoded_args = wp_json_encode( isset( $block['input'] ) ? $block['input'] : (object) array() );
				$tool_calls[] = array(
					'id'       => isset( $block['id'] ) ? (string) $block['id'] : '',
					'type'     => 'function',
					'function' => array(
						'name'      => isset( $block['name'] ) ? (string) $block['name'] : '',
						'arguments' => is_string( $encoded_args ) ? $encoded_args : '{}',
					),
				);
			}
		}

		$message = array(
			'role'    => 'assistant',
			'content' => '' !== $text ? $text : null,
		);

		if ( ! empty( $tool_calls ) ) {
			$message['tool_calls'] = $tool_calls;
		}

		return $message;
	}

	/**
	 * Convert tool definitions to OpenAI-compatible tool declarations.
	 *
	 * @param array $tools Provider-neutral tool definitions.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_tools( array $tools ) {
		$proxy_tools = array();

		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) ) {
				continue;
			}

			$proxy_tools[] = array(
				'type'     => 'function',
				'function' => array(
					'name'        => (string) $tool['name'],
					'description' => isset( $tool['description'] ) ? (string) $tool['description'] : '',
					'parameters'  => isset( $tool['input_schema'] ) && is_array( $tool['input_schema'] )
						? $tool['input_schema']
						: array(
							'type'       => 'object',
							'properties' => (object) array(),
						),
				),
			);
		}

		return $proxy_tools;
	}

	/**
	 * Convert an OpenAI-compatible response to the normalised client shape.
	 *
	 * @param array  $response Decoded proxy response.
	 * @param string $model    Requested model.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function normalise_response( array $response, $model ) {
		$choice = isset( $response['choices'][0] ) && is_array( $response['choices'][0] )
			? $response['choices'][0]
			: null;

		if ( ! is_array( $choice ) || ! isset( $choice['message'] ) || ! is_array( $choice['message'] ) ) {
			return new \WP_Error(
				'invalid_response',
				__( 'AI API Proxy did not return a chat completion message.', 'hey-woo' )
			);
		}

		$message = $choice['message'];
		$content = array();

		if ( isset( $message['content'] ) && '' !== (string) $message['content'] ) {
			$content[] = array(
				'type' => 'text',
				'text' => (string) $message['content'],
			);
		}

		if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
			foreach ( $message['tool_calls'] as $tool_call ) {
				if ( ! is_array( $tool_call ) || ! isset( $tool_call['function'] ) || ! is_array( $tool_call['function'] ) ) {
					continue;
				}

				$arguments = isset( $tool_call['function']['arguments'] ) ? (string) $tool_call['function']['arguments'] : '{}';
				$input     = json_decode( $arguments, true );

				$content[] = array(
					'type'  => 'tool_use',
					'id'    => isset( $tool_call['id'] ) ? (string) $tool_call['id'] : uniqid( 'tool_', true ),
					'name'  => isset( $tool_call['function']['name'] ) ? (string) $tool_call['function']['name'] : '',
					'input' => is_array( $input ) ? $input : array(),
				);
			}
		}

		if ( empty( $content ) ) {
			$content[] = array(
				'type' => 'text',
				'text' => '',
			);
		}

		$stop_reason = ! empty( $message['tool_calls'] ) || ( isset( $choice['finish_reason'] ) && 'tool_calls' === $choice['finish_reason'] )
			? 'tool_use'
			: 'end_turn';

		return array(
			'id'          => isset( $response['id'] ) ? (string) $response['id'] : '',
			'type'        => 'message',
			'role'        => 'assistant',
			'content'     => $content,
			'model'       => isset( $response['model'] ) ? (string) $response['model'] : $model,
			'stop_reason' => $stop_reason,
			'usage'       => isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array(),
			'provider'    => self::PROVIDER,
		);
	}
}
