<?php
/**
 * Hey Woo "Connect to Claude" setup view.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Setup view rendered inside the WooCommerce Settings → Hey Woo tab
 * (default section). Walks a store owner through connecting Hey Woo
 * to Claude Desktop (one-click .mcpb download with the API key
 * embedded) or to other MCP clients via a copy-paste JSON snippet.
 *
 * State-changing actions are exposed as `wp_nonce_url`-protected GET
 * links rather than POST forms because the view is rendered inside
 * WC's outer <form id="mainform"> wrapper, and HTML disallows nested
 * forms.
 */
class SetupPage {

	/**
	 * Capability required to view or use the setup page.
	 *
	 * Matches the capability used by the WC Settings tab itself.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Pinned npm spec for the stdio→HTTP MCP proxy. Embedded into both
	 * the .mcpb bundle's mcp_config.args and the manual JSON / CLI
	 * snippets so a merchant who installed today still runs the same
	 * proxy in 12 months. Bump on each plugin release after vetting
	 * the upstream changelog.
	 */
	const REMOTE_PACKAGE = '@automattic/mcp-wordpress-remote@0.3.0';

	/**
	 * WC settings tab id this page lives under.
	 */
	const SETTINGS_TAB = 'hey-woo';

	const ACTION_DOWNLOAD     = 'hey_woo_download_mcpb';
	const ACTION_REGEN_KEY    = 'hey_woo_regenerate_key';
	const ACTION_DISCONNECT   = 'hey_woo_disconnect';
	const ACTION_GENERATE_KEY = 'hey_woo_generate_key';

	/**
	 * Wire all hooks. Called once during plugin bootstrap.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_action( 'admin_post_' . self::ACTION_DOWNLOAD, array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_' . self::ACTION_REGEN_KEY, array( __CLASS__, 'handle_regenerate_key' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'handle_disconnect' ) );
		add_action( 'admin_post_' . self::ACTION_GENERATE_KEY, array( __CLASS__, 'handle_generate_key' ) );

		// Restrict the setup credential to the Hey Woo MCP endpoint only.
		// WC's API key auth runs at priority 10 on `determine_current_user`;
		// `rest_authentication_errors` runs after that and before the
		// route is dispatched, which is the right point to refuse a
		// route the setup key shouldn't reach.
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'enforce_setup_key_route_scope' ), 50 );

		add_filter(
			'plugin_action_links_' . plugin_basename( HEY_WOO_PLUGIN_FILE ),
			array( __CLASS__, 'add_plugin_row_link' )
		);
	}

	/**
	 * Build the URL of the setup page (WC Settings → Hey Woo, default
	 * section).
	 *
	 * @param array<string,string|int> $args Extra query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => 'wc-settings',
					'tab'  => self::SETTINGS_TAB,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Build a nonce-protected URL for one of the admin-post actions.
	 *
	 * @param string                   $action The action name (also the nonce action).
	 * @param array<string,string|int> $extra  Extra query args to append.
	 * @return string
	 */
	public static function action_url( $action, $extra = array() ) {
		$base = add_query_arg(
			array_merge( array( 'action' => $action ), $extra ),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $base, $action );
	}

	/**
	 * The fully-qualified Hey Woo MCP endpoint for this site.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( \HeyWoo\Plugin::MCP_SERVER_NS . '/' . \HeyWoo\Plugin::MCP_SERVER_ROUTE );
	}

	/**
	 * A stable per-store identifier suitable for use as the MCPB
	 * manifest name, the manual `mcpServers` key, the `claude mcp add`
	 * server name, and the bundle filename.
	 *
	 * Derives from the *full* MCP endpoint URL (host + port + path),
	 * not just the host, so subdirectory multisite installs and
	 * same-host different-port dev stores get distinct slugs:
	 *
	 *   https://example.com/wp-json/hey-woo/mcp
	 *     → `hey-woo-example-com-1a2b3c4d`
	 *   https://example.com/shop-a/wp-json/hey-woo/mcp
	 *     → `hey-woo-example-com-5e6f7a8b` (path differs ⇒ hash differs)
	 *   http://localhost:8888/wp-json/hey-woo/mcp
	 *     → `hey-woo-localhost-9c0d1e2f`
	 *   http://localhost:8889/wp-json/hey-woo/mcp
	 *     → `hey-woo-localhost-3a4b5c6d` (port differs ⇒ hash differs)
	 *
	 * @return string
	 */
	public static function server_slug() {
		return self::derive_server_slug( self::endpoint_url() );
	}

