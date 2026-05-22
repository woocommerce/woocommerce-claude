<?php
/**
 * Integration tests for the Hey Woo chat error mapper.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\HeyWoo\Difm\ChatErrorMapper;

require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-chat-error-mapper.php';

/**
 * Asserts that the WP_Errors produced by every AI client surface map to a
 * stable kind + merchant-friendly copy, with a generic fallback for anything
 * we cannot classify confidently.
 */
class Test_Chat_Error_Mapper extends WP_UnitTestCase {

	/**
	 * Force legacy (non-connector) mode by default so existing "→ generic"
	 * data rows keep their original expectations. Tests that need the
	 * connector-mode safety net flip the filter inline.
	 */
	public function set_up() {
		parent::set_up();
		add_filter( 'hey_woo_difm_connector_mode', '__return_false' );
	}

	/**
	 * Restore default connector-mode detection.
	 */
	public function tear_down() {
		remove_filter( 'hey_woo_difm_connector_mode', '__return_false' );
		parent::tear_down();
	}

	/**
	 * The classifier returns kind + a non-empty message for every input.
	 *
	 * @param string $expected_kind Expected mapper kind.
	 * @param string $code          WP_Error code.
	 * @param mixed  $data          WP_Error data (typically array with 'status').
	 * @param string $message       WP_Error message used to classify transport failures.
	 *
	 * @dataProvider classify_provider
	 */
	public function test_classify_returns_expected_kind( $expected_kind, $code, $data, $message = '' ) {
		$error      = new WP_Error( $code, $message, $data );
		$classified = ChatErrorMapper::classify( $error );

		$this->assertSame( $expected_kind, $classified['kind'] );
		$this->assertIsString( $classified['message'] );
		$this->assertNotSame( '', trim( $classified['message'] ) );
	}

	/**
	 * In WP 7.0 connector mode, anything that would otherwise fall through
	 * to the generic kind is reclassified as bad_key so the merchant gets an
	 * actionable "Open settings" CTA. Inverts the legacy default that set_up
	 * applies.
	 */
	public function test_connector_mode_promotes_unknown_codes_to_bad_key() {
		remove_filter( 'hey_woo_difm_connector_mode', '__return_false' );
		add_filter( 'hey_woo_difm_connector_mode', '__return_true' );

		$error      = new WP_Error( 'something_unexpected', 'unknown failure', array() );
		$classified = ChatErrorMapper::classify( $error );

		$this->assertSame( ChatErrorMapper::KIND_BAD_KEY, $classified['kind'] );

		remove_filter( 'hey_woo_difm_connector_mode', '__return_true' );
	}

	/**
	 * The connector-mode BAD_KEY copy must point merchants at
	 * Settings > Connectors (where they actually re-enter the key on WP 7.0)
	 * rather than the legacy "Settings > Hey Woo" surface.
	 */
	public function test_connector_mode_bad_key_message_points_to_connectors() {
		remove_filter( 'hey_woo_difm_connector_mode', '__return_false' );
		add_filter( 'hey_woo_difm_connector_mode', '__return_true' );

		$error      = new WP_Error( 'anthropic_error', 'invalid x-api-key', array( 'status' => 401 ) );
		$classified = ChatErrorMapper::classify( $error );

		$this->assertSame( ChatErrorMapper::KIND_BAD_KEY, $classified['kind'] );
		$this->assertStringContainsString( 'Settings > Connectors', $classified['message'] );

		remove_filter( 'hey_woo_difm_connector_mode', '__return_true' );
	}

	/**
	 * The legacy BAD_KEY copy must still point at the Hey Woo settings tab,
	 * which is where merchants on WP <= 6.9 enter their Anthropic key.
	 */
	public function test_legacy_mode_bad_key_message_points_to_hey_woo_settings() {
		$error      = new WP_Error( 'anthropic_error', 'invalid x-api-key', array( 'status' => 401 ) );
		$classified = ChatErrorMapper::classify( $error );

		$this->assertSame( ChatErrorMapper::KIND_BAD_KEY, $classified['kind'] );
		$this->assertStringContainsString( 'Settings > Hey Woo', $classified['message'] );
	}

