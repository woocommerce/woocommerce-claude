<?php
/**
 * Plugin Name: Hey Woo
 * Plugin URI: https://woocommerce.com/
 * Description: Makes any WooCommerce store AI-operable — structured knowledge API, readiness scoring, and developer hooks for AI agents.
 * Version: 0.1.0
 * Author: Automattic
 * Author URI: https://automattic.com/
 * Text Domain: hey-woo
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 10.6
 * WC tested up to: 10.7
 * Requires Plugins: woocommerce
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package HeyWoo
 */

defined( 'ABSPATH' ) || exit;

define( 'HEY_WOO_VERSION', '0.1.0' );
define( 'HEY_WOO_PLUGIN_FILE', __FILE__ );
define( 'HEY_WOO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

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
function hey_woo_check_requirements() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'hey_woo_wc_missing_notice' );
		return false;
	}
	return true;
}

/**
 * Render the admin notice shown when WooCommerce isn't active.
 */
function hey_woo_wc_missing_notice() {
	?>
	<div class="notice notice-error">
		<p><strong>Hey Woo</strong> requires WooCommerce to be installed and active.</p>
	</div>
	<?php
}

/**
 * One-shot migration of option keys from the previous plugin name
 * (Woo AI Connect) to the renamed keys (Hey Woo). Runs on every load
 * but exits immediately once the migration sentinel is set, so the
 * cost on subsequent loads is a single get_option() call.
 */
function hey_woo_migrate_legacy_options() {
	if ( get_option( 'hey_woo_options_migrated' ) ) {
		return;
	}
	$map = array(
		'woo_ai_connect_telemetry_enabled' => 'hey_woo_telemetry_enabled',
	);
	foreach ( $map as $old_key => $new_key ) {
		$old_value = get_option( $old_key, null );
		if ( null !== $old_value && false === get_option( $new_key, false ) ) {
			update_option( $new_key, $old_value );
		}
	}
	update_option( 'hey_woo_options_migrated', '1' );
}

/**
 * Initialise the plugin after all plugins have loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( hey_woo_check_requirements() ) {
			hey_woo_migrate_legacy_options();
			require_once HEY_WOO_PLUGIN_DIR . 'includes/class-plugin.php';
			\HeyWoo\Plugin::instance();
		}
	}
);

/**
 * On deactivation, revoke the auto-created WooCommerce REST API key.
 *
 * Without this, a merchant who deactivates Hey Woo to roll back or
 * disconnect Claude leaves an active credential behind: the WC core
 * MCP server (and standard REST API) keep authenticating it because
 * those surfaces don't depend on Hey Woo being active. Revoking on
 * deactivation matches the merchant's mental model — "I turned this
 * off, so the connection is closed."
 *
 * Re-activating the plugin re-runs the setup flow and provisions a
 * fresh key, so the round-trip is clean.
 */
register_deactivation_hook(
	__FILE__,
	function () {
		require_once HEY_WOO_PLUGIN_DIR . 'includes/setup/class-rest-api-key.php';
		( new \HeyWoo\Setup\RestApiKey() )->revoke();
	}
);

