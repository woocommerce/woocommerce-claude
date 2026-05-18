<?php
/**
 * Generic AI provider telemetry helpers for AI Insights.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Telemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and dispatches redacted AI request/response telemetry payloads.
 */
class DifmAiTelemetry {

	/**
	 * Record an outgoing provider request.
	 *
	 * @param string $provider   Provider ID.
	 * @param string $model      Model ID.
	 * @param array  $body       Provider request body.
	 * @param string $body_json  Encoded request body.
	 * @param string $request_id Correlation ID for the request/response pair.
	 * @param array  $context    Caller-supplied context.
	 * @return void
	 */
	public static function record_request( $provider, $model, array $body, $body_json, $request_id, array $context = array() ) {
		TelemetryHandler::record(
			'difm_ai_request',
			array_merge(
				self::build_request_context( $provider, $model, $body, $body_json, $context ),
				array(
					'event'      => 'difm_ai_request',
					'request_id' => $request_id,
				)
			)
		);
	}

	/**
	 * Record a transport-level provider request failure.
	 *
	 * @param string $provider    Provider ID.
	 * @param string $model       Model ID.
	 * @param string $request_id  Correlation ID for the request/response pair.
	 * @param int    $duration_ms Request duration in milliseconds.
	 * @param string $error_code  WP_Error code.
	 * @param array  $context     Caller-supplied context.
	 * @return void
	 */
	public static function record_transport_error( $provider, $model, $request_id, $duration_ms, $error_code, array $context = array() ) {
		TelemetryHandler::record(
			'difm_ai_transport_error',
			array_merge(
				array(
					'event'       => 'difm_ai_transport_error',
					'provider'    => (string) $provider,
					'model'       => (string) $model,
					'request_id'  => $request_id,
					'duration_ms' => $duration_ms,
					'error_code'  => $error_code,
				),
				self::allowed_request_context( $context )
			)
		);
	}

	/**
	 * Record a provider response.
	 *
	 * @param string $provider    Provider ID.
	 * @param string $model       Model ID.
	 * @param string $request_id  Correlation ID for the request/response pair.
	 * @param int    $status_code HTTP status code.
	 * @param int    $duration_ms Request duration in milliseconds.
	 * @param mixed  $decoded     Decoded response body.
	 * @param string $raw_body    Raw response body.
	 * @return void
	 */
	public static function record_response( $provider, $model, $request_id, $status_code, $duration_ms, $decoded, $raw_body = '' ) {
		TelemetryHandler::record(
			'difm_ai_response',
			array_merge(
				array( 'event' => 'difm_ai_response' ),
				self::build_response_context( $provider, $model, $request_id, $status_code, $duration_ms, $decoded, $raw_body )
			)
		);
	}

	/**
	 * Build redacted request diagnostics.
	 *
	 * @param string $provider  Provider ID.
	 * @param string $model     Model ID.
	 * @param array  $body      Provider request body.
	 * @param string $body_json Encoded request body.
	 * @param array  $context   Caller-supplied context.
	 * @return array
	 */
	public static function build_request_context( $provider, $model, array $body, $body_json, array $context = array() ) {
		$tool_names = self::extract_tool_names( $body );

		return array_merge(
			array(
				'provider'      => (string) $provider,
				'model'         => (string) $model,
				'message_count' => self::count_messages( $body ),
				'tool_count'    => count( $tool_names ),
				'tool_names'    => implode( ',', $tool_names ),
				'body_bytes'    => strlen( (string) $body_json ),
			),
			self::allowed_request_context( $context )
		);
	}

	/**
	 * Build response diagnostics from provider usage metadata.
	 *
	 * @param string $provider    Provider ID.
	 * @param string $model       Model ID.
	 * @param string $request_id  Correlation ID for the request/response pair.
	 * @param int    $status_code HTTP status code.
	 * @param int    $duration_ms Request duration in milliseconds.
	 * @param mixed  $decoded     Decoded response body.
	 * @param string $raw_body    Raw response body.
	 * @return array
	 */
	public static function build_response_context( $provider, $model, $request_id, $status_code, $duration_ms, $decoded, $raw_body = '' ) {
		$context = array(
			'provider'    => (string) $provider,
			'model'       => (string) $model,
			'request_id'  => $request_id,
			'status_code' => $status_code,
			'duration_ms' => $duration_ms,
			'body_bytes'  => strlen( (string) $raw_body ),
		);

		if ( is_array( $decoded ) && isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
			foreach ( self::normalise_usage( $decoded['usage'] ) as $field => $value ) {
				$context[ 'usage_' . $field ] = (int) $value;
			}
		}

		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$context['error_type'] = isset( $decoded['error']['type'] ) ? (string) $decoded['error']['type'] : 'unknown';
		}

		if ( is_array( $decoded ) && isset( $decoded['provider_request_id'] ) && is_scalar( $decoded['provider_request_id'] ) ) {
			$context['provider_request_id'] = (string) $decoded['provider_request_id'];
		}

		return $context;
	}

	/**
	 * Extract tool names from provider request bodies.
	 *
	 * @param array $body Provider request body.
	 * @return array<int,string>
	 */
	private static function extract_tool_names( array $body ) {
		$tool_names = array();
		if ( empty( $body['tools'] ) || ! is_array( $body['tools'] ) ) {
			return $tool_names;
		}

		foreach ( $body['tools'] as $tool ) {
			if ( ! is_array( $tool ) ) {
				continue;
			}

			if ( isset( $tool['name'] ) ) {
				$tool_names[] = (string) $tool['name'];
			} elseif ( isset( $tool['function']['name'] ) ) {
				$tool_names[] = (string) $tool['function']['name'];
			}
		}

		return $tool_names;
	}

	/**
	 * Count conversation messages without inspecting prompt text.
	 *
	 * @param array $body Provider request body.
	 * @return int
	 */
	private static function count_messages( array $body ) {
		if ( isset( $body['messages'] ) && is_array( $body['messages'] ) ) {
			return count( $body['messages'] );
		}

		if ( isset( $body['input'] ) && is_array( $body['input'] ) ) {
			return count( $body['input'] );
		}

		return isset( $body['prompt'] ) ? 1 : 0;
	}

	/**
	 * Normalise provider usage fields to stable telemetry keys.
	 *
	 * @param array $usage Provider usage metadata.
	 * @return array<string,int>
	 */
	private static function normalise_usage( array $usage ) {
		$normalised = array();

		$map = array(
			'input_tokens'                => 'input_tokens',
			'prompt_tokens'               => 'input_tokens',
			'cache_creation_input_tokens' => 'cache_creation_input_tokens',
			'cache_read_input_tokens'     => 'cache_read_input_tokens',
			'output_tokens'               => 'output_tokens',
			'completion_tokens'           => 'output_tokens',
			'total_tokens'                => 'total_tokens',
			'thought_tokens'              => 'thought_tokens',
		);

		foreach ( $map as $source => $target ) {
			if ( isset( $usage[ $source ] ) && is_numeric( $usage[ $source ] ) ) {
				$normalised[ $target ] = (int) $usage[ $source ];
			}
		}

		return $normalised;
	}

	/**
	 * Return safe caller-supplied context keys.
	 *
	 * @param array $context Caller-supplied context.
	 * @return array
	 */
	private static function allowed_request_context( array $context ) {
		$allowed = array();

		foreach ( array( 'surface', 'iteration' ) as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$allowed[ $key ] = $context[ $key ];
			}
		}

		return $allowed;
	}
}
