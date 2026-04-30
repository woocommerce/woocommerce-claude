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
	 * Woo core option that gates the MCP endpoint.
	 */
	const FEATURE_FLAG_OPTION = 'woocommerce_feature_mcp_integration_enabled';

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

	const ACTION_DOWNLOAD   = 'hey_woo_download_mcpb';
	const ACTION_REGEN_KEY  = 'hey_woo_regenerate_key';
	const ACTION_ENABLE_MCP = 'hey_woo_enable_mcp_feature';
	const ACTION_SET_PERMS  = 'hey_woo_set_permissions';
	const ACTION_DISCONNECT = 'hey_woo_disconnect';

	/**
	 * Wire all hooks. Called once during plugin bootstrap.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_action( 'admin_post_' . self::ACTION_DOWNLOAD, array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_' . self::ACTION_REGEN_KEY, array( __CLASS__, 'handle_regenerate_key' ) );
		add_action( 'admin_post_' . self::ACTION_ENABLE_MCP, array( __CLASS__, 'handle_enable_mcp' ) );
		add_action( 'admin_post_' . self::ACTION_SET_PERMS, array( __CLASS__, 'handle_set_permissions' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'handle_disconnect' ) );

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
	 * The fully-qualified Woo MCP endpoint for this site.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( 'woocommerce/mcp' );
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
	 *   https://example.com/wp-json/woocommerce/mcp
	 *     → `hey-woo-example-com-1a2b3c4d`
	 *   https://example.com/shop-a/wp-json/woocommerce/mcp
	 *     → `hey-woo-example-com-5e6f7a8b` (path differs ⇒ hash differs)
	 *   http://localhost:8888/wp-json/woocommerce/mcp
	 *     → `hey-woo-localhost-9c0d1e2f`
	 *   http://localhost:8889/wp-json/woocommerce/mcp
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
	 * Whether the Woo core MCP feature flag is currently on.
	 *
	 * @return bool
	 */
	public static function mcp_feature_enabled() {
		return 'yes' === get_option( self::FEATURE_FLAG_OPTION, 'no' );
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
	 * the owner sees the credential, the bundle download, the manual
	 * snippets, and the scope toggle. Non-owners get a "Regenerate
	 * to re-bind to you" panel.
	 */
	public static function render_setup_view() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$key_helper   = new RestApiKey();
		$mcp_enabled  = self::mcp_feature_enabled();
		$site_https   = self::site_is_https();
		$endpoint_url = self::endpoint_url();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message.
		$notice_code = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		/*
		 * Lookup is independent of the MCP flag. An existing Hey Woo
		 * REST key authenticates against the WC REST surface generally,
		 * not just /wp-json/woocommerce/mcp — so the page must surface
		 * (and offer to disconnect) an existing key even when MCP is
		 * off. Lazy provisioning still only happens when MCP is on,
		 * since there's no reason to mint a credential for a flag that
		 * gates the only thing it'd be embedded into.
		 */
		$key_state = $key_helper->existing_state();
		if ( null === $key_state && $mcp_enabled ) {
			$created = $key_helper->get_or_create( 'read' );
			if ( ! is_wp_error( $created ) ) {
				$key_state = $created;
			}
		}

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
	 *   1. MCP feature flag is on (else `mcp_required`).
	 *   2. Provisioning succeeded (else `key_failed`).
	 *   3. The provisioned key is *still* owned by $expected_user_id —
	 *      this closes the download TOCTOU where a concurrent
	 *      regenerate could transfer ownership between the initial
	 *      `require_key_owner()` gate and this method's get_or_create
	 *      call. Without the re-check, the current user could
	 *      exfiltrate a credential that was just rotated to authenticate
	 *      as a different admin.
	 *
	 * @param int $expected_user_id The user_id whose download was approved.
	 * @return array{credential:string,key_id:int,permissions:string,owner_user_id:int}|\WP_Error
	 */
	public static function prepare_download_state( $expected_user_id ) {
		if ( ! self::mcp_feature_enabled() ) {
			return new \WP_Error(
				'mcp_required',
				__( 'Enable WooCommerce MCP integration first.', 'hey-woo' )
			);
		}

		$key_helper = new RestApiKey();
		$state      = $key_helper->get_or_create( 'read' );
		if ( is_wp_error( $state ) ) {
			return new \WP_Error(
				'key_failed',
				__( 'Could not provision the API key.', 'hey-woo' )
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
	 * Rotate the API key (revoke + recreate). Preserves the current
	 * permissions scope.
	 */
	public static function handle_regenerate_key() {
		self::guard( self::ACTION_REGEN_KEY );

		$key_helper = new RestApiKey();
		$existing   = $key_helper->exists();
		$current    = $existing ? ( $key_helper->get_or_create( 'read' )['permissions'] ?? 'read' ) : 'read';
		$state      = $key_helper->regenerate( $current );

		self::redirect( is_wp_error( $state ) ? 'key_failed' : 'key_regenerated' );
	}

	/**
	 * Flip the Woo core MCP feature flag to "yes".
	 */
	public static function handle_enable_mcp() {
		self::guard( self::ACTION_ENABLE_MCP );

		update_option( self::FEATURE_FLAG_OPTION, 'yes' );
		self::redirect( 'mcp_enabled' );
	}

	/**
	 * Toggle between read and read_write scope on the existing key.
	 * Reads the new scope from the `permissions` query arg (the action
	 * is GET-based; nonce verified by self::guard()).
	 *
	 * Ownership gate: scope changes affect what the credential can
	 * do, and a non-owner shouldn't be able to escalate someone
	 * else's key. Only the owner can call this; non-owners must
	 * Regenerate first to re-bind the key to themselves.
	 */
	public static function handle_set_permissions() {
		self::guard( self::ACTION_SET_PERMS );
		self::require_key_owner();

		// Scope changes are meaningless while the MCP endpoint is off
		// — and worse, an unsuspecting merchant could escalate to
		// Read+Write thinking the integration is dormant when in fact
		// the rotated credential remains usable against the standard
		// WC REST surface. Refuse the action until MCP is back on.
		if ( ! self::mcp_feature_enabled() ) {
			self::redirect( 'mcp_required' );
		}

		$expected_user_id = get_current_user_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by self::guard() above.
		$requested = isset( $_GET['permissions'] ) ? sanitize_key( wp_unslash( $_GET['permissions'] ) ) : 'read';
		$allowed   = array( 'read', 'read_write' );
		if ( ! in_array( $requested, $allowed, true ) ) {
			$requested = 'read';
		}

		$key_helper = new RestApiKey();
		$result     = $key_helper->set_permissions( $requested );

		if ( is_wp_error( $result ) ) {
			self::redirect( 'key_failed' );
		}

		// TOCTOU re-check: a concurrent regenerate could have rotated
		// the key out from under us between the initial gate and the
		// scope change. Confirm the post-state is still ours.
		$post = $key_helper->existing_state();
		if ( null !== $post && (int) $post['owner_user_id'] !== $expected_user_id ) {
			self::redirect( 'ownership_changed' );
		}

		// Distinguish in-place downgrade from credential rotation,
		// because rotation invalidates already-distributed bundles
		// and the merchant needs to know to re-download.
		$flash = RestApiKey::SCOPE_ROTATED === $result ? 'permissions_rotated' : 'permissions_updated';
		self::redirect( $flash );
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
	 * Add a "Set up Claude" link to the plugin's row on Plugins screen.
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
			esc_html__( 'Set up Claude', 'hey-woo' )
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
