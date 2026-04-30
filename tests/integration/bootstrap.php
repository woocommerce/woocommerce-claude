<?php
/**
 * PHPUnit bootstrap.
 *
 * Designed to run inside the wp-env `tests-cli` container, where the
 * WordPress test suite is mounted at `/wordpress-phpunit` and the repo
 * is mounted (via the `mappings` entry in .wp-env.json) at
 * `/var/www/html/wp-content/plugins/hey-woo-tests`.
 *
 * @package HeyWoo\Tests
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/wordpress-phpunit';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo 'Could not find ' . esc_html( $_tests_dir ) . '/includes/functions.php — is the wp-env tests environment running?' . PHP_EOL;
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load WooCommerce + Hey Woo before WP_UnitTestCase boots.
 *
 * `muplugins_loaded` fires after WordPress core is up (so WP_PLUGIN_DIR is
 * defined) but before the regular plugin loader, which lets us guarantee
 * load order: WooCommerce first, then this plugin.
 *
 * Plugin slugs are resolved at runtime: wp-env mounts WooCommerce as
 * `woocommerce*` (the .latest-stable zip lands at `woocommerce.latest-stable/`),
 * and our plugin at `hey-woo/` (destination slug of the `./plugin`
 * mount in `.wp-env.json`).
 */
function hey_woo_tests_load_plugins() {
	$plugin_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';

	$wc_candidates = glob( $plugin_dir . '/woocommerce*/woocommerce.php' );
	if ( empty( $wc_candidates ) ) {
		echo 'Could not find woocommerce/woocommerce.php under ' . esc_html( $plugin_dir ) . ' — is WooCommerce installed in the tests environment?' . PHP_EOL;
		exit( 1 );
	}
	require_once $wc_candidates[0];

	require_once $plugin_dir . '/hey-woo/hey-woo.php';
}
tests_add_filter( 'muplugins_loaded', 'hey_woo_tests_load_plugins' );

/**
 * Activate WooCommerce explicitly so its install routine runs and the
 * HPOS tables (wc_orders / wc_orders_meta / wc_order_addresses /
 * wc_order_operational_data) exist before the first test query.
 *
 * HPOS is enabled by setting `woocommerce_custom_orders_table_enabled`
 * BEFORE `WC_Install::create_tables()` runs — the installer gates the
 * HPOS `dbDelta` on FeaturesController::feature_is_enabled(), which
 * reads that option. Data sync is disabled so orders land straight in
 * the HPOS tables without a parallel wp_postmeta write. Suppressing
 * the incompatible-plugin notice keeps the option update quiet.
 *
 * WC_Install::install() is invoked explicitly after activate_plugin()
 * because activate_plugin()'s activation-hook path runs asynchronously
 * in some WP paths; calling install() directly guarantees create_tables
 * fires once the option is in place.
 */
function hey_woo_tests_activate_woocommerce() {
	if ( ! function_exists( 'activate_plugin' ) ) {
		return;
	}

	update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	update_option( 'woocommerce_show_feature_enable_notice_custom_order_tables', 'no' );

	$plugin_dir    = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins';
	$wc_candidates = glob( $plugin_dir . '/woocommerce*/woocommerce.php' );
	if ( empty( $wc_candidates ) ) {
		return;
	}
	$relative = ltrim( str_replace( $plugin_dir, '', $wc_candidates[0] ), '/' );
	activate_plugin( $relative );

	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
	}
}
tests_add_filter( 'setup_theme', 'hey_woo_tests_activate_woocommerce' );

require $_tests_dir . '/includes/bootstrap.php';

// Shared fixture helpers — `use`d by individual test classes.
require_once __DIR__ . '/trait-analytics-fixtures.php';
