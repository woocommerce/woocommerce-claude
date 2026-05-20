<?php
/**
 * Plugin Name: Hey Woo
 * Plugin URI: https://woocommerce.com/
 * Description: Bring-your-own-key WooCommerce assistant powered by shared commerce abilities.
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
 * Load the shared commerce-abilities package classes.
 *
 * The shared package currently has no third-party runtime dependencies. Loading
 * the package files directly avoids collisions between multiple plugin copies
 * that vendor the same Composer autoloader class name from the same lock file.
 */
function hey_woo_load_commerce_abilities() {
	$hey_woo_package_dirs = array(
		dirname( dirname( HEY_WOO_PLUGIN_DIR ) ) . '/php-packages/commerce-abilities/src/',
		HEY_WOO_PLUGIN_DIR . 'vendor/woocommerce/commerce-abilities/src/',
	);
	$hey_woo_classes      = array(
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
		\WooCommerce\CommerceAbilities\Abilities\Store\GetStoreProfileAbilityTrait::class => 'Abilities/Store/trait-get-store-profile-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\SearchProductsAbilityTrait::class => 'Abilities/Store/trait-search-products-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\GetProductDetailsAbilityTrait::class => 'Abilities/Store/trait-get-product-details-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\GetReadinessScoreAbilityTrait::class => 'Abilities/Store/trait-get-readiness-score-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\GetRecommendationsAbilityTrait::class => 'Abilities/Store/trait-get-recommendations-ability.php',
		\WooCommerce\CommerceAbilities\Abilities\Store\SuggestImprovementsAbilityTrait::class => 'Abilities/Store/trait-suggest-improvements-ability.php',
	);

	foreach ( $hey_woo_classes as $class_name => $relative_path ) {
		$file = hey_woo_find_commerce_abilities_file( $hey_woo_package_dirs, $relative_path );
		if ( ! hey_woo_commerce_abilities_symbol_exists( $class_name ) && file_exists( $file ) ) {
			require_once $file;
		}
	}
}

/**
 * Return the first available commerce-abilities package file.
 *
 * Local monorepo checkouts may not have run Composer for Hey Woo yet, while
 * release zips rely on the vendored path package copy.
 *
 * @param array  $package_dirs Package source directories to search.
 * @param string $relative_path Relative file path within the package source.
 * @return string Absolute path, or the release-vendor path when none exists.
 */
function hey_woo_find_commerce_abilities_file( $package_dirs, $relative_path ) {
	foreach ( $package_dirs as $package_dir ) {
		$file = $package_dir . $relative_path;
		if ( file_exists( $file ) ) {
			return $file;
		}
	}

	return HEY_WOO_PLUGIN_DIR . 'vendor/woocommerce/commerce-abilities/src/' . $relative_path;
}

/**
 * Whether a shared commerce-abilities symbol is already loaded.
 *
 * @param string $symbol_name Fully-qualified class, interface, or trait name.
 * @return bool
 */
function hey_woo_commerce_abilities_symbol_exists( $symbol_name ) {
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
	require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/class-difm-ai-telemetry.php';

	// Store knowledge and readiness scoring.
	require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/interface-knowledge-provider.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/class-knowledge-registry.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/providers/class-store-profile-provider.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/providers/class-catalog-provider.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/providers/class-product-provider.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/providers/class-policy-provider.php';

	require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/class-scoring-engine.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/factors/class-product-completeness.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/factors/class-schema-coverage.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/factors/class-policy-completeness.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/factors/class-content-quality.php';

	require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-abilities-bootstrap.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-store-profile-ability.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-search-products-ability.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-product-details-ability.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-readiness-score-ability.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-recommendations-ability.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-suggest-improvements-ability.php';

	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/interface-difm-ai-client.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-difm-provider-environment.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-ai-api-proxy-client.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-wordpress-ai-client-adapter.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-difm-provider-resolver.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-workflow-skills.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-difm-rest-controller.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-difm-conversations-controller.php';
	require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-difm-admin-page.php';
}

/**
 * Store the first installation timestamp on a fresh activation.
 */
function hey_woo_activate() {
	add_option( 'hey_woo_first_installed_at', time(), '', false );
}

/**
 * Pin existing Anthropic installs to Anthropic before auto mode is introduced.
 *
 * New installs keep the implicit `auto` default by leaving the provider option
 * absent. Existing installs with an Anthropic key are pinned so an update cannot
 * silently move them to a different provider.
 */
function hey_woo_migrate_difm_provider_option() {
	if ( get_option( 'hey_woo_difm_provider_migrated' ) ) {
		return;
	}

	$provider_is_absent = false === get_option( 'hey_woo_difm_provider', false );
	$has_anthropic_key  = '' !== (string) get_option( 'hey_woo_anthropic_api_key', '' )
		|| '' !== (string) get_option( 'woocommerce_claude_anthropic_api_key', '' )
		|| ( defined( 'HEY_WOO_ANTHROPIC_KEY' ) && '' !== (string) HEY_WOO_ANTHROPIC_KEY )
		|| ( defined( 'WOOCOMMERCE_CLAUDE_ANTHROPIC_KEY' ) && '' !== (string) WOOCOMMERCE_CLAUDE_ANTHROPIC_KEY );

	if ( $provider_is_absent && $has_anthropic_key ) {
		update_option( 'hey_woo_difm_provider', 'anthropic' );
	}

	update_option( 'hey_woo_difm_provider_migrated', '1' );
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
	hey_woo_register_providers();

	hey_woo_add_action_once(
		'wp_abilities_api_categories_init',
		array( \WooCommerce\HeyWoo\Abilities\AbilitiesBootstrap::class, 'register_category' )
	);
	hey_woo_add_action_once(
		'wp_abilities_api_init',
		array( \WooCommerce\HeyWoo\Abilities\AbilitiesBootstrap::class, 'register_abilities' )
	);

	add_filter( 'woocommerce_get_settings_pages', 'hey_woo_register_settings_page' );

	( new \WooCommerce\HeyWoo\Difm\DifmAdminPage() )->register();
	( new \WooCommerce\HeyWoo\Difm\DifmRestController() )->register();
	( new \WooCommerce\HeyWoo\Difm\DifmConversationsController() )->register();
}

/**
 * Register Hey Woo knowledge providers.
 */
function hey_woo_register_providers() {
	$registry = \WooCommerce\CommerceAbilities\Store\StoreKnowledge::register_default_providers( 'hey-woo' );

	/**
	 * Allow other plugins to register their own Hey Woo knowledge providers.
	 *
	 * @since 0.4.2
	 *
	 * @param \WooCommerce\HeyWoo\Knowledge\KnowledgeRegistry $registry The knowledge registry instance.
	 */
	do_action( 'hey_woo_register_providers', $registry );
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
			hey_woo_migrate_difm_provider_option();
			hey_woo_init_runtime();
		}
	}
);

if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, 'hey_woo_activate' );
}