	/**
	 * Cases covering every documented kind plus the generic fallback.
	 *
	 * @return array<string,array{0:string,1:string,2:mixed,3?:string}>
	 */
	public function classify_provider() {
		return array(
			// Provider key errors — fired before any HTTP request.
			'no_api_key code → bad_key'                   => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'no_api_key',
				array(),
			),
			'invalid_key code → bad_key'                  => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'invalid_key',
				array(),
			),
			'empty_key code → bad_key'                    => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'empty_key',
				array(),
			),
			'no_model code → bad_key'                     => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'no_model',
				array(),
			),
			'no_ai_provider code → bad_key'               => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'no_ai_provider',
				array(),
			),

			// WP 7.0 connector-mode failures from WordPressAiClientAdapter.
			// These nearly always indicate a key/auth problem on the
			// connector side (e.g. an upstream-revoked Anthropic key), so the
			// mapper routes them through bad_key for an actionable CTA.
			'wordpress_ai_error code → bad_key'           => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'wordpress_ai_error',
				array(),
			),
			'wordpress_ai_unavailable code → bad_key'     => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'wordpress_ai_unavailable',
				array(),
			),
			'wordpress_ai_exception code → bad_key'       => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'wordpress_ai_exception',
				array(),
			),

			// HTTP status-driven classification.
			'anthropic 401 → bad_key'                     => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'anthropic_error',
				array( 'status' => 401 ),
			),
			'anthropic 403 → bad_key'                     => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'anthropic_error',
				array( 'status' => 403 ),
			),
			'anthropic 429 → rate_limited'                => array(
				ChatErrorMapper::KIND_RATE_LIMITED,
				'anthropic_error',
				array( 'status' => 429 ),
			),
			'anthropic 408 → timeout'                     => array(
				ChatErrorMapper::KIND_TIMEOUT,
				'anthropic_error',
				array( 'status' => 408 ),
			),
			'anthropic 504 → timeout'                     => array(
				ChatErrorMapper::KIND_TIMEOUT,
				'anthropic_error',
				array( 'status' => 504 ),
			),
			'anthropic 503 → overloaded'                  => array(
				ChatErrorMapper::KIND_OVERLOADED,
				'anthropic_error',
				array( 'status' => 503 ),
			),
			'anthropic 529 → overloaded'                  => array(
				ChatErrorMapper::KIND_OVERLOADED,
				'anthropic_error',
				array( 'status' => 529 ),
			),
			'http_error 401 → bad_key'                    => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'http_error',
				array( 'status' => 401 ),
			),
			'http_error 429 → rate_limited'               => array(
				ChatErrorMapper::KIND_RATE_LIMITED,
				'http_error',
				array( 'status' => 429 ),
			),
			'ai_api_proxy_error 401 → bad_key'            => array(
				ChatErrorMapper::KIND_BAD_KEY,
				'ai_api_proxy_error',
				array( 'status' => 401 ),
			),
			'ai_api_proxy_error 529 → overloaded'         => array(
				ChatErrorMapper::KIND_OVERLOADED,
				'ai_api_proxy_error',
				array( 'status' => 529 ),
			),

			// Transport failures from wp_remote_post — no status, code + message.
			'http_request_failed (timeout msg) → timeout' => array(
				ChatErrorMapper::KIND_TIMEOUT,
				'http_request_failed',
				array(),
				'cURL error 28: Operation timed out after 60000 milliseconds',
			),
			'http_request_failed (connection refused) → network' => array(
				ChatErrorMapper::KIND_NETWORK,
				'http_request_failed',
				array(),
				'cURL error 7: Failed to connect to api.anthropic.com',
			),
			'http_request_timeout code → timeout'         => array(
				ChatErrorMapper::KIND_TIMEOUT,
				'http_request_timeout',
				array(),
				'Request timeout',
			),

			// Fallback path — code we do not recognise and no useful status.
			'invalid_response (no status) → generic'      => array(
				ChatErrorMapper::KIND_GENERIC,
				'invalid_response',
				array(),
			),
			'arbitrary 500 → generic'                     => array(
				ChatErrorMapper::KIND_GENERIC,
				'anthropic_error',
				array( 'status' => 500 ),
			),
			'unknown code → generic'                      => array(
				ChatErrorMapper::KIND_GENERIC,
				'something_unexpected',
				array(),
			),
		);
	}

	/**
	 * Each kind ships translatable, distinct, merchant-friendly copy.
	 */
	public function test_messages_are_distinct_per_kind() {
		$kinds = array(
			ChatErrorMapper::KIND_BAD_KEY,
			ChatErrorMapper::KIND_RATE_LIMITED,
			ChatErrorMapper::KIND_OVERLOADED,
			ChatErrorMapper::KIND_TIMEOUT,
			ChatErrorMapper::KIND_NETWORK,
			ChatErrorMapper::KIND_GENERIC,
		);

		$messages = array();
		foreach ( $kinds as $kind ) {
			// Provoke each kind through a representative WP_Error.
			$error = $this->error_for_kind( $kind );
			$out   = ChatErrorMapper::classify( $error );
			$this->assertSame( $kind, $out['kind'] );
			$messages[ $kind ] = $out['message'];
		}

		$this->assertSame(
			count( $messages ),
			count( array_unique( $messages ) ),
			'Each kind should have its own copy.'
		);
	}

	/**
	 * Build a representative WP_Error for a given kind.
	 *
	 * @param string $kind Mapper kind constant.
	 * @return WP_Error
	 */
	private function error_for_kind( $kind ) {
		switch ( $kind ) {
			case ChatErrorMapper::KIND_BAD_KEY:
				return new WP_Error( 'anthropic_error', 'invalid x-api-key', array( 'status' => 401 ) );
			case ChatErrorMapper::KIND_RATE_LIMITED:
				return new WP_Error( 'anthropic_error', 'rate limit exceeded', array( 'status' => 429 ) );
			case ChatErrorMapper::KIND_OVERLOADED:
				return new WP_Error( 'anthropic_error', 'overloaded', array( 'status' => 529 ) );
			case ChatErrorMapper::KIND_TIMEOUT:
				return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
			case ChatErrorMapper::KIND_NETWORK:
				return new WP_Error( 'http_request_failed', 'cURL error 7: connection refused' );
			default:
				return new WP_Error( 'unknown', 'something broke' );
		}
	}
}
