<?php
/**
 * Plugin Name: WooCommerce for Claude
 * Plugin URI: https://woocommerce.com/
 * Description: Makes any WooCommerce store AI-operable — structured knowledge API, readiness scoring, and developer hooks for AI agents.
 * Version: 0.4.2
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

define( 'WOOCOMMERCE_CLAUDE_VERSION', '0.4.2' );
define( 'WOOCOMMERCE_CLAUDE_PLUGIN_FILE', __FILE__ );
define( 'WOOCOMMERCE_CLAUDE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

woocommerce_claude_load_commerce_abilities();

/**
 * Load the shared commerce-abilities package classes.
 *
 * Composer's generated autoloader class name is stable for the same lock file,
 * so wp-env's PHPUnit bootstrap and production/test double mount can fatal if
 * multiple plugin copies require their own vendor/autoload.php. The runtime
 * package currently has no third-party dependencies, so load the shared package
 * files directly and skip classes an earlier package copy already provided.
 */
function woocommerce_claude_load_commerce_abilities() {
	$woocommerce_claude_package_dirs = array(
		dirname( dirname( WOOCOMMERCE_CLAUDE_PLUGIN_DIR ) ) . '/php-packages/commerce-abilities/src/',
		WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'vendor/woocommerce/commerce-abilities/src/',
	);
	$woocommerce_claude_classes      = array(
		\WooCommerce\CommerceAbilities\Loader::class => 'loader.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsBootstrap::class => 'Abilities/class-analytics-bootstrap.php',
		\WooCommerce\CommerceAbilities\Abilities\LargeRangeGate::class => 'Abilities/class-large-range-gate.php',
		\WooCommerce\CommerceAbilities\Abilities\ConfirmLargeRangeAbility::class => 'Abilities/class-confirm-large-range-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility::class => 'Abilities/class-analytics-totals-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsBreakdownAbility::class => 'Abilities/class-analytics-breakdown-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsSeriesAbility::class => 'Abilities/class-analytics-series-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\AnalyticsRowsAbility::class => 'Abilities/class-analytics-rows-ability.php',
		\WooCommerce\CommerceAbilities\Analytics\AnalyticsService::class => 'Analytics/class-analytics-service.php',
		\WooCommerce\CommerceAbilities\Knowledge\KnowledgeProvider::class => 'Knowledge/interface-knowledge-provider.php',
		\WooCommerce\CommerceAbilities\Knowledge\KnowledgeRegistry::class => 'Knowledge/class-knowledge-registry.php',
		\WooCommerce\CommerceAbilities\Knowledge\Providers\StoreProfileProvider::class => 'Knowledge/providers/class-store-profile-provider.php',
		\WooCommerce\CommerceAbilities\Knowledge\Providers\CatalogProvider::class => 'Knowledge/providers/class-catalog-provider.php',
		\WooCommerce\CommerceAbilities\Knowledge\Providers\ProductProvider::class => 'Knowledge/providers/class-product-provider.php',
		\WooCommerce\CommerceAbilities\Knowledge\Providers\PolicyProvider::class => 'Knowledge/providers/class-policy-provider.php',
		\WooCommerce\CommerceAbilities\Scoring\Factors\ProductCompleteness::class => 'Scoring/factors/class-product-completeness.php',
		\WooCommerce\CommerceAbilities\Scoring\Factors\SchemaCoverage::class => 'Scoring/factors/class-schema-coverage.php',
		\WooCommerce\CommerceAbilities\Scoring\Factors\PolicyCompleteness::class => 'Scoring/factors/class-policy-completeness.php',
		\WooCommerce\CommerceAbilities\Scoring\Factors\ContentQuality::class => 'Scoring/factors/class-content-quality.php',
		\WooCommerce\CommerceAbilities\Scoring\ScoringEngine::class => 'Scoring/class-scoring-engine.php',
		\WooCommerce\CommerceAbilities\Store\StoreKnowledge::class => 'Store/class-store-knowledge.php',
		\WooCommerce\CommerceAbilities\API\AbstractStoreController::class => 'API/class-abstract-store-controller.php',
		\WooCommerce\CommerceAbilities\API\AbstractProductsController::class => 'API/class-abstract-products-controller.php',
		\WooCommerce\CommerceAbilities\API\AbstractReadinessController::class => 'API/class-abstract-readiness-controller.php',
		\WooCommerce\CommerceAbilities\API\AbstractCatalogController::class => 'API/class-abstract-catalog-controller.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\GetStoreProfileAbilityTrait::class => 'Abilities/Store/trait-get-store-profile-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\SearchProductsAbilityTrait::class => 'Abilities/Store/trait-search-products-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\GetProductDetailsAbilityTrait::class => 'Abilities/Store/trait-get-product-details-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\GetReadinessScoreAbilityTrait::class => 'Abilities/Store/trait-get-readiness-score-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\GetRecommendationsAbilityTrait::class => 'Abilities/Store/trait-get-recommendations-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\SuggestImprovementsAbilityTrait::class => 'Abilities/Store/trait-suggest-improvements-ability.php',
	);

	foreach ( $woocommerce_claude_classes as $class_name => $relative_path ) {
		$file = woocommerce_claude_find_commerce_abilities_file( $woocommerce_claude_package_dirs, $relative_path );
		if ( ! woocommerce_claude_commerce_abilities_symbol_exists( $class_name ) && file_exists( $file ) ) {
			require_once $file;
		}
	}
}

