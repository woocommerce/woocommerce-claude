<?php
/**
 * Authentication contract for the WooCommerce for Claude MCP transport callback —
 * `Plugin::authenticate_mcp_request()`.
 *
 * Pins the security-sensitive contract:
 *
 *   "Only valid WooCommerce REST API keys with read or read_write
 *    permissions authenticate against the MCP endpoint. Write-only
 *    keys, mismatched secrets, and unknown consumer keys are rejected
 *    before `wp_set_current_user()` runs."
 *
 * Regressions surfaced by Codex's 2026-05-07 review:
 *
 *   - The original lookup did not read the `permissions` column, so a
 *     merchant who set their key to write-only in WC admin would still
 *     authenticate against the read-only MCP surface — silently
 *     bypassing the scope they explicitly chose.
 *
 * The auth callback only requires `$request` to be a WP_REST_Request
 * instance; the credential comes from $_SERVER (PHP_AUTH_USER/PW or
 * HTTP_AUTHORIZATION). Tests inject the credential via $_SERVER and
 * assert the return shape.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Authentication tests for WooCommerce\Claude\Plugin::authenticate_mcp_request().
 */
class Test_MCP_Authentication extends WP_UnitTestCase {

	/**
	 * The original $_SERVER snapshot, restored in tearDown.
	 *
	 * @var array<string, mixed>
	 */
	private $original_server;

	/**
	 * Snapshot $_SERVER and clear any prior auth keys so each test
	 * starts from a clean slate.
	 */
	public function set_up() {
		parent::set_up();
		$this->original_server = $_SERVER;
		unset(
			$_SERVER['PHP_AUTH_USER'],
			$_SERVER['PHP_AUTH_PW'],
			$_SERVER['HTTP_AUTHORIZATION']
		);
		$this->wipe_test_rows();
	}

	/**
	 * Restore $_SERVER and remove any rows the test inserted.
	 */
	public function tear_down() {
		$_SERVER = $this->original_server;
		$this->wipe_test_rows();
		parent::tear_down();
	}

	/**
	 * Read-permission key authenticates successfully and sets the
	 * current user.
	 */
	public function test_authenticates_read_permission_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$cred    = $this->insert_api_key( $user_id, 'read' );
		$this->set_basic_auth( $cred );

		$result = \WooCommerce\Claude\Plugin::instance()->authenticate_mcp_request( new \WP_REST_Request() );

