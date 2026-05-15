<?php
/**
 * Thin Anthropic API client for Hey Woo.
 *
 * Uses `wp_remote_post()` directly — no SDK dependency. The abstraction
 * boundary is intentionally here: if WordPress ships a first-party AI client
 * (`wp_ai_client_prompt`) in a future release, swapping to it is a
 * one-file change.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

use WooCommerce\HeyWoo\Telemetry\AnthropicTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Anthropic Messages API client.
 *
 * PHP 7.4 compatible — no union types, no match, no enums.
 */
class AnthropicClient {

	/**
	 * Anthropic API base URL.
	 */
	const API_BASE = 'https://api.anthropic.com/v1';

	/**
	 * Anthropic API version header value.
	 */
	const API_VERSION = '2023-06-01';

	/**
	 * Default model used for all Hey Woo calls.
	 *
	 * Override via the `hey_woo_difm_model` filter.
	 */
	const MODEL = 'claude-sonnet-4-5';

	/**
	 * HTTP request timeout in seconds.
	 *
	 * AI inference calls can take up to ~20 seconds on a cold start;
	 * 90 s gives comfortable headroom even under high load.
	 */
	const REQUEST_TIMEOUT = 90;

	/**
	 * Return the Anthropic API key in use.
	 *
	 * Priority:
	 *   1. `HEY_WOO_ANTHROPIC_KEY` PHP constant (key never touches the DB)
	 *   2. `WOOCOMMERCE_CLAUDE_ANTHROPIC_KEY` PHP constant (legacy fallback)
	 *   3. `hey_woo_anthropic_api_key` wp_options value
	 *   4. `woocommerce_claude_anthropic_api_key` wp_options value (legacy fallback)
	 *
	 * @return string Empty string when no key is configured.
	 */
	public static function get_api_key() {
		if ( defined( 'HEY_WOO_ANTHROPIC_KEY' ) ) {
			return (string) HEY_WOO_ANTHROPIC_KEY;
		}

		if ( defined( 'WOOCOMMERCE_CLAUDE_ANTHROPIC_KEY' ) ) {
			return (string) WOOCOMMERCE_CLAUDE_ANTHROPIC_KEY;
		}

		$current_key = (string) get_option( 'hey_woo_anthropic_api_key', '' );
		if ( '' !== $current_key ) {
			return $current_key;
		}

		return (string) get_option( 'woocommerce_claude_anthropic_api_key', '' );
	}

	/**
	 * Whether an Anthropic API key is currently configured.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== self::get_api_key();
	}

	/**
	 * Call the Anthropic Messages API.
	 *
	 * @param array  $messages   Conversation messages, each a `['role' => 'user'|'assistant', 'content' => string]` pair.
	 * @param string $system     System prompt (optional).
	 * @param array  $tools      Anthropic tool definitions (optional).
	 * @param int    $max_tokens Maximum tokens to generate.
	 * @param array  $context    Optional diagnostic context for logging.
	 * @return array|\WP_Error   Decoded response array, or WP_Error on failure.
	 */
	public function messages( array $messages, $system = '', array $tools = array(), $max_tokens = 4096, array $context = array() ) {
		$api_key = self::get_api_key();
		if ( '' === $api_key ) {
			return new \WP_Error( 'no_api_key', __( 'No Anthropic API key is configured.', 'hey-woo' ) );
		}

		/**
		 * Filter the model used for Hey Woo calls.
		 *
		 * @since 0.2.0
		 *
		 * @param string $model Anthropic model identifier.
		 */
		$model = (string) apply_filters( 'hey_woo_difm_model', self::MODEL );

		$body = array(
			'model'      => $model,
			'max_tokens' => $max_tokens,
			'messages'   => $messages,
		);

		if ( '' !== $system ) {
			$body['system'] = $system;
		}

		if ( ! empty( $tools ) ) {
			$body['tools'] = $tools;
		}

		$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'difm_', true );
		$body_json  = wp_json_encode( $body );
		$body_json  = is_string( $body_json ) ? $body_json : '';
		$start_ms   = microtime( true );

		AnthropicTelemetry::record_request( $body, $body_json, $request_id, $context );

		$response = wp_remote_post(
			self::API_BASE . '/messages',
			array(
				'timeout' => self::REQUEST_TIMEOUT,
				'headers' => array(
					'x-api-key'         => $api_key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => $body_json,
			)
		);

		if ( is_wp_error( $response ) ) {
			AnthropicTelemetry::record_transport_error(
				$request_id,
				(int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				$response->get_error_code()
			);
			return $response;
		}

		$status_code  = (int) wp_remote_retrieve_response_code( $response );
		$raw_body     = wp_remote_retrieve_body( $response );
		$decoded_body = json_decode( $raw_body, true );
		$duration_ms  = (int) round( ( microtime( true ) - $start_ms ) * 1000 );

		AnthropicTelemetry::record_response( $request_id, $status_code, $duration_ms, $decoded_body );

		if ( ! is_array( $decoded_body ) ) {
			return new \WP_Error(
				'invalid_response',
				__( 'Anthropic returned an unexpected response format.', 'hey-woo' )
			);
		}

		// Anthropic signals errors with {"type":"error"} even on 200 (rare) and
		// always on 4xx/5xx.
		if ( isset( $decoded_body['type'] ) && 'error' === $decoded_body['type'] ) {
			$error_message = isset( $decoded_body['error']['message'] )
				? (string) $decoded_body['error']['message']
				: __( 'Unknown Anthropic error.', 'hey-woo' );

			return new \WP_Error(
				'anthropic_error',
				$error_message,
				array( 'status' => $status_code )
			);
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return new \WP_Error(
				'http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Anthropic API returned HTTP %d.', 'hey-woo' ),
					$status_code
				),
				array( 'status' => $status_code )
			);
		}

		return $decoded_body;
	}

	/**
	 * Validate an API key with a minimal (max_tokens=1) API call.
	 *
	 * Does not persist the key. Returns true on success, WP_Error on failure.
	 *
	 * @param string $key Anthropic API key to validate.
	 * @return true|\WP_Error
	 */
	public static function validate_key( $key ) {
		$key = (string) $key;
		if ( '' === $key ) {
			return new \WP_Error( 'empty_key', __( 'API key must not be empty.', 'hey-woo' ) );
		}

		$response = wp_remote_post(
			self::API_BASE . '/messages',
			array(
				'timeout' => 15,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => self::MODEL,
						'max_tokens' => 1,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'hi',
							),
						),
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
				__( 'Invalid API key — Anthropic returned 401.', 'hey-woo' )
			);
		}

		if ( is_array( $decoded_body ) && isset( $decoded_body['type'] ) && 'error' === $decoded_body['type'] ) {
			$error_message = isset( $decoded_body['error']['message'] )
				? (string) $decoded_body['error']['message']
				: __( 'Unknown Anthropic error.', 'hey-woo' );

			return new \WP_Error( 'anthropic_error', $error_message );
		}

		// Any 2xx or the expected partial response (stop_reason=max_tokens) is
		// treated as a valid key.
		if ( $status_code >= 200 && $status_code < 300 ) {
			return true;
		}

		return new \WP_Error(
			'http_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'Anthropic API returned HTTP %d.', 'hey-woo' ),
				$status_code
			)
		);
	}
}
