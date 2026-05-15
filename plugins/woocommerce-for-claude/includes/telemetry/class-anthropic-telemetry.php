<?php
/**
 * Back-compatible Anthropic telemetry facade.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Telemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and dispatches generic AI telemetry payloads for Anthropic callers.
 */
class AnthropicTelemetry {

	/**
	 * Record an outgoing Anthropic Messages request.
	 *
	 * @param array  $body       Anthropic request body.
	 * @param string $body_json  Encoded request body.
	 * @param string $request_id Correlation ID for the request/response pair.
	 * @param array  $context    Caller-supplied context.
	 * @return void
	 */
	public static function record_request( array $body, $body_json, $request_id, array $context = array() ) {
		$model = isset( $body['model'] ) ? (string) $body['model'] : '';
		DifmAiTelemetry::record_request( 'anthropic', $model, $body, $body_json, $request_id, $context );
	}

	/**
	 * Record a transport-level Anthropic request failure.
	 *
	 * @param string $request_id  Correlation ID for the request/response pair.
	 * @param int    $duration_ms Request duration in milliseconds.
	 * @param string $error_code  WP_Error code.
	 * @return void
	 */
	public static function record_transport_error( $request_id, $duration_ms, $error_code ) {
		DifmAiTelemetry::record_transport_error( 'anthropic', '', $request_id, $duration_ms, $error_code );
	}

	/**
	 * Record an Anthropic Messages response.
	 *
	 * @param string $request_id  Correlation ID for the request/response pair.
	 * @param int    $status_code HTTP status code.
	 * @param int    $duration_ms Request duration in milliseconds.
	 * @param mixed  $decoded     Decoded response body.
	 * @return void
	 */
	public static function record_response( $request_id, $status_code, $duration_ms, $decoded ) {
		$model = is_array( $decoded ) && isset( $decoded['model'] ) ? (string) $decoded['model'] : '';
		DifmAiTelemetry::record_response( 'anthropic', $model, $request_id, $status_code, $duration_ms, $decoded );
	}

	/**
	 * Build redacted request diagnostics for the Anthropic request.
	 *
	 * @param array  $body      Anthropic request body.
	 * @param string $body_json Encoded request body.
	 * @param array  $context   Caller-supplied context.
	 * @return array
	 */
	public static function build_request_context( array $body, $body_json, array $context = array() ) {
		$tool_names = array();
		if ( ! empty( $body['tools'] ) && is_array( $body['tools'] ) ) {
			foreach ( $body['tools'] as $tool ) {
				if ( is_array( $tool ) && isset( $tool['name'] ) ) {
					$tool_names[] = (string) $tool['name'];
				}
			}
		}

		return array_merge(
			array(
				'model'         => isset( $body['model'] ) ? (string) $body['model'] : '',
				'message_count' => isset( $body['messages'] ) && is_array( $body['messages'] ) ? count( $body['messages'] ) : 0,
				'tool_count'    => count( $tool_names ),
				'tool_names'    => implode( ',', $tool_names ),
				'body_bytes'    => strlen( (string) $body_json ),
			),
			self::allowed_request_context( $context )
		);
	}

	/**
	 * Build response diagnostics from Anthropic usage metadata.
	 *
	 * @param string $request_id  Correlation ID for the request/response pair.
	 * @param int    $status_code HTTP status code.
	 * @param int    $duration_ms Request duration in milliseconds.
	 * @param mixed  $decoded     Decoded response body.
	 * @return array
	 */
	public static function build_response_context( $request_id, $status_code, $duration_ms, $decoded ) {
		$context = array(
			'request_id'  => $request_id,
			'status_code' => $status_code,
			'duration_ms' => $duration_ms,
		);

		if ( is_array( $decoded ) && isset( $decoded['usage'] ) && is_array( $decoded['usage'] ) ) {
			$usage = $decoded['usage'];
			foreach ( array( 'input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens', 'output_tokens' ) as $field ) {
				if ( isset( $usage[ $field ] ) ) {
					$context[ 'usage_' . $field ] = (int) $usage[ $field ];
				}
			}
		}

		if ( is_array( $decoded ) && isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
			$context['error_type'] = isset( $decoded['error']['type'] ) ? (string) $decoded['error']['type'] : 'unknown';
		}

		return $context;
	}

	/**
	 * Return safe caller-supplied context keys for Anthropic request telemetry.
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
