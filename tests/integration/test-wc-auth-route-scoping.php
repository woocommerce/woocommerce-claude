<?php
/**
 * WC consumer-key auth scope — pretty + plain permalinks, owned routes only.
 *
 * Pins two regressions surfaced by the 2026-04-28 Codex review of
 * `Plugin::enable_wc_auth_for_our_routes`:
 *
 *   1. Plain permalinks broke the auth filter entirely. WordPress routes
 *      REST requests via `?rest_route=/...` when permalinks are not
 *      pretty, but the original implementation only inspected the
 *      `/wp-json/...` shape inside REQUEST_URI — so on plain-permalink
 *      installs WC consumer-key auth never activated for our endpoints.
 *
 *   2. The wp-abilities/v1 opt-in was scope-bleeding. The original code
 *      returned `true` for any URI containing `wp-abilities/`, opting WC
 *      consumer-key auth into abilities registered by other plugins
 *      under the same Abilities API surface. A Hey Woo WC API key
 *      shouldn't be a usable auth path for unrelated plugins' abilities.
 *
 * Hits the filter callback directly with the relevant superglobals set.
 * No HTTP layer involved — the function is a pure inspection of
 * `$_SERVER['REQUEST_URI']` and `$_GET['rest_route']`.
 *
 * @package HeyWoo\Tests
 */

/**
 * Auth-scope tests for WC REST request classification.
 */
class Test_WC_Auth_Route_Scoping extends WP_UnitTestCase {

	/**
	 * Original REQUEST_URI before the test mutated it.
	 *
	 * Stored as `[ 'present' => bool, 'value' => string|null ]` so the
	 * tear_down can distinguish "was unset" from "was empty string".
	 *
	 * @var array{present: bool, value: ?string}
	 */
	private $original_request_uri;

	/**
	 * Original $_GET['rest_route'] before the test mutated it.
	 *
	 * @var array{present: bool, value: ?string}
	 */
	private $original_rest_route_get;