	/**
	 * Pure slug derivation: takes a full URL, returns a slug.
	 * Exposed as a separate static so tests can pass arbitrary URLs
	 * without touching the WP environment.
	 *
	 * @param string $url Full URL including scheme, host, optional port and path.
	 * @return string
	 */
	public static function derive_server_slug( $url ) {
		$url  = is_string( $url ) ? $url : '';
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$host = is_string( $host ) ? sanitize_title( $host ) : '';
		// Hash the full URL so any difference in host, port, or path
		// produces a distinct slug — even when the visible host part
		// is identical between two stores.
		$digest = '' === $url ? '' : substr( hash( 'sha256', $url ), 0, 8 );

		if ( '' === $host && '' === $digest ) {
			return 'hey-woo';
		}
		if ( '' === $host ) {
			return 'hey-woo-' . $digest;
		}
		if ( '' === $digest ) {
			return 'hey-woo-' . $host;
		}
		return 'hey-woo-' . $host . '-' . $digest;
	}

	/**
	 * Whether home_url() resolves to an https:// URL — used to warn
	 * about local-dev HTTP setups in the page UI.
	 *
	 * @return bool
	 */
	public static function site_is_https() {
		return 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME );
	}

	/**
	 * Render the setup view. Called by SettingsPage::output() when the
	 * default section is active. Outputs HTML directly.
	 *
	 * Computes `$is_owner` — whether the current user is the WP user
	 * the WC API key is bound to. WC API keys authenticate as their
	 * `user_id`, so showing the credential to a different admin (or
	 * to a shop manager with `manage_woocommerce`) would let them
	 * exfiltrate it and impersonate the original owner remotely. Only
	 * the owner sees the credential, the bundle download, and the
	 * manual snippets. Non-owners get a "Regenerate to re-bind to you"
	 * panel.
	 */
	public static function render_setup_view() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$key_helper   = new RestApiKey();
		$site_https   = self::site_is_https();
		$endpoint_url = self::endpoint_url();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message.
		$notice_code = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		/*
		 * Render-time lookup is read-only. An existing Hey Woo REST
		 * key authenticates against the WC REST surface generally, not
		 * just /wp-json/hey-woo/mcp — so the page must surface (and
		 * offer to disconnect) an existing key. Provisioning is gated
		 * behind the explicit "Generate key" button (see
		 * handle_generate_key) rather than happening silently on
		 * render — the merchant should always know when a credential
		 * has been minted on their behalf.
		 */
		$key_state = $key_helper->existing_state();

		$current_user_id = get_current_user_id();
		$owner_user_id   = is_array( $key_state ) ? (int) ( $key_state['owner_user_id'] ?? 0 ) : 0;
		$is_owner        = $owner_user_id > 0 && $owner_user_id === $current_user_id;
		$owner_display   = '';
		if ( $owner_user_id > 0 && ! $is_owner ) {
			$owner_user    = get_userdata( $owner_user_id );
			$owner_display = $owner_user ? $owner_user->display_name : __( 'another administrator', 'hey-woo' );
		}

		require HEY_WOO_PLUGIN_DIR . 'includes/setup/views/page.php';
	}

	/**
	 * Handle the .mcpb download. Verifies cap + nonce + ownership +
	 * state, then streams the bundle and exits.
	 *
	 * Ownership gate: the bundle embeds the WC API credential, which
	 * authenticates as the user the key was provisioned under. Only
	 * that user can download — otherwise a different admin could
	 * exfiltrate a credential bound to someone else's user_id.
	 */
	public static function handle_download() {
		self::guard( self::ACTION_DOWNLOAD );
		self::require_key_owner();

		$state = self::prepare_download_state( get_current_user_id() );
		if ( is_wp_error( $state ) ) {
			self::redirect( $state->get_error_code() );
		}

		require_once HEY_WOO_PLUGIN_DIR . 'includes/setup/class-mcpb-bundle.php';
		$bundle = new McpbBundle( self::endpoint_url(), $state['credential'], HEY_WOO_VERSION );
		$bundle->stream( self::server_slug() . '.mcpb' );
		// stream() exits.
	}

	/**
	 * Resolve the key state we'll bake into a download bundle, or
	 * return a WP_Error whose code maps to a flash-redirect notice.
	 *
	 * Extracted from handle_download() so the precondition logic —
	 * including the post-provision ownership re-check — is testable
	 * without fighting wp_safe_redirect/exit. Validates, in order:
	 *
	 *   1. A key already exists (else `key_required`). The download is
	 *      a *use* of an explicitly-generated credential — never a
	 *      mint path. Without this gate a stale or bookmarked download
	 *      URL with a still-valid nonce could silently provision a
	 *      default-permissions key, bypassing the explicit Generate
	 *      step and the merchant's chosen scope.
	 *   2. The current key is *still* owned by $expected_user_id —
	 *      this closes the download TOCTOU where a concurrent
	 *      regenerate could transfer ownership between the initial
	 *      `require_key_owner()` gate and this method's lookup. Without
	 *      the re-check, the current user could exfiltrate a credential
	 *      that was just rotated to authenticate as a different admin.
	 *
	 * @param int $expected_user_id The user_id whose download was approved.
	 * @return array{credential:string,key_id:int,permissions:string,owner_user_id:int}|\WP_Error
	 */
	public static function prepare_download_state( $expected_user_id ) {
		$key_helper = new RestApiKey();
		$state      = $key_helper->existing_state();
		if ( null === $state ) {
			return new \WP_Error(
				'key_required',
				__( 'Generate an API key in Step 1 before downloading.', 'hey-woo' )
			);
		}

		if ( (int) ( $state['owner_user_id'] ?? 0 ) !== (int) $expected_user_id ) {
			return new \WP_Error(
				'ownership_changed',
				__( 'The API key was rotated by another admin while your download was being prepared. Refresh the page and try again.', 'hey-woo' )
			);
		}

		return $state;
	}

	/**
	 * Rotate the API key (revoke + recreate). Always issues a fresh
	 * read-only key; if the merchant had previously broadened the
	 * scope under WooCommerce → Settings → Advanced → REST API,
	 * they re-apply that change there after regenerating.
	 *
	 * Refuses if no key exists — Regenerate is a rotation primitive,
	 * never a mint primitive. Without this gate a stale URL could
	 * silently create a key, bypassing the explicit Generate step.
	 */
	public static function handle_regenerate_key() {
		self::guard( self::ACTION_REGEN_KEY );

		$key_helper = new RestApiKey();
		if ( null === $key_helper->existing_state() ) {
			self::redirect( 'key_required' );
		}

		$state = $key_helper->regenerate();

		self::redirect( is_wp_error( $state ) ? 'key_failed' : 'key_regenerated' );
	}

	/**
	 * Provision the Hey Woo REST API key from the explicit Step 1 form.
	 * Always provisions a read-only key — the merchant can broaden it
	 * later under WooCommerce → Settings → Advanced → REST API.
	 *
	 * Gated by:
	 *  - manage_woocommerce + nonce (self::guard)
	 *  - No existing key — generation is a create-only action; if a key
	 *    already exists the merchant should use Regenerate (which
	 *    explicitly invalidates distributed bundles) instead of
	 *    silently rotating.
	 *
	 * The description column is always written as the canonical
	 * KEY_DESCRIPTION so RestApiKey::delete_owned_rows() retains its
	 * description-based orphan-cleanup safety net.
	 */
	public static function handle_generate_key() {
		self::guard( self::ACTION_GENERATE_KEY );

		$key_helper = new RestApiKey();
		if ( null !== $key_helper->existing_state() ) {
			// Idempotent landing for double-submits — surface the
			// existing key rather than treating it as an error or
			// silently rotating.
			//
			// Use the same "key exists" definition the renderer uses
			// (existing_state, not exists) so partial state — e.g.
			// OPTION_KEY_ID points at a live WC row but OPTION_CREDENTIAL
			// was lost in a compensating-cleanup race — drops through
			// to get_or_create(), whose stale-state recovery clears
			// the dangling option and lets create() revoke the orphan
			// row via its canonical KEY_DESCRIPTION marker before
			// inserting a fresh credential. Without this alignment the
			// renderer would show the Generate form while the handler
			// kept refusing — leaving the merchant stuck without a DB
			// edit.
			self::redirect( 'key_exists' );
		}

		$state = $key_helper->get_or_create();
		self::redirect( is_wp_error( $state ) ? 'key_failed' : 'key_generated' );
	}

	/**
	 * Explicitly disconnect: revoke the WC API key and clear the
	 * stored credential. Mirrors the deactivation hook but exposed
	 * as a UI action so a merchant can tear down the connection
	 * without deactivating the plugin (e.g. they want to keep the
	 * settings tab around but stop Claude's access today).
	 *
	 * Available to any user with `manage_woocommerce`, including
	 * non-owners — disconnect is a destructive cleanup that the
	 * setup-page admin should always be able to perform regardless
	 * of who provisioned the key.
	 */
	public static function handle_disconnect() {
		self::guard( self::ACTION_DISCONNECT );

		( new RestApiKey() )->revoke();
		self::redirect( 'disconnected' );
	}

	/**
	 * Add a "Setup" link to the plugin's row on the Plugins screen.
	 *
	 * @param array<int|string,string> $links Existing links.
	 * @return array<int|string,string>
	 */
	public static function add_plugin_row_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}
		$setup_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::url() ),
			esc_html__( 'Setup', 'hey-woo' )
		);
		array_unshift( $links, $setup_link );
		return $links;
	}

	/**
	 * Enqueue the page's CSS and JS, only on the WC Settings → Hey
	 * Woo Setup section.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! self::is_setup_page_request( $hook_suffix ) ) {
			return;
		}

		$base_url  = plugins_url( 'includes/setup/assets/', HEY_WOO_PLUGIN_FILE );
		$base_path = HEY_WOO_PLUGIN_DIR . 'includes/setup/assets/';

		// Use filemtime() so any edit to the asset auto-busts the
		// browser cache. Plugin version doesn't change between iterations
		// during pre-release development, so a static version cached the
		// old CSS even after the file was rewritten.
		$css_path = $base_path . 'setup.css';
		$js_path  = $base_path . 'setup.js';
		$css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : HEY_WOO_VERSION;
		$js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : HEY_WOO_VERSION;

		wp_enqueue_style( 'hey-woo-setup', $base_url . 'setup.css', array(), $css_ver );
		wp_enqueue_script( 'hey-woo-setup', $base_url . 'setup.js', array(), $js_ver, true );

		// Hide WC's outer Save Changes button on this section — the
		// setup view has its own actioned controls and nothing to
		// "save". Scoped to #mainform > .submit so it only suppresses
		// the WC-emitted save row, not anything inside our cards.
		wp_add_inline_style(
			'hey-woo-setup',
			'#mainform > p.submit { display: none; }'
		);
	}

	/**
	 * Whether the current request is the setup page (WC Settings →
	 * Hey Woo, default section). Caller may pass the admin hook suffix
	 * if it has it; falls back to the request's GET vars otherwise.
	 *
	 * @param string|null $hook_suffix Optional admin hook suffix.
	 * @return bool
	 */
	private static function is_setup_page_request( $hook_suffix = null ) {
		if ( null !== $hook_suffix && 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return false;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only request inspection.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$sec  = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page && self::SETTINGS_TAB === $tab && '' === $sec;
	}

	/**
	 * Server-side enforcement: the auto-created Hey Woo REST API
	 * key is technically a standard `woocommerce_api_keys` row, so
	 * by default it would authenticate against any WC REST surface
	 * (`/wc/v3/orders`, `/wc/v3/customers`, etc.). The setup UI
	 * implies the credential is scoped to the MCP integration, and
	 * the bundle only ever calls `/wp-json/hey-woo/mcp` — so we
	 * reject the key on every other route here.
	 *
	 * This shrinks the blast radius if the bundle leaks: the
	 * credential is useless against the standard WC REST API even
	 * with valid `ck_…:cs_…` pair. A merchant who wants direct WC
	 * REST access for development should create a separate API key
	 * the normal way.
	 *
	 * @param mixed $error Existing error (or null) from earlier in the auth chain.
	 * @return mixed
	 */
	public static function enforce_setup_key_route_scope( $error ) {
		// Earlier auth filter already errored — pass through.
		if ( ! empty( $error ) ) {
			return $error;
		}

		$presented = self::extract_request_credential();
		if ( '' === $presented ) {
			return $error;
		}

		$stored = get_option( RestApiKey::OPTION_CREDENTIAL, '' );
		if ( '' === $stored || ! hash_equals( (string) $stored, $presented ) ) {
			return $error;
		}

		// The request authenticated with our setup credential. Now
		// bound to the MCP endpoint only.
		if ( ! self::route_is_allowed_for_setup_key( self::current_rest_route() ) ) {
			return new \WP_Error(
				'hey_woo_route_restricted',
				__( 'This API key is restricted to the Hey Woo MCP endpoint. Create a separate WooCommerce REST API key for direct WC REST access.', 'hey-woo' ),
				array( 'status' => 403 )
			);
		}
		return $error;
	}

	/**
	 * Whether the given REST route is one the setup credential is
	 * allowed to authenticate. Anchored on the canonical Hey Woo MCP
	 * endpoint — `/wp-json/hey-woo/mcp` and any subpaths the MCP
	 * adapter's transport may add (e.g. session-id sub-routes).
	 *
	 * @param string $route REST route relative to the REST prefix (no leading slash).
	 * @return bool
	 */
	private static function route_is_allowed_for_setup_key( $route ) {
		if ( '' === $route ) {
			return false;
		}
		$allowed = \HeyWoo\Plugin::MCP_SERVER_NS . '/' . \HeyWoo\Plugin::MCP_SERVER_ROUTE;
		return 0 === strpos( $route, $allowed );
	}

	/**
	 * Extract the credential from the current REST request, if any.
	 * Recognises the surfaces WC's REST auth reads (Basic auth split
	 * form via PHP_AUTH_USER/PW; raw HTTP_AUTHORIZATION; query-string
	 * `consumer_key` / `consumer_secret`) plus the legacy
	 * `X-MCP-API-Key` header.
	 *
	 * The legacy header is no longer accepted by our MCP auth callback,
	 * but pre-migration `.mcpb` bundles distributed against earlier
	 * plugin versions still send it — typically targeting the
	 * deprecated WC core MCP endpoint at `/wp-json/woocommerce/mcp`.
	 * Reading it here means `enforce_setup_key_route_scope()` can
	 * still recognise our credential on those legacy requests and
	 * deny them on every route except `/wp-json/hey-woo/mcp`. Drop
	 * the header here and a leaked legacy bundle silently keeps
	 * authenticating against the WC core endpoint.
	 *
	 * Returns '' if no credential is present (the request will be
	 * unauthenticated or rely on cookies/nonces instead, neither of
	 * which we want to gate here).
	 *
	 * @return string
	 */
	private static function extract_request_credential() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only inspection of an in-flight REST request.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- credentials are byte-compared, not interpolated; sanitisation would corrupt them.

		// 1. Legacy X-MCP-API-Key header — kept for defense-in-depth so
		// pre-migration bundles still hit the route-scope deny path on
		// non-allowed routes. Not a supported auth path for the new
		// /wp-json/hey-woo/mcp endpoint (Plugin::authenticate_mcp_request
		// only reads Basic auth).
		if ( ! empty( $_SERVER['HTTP_X_MCP_API_KEY'] ) ) {
			return wp_unslash( (string) $_SERVER['HTTP_X_MCP_API_KEY'] );
		}

		// 2a. PHP_AUTH_USER + PHP_AUTH_PW — the split form Apache/mod_php
		// (and many fastcgi setups) expose Basic auth as. WC's auth reads
		// these directly, so the route-scope filter must too — otherwise
		// a request bearing our setup credential against a non-MCP route
		// (e.g. /wc/v3/orders) would slip past the scope check on any
		// SAPI that doesn't surface HTTP_AUTHORIZATION.
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			return wp_unslash( (string) $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( (string) $_SERVER['PHP_AUTH_PW'] );
		}

		// 2b. HTTP Basic auth (raw header form) — username:password = ck_xxx:cs_xxx.
		// Reads HTTP_AUTHORIZATION first, then REDIRECT_HTTP_AUTHORIZATION as
		// a fallback. The latter is what shows up on CGI/FastCGI behind
		// Apache `mod_rewrite` — the same SAPI shape `WP_REST_Server::get_headers()`
		// normalises into the request's `Authorization` header. We can't
		// reach that normalised value from this `rest_authentication_errors`
		// filter (no request param), so cover the alternate $_SERVER key
		// directly, otherwise our setup credential could be replayed
		// against non-MCP routes on those SAPIs without our scope guard
		// recognising it.
		$auth = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = wp_unslash( (string) $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = wp_unslash( (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		}
		if ( 0 === stripos( $auth, 'Basic ' ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding HTTP Basic auth header per RFC 7617; strict mode rejects invalid input.
			$decoded = base64_decode( substr( $auth, 6 ), true );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				return $decoded;
			}
		}

		// 3. Query string consumer_key + consumer_secret (WC legacy auth).
		if ( ! empty( $_GET['consumer_key'] ) && ! empty( $_GET['consumer_secret'] ) ) {
			return wp_unslash( (string) $_GET['consumer_key'] ) . ':' . wp_unslash( (string) $_GET['consumer_secret'] );
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return '';
	}

	/**
	 * Resolve the REST route the current request targets, regardless
	 * of pretty (/wp-json/<route>) vs plain (?rest_route=/<route>)
	 * permalinks. Returns the route relative to the REST prefix
	 * without a leading slash, or '' if the request isn't a REST
	 * request.
	 *
	 * @return string
	 */
	private static function current_rest_route() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only inspection of an in-flight REST request.
		if ( isset( $_GET['rest_route'] ) ) {
			$route = wp_unslash( $_GET['rest_route'] );
			return is_string( $route ) ? ltrim( $route, '/' ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			return '';
		}
		$path = strtok( $request_uri, '?#' );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$rest_prefix = trailingslashit( rest_get_url_prefix() );
		$position    = strpos( $path, $rest_prefix );
		if ( false === $position ) {
			return '';
		}
		return substr( $path, $position + strlen( $rest_prefix ) );
	}

	/**
	 * Refuse the request if a key exists and the current user isn't
	 * the WP user it's bound to. Used to gate credential-disclosing
	 * and scope-changing actions; Regenerate is *not* gated, since
	 * that's the explicit re-bind path for non-owners.
	 *
	 * No-op when no key is provisioned yet (current user becomes
	 * owner on creation).
	 */
	private static function require_key_owner() {
		$owner = ( new RestApiKey() )->owner_user_id();
		if ( 0 < $owner && get_current_user_id() !== $owner ) {
			wp_die(
				esc_html__( 'This action is restricted to the admin who provisioned the Hey Woo API key. Use Regenerate on the setup page to re-bind the key to your user, then try again.', 'hey-woo' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Standard guard for admin-post handlers: verify capability +
	 * nonce, or wp_die with a 403.
	 *
	 * @param string $action The action name (used as the nonce name).
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to perform this action.', 'hey-woo' ),
				'',
				array( 'response' => 403 )
			);
		}
		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the setup page with an optional notice code.
	 *
	 * @param string $notice One of the supported notice codes.
	 */
	private static function redirect( $notice = '' ) {
		$args = array();
		if ( '' !== $notice ) {
			$args['notice'] = $notice;
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}
}
