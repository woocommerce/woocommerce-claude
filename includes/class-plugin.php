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

		// Resource + prompt abilities — not tools; passed to our MCP server's
		// resources/prompts arrays in register_mcp_server(), wired via the
		// mcp_adapter_init hook in init_hooks().
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-store-profile-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-catalog-schema-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-store-policies-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-catalog-audit-ability.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/abilities/class-product-improve-ability.php';

		// Connect-to-Claude setup page. McpbBundle is loaded lazily inside
		// the download handler since it's only used on that one path.
		require_once HEY_WOO_PLUGIN_DIR . 'includes/setup/class-rest-api-key.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/setup/class-setup-page.php';
	}

	/**
	 * Wire up WordPress hooks for REST routes, abilities, and authentication.
	 */
	private function init_hooks() {
		// Gate TracksHandler behind the settings toggle. Must run before
		// SkillTelemetry::init() so the filter is registered when handlers are built.
		add_filter( 'hey_woo_telemetry_handlers', array( $this, 'maybe_add_tracks_handler' ) );

		Telemetry\SkillTelemetry::init();
		Setup\SetupPage::init();

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

		// Boot the MCP adapter ourselves so the Hey Woo MCP endpoint works
		// independently of WC's `mcp_integration` feature flag. The adapter
		// is a singleton — calling instance() repeatedly is a no-op, so this
		// is safe even if WC's MCPAdapterProvider also boots it.
		add_action( 'plugins_loaded', array( $this, 'bootstrap_mcp_adapter' ), 20 );

		// Register our own MCP server when the adapter initializes. Owns the
		// `/wp-json/hey-woo/mcp` endpoint with a curated tool/resource/prompt
		// surface and a custom auth callback that handles X-MCP-API-Key (the
		// header `mcp-wordpress-remote` sends).
		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ) );
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
	 * `mcp_tool_ability_ids()` curated list below has its own per-namespace
	 * logic and must stay aligned by hand.
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

		// Match REST controllers under hey-woo/v1/ — but *not* the MCP
		// endpoint at hey-woo/mcp. WC's check_user_permissions enforces
		// the read/write split per HTTP method, which would block POST
		// calls from a read-only key. MCP uses POST for every call
		// (including semantic reads), so the MCP transport authenticates
		// itself via the registered transport_permission_callback rather
		// than going through WC's full auth chain.
		if ( 0 === strpos( $route, 'hey-woo/v1/' ) ) {
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
	 * Server identity used when registering our MCP server. The endpoint
	 * resolves to `/wp-json/<namespace>/<route>` — i.e. `/wp-json/hey-woo/mcp`.
	 */
	const MCP_SERVER_ID    = 'hey-woo';
	const MCP_SERVER_NS    = 'hey-woo';
	const MCP_SERVER_ROUTE = 'mcp';

	/**
	 * Boot the WordPress MCP adapter ahead of `rest_api_init` so our server
	 * can register on `mcp_adapter_init`.
	 *
	 * The adapter is a vendored library inside WooCommerce
	 * (`vendor/wordpress/mcp-adapter`); WC only initializes it when its
	 * `mcp_integration` feature flag is on. We boot it ourselves so the Hey
	 * Woo endpoint works without that toggle. `McpAdapter::instance()` is
	 * idempotent — if WC has already booted it, this is a no-op.
	 */
	public function bootstrap_mcp_adapter() {
		if ( class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) ) {
			\WP\MCP\Core\McpAdapter::instance();
		}
	}

	/**
	 * Register the Hey Woo MCP server on `mcp_adapter_init`.
	 *
	 * Replaces the previous "ride on WC's woocommerce-mcp server" approach
	 * (which used `woocommerce_mcp_include_ability` + a late
	 * `mcp_adapter_init` injection of resources/prompts). Our server owns
	 * `/wp-json/hey-woo/mcp` directly and ships a curated tool list, so the
	 * deprecation of WC's MCP endpoint and the upcoming change to its default
	 * inclusion rules don't affect us.
	 *
	 * @param object $adapter The McpAdapter instance.
	 * @return void
	 */
	public function register_mcp_server( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		if ( ! class_exists( '\\WP\\MCP\\Transport\\HttpTransport' ) ) {
			return;
		}

		try {
			$adapter->create_server(
				self::MCP_SERVER_ID,
				self::MCP_SERVER_NS,
				self::MCP_SERVER_ROUTE,
				__( 'Hey Woo MCP Server', 'hey-woo' ),
				__( 'AI-accessible WooCommerce store analytics, knowledge, and prompts via MCP.', 'hey-woo' ),
				HEY_WOO_VERSION,
				array( \WP\MCP\Transport\HttpTransport::class ),
				\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
				\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
				$this->mcp_tool_ability_ids(),
				array(
					Abilities\StoreProfileAbility::ABILITY_NAME,
					Abilities\CatalogSchemaAbility::ABILITY_NAME,
					Abilities\StorePoliciesAbility::ABILITY_NAME,
				),
				array(
					Abilities\CatalogAuditAbility::ABILITY_NAME,
					Abilities\ProductImproveAbility::ABILITY_NAME,
				),
				array( $this, 'authenticate_mcp_request' )
			);
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'Hey Woo MCP server initialization failed: ' . $e->getMessage(),
					array( 'source' => 'hey-woo-mcp' )
				);
			}
		}
	}

	/**
	 * Tool ability IDs to expose on our MCP server.
	 *
	 * Curated rather than namespace-derived: only the three top-level
	 * `wc-analytics/*` routing tools (get-data, describe, confirm-large-range)
	 * are exposed; the individual analytics abilities remain registered in
	 * the WP Abilities API so `wc-analytics/describe` can read their
	 * documentation, but are intentionally hidden from the MCP tool list.
	 *
	 * @return array<int, string>
	 */
	private function mcp_tool_ability_ids() {
		$tools = array(
			// hey-woo/* — store knowledge, readiness, product helpers.
			Abilities\GetStoreProfileAbility::ABILITY_NAME,
			Abilities\GetReadinessScoreAbility::ABILITY_NAME,
			Abilities\GetRecommendationsAbility::ABILITY_NAME,
			Abilities\GetProductDetailsAbility::ABILITY_NAME,
			Abilities\SearchProductsAbility::ABILITY_NAME,
			Abilities\SuggestImprovementsAbility::ABILITY_NAME,
			// wc-analytics/* — three top-level routing tools.
			Abilities\GetDataAbility::ABILITY_NAME,
			Abilities\DescribeAbility::ABILITY_NAME,
			Abilities\ConfirmLargeRangeAbility::ABILITY_NAME,
		);

		// hey-woo-integrations/* — dev/local only. The class is loaded under
		// the same environment gate in includes(), so check_class_exists
		// before referencing the constant to keep production safe.
		if ( class_exists( '\\HeyWoo\\Abilities\\GoogleAnalyticsChannelsAbility' ) ) {
			$tools[] = \HeyWoo\Abilities\GoogleAnalyticsChannelsAbility::ABILITY_NAME;
		}

		return $tools;
	}

	/**
	 * Authenticate an MCP request using a WooCommerce REST API key sent
	 * as HTTP Basic Auth (`ck_xxx` username, `cs_xxx` password).
	 *
	 * Why a custom callback instead of WC's standard REST auth: WC's
	 * `check_user_permissions` enforces a read/write split per HTTP
	 * method — POST requires a `read_write` or `write` key. MCP uses
	 * POST for every call, including semantic reads (tools/list,
	 * resources/read, etc.). Hey Woo provisions read-only keys by
	 * design, so the standard chain would 401 every MCP call. This
	 * callback authenticates the consumer key directly against
	 * `wp_woocommerce_api_keys`, sets the user, and returns true —
	 * bypassing WC's POST-as-write assumption while still requiring a
	 * valid key. Per-ability `permission_callback` handlers enforce the
	 * real capability gates (e.g. `manage_woocommerce` for analytics
	 * tools).
	 *
	 * @param \WP_REST_Request $request The current REST request.
	 * @return bool True if authenticated, false otherwise.
	 */
	public function authenticate_mcp_request( $request ) {
		if ( ! ( $request instanceof \WP_REST_Request ) ) {
			return false;
		}

		list( $consumer_key, $consumer_secret ) = self::extract_basic_auth();
		if ( '' === $consumer_key || '' === $consumer_secret ) {
			return false;
		}

		if ( ! function_exists( 'wc_api_hash' ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- direct lookup against woocommerce_api_keys; no caching surface for auth.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT key_id, user_id, consumer_secret FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
				wc_api_hash( $consumer_key )
			)
		);

		if ( ! $row || ! hash_equals( (string) $row->consumer_secret, $consumer_secret ) ) {
			return false;
		}

		$user = get_user_by( 'id', (int) $row->user_id );
		if ( ! $user ) {
			return false;
		}

		wp_set_current_user( $user->ID );
		return true;
	}

	/**
	 * Pull a Basic Auth credential pair off the current request.
	 *
	 * Tries `PHP_AUTH_USER`/`PHP_AUTH_PW` first (what mod_php and
	 * php-fpm normally populate from `Authorization: Basic …`), then
	 * falls back to parsing `HTTP_AUTHORIZATION` directly for
	 * environments that don't populate the split form (CGI, certain
	 * fastcgi setups). Returns `['', '']` if no credential is present
	 * or the header is malformed — caller treats that as auth failure.
	 *
	 * @return array{0:string,1:string} `[username, password]`.
	 */
	private static function extract_basic_auth() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only inspection of an in-flight REST request.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- credentials are byte-compared, not interpolated; sanitisation would corrupt them.
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			return array(
				trim( wp_unslash( (string) $_SERVER['PHP_AUTH_USER'] ) ),
				trim( wp_unslash( (string) $_SERVER['PHP_AUTH_PW'] ) ),
			);
		}

		$header = isset( $_SERVER['HTTP_AUTHORIZATION'] )
			? wp_unslash( (string) $_SERVER['HTTP_AUTHORIZATION'] )
			: '';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 0 !== stripos( $header, 'Basic ' ) ) {
			return array( '', '' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding HTTP Basic auth header per RFC 7617; strict mode rejects invalid input.
		$decoded = base64_decode( substr( $header, 6 ), true );
		if ( false === $decoded || false === strpos( $decoded, ':' ) ) {
			return array( '', '' );
		}

		list( $username, $password ) = explode( ':', $decoded, 2 );
		return array( trim( $username ), trim( $password ) );
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