	/**
	 * Snapshot the superglobals the filter inspects.
	 */
	public function set_up() {
		parent::set_up();

		$this->original_request_uri = array(
			'present' => isset( $_SERVER['REQUEST_URI'] ),
			'value'   => isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : null,
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- snapshotting superglobals for restoration.
		$this->original_rest_route_get = array(
			'present' => isset( $_GET['rest_route'] ),
			'value'   => isset( $_GET['rest_route'] ) ? (string) $_GET['rest_route'] : null,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Restore the superglobals so cross-test contamination doesn't surface
	 * as flaky permission checks elsewhere.
	 */
	public function tear_down() {
		if ( $this->original_request_uri['present'] ) {
			$_SERVER['REQUEST_URI'] = $this->original_request_uri['value'];
		} else {
			unset( $_SERVER['REQUEST_URI'] );
		}

		if ( $this->original_rest_route_get['present'] ) {
			$_GET['rest_route'] = $this->original_rest_route_get['value'];
		} else {
			unset( $_GET['rest_route'] );
		}

		parent::tear_down();
	}

	/**
	 * Set the request superglobals for a pretty-permalink REST request.
	 *
	 * @param string $route Route relative to the REST prefix, with leading slash.
	 *                      e.g. "/hey-woo/v1/store/profile".
	 */
	private function set_pretty_permalink_request( $route ) {
		$_SERVER['REQUEST_URI'] = '/' . trailingslashit( rest_get_url_prefix() ) . ltrim( $route, '/' );
		unset( $_GET['rest_route'] );
	}

	/**
	 * Set the request superglobals for a plain-permalink REST request.
	 *
	 * @param string $route Route relative to the REST prefix, with leading slash.
	 *                      e.g. "/hey-woo/v1/store/profile".
	 */
	private function set_plain_permalink_request( $route ) {
		$_SERVER['REQUEST_URI'] = '/?rest_route=' . rawurlencode( $route );
		$_GET['rest_route']     = $route;
	}

	/**
	 * Invoke the filter callback under test.
	 *
	 * @return bool
	 */
	private function check() {
		return \HeyWoo\Plugin::instance()->enable_wc_auth_for_our_routes( false );
	}

	/**
	 * Pretty-permalink hits to the plugin's own REST namespace stay in scope.
	 */
	public function test_pretty_permalink_hey_woo_route_returns_true() {
		$this->set_pretty_permalink_request( '/hey-woo/v1/store/profile' );
		$this->assertTrue( $this->check() );
	}

	/**
	 * Plain-permalink (?rest_route=) hits to the plugin's own namespace stay in scope.
	 */
	public function test_plain_permalink_hey_woo_route_returns_true() {
		$this->set_plain_permalink_request( '/hey-woo/v1/store/profile' );
		$this->assertTrue( $this->check() );
	}

	/**
	 * Each plugin-owned Abilities namespace is in scope under pretty permalinks.
	 */
	public function test_pretty_permalink_owned_ability_namespace_returns_true() {
		foreach ( array( 'wc-analytics', 'hey-woo', 'hey-woo-integrations' ) as $namespace ) {
			$this->set_pretty_permalink_request( "/wp-abilities/v1/abilities/{$namespace}/some-skill/run" );
			$this->assertTrue(
				$this->check(),
				"Pretty permalinks: namespace '{$namespace}' should be in scope."
			);
		}
	}

	/**
	 * Each plugin-owned Abilities namespace is in scope under plain permalinks.
	 */
	public function test_plain_permalink_owned_ability_namespace_returns_true() {
		foreach ( array( 'wc-analytics', 'hey-woo', 'hey-woo-integrations' ) as $namespace ) {
			$this->set_plain_permalink_request( "/wp-abilities/v1/abilities/{$namespace}/some-skill/run" );
			$this->assertTrue(
				$this->check(),
				"Plain permalinks: namespace '{$namespace}' should be in scope."
			);
		}
	}

	/**
	 * A third-party plugin's ability namespace must not opt into WC consumer-key auth.
	 */
	public function test_pretty_permalink_third_party_ability_returns_false() {
		$this->set_pretty_permalink_request( '/wp-abilities/v1/abilities/some-other-plugin/their-skill/run' );
		$this->assertFalse(
			$this->check(),
			'Pretty permalinks: a third-party plugin\'s ability namespace must not be in scope.'
		);
	}

	/**
	 * Same scope tightening must hold under plain permalinks.
	 */
	public function test_plain_permalink_third_party_ability_returns_false() {
		$this->set_plain_permalink_request( '/wp-abilities/v1/abilities/some-other-plugin/their-skill/run' );
		$this->assertFalse(
			$this->check(),
			'Plain permalinks: a third-party plugin\'s ability namespace must not be in scope.'
		);
	}

	/**
	 * Unrelated REST routes (e.g. /wp/v2/posts) must not opt into WC auth.
	 */
	public function test_unrelated_rest_route_returns_false() {
		$this->set_pretty_permalink_request( '/wp/v2/posts' );
		$this->assertFalse( $this->check() );

		$this->set_plain_permalink_request( '/wp/v2/posts' );
		$this->assertFalse( $this->check() );
	}

	/**
	 * Existing behaviour: when WC already classified the request, the filter
	 * passes the incoming truthy value through unchanged.
	 */
	public function test_passes_through_when_already_classified_as_request() {
		$this->set_pretty_permalink_request( '/some/random/path' );
		$this->assertTrue(
			\HeyWoo\Plugin::instance()->enable_wc_auth_for_our_routes( true ),
			'Should pass through unchanged when WC has already classified the request.'
		);
	}

	/**
	 * Defensive shape: with neither REQUEST_URI nor rest_route present, the
	 * filter must return the incoming default (false here) rather than mis-classify.
	 */
	public function test_no_request_metadata_returns_pass_through() {
		unset( $_SERVER['REQUEST_URI'] );
		unset( $_GET['rest_route'] );
		$this->assertFalse(
			$this->check(),
			'When neither superglobal is set, the filter should return the incoming false.'
		);
	}

	/**
	 * Regression: a query string referencing the wp-json prefix doesn't
	 * sneak past the path-component split. Pre-fix, the loose
	 * strpos-anywhere check would have matched the substring inside
	 * the query and granted scope to a /wp/v2/posts request.
	 */
	public function test_query_string_with_owned_substring_does_not_grant_scope() {
		$_SERVER['REQUEST_URI'] = '/' . trailingslashit( rest_get_url_prefix() ) . 'wp/v2/posts?return_to=/wp-json/hey-woo/v1/foo';
		unset( $_GET['rest_route'] );
		$this->assertFalse(
			$this->check(),
			'A query string mentioning a hey-woo/ path must not opt the underlying /wp/v2/posts request into WC auth.'
		);
	}

	/**
	 * The MCP route is intentionally *outside* WC's auth scope. WC's
	 * `check_user_permissions` enforces a per-method read/write split
	 * that would 401 every MCP POST against a read-only key, so the
	 * MCP transport handles auth itself via its own permission
	 * callback. This pins the narrowing — if the prefix match ever
	 * widens back to `hey-woo/`, MCP requests with read-only keys
	 * would start 401-ing.
	 */
	public function test_mcp_route_is_outside_wc_auth_scope() {
		$this->set_pretty_permalink_request( '/hey-woo/mcp' );
		$this->assertFalse(
			$this->check(),
			'Pretty permalinks: hey-woo/mcp must NOT opt into WC auth (the MCP transport authenticates itself).'
		);

		$this->set_plain_permalink_request( '/hey-woo/mcp' );
		$this->assertFalse(
			$this->check(),
			'Plain permalinks: hey-woo/mcp must NOT opt into WC auth.'
		);
	}

	/**
	 * `Plugin::exclude_mcp_route_from_app_password_auth` is the second
	 * half of the auth picture. Without it, on any site where a
	 * `WP_Application_Passwords` row exists,
	 * `wp_authenticate_application_password()` runs at
	 * `determine_current_user` priority 20 against our `ck_xxx`
	 * username, fails with `invalid_username`, stores the error on the
	 * `$wp_rest_application_password_status` global, and
	 * `rest_application_password_check_errors` returns 401 before our
	 * route permission callback ever runs. Returning false here makes
	 * `wp_authenticate_application_password()` early-return without
	 * touching the global, so the MCP transport's own callback runs
	 * cleanly.
	 *
	 * Pin the deny path so a regression doesn't silently re-open the
	 * 401 cliff for any merchant who has app passwords in use.
	 */
	public function test_mcp_route_excluded_from_app_password_auth() {
		$plugin = \HeyWoo\Plugin::instance();

		$this->set_pretty_permalink_request( '/hey-woo/mcp' );
		$this->assertFalse(
			$plugin->exclude_mcp_route_from_app_password_auth( true ),
			'Pretty permalinks: hey-woo/mcp must opt out of app-password auth.'
		);

		$this->set_plain_permalink_request( '/hey-woo/mcp' );
		$this->assertFalse(
			$plugin->exclude_mcp_route_from_app_password_auth( true ),
			'Plain permalinks: hey-woo/mcp must opt out of app-password auth.'
		);
	}

	/**
	 * The app-password exclusion must be surgical — every other REST
	 * route (including our own `hey-woo/v1/...` REST controllers) keeps
	 * WP's normal app-password handling. Anything else would silently
	 * disable a documented WP feature on unrelated routes.
	 */
	public function test_app_password_auth_passes_through_for_non_mcp_routes() {
		$plugin = \HeyWoo\Plugin::instance();

		foreach ( array( '/hey-woo/v1/store/profile', '/wp/v2/posts', '/wc/v3/orders', '/wp-abilities/v1/abilities/wc-analytics/get-revenue-summary/run' ) as $route ) {
			$this->set_pretty_permalink_request( $route );
			$this->assertTrue(
				$plugin->exclude_mcp_route_from_app_password_auth( true ),
				"Route {$route} must keep WP's app-password handling."
			);
		}
	}

	/**
	 * Pass-through when WP would not have considered the request an
	 * API request anyway. We only ever flip true → false; we never
	 * coerce false → true.
	 */
	public function test_app_password_filter_passes_through_when_already_false() {
		$plugin = \HeyWoo\Plugin::instance();
		$this->set_pretty_permalink_request( '/hey-woo/mcp' );
		$this->assertFalse(
			$plugin->exclude_mcp_route_from_app_password_auth( false ),
			'When the incoming value is already false, the filter is a no-op pass-through.'
		);
	}

	/**
	 * The plugin must suppress the WP MCP adapter's auto-created
	 * default server. That endpoint at
	 * `/wp-json/mcp/mcp-adapter-default-server` uses the adapter's
	 * default `current_user_can('read')` permission instead of our
	 * `authenticate_mcp_request` callback, so leaving it on would
	 * expose a second, non-curated MCP surface — including any
	 * abilities a third-party plugin marks as `mcp.public`. The setup
	 * flow only scopes access to `/wp-json/hey-woo/mcp`, so the
	 * default endpoint is an unsupervised parallel surface.
	 *
	 * Pin the filter contract: after Plugin::instance() runs,
	 * `mcp_adapter_create_default_server` resolves to false regardless
	 * of the incoming value. If a future refactor drops the filter
	 * registration, this test fails loud.
	 */
	public function test_default_mcp_adapter_server_is_suppressed() {
		// Trigger plugin bootstrap if it hasn't already happened in this test run.
		\HeyWoo\Plugin::instance();

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- synthetic application of an upstream filter for assertion only; not a hook definition.
		$result = apply_filters( 'mcp_adapter_create_default_server', true );

		$this->assertFalse(
			$result,
			'mcp_adapter_create_default_server must resolve to false so the default endpoint is not registered.'
		);
	}
}
