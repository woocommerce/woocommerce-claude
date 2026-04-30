<?php
/**
 * Hey Woo "Connect to Claude" setup page.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Admin setup page that walks a store owner through connecting Hey Woo
 * to Claude Desktop (one-click .mcpb download with the API key embedded)
 * or to other MCP clients via a copy-paste JSON snippet.
 */
class SetupPage {

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'hey-woo-setup';

	/**
	 * Capability required to view or use the setup page.
	 *
	 * Matches the capability used by the existing WC Settings tab
	 * (see HeyWoo\Settings\SettingsPage).
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Transient that drives the post-activation admin notice.
	 */
	const NOTICE_TRANSIENT = 'hey_woo_show_setup_notice';

	/**
	 * Woo core option that gates the MCP endpoint.
	 */
	const FEATURE_FLAG_OPTION = 'woocommerce_feature_mcp_integration_enabled';

	const ACTION_DOWNLOAD       = 'hey_woo_download_mcpb';
	const ACTION_REGEN_KEY      = 'hey_woo_regenerate_key';
	const ACTION_ENABLE_MCP     = 'hey_woo_enable_mcp_feature';
	const ACTION_SET_PERMS      = 'hey_woo_set_permissions';
	const ACTION_DISMISS_NOTICE = 'hey_woo_dismiss_setup_notice';

	/**
	 * Wire all hooks. Called once during plugin bootstrap.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_activation_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_action( 'admin_post_' . self::ACTION_DOWNLOAD, array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_' . self::ACTION_REGEN_KEY, array( __CLASS__, 'handle_regenerate_key' ) );
		add_action( 'admin_post_' . self::ACTION_ENABLE_MCP, array( __CLASS__, 'handle_enable_mcp' ) );
		add_action( 'admin_post_' . self::ACTION_SET_PERMS, array( __CLASS__, 'handle_set_permissions' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS_NOTICE, array( __CLASS__, 'handle_dismiss_notice' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( HEY_WOO_PLUGIN_FILE ),
			array( __CLASS__, 'add_plugin_row_link' )
		);
	}

	/**
	 * Register the WooCommerce → Hey Woo submenu entry.
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Hey Woo Setup', 'hey-woo' ),
			__( 'Hey Woo', 'hey-woo' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Build a URL pointing at the setup page, optionally with extra query args.
	 *
	 * @param array<string,string|int> $args Extra query args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $args ),
			admin_url( 'admin.php' )
		);
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
	 * Render the setup page. Loads the view template, which expects
	 * the variables prepared here.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'hey-woo' ) );
		}

		$key_helper     = new RestApiKey();
		$mcp_enabled    = self::mcp_feature_enabled();
		$site_https     = self::site_is_https();
		$endpoint_url   = self::endpoint_url();
		$current_client = isset( $_GET['client'] ) ? sanitize_key( wp_unslash( $_GET['client'] ) ) : 'claude-code'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only client picker.
		$notice_code    = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash message.

		// Provision the credential lazily once the feature flag is on so
		// the manual JSON snippet has something concrete to show. Before
		// the flag is on, generating a key would be wasted state.
		$key_state = null;
		if ( $mcp_enabled ) {
			$key_state = $key_helper->get_or_create( 'read' );
			if ( is_wp_error( $key_state ) ) {
				$key_state = null;
			}
		}

		$view_path = HEY_WOO_PLUGIN_DIR . 'includes/setup/views/page.php';
		require $view_path;
	}

	/**
	 * Handle the .mcpb download. Verifies cap + nonce + state, then
	 * streams the bundle and exits.
	 */
	public static function handle_download() {
		self::guard( self::ACTION_DOWNLOAD );

		if ( ! self::mcp_feature_enabled() ) {
			self::redirect( 'mcp_required' );
		}

		$key_helper = new RestApiKey();
		$state      = $key_helper->get_or_create( 'read' );
		if ( is_wp_error( $state ) ) {
			self::redirect( 'key_failed' );
		}

		require_once HEY_WOO_PLUGIN_DIR . 'includes/setup/class-mcpb-bundle.php';
		$bundle = new McpbBundle( self::endpoint_url(), $state['credential'], HEY_WOO_VERSION );
		$bundle->stream( 'hey-woo.mcpb' );
		// stream() exits.
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
	 * Flip the Woo core MCP feature flag to "yes" (the page only fires
	 * this action after a confirm step).
	 */
	public static function handle_enable_mcp() {
		self::guard( self::ACTION_ENABLE_MCP );

		update_option( self::FEATURE_FLAG_OPTION, 'yes' );
		self::redirect( 'mcp_enabled' );
	}

	/**
	 * Toggle between read and read_write scope on the existing key.
	 */
	public static function handle_set_permissions() {
		self::guard( self::ACTION_SET_PERMS );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by self::guard() above.
		$requested = isset( $_POST['permissions'] ) ? sanitize_key( wp_unslash( $_POST['permissions'] ) ) : 'read';
		$allowed   = array( 'read', 'read_write' );
		if ( ! in_array( $requested, $allowed, true ) ) {
			$requested = 'read';
		}

		$key_helper = new RestApiKey();
		if ( $key_helper->exists() ) {
			$key_helper->set_permissions( $requested );
		} else {
			$state = $key_helper->get_or_create( $requested );
			if ( is_wp_error( $state ) ) {
				self::redirect( 'key_failed' );
			}
		}
		self::redirect( 'permissions_updated' );
	}

	/**
	 * Hide the post-activation admin notice.
	 */
	public static function handle_dismiss_notice() {
		self::guard( self::ACTION_DISMISS_NOTICE );
		delete_transient( self::NOTICE_TRANSIENT );

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url();
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the post-activation "Connect to Claude" admin notice on
	 * every screen except the setup page itself. Suppressed once the
	 * user has both enabled the MCP feature and provisioned a key.
	 */
	public static function maybe_render_activation_notice() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( ! get_transient( self::NOTICE_TRANSIENT ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		$setup_url   = self::url();
		$dismiss_url = wp_nonce_url(
			add_query_arg( 'action', self::ACTION_DISMISS_NOTICE, admin_url( 'admin-post.php' ) ),
			self::ACTION_DISMISS_NOTICE
		);
		?>
		<div class="notice notice-info is-dismissible hey-woo-activation-notice">
			<p>
				<strong><?php esc_html_e( 'Hey Woo is active.', 'hey-woo' ); ?></strong>
				<?php esc_html_e( 'Connect this store to Claude in under a minute.', 'hey-woo' ); ?>
				<a class="button button-primary" style="margin-left:8px;" href="<?php echo esc_url( $setup_url ); ?>">
					<?php esc_html_e( 'Set up Claude', 'hey-woo' ); ?>
				</a>
				<a style="margin-left:12px;" href="<?php echo esc_url( $dismiss_url ); ?>">
					<?php esc_html_e( 'Dismiss', 'hey-woo' ); ?>
				</a>
			</p>
		</div>
		<?php
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
	 * Enqueue the page's CSS and JS, only on the setup screen.
	 *
	 * @param string $hook_suffix Current admin hook suffix.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( ! is_string( $hook_suffix ) || false === strpos( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}
		$base_url = plugins_url( 'includes/setup/assets/', HEY_WOO_PLUGIN_FILE );
		wp_enqueue_style( 'hey-woo-setup', $base_url . 'setup.css', array(), HEY_WOO_VERSION );
		wp_enqueue_script( 'hey-woo-setup', $base_url . 'setup.js', array(), HEY_WOO_VERSION, true );
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