		$this->assertTrue( $result, 'A read-permission key authenticates.' );
		$this->assertSame( $user_id, get_current_user_id(), 'The bound user is set as the current user.' );
	}

	/**
	 * Read_write-permission key authenticates successfully — the MCP
	 * surface is read-only today, but a key whose scope already
	 * permits reads (read_write being a superset) is still valid.
	 */
	public function test_authenticates_read_write_permission_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$cred    = $this->insert_api_key( $user_id, 'read_write' );
		$this->set_basic_auth( $cred );

		$result = \WooCommerce\Claude\Plugin::instance()->authenticate_mcp_request( new \WP_REST_Request() );

		$this->assertTrue( $result, 'A read_write-permission key authenticates.' );
		$this->assertSame( $user_id, get_current_user_id() );
	}

	/**
	 * Write-only key is rejected. This is the regression Codex flagged:
	 * a merchant who deliberately sets their key to write-only in WC
	 * admin must not have that scope silently widened to "can read"
	 * by the MCP transport. The MCP surface is read-only today; a
	 * write-only key has nothing to authenticate for, so we refuse.
	 */
	public function test_rejects_write_only_permission_key() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$cred    = $this->insert_api_key( $user_id, 'write' );
		$this->set_basic_auth( $cred );

		$result = \WooCommerce\Claude\Plugin::instance()->authenticate_mcp_request( new \WP_REST_Request() );

		$this->assertFalse( $result, 'A write-only key is rejected by the MCP auth callback.' );
		$this->assertSame( 0, get_current_user_id(), 'No user is set when auth fails.' );
	}

	/**
	 * Wrong consumer secret is rejected even when the consumer key
	 * matches a stored row.
	 */
	public function test_rejects_wrong_consumer_secret() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$cred    = $this->insert_api_key( $user_id, 'read' );

		list( $ck ) = explode( ':', $cred, 2 );
		$this->set_basic_auth( $ck . ':cs_definitely_not_the_right_secret' );

		$result = \WooCommerce\Claude\Plugin::instance()->authenticate_mcp_request( new \WP_REST_Request() );

		$this->assertFalse( $result, 'A mismatched consumer secret is rejected.' );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Unknown consumer key (no row in woocommerce_api_keys) is rejected.
	 */
	public function test_rejects_unknown_consumer_key() {
		$this->set_basic_auth( 'ck_no_such_key:cs_no_such_secret' );

		$result = \WooCommerce\Claude\Plugin::instance()->authenticate_mcp_request( new \WP_REST_Request() );

		$this->assertFalse( $result );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * No credential at all → rejected. Important so an unauthenticated
	 * request can never accidentally authenticate by reaching the
	 * downstream `current_user_can()` check with whatever the previous
	 * request left in `$_SERVER`.
	 */
	public function test_rejects_request_without_credential() {
		$result = \WooCommerce\Claude\Plugin::instance()->authenticate_mcp_request( new \WP_REST_Request() );

		$this->assertFalse( $result );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * The callback's first defence: only act on a real WP_REST_Request.
	 * Anything else is rejected outright — it shouldn't happen via
	 * normal MCP transport invocation, but the type guard means a
	 * caller passing the wrong object can never accidentally
	 * authenticate.
	 */
	public function test_rejects_non_request_argument() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$cred    = $this->insert_api_key( $user_id, 'read' );
		$this->set_basic_auth( $cred );

		$result = \WooCommerce\Claude\Plugin::instance()->authenticate_mcp_request( null );

		$this->assertFalse( $result );
	}

	/**
	 * Basic auth raw form authenticates via `$request->get_header()`.
	 * `WP_REST_Server::get_headers()` normalises every Authorization
	 * SAPI variant — `HTTP_AUTHORIZATION` (mod_php / php-fpm) and
	 * `REDIRECT_HTTP_AUTHORIZATION` (CGI/FastCGI behind Apache
	 * `mod_rewrite`) — onto the same request header, so a single
	 * `get_header('authorization')` covers them all. Pre-fix the auth
	 * callback only inspected `$_SERVER['HTTP_AUTHORIZATION']`, so
	 * stores on the alternate SAPI rejected every MCP call even with
	 * a valid key (Codex regression flag).
	 */
	public function test_authenticates_via_request_authorization_header() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$cred    = $this->insert_api_key( $user_id, 'read' );

		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );

		$request = new \WP_REST_Request();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding a synthetic Basic auth header for the test fixture.
		$request->set_header( 'authorization', 'Basic ' . base64_encode( $cred ) );

		$result = \WooCommerce\Claude\Plugin::instance()->authenticate_mcp_request( $request );

		$this->assertTrue( $result, 'A credential on the request header authenticates regardless of which $_SERVER var the SAPI populated.' );
		$this->assertSame( $user_id, get_current_user_id() );
	}

	/**
	 * Insert a WC API key row with the given user_id and permissions
	 * scope. Returns the joined `ck_xxx:cs_xxx` credential string.
	 *
	 * @param int    $user_id     The WP user_id to bind the key to.
	 * @param string $permissions One of 'read', 'write', 'read_write'.
	 * @return string `ck_xxx:cs_xxx`.
	 */
	private function insert_api_key( $user_id, $permissions ) {
		global $wpdb;

		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture; no caching surface.
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => $user_id,
				'description'     => 'WooCommerce for Claude auth test',
				'permissions'     => $permissions,
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $consumer_key . ':' . $consumer_secret;
	}

	/**
	 * Populate the PHP_AUTH split form for a request bearing the given
	 * `ck_xxx:cs_xxx` credential. Mirrors what Apache + mod_php
	 * exposes after parsing `Authorization: Basic <base64>`.
	 *
	 * @param string $credential Joined `ck_xxx:cs_xxx`.
	 */
	private function set_basic_auth( $credential ) {
		list( $username, $password ) = explode( ':', $credential, 2 );
		$_SERVER['PHP_AUTH_USER']    = $username;
		$_SERVER['PHP_AUTH_PW']      = $password;
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
	}

	/**
	 * Remove every test-owned row from the WC API key table. Both
	 * set_up and tear_down run this so a half-completed prior test
	 * doesn't leak state into the next.
	 */
	private function wipe_test_rows() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture cleanup; no caching surface.
		$wpdb->delete(
			$wpdb->prefix . 'woocommerce_api_keys',
			array( 'description' => 'WooCommerce for Claude auth test' ),
			array( '%s' )
		);
		wp_set_current_user( 0 );
	}
}
