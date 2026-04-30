<?php
/**
 * Main plugin class.
 *
 * @package HeyWoo
 */

namespace HeyWoo;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton — wires up includes, hooks, and knowledge providers.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Return the singleton instance, constructing it on first access.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Bootstrap the plugin.
	 */
	private function __construct() {
		$this->includes();
		$this->init_hooks();
		$this->register_providers();
	}

	/**
	 * Load all plugin files.
	 */
	private function includes() {
		// Settings.
		// Loaded lazily inside register_settings_page() so WC_Settings_Page is
		// already available (WC includes it just before the filter fires).

		// Telemetry.
		require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/interface-telemetry-handler.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/handlers/class-log-handler.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/handlers/class-tracks-handler.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/class-skill-telemetry.php';

		// Knowledge system.
		require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/interface-knowledge-provider.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/class-knowledge-registry.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/providers/class-store-profile-provider.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/providers/class-catalog-provider.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/providers/class-product-provider.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/knowledge/providers/class-policy-provider.php';

		// Scoring engine.
		require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/class-scoring-engine.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/factors/class-product-completeness.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/factors/class-schema-coverage.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/factors/class-policy-completeness.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/scoring/factors/class-content-quality.php';

		// REST API (store knowledge + readiness — analytics lives under Abilities).
		require_once HEY_WOO_PLUGIN_DIR . 'includes/api/class-store-controller.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/api/class-catalog-controller.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/api/class-products-controller.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/api/class-readiness-controller.php';
		// AnalyticsController is now a shared data-access helper (no REST
		// routes of its own) — required so ability classes can call into it.
		require_once HEY_WOO_PLUGIN_DIR . 'includes/api/class-analytics-controller.php';

		// Abilities API — every analytics skill is exposed here.
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-large-range-gate.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-abilities-bootstrap.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-confirm-large-range-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-describe-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-data-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-revenue-summary-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-orders-summary-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-product-performance-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-customer-overview-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-attribution-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-customer-value-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-revenue-breakdown-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-coupon-performance-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-refund-analysis-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-tax-summary-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-query-analytics-ability.php';

		// External-integration abilities (hey-woo-integrations/*) — dev/local only.
		// The GA4 ability is a prototype scaffold; only register it in local and
		// development environments so it never surfaces to merchants on production.
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-google-analytics-channels-ability.php';
		}

		// Non-analytics tool abilities (hey-woo/*) — store knowledge,
		// readiness, and product search helpers.
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-store-profile-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-search-products-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-product-details-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-readiness-score-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-get-recommendations-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-suggest-improvements-ability.php';

		// Resource + prompt abilities — not tools; wired into Woo core MCP
		// via the mcp_adapter_init hook in init_hooks().
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-store-profile-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-catalog-schema-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-store-policies-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-catalog-audit-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-product-improve-ability.php';
	}

	/**
	 * Wire up WordPress hooks for REST routes, abilities, and authentication.
	 */
	private function init_hooks() {
		// Gate TracksHandler behind the settings toggle. Must run before
		// SkillTelemetry::init() so the filter is registered when handlers are built.
		add_filter( 'hey_woo_telemetry_handlers', array( $this, 'maybe_add_tracks_handler' ) );

		Telemetry\SkillTelemetry::init();

		add_action( 'rest_api_init', array( API\StoreController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( API\CatalogController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( API\ProductsController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( API\ReadinessController::class, 'register_routes' ) );

		// Abilities API — the analytics category and six abilities. Category
		// must exist before any ability claims it, so they register on separate
		// hooks.
		add_action( 'wp_abilities_api_categories_init', array( Abilities\AbilitiesBootstrap::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( Abilities\AbilitiesBootstrap::class, 'register_abilities' ) );

		// WooCommerce Settings tab.
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'register_settings_page' ) );

		// Enable WooCommerce REST API key authentication for our custom namespace.
		// WC's auth handler only processes requests to /wc/ routes by default.
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( $this, 'enable_wc_auth_for_our_routes' ), 10 );

		// Opt our wc-analytics/* abilities into the Woo core MCP server. WC core
		// MCP only exposes woocommerce/* abilities by default; this filter widens
		// that to include our namespace. See MCPAdapterProvider::get_woocommerce_mcp_abilities().
		add_filter( 'woocommerce_mcp_include_ability', array( $this, 'include_wc_analytics_in_mcp' ), 10, 2 );

		// Resources and prompts don't get a filter in WC core MCP — they have to
		// be injected into the server's component registry directly. WC creates
		// the 'woocommerce-mcp' server on mcp_adapter_init priority 10; we run
		// after that to register our wc-knowledge/* resources and wc-prompts/*.
		add_action( 'mcp_adapter_init', array( $this, 'inject_mcp_components' ), 20 );
	}

	/**
	 * Register the Hey Woo settings tab in WooCommerce > Settings.
	 *
	 * WC includes WC_Settings_Page before firing this filter, so our class
	 * is safe to load here.
	 *
	 * @param array $pages Registered settings pages.
	 * @return array
	 */
	public function register_settings_page( $pages ) {
		require_once HEY_WOO_PLUGIN_DIR . 'includes/settings/class-settings-page.php';
		$pages[] = new Settings\SettingsPage();
		return $pages;
	}

	/**
	 * Conditionally add TracksHandler to the telemetry handler list.
	 *
	 * Hooked on hey_woo_telemetry_handlers before SkillTelemetry::init()
	 * so the option is evaluated when the handler set is first built.
	 * Option name matches Settings\SettingsPage::TELEMETRY_ENABLED_OPTION.
	 *
	 * @param array $handlers Current handler list.
	 * @return array
	 */
	public function maybe_add_tracks_handler( $handlers ) {
		if ( 'yes' === get_option( 'hey_woo_telemetry_enabled', 'no' ) ) {
			$handlers[] = new Telemetry\Handlers\TracksHandler();
		}
		return $handlers;
	}

	/**
	 * Plugin-owned Abilities API namespaces.
	 *
	 * Defines the namespaces this plugin claims ownership of. The WC auth
	 * scope filter trusts only routes under one of these namespaces, so a
	 * Hey Woo consumer key can't be replayed against abilities registered
	 * by an unrelated plugin under a different prefix. Kept as a constant
	 * so adding a new namespace is a single-edit operation; the
	 * `include_wc_analytics_in_mcp` filter below carries its own
	 * per-namespace logic and must stay aligned by hand.
	 *
	 * @var array<int, string>
	 */
	private const OWNED_ABILITY_NAMESPACES = array(
		'wc-analytics/',
		'hey-woo/',
		'hey-woo-integrations/',
	);

	/**
	 * Tell WooCommerce to authenticate requests to our API namespace
	 * using WC consumer key/secret, same as /wc/v3/ routes.
	 *
	 * Matches plugin-owned routes only — both pretty permalinks
	 * (/wp-json/<route>) and plain permalinks (?rest_route=/<route>).
	 * The wp-abilities path is restricted to the namespaces in
	 * OWNED_ABILITY_NAMESPACES, so a WC API key issued for this plugin
	 * can't auth into abilities registered by other plugins under the
	 * same Abilities API surface.
	 *
	 * @param bool $is_request Whether WC has already classified this as a WC REST request.
	 * @return bool
	 */
	public function enable_wc_auth_for_our_routes( $is_request ) {
		if ( $is_request ) {
			return $is_request;
		}

		$route = $this->current_rest_route();
		if ( '' === $route ) {
			return $is_request;
		}

		if ( 0 === strpos( $route, 'hey-woo/' ) ) {
			return true;
		}

		foreach ( self::OWNED_ABILITY_NAMESPACES as $namespace ) {
			if ( 0 === strpos( $route, 'wp-abilities/v1/abilities/' . $namespace ) ) {
				return true;
			}
		}

		return $is_request;
	}

	/**
	 * Resolve the REST route the current request targets, regardless of
	 * whether the install uses pretty (/wp-json/<route>) or plain
	 * (?rest_route=/<route>) permalinks.
	 *
	 * Returns the route relative to the REST prefix, without a leading
	 * slash (e.g. `hey-woo/v1/store/profile` or
	 * `wp-abilities/v1/abilities/wc-analytics/get-revenue-summary/run`),
	 * or '' if the request doesn't look like a REST request.
	 *
	 * @return string
	 */
	private function current_rest_route() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only path inspection; no state change.
		if ( isset( $_GET['rest_route'] ) ) {
			$route = wp_unslash( $_GET['rest_route'] );
			if ( ! is_string( $route ) ) {
				return '';
			}
			return ltrim( $route, '/' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( ! is_string( $request_uri ) || '' === $request_uri ) {
			return '';
		}

		$path = strtok( $request_uri, '?#' );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$rest_prefix = trailingslashit( rest_get_url_prefix() );
		$position    = strpos( $path, $rest_prefix );
		if ( false === $position ) {
			return '';
		}

		return substr( $path, $position + strlen( $rest_prefix ) );
	}

	/**
	 * Include our plugin's tool abilities in the Woo core MCP server.
	 *
	 * WC core MCP filters the abilities registry to `woocommerce/*` by default.
	 * Our analytics skills live under `wc-analytics/*` and our non-analytics
	 * tool skills live under `hey-woo/*`, so without this filter neither
	 * set would be exposed at `/wp-json/woocommerce/mcp`. Resources
	 * (`wc-knowledge/*`) and prompts (`wc-prompts/*`) are intentionally *not*
	 * added here — they go through `inject_mcp_components()` below.
	 *
	 * @param bool   $should_include Whether core MCP would include this ability.
	 * @param string $ability_id     The ability ID being evaluated.
	 * @return bool
	 */
	public function include_wc_analytics_in_mcp( $should_include, $ability_id ) {
		if ( is_string( $ability_id ) ) {
			// hey-woo/* and hey-woo-integrations/* are always included.
			// Each prefix is plugin-owned. A bare `integrations/` prefix would
			// hijack abilities registered by other plugins under the same
			// generic namespace — keep our integration abilities under
			// `hey-woo-integrations/` so the filter only opts our own surface
			// into the Woo MCP tool list.
			foreach ( array( 'hey-woo/', 'hey-woo-integrations/' ) as $prefix ) {
				if ( str_starts_with( $ability_id, $prefix ) ) {
					return true;
				}
			}
			// wc-analytics/* — only the three top-level routing tools are exposed
			// to MCP. The individual analytics abilities remain registered
			// in the WP Abilities API so wc-analytics/describe can read their
			// documentation, but are intentionally hidden from the MCP tool list.
			if ( str_starts_with( $ability_id, 'wc-analytics/' ) ) {
				return in_array(
					$ability_id,
					array(
						Abilities\GetDataAbility::ABILITY_NAME,
						Abilities\DescribeAbility::ABILITY_NAME,
						Abilities\ConfirmLargeRangeAbility::ABILITY_NAME,
					),
					true
				);
			}
		}
		return (bool) $should_include;
	}

	/**
	 * Register our resource and prompt abilities into the Woo core MCP server.
	 *
	 * WC core MCP only accepts tools via the `woocommerce_mcp_include_ability`
	 * filter; it passes empty arrays for resources and prompts when calling
	 * `create_server()`, and exposes no filter to extend them. The `McpServer`
	 * class does expose `get_component_registry()` publicly, whose
	 * `register_resources()` / `register_prompts()` methods accept ability IDs,
	 * so we hook `mcp_adapter_init` *after* WC (priority 20 vs WC's 10) and
	 * inject our abilities directly.
	 *
	 * @param object $adapter The McpAdapter instance.
	 * @return void
	 */
	public function inject_mcp_components( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'get_server' ) ) {
			return;
		}

		$server = $adapter->get_server( 'woocommerce-mcp' );
		if ( ! $server || ! method_exists( $server, 'get_component_registry' ) ) {
			return;
		}

		$registry = $server->get_component_registry();

		$registry->register_resources(
			array(
				Abilities\StoreProfileAbility::ABILITY_NAME,
				Abilities\CatalogSchemaAbility::ABILITY_NAME,
				Abilities\StorePoliciesAbility::ABILITY_NAME,
			)
		);

		$registry->register_prompts(
			array(
				Abilities\CatalogAuditAbility::ABILITY_NAME,
				Abilities\ProductImproveAbility::ABILITY_NAME,
			)
		);
	}

	/**
	 * Register all knowledge providers.
	 */
	private function register_providers() {
		$registry = Knowledge\KnowledgeRegistry::instance();

		$registry->register( new Knowledge\Providers\StoreProfileProvider() );
		$registry->register( new Knowledge\Providers\CatalogProvider() );
		$registry->register( new Knowledge\Providers\ProductProvider() );
		$registry->register( new Knowledge\Providers\PolicyProvider() );

		/**
		 * Allow other plugins to register their own knowledge providers.
		 *
		 * @since 0.1.0
		 *
		 * @param Knowledge\KnowledgeRegistry $registry The knowledge registry instance.
		 */
		do_action( 'hey_woo_register_providers', $registry );
	}
}
