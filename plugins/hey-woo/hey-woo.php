<?php
/**
 * Plugin Name: Hey Woo
 * Plugin URI: https://woocommerce.com/
 * Description: Bring-your-own-key WooCommerce assistant scaffold powered by shared commerce abilities.
 * Version: 0.4.2
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
 * @package WooCommerce\HeyWoo
 */

defined( 'ABSPATH' ) || exit;

define( 'HEY_WOO_VERSION', '0.4.2' );
define( 'HEY_WOO_PLUGIN_FILE', __FILE__ );
define( 'HEY_WOO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

hey_woo_load_commerce_abilities();

/**
 * Load the vendored commerce-abilities package classes.
 *
 * The shared package currently has no third-party runtime dependencies. Loading
 * the package files directly avoids collisions between multiple plugin copies
 * that vendor the same Composer autoloader class name from the same lock file.
 */
function hey_woo_load_commerce_abilities() {
	$hey_woo_package_dir = HEY_WOO_PLUGIN_DIR . 'vendor/woocommerce/commerce-abilities/src/';
	$hey_woo_classes     = array(
		\WooCommerce\CommerceAbilities\Loader::class => 'loader.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsBootstrap::class => 'Abilities/class-analytics-bootstrap.php',
		\WooCommerce\CommerceAbilities\Abilities\LargeRangeGate::class => 'Abilities/class-large-range-gate.php',
		\WooCommerce\CommerceAbilities\Abilities\ConfirmLargeRangeAbility::class => 'Abilities/class-confirm-large-range-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility::class => 'Abilities/class-analytics-totals-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsBreakdownAbility::class => 'Abilities/class-analytics-breakdown-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsSeriesAbility::class => 'Abilities/class-analytics-series-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsRowsAbility::class => 'Abilities/class-analytics-rows-ability.php',
		\WooCommerce\CommerceAbilities\Analytics\AnalyticsService::class => 'Analytics/class-analytics-service.php',
	);

	foreach ( $hey_woo_classes as $class_name => $relative_path ) {
		$file = $hey_woo_package_dir . $relative_path;
		if ( ! class_exists( $class_name, false ) && file_exists( $file ) ) {
			require_once $file;
		}
	}
}

/**
 * Initialise the shared commerce abilities package.
 *
 * The direct hook fallback covers the mixed-version case where an older no-op
 * Loader class exists before this plugin is loaded.
 */
function hey_woo_init_commerce_abilities() {
	if ( class_exists( \WooCommerce\CommerceAbilities\Loader::class ) ) {
		\WooCommerce\CommerceAbilities\Loader::init();
	}

	if ( class_exists( \WooCommerce\CommerceAbilities\Abilities\AnalyticsBootstrap::class ) ) {
		hey_woo_add_action_once(
			'wp_abilities_api_categories_init',
			array( \WooCommerce\CommerceAbilities\Abilities\AnalyticsBootstrap::class, 'register_category' )
		);
		hey_woo_add_action_once(
			'wp_abilities_api_init',
			array( \WooCommerce\CommerceAbilities\Abilities\AnalyticsBootstrap::class, 'register_abilities' )
		);
	}
}

/**
 * Add a WordPress action only when the exact callback is not registered.
 *
 * @param string $hook_name Hook name.
 * @param array  $callback  Static method callback.
 */
function hey_woo_add_action_once( $hook_name, $callback ) {
	if ( ! function_exists( 'add_action' ) ) {
		return;
	}

	if ( function_exists( 'has_action' ) && false !== has_action( $hook_name, $callback ) ) {
		return;
	}

	add_action( $hook_name, $callback );
}

/**
 * Load Hey Woo runtime classes.
 */
function hey_woo_load_runtime_files() {
	require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/interface-telemetry-handler.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/class-telemetry-handler.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/handlers/class-log-handler.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/class-anthropic-telemetry.php';

	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-workflow-skills.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-difm-rest-controller.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-difm-conversations-controller.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-difm-admin-page.php';
}

/**
 * Register the Hey Woo WooCommerce settings tab.
 *
 * WC includes WC_Settings_Page before firing this filter, so the settings
 * class is safe to load here.
 *
 * @param array $pages Registered settings pages.
 * @return array
 */
function hey_woo_register_settings_page( $pages ) {
	require_once HEY_WOO_PLUGIN_DIR . 'includes/settings/class-settings-page.php';
	$pages[] = new \WooCommerce\HeyWoo\Settings\SettingsPage();
	return $pages;
}

/**
 * Initialise the Hey Woo BYOK runtime.
 */
function hey_woo_init_runtime() {
	hey_woo_load_runtime_files();

	\WooCommerce\HeyWoo\Telemetry\TelemetryHandler::init();

	add_filter( 'woocommerce_get_settings_pages', 'hey_woo_register_settings_page' );

	( new \WooCommerce\HeyWoo\Difm\DifmAdminPage() )->register();
	( new \WooCommerce\HeyWoo\Difm\DifmRestController() )->register();
	( new \WooCommerce\HeyWoo\Difm\DifmConversationsController() )->register();
}

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
 *
 * @return bool
 */
function hey_woo_check_requirements() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'hey_woo_wc_missing_notice' );
		return false;
	}

	return true;
}

/**
 * Render the admin notice shown when WooCommerce is not active.
 */
function hey_woo_wc_missing_notice() {
	?>
	<div class="notice notice-error">
		<p>
			<strong><?php echo esc_html( 'Hey Woo' ); ?></strong>
			<?php echo esc_html( 'requires WooCommerce to be installed and active.' ); ?>
		</p>
	</div>
	<?php
}

/**
 * Initialise the plugin after all plugins have loaded.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( hey_woo_check_requirements() ) {
			hey_woo_init_commerce_abilities();
			hey_woo_init_runtime();
		}
	}
);
