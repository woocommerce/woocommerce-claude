<?php
/**
 * Plugin Name: WooCommerce for Claude
 * Plugin URI: https://woocommerce.com/
 * Description: Makes any WooCommerce store AI-operable — structured knowledge API, readiness scoring, and developer hooks for AI agents.
 * Version: 0.1.3
 * Author: Automattic
 * Author URI: https://automattic.com/
 * Text Domain: woocommerce-claude
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 10.6
 * WC tested up to: 10.7
 * Requires Plugins: woocommerce
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WooCommerce\Claude
 */

defined( 'ABSPATH' ) || exit;

define( 'WOOCOMMERCE_CLAUDE_VERSION', '0.1.3' );
define( 'WOOCOMMERCE_CLAUDE_PLUGIN_FILE', __FILE__ );
define( 'WOOCOMMERCE_CLAUDE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Declare HPOS compatibility.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Check that WooCommerce is active before initialising.
 */
function woocommerce_claude_check_requirements() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'woocommerce_claude_wc_missing_notice' );
		return false;
	}
	return true;
}

/**
 * Render the admin notice shown when WooCommerce isn't active.
 */
function woocommerce_claude_wc_missing_notice() {
	?>
	<div class="notice notice-error">
		<p><strong>WooCommerce for Claude</strong> requires WooCommerce to be installed and active.</p>
	</div>
	<?php
}

/**
 * One-shot migration of option keys from the previous plugin name
 * (Woo AI Connect) to the renamed keys (WooCommerce for Claude). Runs on every load
 * but exits immediately once the migration sentinel is set, so the
 * cost on subsequent loads is a single get_option() call.
 */
function woocommerce_claude_migrate_legacy_options() {
	if ( get_option( 'woocommerce_claude_options_migrated' ) ) {
		return;
	}
	$map = array(
		'woo_ai_connect_telemetry_enabled' => 'woocommerce_claude_telemetry_enabled',
		'hey_woo_anthropic_api_key'        => 'woocommerce_claude_anthropic_api_key',
	);
	foreach ( $map as $old_key => $new_key ) {
		$old_value = get_option( $old_key, null );
		if ( null !== $old_value && false === get_option( $new_key, false ) ) {
			update_option( $new_key, $old_value );
		}
	}
	update_option( 'woocommerce_claude_options_migrated', '1' );
}

/**
 * Initialise the plugin after all plugins have loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( woocommerce_claude_check_requirements() ) {
			woocommerce_claude_migrate_legacy_options();
			require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/class-plugin.php';
			\WooCommerce\Claude\Plugin::instance();
		}
	}
);

/**
 * On activation, opt new installs into anonymised usage telemetry by
 * default. Gated on the option being absent so:
 *
 *   - Fresh installs land on `'yes'`.
 *   - Existing installs that already chose `'no'` (or `'yes'`) keep
 *     their value across reactivation.
 *   - Plugin auto-updates don't fire activation hooks at all, so an
 *     upgrade-in-place merchant is never silently flipped on.
 *
 * The legacy-option migration runs eagerly before the default check so
 * a prior `woo_ai_connect_telemetry_enabled` (the pre-rename key) is
 * copied to the current key first. Without this, activation would
 * write `'yes'` before plugins_loaded fires the migration, and the
 * migration's "new key absent?" guard would skip — silently dropping
 * a user's pre-rename opt-out.
 *
 * Defaulting on still respects WC's site-wide tracking opt-in: TracksHandler
 * routes through WC_Tracks::record_event(), which is itself gated by
 * the `woocommerce_allow_tracking` setting — no events leave the site
 * unless that's also enabled.
 */
register_activation_hook(
	__FILE__,
	function () {
		woocommerce_claude_migrate_legacy_options();

		if ( false === get_option( 'woocommerce_claude_telemetry_enabled', false ) ) {
			update_option( 'woocommerce_claude_telemetry_enabled', 'yes' );
		}
	}
);

/**
 * On deactivation, revoke the auto-created WooCommerce REST API key.
 *
 * Without this, a merchant who deactivates WooCommerce for Claude to roll back or
 * disconnect Claude leaves an active credential behind: the WC core
 * MCP server (and standard REST API) keep authenticating it because
 * those surfaces don't depend on WooCommerce for Claude being active. Revoking on
 * deactivation matches the merchant's mental model — "I turned this
 * off, so the connection is closed."
 *
 * Re-activating the plugin re-runs the setup flow and provisions a
 * fresh key, so the round-trip is clean.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/setup/class-rest-api-key.php';
		( new \WooCommerce\Claude\Setup\RestApiKey() )->revoke();
	}
);
