<?php
/**
 * Classifies provider WP_Errors into merchant-facing kinds + copy.
 *
 * The chat REST endpoint catches WP_Errors from the AI client (AnthropicClient,
 * AiApiProxyClient, WordPressAiClientAdapter) and converts them into the
 * `{ status: 'error' }` response shape consumed by useChat. Before this mapper
 * the response carried only the raw provider message, which surfaced
 * Anthropic-specific wording ("authentication_error: invalid x-api-key") to
 * merchants. The mapper translates HTTP status / error code into a short stable
 * kind + a merchant-friendly explanation, so the chat UI can branch on the kind
 * for actions like "Open settings" or "Retry".
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Maps provider WP_Errors into chat error kinds and copy.
 */
class ChatErrorMapper {

	/**
	 * The configured key was rejected by the provider (401/403, invalid/empty key).
	 */
	const KIND_BAD_KEY = 'bad_key';

	/**
	 * The provider rate-limited the store (HTTP 429).
	 */
	const KIND_RATE_LIMITED = 'rate_limited';

	/**
	 * The provider is overloaded (HTTP 529 Anthropic-specific, or 503).
	 */
	const KIND_OVERLOADED = 'overloaded';

	/**
	 * The HTTP request timed out before the provider responded.
	 */
	const KIND_TIMEOUT = 'timeout';

	/**
	 * Transport failure — DNS, TLS, refused connection, dropped socket.
	 */
	const KIND_NETWORK = 'network';

	/**
	 * Fallback for anything we cannot classify confidently.
	 */
	const KIND_GENERIC = 'generic';

	/**
	 * Classify a WP_Error from an AI client into a kind + merchant-facing message.
	 *
	 * The original error message and code remain on the WP_Error for telemetry
	 * via DifmAiTelemetry. Only the merchant-facing surface is rewritten here.
	 *
	 * @param \WP_Error $error WP_Error returned by an AI client.
	 * @return array{kind:string,message:string} Classified kind and copy.
	 */
	public static function classify( \WP_Error $error ) {
		$code   = (string) $error->get_error_code();
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		// Explicit provider configuration codes — these can fire before any
		// HTTP request. `no_model` and `no_ai_provider` come from
		// AiApiProxyClient and DifmProviderResolver respectively. The
		// `wordpress_ai_*` codes come from WordPressAiClientAdapter when the
		// connector-side call throws or returns an unrecognised error — in
		// practice these are nearly always key/auth issues (e.g. a revoked
		// upstream key), so route them through bad_key to give the merchant
		// an actionable "Open settings" action instead of a dead-end generic
		// error.
		if ( in_array(
			$code,
			array(
				'no_api_key',
				'invalid_key',
				'empty_key',
				'no_model',
				'no_ai_provider',
				'wordpress_ai_error',
				'wordpress_ai_unavailable',
				'wordpress_ai_exception',
			),
			true
		) ) {
			return array(
				'kind'    => self::KIND_BAD_KEY,
				'message' => self::message_for( self::KIND_BAD_KEY ),
			);
		}

		// Transport-level failures come back from wp_remote_post without a
		// status. Distinguish a connection timeout from a routing/DNS error so
		// we can offer the right retry copy.
		if ( 0 === $status && self::is_transport_failure_code( $code ) ) {
			$raw_message = (string) $error->get_error_message();
			$kind        = self::looks_like_timeout( $raw_message ) ? self::KIND_TIMEOUT : self::KIND_NETWORK;
			return array(
				'kind'    => $kind,
				'message' => self::message_for( $kind ),
			);
		}

		// HTTP status-driven classification — all three clients embed the
		// provider status in error_data['status'].
		if ( 401 === $status || 403 === $status ) {
			return array(
				'kind'    => self::KIND_BAD_KEY,
				'message' => self::message_for( self::KIND_BAD_KEY ),
			);
		}

		if ( 429 === $status ) {
			return array(
				'kind'    => self::KIND_RATE_LIMITED,
				'message' => self::message_for( self::KIND_RATE_LIMITED ),
			);
		}

		if ( 408 === $status || 504 === $status ) {
			return array(
				'kind'    => self::KIND_TIMEOUT,
				'message' => self::message_for( self::KIND_TIMEOUT ),
			);
		}

		if ( 503 === $status || 529 === $status ) {
			return array(
				'kind'    => self::KIND_OVERLOADED,
				'message' => self::message_for( self::KIND_OVERLOADED ),
			);
		}

		// Connector-mode safety net: by this point we've ruled out known
		// transport, rate-limit, timeout, and overload signatures. Anything
		// left in WP 7.0 connector mode is almost always a connector/key
		// problem (most often an upstream-revoked or invalid key). Routing
		// the residue through bad_key gives the merchant an actionable
		// "Open settings" CTA instead of a dead-end generic message.
		if ( DifmProviderEnvironment::is_connector_mode() ) {
			return array(
				'kind'    => self::KIND_BAD_KEY,
				'message' => self::message_for( self::KIND_BAD_KEY ),
			);
		}

		return array(
			'kind'    => self::KIND_GENERIC,
			'message' => self::message_for( self::KIND_GENERIC ),
		);
	}

	/**
	 * Whether a WP_Error code looks like a wp_remote_post transport failure.
	 *
	 * @param string $code WP_Error code.
	 * @return bool
	 */
	private static function is_transport_failure_code( $code ) {
		return in_array(
			$code,
			array(
				'http_request_failed',
				'http_request_timeout',
				'connect_timeout',
			),
			true
		);
	}

	/**
	 * Whether a transport-failure message string looks like a timeout.
	 *
	 * @param string $message WP_Error message.
	 * @return bool
	 */
	private static function looks_like_timeout( $message ) {
		$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $message ) : strtolower( $message );

		return false !== strpos( $lower, 'timed out' )
			|| false !== strpos( $lower, 'timeout' )
			|| false !== strpos( $lower, 'operation too slow' );
	}

	/**
	 * Merchant-facing copy for a classified kind.
	 *
	 * @param string $kind Classified kind.
	 * @return string
	 */
	private static function message_for( $kind ) {
		switch ( $kind ) {
			case self::KIND_BAD_KEY:
				return __( 'Hey Woo could not reach the AI provider with the current settings. Update them in WooCommerce > Settings > Hey Woo and try again.', 'hey-woo' );

			case self::KIND_RATE_LIMITED:
				return __( 'The AI service is rate-limiting requests from this store. Wait a few seconds and try again.', 'hey-woo' );

			case self::KIND_OVERLOADED:
				return __( 'The AI service is overloaded right now. Try again in a few seconds.', 'hey-woo' );

			case self::KIND_TIMEOUT:
				return __( 'The AI service did not respond in time. Try again, or ask a more focused question.', 'hey-woo' );

			case self::KIND_NETWORK:
				return __( 'Could not reach the AI service. Check this site\'s outbound connection and try again.', 'hey-woo' );

			default:
				return __( 'Something went wrong on the AI service. Try again, or rephrase your question.', 'hey-woo' );
		}
	}
}