/**
 * Return the first available commerce-abilities package file.
 *
 * Local monorepo checkouts can load the shared package source directly; release
 * zips fall back to the Composer-vendored path package copy.
 *
 * @param array  $package_dirs Package source directories to search.
 * @param string $relative_path Relative file path within the package source.
 * @return string Absolute path, or the release-vendor path when none exists.
 */
function woocommerce_claude_find_commerce_abilities_file( $package_dirs, $relative_path ) {
	foreach ( $package_dirs as $package_dir ) {
		$file = $package_dir . $relative_path;
		if ( file_exists( $file ) ) {
			return $file;
		}
	}

	return WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'vendor/woocommerce/commerce-abilities/src/' . $relative_path;
}

/**
 * Whether a shared commerce-abilities symbol is already loaded.
 *
 * @param string $symbol_name Fully-qualified class, interface, or trait name.
 * @return bool
 */
function woocommerce_claude_commerce_abilities_symbol_exists( $symbol_name ) {
	return class_exists( $symbol_name, false )
		|| interface_exists( $symbol_name, false )
		|| trait_exists( $symbol_name, false );
}

/**
 * Initialise the shared commerce abilities package.
 *
 * The direct hook fallback covers the mixed-version case where an older no-op
 * Loader class exists before this plugin is loaded.
 */
function woocommerce_claude_init_commerce_abilities() {
	if ( class_exists( \WooCommerce\CommerceAbilities\Loader::class ) ) {
		\WooCommerce\CommerceAbilities\Loader::init();
	}

	if ( class_exists( \WooCommerce\CommerceAbilities\Abilities\AnalyticsBootstrap::class ) ) {
		woocommerce_claude_add_action_once(
			'wp_abilities_api_categories_init',
			array( \WooCommerce\CommerceAbilities\Abilities\AnalyticsBootstrap::class, 'register_category' )
		);
		woocommerce_claude_add_action_once(
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
function woocommerce_claude_add_action_once( $hook_name, $callback ) {
	if ( ! function_exists( 'add_action' ) ) {
		return;
	}

	if ( function_exists( 'has_action' ) && false !== has_action( $hook_name, $callback ) ) {
		return;
	}

	add_action( $hook_name, $callback );
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
			woocommerce_claude_init_commerce_abilities();
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
