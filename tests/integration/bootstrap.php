<?php
/**
 * PHPUnit bootstrap.
 *
 * Supports two environments:
 *
 * 1. Local wp-env — run via `bin/check`. The tests-cli container mounts the
 *    WP test suite at `/wordpress-phpunit` and the repo at
 *    `wp-content/plugins/hey-woo-tests` (the --env-cwd target).
 *
 * 2. CI / bare PHP — run after `bin/install-wp-tests.sh`. Set WP_TESTS_DIR to
 *    the path where the WP PHPUnit suite was installed; WooCommerce and the
 *    plugin are installed into WP_PLUGIN_DIR by the install script.
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

	// Prevent WooCommerce's own check_version() hook (plugins_loaded) from
	// running WC_Install::install() before we have a chance to set the HPOS
	// options below.  We do the controlled install ourselves in the init hook.
	update_option( 'woocommerce_db_version', WC()->version );
}
tests_add_filter( 'muplugins_loaded', 'hey_woo_tests_load_plugins' );

/**
 * Run the WooCommerce install routine with the correct options set.
 *
 * Hooked to `init` (priority 0) so WordPress is fully bootstrapped before we
 * touch the database.  Three things matter here:
 *
 * 1. HPOS options must be set before WC_Install::create_tables() runs, otherwise
 *    the HPOS order tables are not created.
 * 2. woocommerce_db_version must be deleted first so WC_Install::install() does
 *    not bail out early thinking WC is already up-to-date.
 * 3. $wp_roles must be reset after create_roles() writes new capabilities to the
 *    database.  WP_Roles is a singleton that was already initialized before
 *    create_roles() ran; without the reset, current_user_can() checks in tests
 *    see the stale pre-install snapshot.
 *    See https://core.trac.wordpress.org/ticket/28374
 */
function hey_woo_tests_install_woocommerce() {
	update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
	update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
	update_option( 'woocommerce_show_feature_enable_notice_custom_order_tables', 'no' );

	// Allow install() to run by removing the version we pinned in muplugins_loaded.
	delete_option( 'woocommerce_db_version' );

	if ( class_exists( 'WC_Install' ) ) {
		WC_Install::install();
	}

	// Reload the WP_Roles singleton from the database so the capabilities added
	// by create_roles() (e.g. manage_woocommerce) are visible to tests.
	$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	wp_roles();
}
tests_add_filter( 'init', 'hey_woo_tests_install_woocommerce', 0 );

require $_tests_dir . '/includes/bootstrap.php';

// Shared fixture helpers — `use`d by individual test classes.
require_once __DIR__ . '/trait-analytics-fixtures.php';
