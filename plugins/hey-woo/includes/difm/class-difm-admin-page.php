<?php
/**
 * WP Admin integration for Hey Woo.
 *
 * Loads the wp-build generated build/build.php (which registers all script
 * modules, routes, and the full-page boot interceptor) and wires up the
 * WooCommerce submenu entry when an Anthropic API key is configured. The boot
 * interceptor handles actual page rendering on admin_init, so the submenu
 * callback is never invoked.
 *
 * Page-load data (nonce, REST base, etc.) is injected as window.heyWooData
 * via an inline script hooked into the hey-woo-insights_init action,
 * which the generated page.php fires just before it enqueues assets.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Hey Woo WP Admin page and integrates with @wordpress/boot.
 *
 * PHP 7.4 compatible — no union types, no match, no enums.
 */
class DifmAdminPage {

	/**
	 * Menu slug for the admin page — must match wpPlugin.pages[].id in package.json.
	 */
	const MENU_SLUG = 'hey-woo-insights';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render_missing_runtime_notice' ) );

		if ( ! $this->has_required_runtime() ) {
			return;
		}

		$build_entry = HEY_WOO_PLUGIN_DIR . 'build/build.php';
		if ( file_exists( $build_entry ) ) {
			require_once $build_entry;
		}

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'hey-woo-insights_init', array( $this, 'on_init' ) );
	}

	/**
	 * Add the Hey Woo submenu under WooCommerce.
	 *
	 * Hey Woo needs a merchant-provided Anthropic API key before it can be
	 * used, so keep the navigation out of the way until one is configured.
	 *
	 * The callback is __return_null because the boot interceptor in build/build.php
	 * renders the full-page SPA on admin_init and calls exit() before WordPress
	 * ever invokes this callback.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		if ( ! $this->has_anthropic_api_key() || ! $this->has_required_runtime() ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Ask Claude', 'hey-woo' ),
			__( 'Ask Claude', 'hey-woo' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			'__return_null'
		);
	}

	/**
	 * Called by the generated page.php via do_action('hey-woo-insights_init').
	 *
	 * Fires inside heywoo_hey_woo_insights_render_page(), after all admin
	 * scripts have been dequeued but before the prerequisites handle is registered.
	 * Registers the boot sidebar menu item and injects page-load data as a JS global.
	 *
	 * @return void
	 */
	public function on_init() {
		if ( ! $this->has_required_runtime() ) {
			return;
		}

		// Register the sidebar menu item for the boot navigation shell.
		if ( $this->has_anthropic_api_key() && function_exists( 'heywoo_register_hey_woo_insights_menu_item' ) ) {
			heywoo_register_hey_woo_insights_menu_item(
				'ai-insights',
				__( 'Ask Claude', 'hey-woo' ),
				'/'
			);
		}

		// Build page-load data — mirrors the old wp_localize_script() payload.
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-difm-conversations-controller.php';

		$user_id = get_current_user_id();

		$data = array(
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'restBase'      => rest_url( 'hey-woo/v1/difm' ),
			'settingsUrl'   => admin_url( 'admin.php?page=wc-settings&tab=hey-woo' ),
			'userName'      => wp_get_current_user()->display_name,
			'currency'      => get_woocommerce_currency_symbol(),
			'hasKey'        => AnthropicClient::has_api_key(),
			'conversations' => DifmConversationsController::get_recent_conversations( $user_id ),
		);

		// Register an inline-only script handle so print_footer_scripts() outputs the data.
		wp_register_script( 'hey-woo-page-data', false, array(), HEY_WOO_VERSION, true );
		wp_add_inline_script(
			'hey-woo-page-data',
			'window.heyWooData = ' . wp_json_encode( $data, JSON_HEX_TAG ) . ';'
		);
		wp_enqueue_script( 'hey-woo-page-data' );
	}

	/**
	 * Show a requirement notice when the BYOK app cannot load safely.
	 *
	 * @return void
	 */
	public function render_missing_runtime_notice() {
		if ( $this->has_required_runtime() || ! $this->has_anthropic_api_key() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Ask Claude requires Gutenberg or WordPress 7.0.', 'hey-woo' ); ?></strong>
				<?php esc_html_e( 'Install and activate the Gutenberg plugin, or upgrade to WordPress 7.0 or later, to use Ask Claude with your Anthropic API key.', 'hey-woo' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether the boot-based Hey Woo runtime is available.
	 *
	 * @return bool
	 */
	public function has_required_runtime() {
		$has_required_runtime = defined( 'GUTENBERG_VERSION' ) || version_compare( get_bloginfo( 'version' ), '7.0-alpha', '>=' );

		/**
		 * Filter whether Hey Woo can load the required boot runtime.
		 *
		 * @since 0.2.0
		 *
		 * @param bool $has_required_runtime True when Gutenberg is active or WordPress 7.0+ is running.
		 */
		return (bool) apply_filters( 'hey_woo_difm_has_required_runtime', $has_required_runtime );
	}

	/**
	 * Whether an Anthropic API key is configured for Hey Woo.
	 *
	 * @return bool
	 */
	private function has_anthropic_api_key() {
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		return AnthropicClient::has_api_key();
	}
}
