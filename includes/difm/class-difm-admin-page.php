<?php
/**
 * WP Admin integration for WooCommerce for Claude AI Insights.
 *
 * Loads the wp-build generated build/build.php (which registers all script
 * modules, routes, and the full-page boot interceptor) and wires up the
 * WooCommerce submenu entry when an Anthropic API key is configured. The boot
 * interceptor handles actual page rendering on admin_init, so the submenu
 * callback is never invoked.
 *
 * Page-load data (nonce, REST base, etc.) is injected as window.woocommerceClaudeTodayData
 * via an inline script hooked into the woocommerce-claude-insights_init action,
 * which the generated page.php fires just before it enqueues assets.
 *
 * @package WooCommerce\Claude\Difm
 */

namespace WooCommerce\Claude\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the AI Insights WP Admin page and integrates with @wordpress/boot.
 *
 * PHP 7.4 compatible — no union types, no match, no enums.
 */
class DifmAdminPage {

	/**
	 * Menu slug for the admin page — must match wpPlugin.pages[].id in package.json.
	 */
	const MENU_SLUG = 'woocommerce-claude-insights';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		$build_entry = WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'build/build.php';
		if ( file_exists( $build_entry ) ) {
			require_once $build_entry;
		}

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'woocommerce-claude-insights_init', array( $this, 'on_init' ) );
	}

	/**
	 * Add the AI Insights submenu under WooCommerce.
	 *
	 * AI Insights needs a merchant-provided Anthropic API key before it can be
	 * used, so keep the navigation out of the way until one is configured.
	 *
	 * The callback is __return_null because the boot interceptor in build/build.php
	 * renders the full-page SPA on admin_init and calls exit() before WordPress
	 * ever invokes this callback.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		if ( ! $this->has_api_key() ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'AI Insights', 'woocommerce-claude' ),
			__( 'AI Insights', 'woocommerce-claude' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			'__return_null'
		);
	}

	/**
	 * Called by the generated page.php via do_action('woocommerce-claude-insights_init').
	 *
	 * Fires inside jpa_woocommerce_claude_insights_render_page(), after all admin
	 * scripts have been dequeued but before the prerequisites handle is registered.
	 * Registers the boot sidebar menu item and injects page-load data as a JS global.
	 *
	 * @return void
	 */
	public function on_init() {
		// Register the sidebar menu item for the boot navigation shell.
		if ( $this->has_api_key() ) {
			$this->register_boot_menu_item();
		}

		// Build page-load data — mirrors the old wp_localize_script() payload.
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-difm-conversations-controller.php';

		$user_id = get_current_user_id();

		$data = array(
			'nonce'         => wp_create_nonce( 'wp_rest' ),
			'restBase'      => rest_url( 'woocommerce-claude/v1/difm' ),
			'settingsUrl'   => admin_url( 'admin.php?page=wc-settings&tab=woocommerce-claude' ),
			'userName'      => wp_get_current_user()->display_name,
			'currency'      => get_woocommerce_currency_symbol(),
			'hasKey'        => AnthropicClient::has_api_key(),
			'conversations' => DifmConversationsController::get_recent_conversations( $user_id ),
		);

		// Register an inline-only script handle so print_footer_scripts() outputs the data.
		wp_register_script( 'woocommerce-claude-page-data', false, array(), WOOCOMMERCE_CLAUDE_VERSION, true );
		wp_add_inline_script(
			'woocommerce-claude-page-data',
			'window.woocommerceClaudeTodayData = ' . wp_json_encode( $data, JSON_HEX_TAG ) . ';'
		);
		wp_enqueue_script( 'woocommerce-claude-page-data' );
	}

	/**
	 * Whether an Anthropic API key is configured for AI Insights.
	 *
	 * @return bool
	 */
	private function has_api_key() {
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		return AnthropicClient::has_api_key();
	}

	/**
	 * Register the AI Insights item in the generated boot sidebar menu.
	 *
	 * @return void
	 */
	protected function register_boot_menu_item() {
		if ( ! function_exists( 'wcai_register_woocommerce_claude_insights_menu_item' ) ) {
			return;
		}

		wcai_register_woocommerce_claude_insights_menu_item(
			'ai-insights',
			__( 'AI Insights', 'woocommerce-claude' ),
			'/'
		);
	}
}
