<?php
/**
 * Main plugin class.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude;

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
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/telemetry/interface-telemetry-handler.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/telemetry/class-telemetry-handler.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/telemetry/handlers/class-log-handler.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/telemetry/handlers/class-tracks-handler.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/telemetry/class-skill-telemetry.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/telemetry/class-difm-ai-telemetry.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/telemetry/class-anthropic-telemetry.php';

		// Knowledge system.
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/knowledge/interface-knowledge-provider.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/knowledge/class-knowledge-registry.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/knowledge/providers/class-store-profile-provider.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/knowledge/providers/class-catalog-provider.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/knowledge/providers/class-product-provider.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/knowledge/providers/class-policy-provider.php';

		// Scoring engine.
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/scoring/class-scoring-engine.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/scoring/factors/class-product-completeness.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/scoring/factors/class-schema-coverage.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/scoring/factors/class-policy-completeness.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/scoring/factors/class-content-quality.php';

		// REST API (store knowledge + readiness — analytics lives in commerce-abilities).
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/api/class-store-controller.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/api/class-catalog-controller.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/api/class-products-controller.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/api/class-readiness-controller.php';
		// Backwards-compatible alias to the shared analytics service (no REST
		// routes of its own).
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/api/class-analytics-controller.php';

		// Shared analytics compatibility aliases. The commerce-abilities
		// package owns registration; these keep old PHP class references stable.
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-large-range-gate.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-confirm-large-range-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-analytics-totals-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-analytics-breakdown-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-analytics-series-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-analytics-rows-ability.php';

		// Abilities API — Claude-specific tools, resources, prompts, and dev scaffolds.
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-abilities-bootstrap.php';

		// External-integration abilities (woocommerce-claude-integrations/*) — dev/local only.
		// The GA4 ability is a prototype scaffold; only register it in local and
		// development environments so it never surfaces to merchants on production.
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-google-analytics-channels-ability.php';
		}

		// Non-analytics tool abilities (woocommerce-claude/*) — store knowledge,
		// readiness, and product search helpers.
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-get-store-profile-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-search-products-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-get-product-details-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-get-readiness-score-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-get-recommendations-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-suggest-improvements-ability.php';

		// Resource + prompt abilities — not tools; passed to our MCP server's
		// resources/prompts arrays in register_mcp_server(), wired via the
		// mcp_adapter_init hook in init_hooks().
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-store-profile-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-catalog-schema-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-store-policies-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-catalog-audit-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-product-improve-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-weekly-store-review-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-failed-order-triage-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-refund-triage-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-revenue-drop-triage-ability.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/abilities/class-coupon-performance-triage-ability.php';

		// Connect-to-Claude setup page. McpbBundle is loaded lazily inside
		// the download handler since it's only used on that one path.
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/setup/class-rest-api-key.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/setup/class-setup-page.php';

		// DIFM — AI Insights (AI provider). The admin page, REST
		// controller, and provider clients are loaded lazily by the hooks they
		// register, so we only require the class files here and let the hooks
		// instantiate as needed.
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/interface-difm-ai-client.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-openai-responses-client.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-wordpress-ai-client-adapter.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-difm-provider-resolver.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-workflow-skills.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-difm-rest-controller.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-difm-conversations-controller.php';
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-difm-admin-page.php';
	}

	/**
	 * Wire up WordPress hooks for REST routes, abilities, and authentication.
	 */
	private function init_hooks() {
		// Gate TracksHandler behind the settings toggle. Must run before
		// TelemetryHandler::init() so the filter is registered when handlers are built.
		add_filter( 'woocommerce_claude_telemetry_handlers', array( $this, 'maybe_add_tracks_handler' ) );

		Telemetry\TelemetryHandler::init();
		Telemetry\SkillTelemetry::init();
		Setup\SetupPage::init();

		add_action( 'rest_api_init', array( API\StoreController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( API\CatalogController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( API\ProductsController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( API\ReadinessController::class, 'register_routes' ) );

		// Abilities API — commerce-abilities registers the shared wc-analytics
		// category/tools; this plugin registers Claude-specific surfaces.
		add_action( 'wp_abilities_api_init', array( Abilities\AbilitiesBootstrap::class, 'register_abilities' ) );

		// WooCommerce Settings tab.
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'register_settings_page' ) );

		// AI Insights admin page + REST controllers. Hey Woo owns the BYOK
		// admin-chat product when it is installed, so avoid registering the
		// legacy WooCommerce for Claude surface beside it.
		if ( ! $this->is_hey_woo_active() ) {
			( new Difm\DifmAdminPage() )->register();
			( new Difm\DifmRestController() )->register();
			( new Difm\DifmConversationsController() )->register();
		}

		// Enable WooCommerce REST API key authentication for our custom namespace.
		// WC's auth handler only processes requests to /wc/ routes by default.
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( $this, 'enable_wc_auth_for_our_routes' ), 10 );

		// Boot the MCP adapter ourselves so the WooCommerce for Claude MCP endpoint works
		// independently of WC's `mcp_integration` feature flag. The adapter
		// is a singleton — calling instance() repeatedly is a no-op, so this
		// is safe even if WC's MCPAdapterProvider also boots it.
		add_action( 'plugins_loaded', array( $this, 'bootstrap_mcp_adapter' ), 20 );

		// Suppress the adapter's auto-created default server at
		// `/wp-json/mcp/mcp-adapter-default-server`. That endpoint uses the
		// adapter's default `current_user_can('read')` permission instead
		// of our `authenticate_mcp_request` callback, so leaving it on
		// would expose a second, non-curated MCP surface — including any
		// abilities a third-party plugin marks as `mcp.public`. The setup
		// flow scopes access to `/wp-json/woocommerce-claude/mcp` only, and our
		// curated server already covers the surface we want to expose.
		add_filter( 'mcp_adapter_create_default_server', '__return_false' );

		// Register our own MCP server when the adapter initializes. Owns the
		// `/wp-json/woocommerce-claude/mcp` endpoint with a curated tool/resource/prompt
		// surface and a custom auth callback that authenticates ck:cs Basic
		// Auth against `wp_woocommerce_api_keys`.
		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ) );

		// Tell WP not to run application-password auth on our MCP route.
		// Without this, on any site where any app password exists
		// (`WP_Application_Passwords::is_in_use()` returns true), WP's
		// app-password handler runs at `determine_current_user` priority
		// 20, fails with `invalid_username` for our `ck_xxx` user, stores
		// the error on `$wp_rest_application_password_status`, and
		// `rest_application_password_check_errors` returns 401 before our
		// route permission callback ever runs. Excluding the MCP route
		// from `application_password_is_api_request` makes the early
		// return inside `wp_authenticate_application_password` fire — no
		// error global, no 401 — so our own callback can authenticate
		// the WC API key cleanly.
		add_filter( 'application_password_is_api_request', array( $this, 'exclude_mcp_route_from_app_password_auth' ) );
	}

	/**
	 * Register the WooCommerce for Claude settings tab in WooCommerce > Settings.
	 *
	 * WC includes WC_Settings_Page before firing this filter, so our class
	 * is safe to load here.
	 *
	 * @param array $pages Registered settings pages.
	 * @return array
	 */
	public function register_settings_page( $pages ) {
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/settings/class-settings-page.php';
		$pages[] = new Settings\SettingsPage();
		return $pages;
	}

	/**
	 * Conditionally add TracksHandler to the telemetry handler list.
	 *
	 * Hooked on woocommerce_claude_telemetry_handlers before TelemetryHandler::init()
	 * so the option is evaluated when the handler set is first built.
	 * Option name matches Setup\SetupPage::TELEMETRY_OPTION.
	 *
	 * @param array $handlers Current handler list.
	 * @return array
	 */
	public function maybe_add_tracks_handler( $handlers ) {
		if ( 'yes' === get_option( 'woocommerce_claude_telemetry_enabled', 'no' ) ) {
			$handlers[] = new Telemetry\Handlers\TracksHandler();
		}
		return $handlers;
	}

	/**
	 * Whether the Hey Woo plugin is active in this request.
	 *
	 * @return bool
	 */
	private function is_hey_woo_active() {
		if ( defined( 'HEY_WOO_PLUGIN_FILE' ) ) {
			return true;
		}

		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( in_array( 'hey-woo/hey-woo.php', $active_plugins, true ) ) {
			return true;
		}

		if ( is_multisite() ) {
			$network_plugins = (array) get_site_option( 'active_sitewide_plugins', array() );
			return isset( $network_plugins['hey-woo/hey-woo.php'] );
		}

		return false;
	}

	/**
	 * Plugin-owned Abilities API namespaces.
	 *
	 * Defines the namespaces this plugin claims ownership of. The WC auth
	 * scope filter trusts only routes under one of these namespaces, so a
	 * WooCommerce for Claude consumer key can't be replayed against abilities registered
	 * by an unrelated plugin under a different prefix. Kept as a constant
	 * so adding a new namespace is a single-edit operation; the
	 * `mcp_tool_ability_ids()` curated list below has its own per-namespace
	 * logic and must stay aligned by hand.
	 *
	 * @var array<int, string>
	 */
	private const OWNED_ABILITY_NAMESPACES = array(
		'wc-analytics/',
		'woocommerce-claude/',
		'woocommerce-claude-integrations/',
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

		// Match REST controllers under woocommerce-claude/v1/ — but *not* the MCP
		// endpoint at woocommerce-claude/mcp. WC's check_user_permissions enforces
		// the read/write split per HTTP method, which would block POST
		// calls from a read-only key. MCP uses POST for every call
		// (including semantic reads), so the MCP transport authenticates
		// itself via the registered transport_permission_callback rather
		// than going through WC's full auth chain.
		if ( 0 === strpos( $route, 'woocommerce-claude/v1/' ) ) {
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
	 * Tell WP not to treat the WooCommerce for Claude MCP route as an "API request" for
	 * the purposes of application-password auth. See the rationale on
	 * the `application_password_is_api_request` filter registration in
	 * `init_hooks()` for the full chain — short version: the WC consumer
	 * key (`ck_xxx`) is not a WP user login, and the app-password
	 * handler turns that into a 401 on `rest_authentication_errors`
	 * before our route permission callback runs.
	 *
	 * Filters very narrowly — only `/wp-json/woocommerce-claude/mcp` and any
	 * sub-routes the transport may add. Every other REST route still
	 * gets WP's normal app-password handling.
	 *
	 * @param bool $is_api_request What WP would otherwise consider this request.
	 * @return bool
	 */
	public function exclude_mcp_route_from_app_password_auth( $is_api_request ) {
		if ( ! $is_api_request ) {
			return $is_api_request;
		}

		$route = $this->current_rest_route();
		if ( '' === $route ) {
			return $is_api_request;
		}

		$mcp_prefix = self::MCP_SERVER_NS . '/' . self::MCP_SERVER_ROUTE;
		if ( 0 === strpos( $route, $mcp_prefix ) ) {
			return false;
		}

		return $is_api_request;
	}

	/**
	 * Resolve the REST route the current request targets, regardless of
	 * whether the install uses pretty (/wp-json/<route>) or plain
	 * (?rest_route=/<route>) permalinks.
	 *
	 * Returns the route relative to the REST prefix, without a leading
	 * slash (e.g. `woocommerce-claude/v1/store/profile` or
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
	 * resolves to `/wp-json/<namespace>/<route>` — i.e. `/wp-json/woocommerce-claude/mcp`.
	 */
	const MCP_SERVER_ID    = 'woocommerce-claude';
	const MCP_SERVER_NS    = 'woocommerce-claude';
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
	 * Register the WooCommerce for Claude MCP server on `mcp_adapter_init`.
	 *
	 * Replaces the previous "ride on WC's woocommerce-mcp server" approach
	 * (which used `woocommerce_mcp_include_ability` + a late
	 * `mcp_adapter_init` injection of resources/prompts). Our server owns
	 * `/wp-json/woocommerce-claude/mcp` directly and ships a curated tool list, so the
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
				__( 'WooCommerce for Claude MCP Server', 'woocommerce-claude' ),
				$this->mcp_server_instructions(),
				WOOCOMMERCE_CLAUDE_VERSION,
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
					Abilities\WeeklyStoreReviewAbility::ABILITY_NAME,
					Abilities\FailedOrderTriageAbility::ABILITY_NAME,
					Abilities\RefundTriageAbility::ABILITY_NAME,
					Abilities\RevenueDropTriageAbility::ABILITY_NAME,
					Abilities\CouponPerformanceTriageAbility::ABILITY_NAME,
				),
				array( $this, 'authenticate_mcp_request' )
			);
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'WooCommerce for Claude MCP server initialization failed: ' . $e->getMessage(),
					array( 'source' => 'woocommerce-claude-mcp' )
				);
			}
		}
	}

	/**
	 * Server-level guidance shipped to MCP clients on `initialize`.
	 *
	 * The WP MCP adapter's `InitializeHandler::handle()` puts this string in
	 * the `instructions` field of the JSON-RPC response — the slot the MCP
	 * spec defines for "instructions describing how to use the server and
	 * its features." Every Claude session that connects to this connector
	 * receives this block as preloaded context, with no merchant action
	 * required.
	 *
	 * The adapter's `create_server()` parameter is unfortunately named
	 * `server_description`, but the only consumers that read it are the
	 * `instructions` slot above and the WP-CLI `wp mcp server list` output —
	 * there is no separate short-summary slot in the adapter's surface, so
	 * this string serves both jobs.
	 *
	 * What earns its place here:
	 *   - Routing decisions across the four verb-shaped analytics tools
	 *     (totals / breakdown / series / rows) — the model can't infer
	 *     "use wc-analytics-rows for 'show me the actual records'" from
	 *     individual tool descriptions read in isolation.
	 *   - The privacy-mode posture (pseudonymised customer rows, never
	 *     real names/emails). Without this, sessions sometimes refuse the
	 *     question entirely instead of returning the pseudonymised rows
	 *     the connector is happy to provide.
	 *   - The 365-day gate handshake. The gate fires with a session-keyed
	 *     pending record and must be approved via wc-analytics-confirm-large-
	 *     range, then the original call retried — autonomous approval
	 *     defeats the merchant-consent purpose.
	 *
	 * What does NOT belong here: anything already covered by individual
	 * tool descriptions — the verb tools each carry a consolidated
	 * per-subject describe doc inline, so the connector instructions
	 * should not duplicate per-subject narrative. Keep this block focused
	 * on cross-tool decisions and connector-level posture.
	 *
	 * Not translated via `__()` — this is consumed by the model, not the
	 * merchant, and the model is best at parsing English.
	 *
	 * @return string
	 */
	private function mcp_server_instructions() {
		return <<<'INSTRUCTIONS'
You are connected to a live WooCommerce store via the WooCommerce for Claude MCP server. Read this once at session start.

## Tool families

Two families of tools are exposed:

1. Analytics — four verb-shaped tools (`wc-analytics-totals`, `wc-analytics-breakdown`, `wc-analytics-series`, `wc-analytics-rows`) plus `wc-analytics-confirm-large-range` (the gate-approval helper). The verb tools each carry a consolidated describe doc inline — read the tool's own description for parameter shape, narrative guidance, and per-subject privacy rules.
2. Store knowledge — `woocommerce-claude-get-store-profile`, `woocommerce-claude-search-products`, `woocommerce-claude-get-product-details`, `woocommerce-claude-get-readiness-score`, `woocommerce-claude-get-recommendations`, `woocommerce-claude-suggest-improvements`. Call `woocommerce-claude-get-store-profile` once early in any session that touches store data — it returns currency, payment setup, shipping zones, and locale.

## Picking the right analytics tool — by question shape

The four verb tools differ by SHAPE, not by topic. Pick the tool by the shape of the merchant's question, then pick the `subject` (and `dimension` / `interval` where relevant).

`wc-analytics-totals` — headline aggregates, "how much / how many" questions. One scalar-shaped response per subject. `subject` ∈ {revenue, orders, customers, customer_value, tax, refunds}.

`wc-analytics-breakdown` — "broken down by X" questions. One subject × one dimension per call; the dimension space varies by subject. `subject` ∈ {revenue, attribution, products, refunds, tax, coupons}. Examples of subject→dimensions: revenue→{category, country, payment_method, shipping_method}; attribution→{channel, source, medium, campaign, term, content, device, channel_source}; products→{product, variation}.

`wc-analytics-series` — trend questions, "how is X changing over time". `subject` ∈ {customers, products}; `interval` ∈ {day, week, month, auto}. Each row in the series carries the same per-subject metrics for that bucket.

`wc-analytics-rows` — "show me the actual records" questions. Flexible filter engine across three entities. `entity` ∈ {orders, products, customers}; `mode` ∈ {aggregate, rows}; `filters` is an array of `{field, operator, value}`. Reach for this BEFORE concluding an aggregated tool "can't show specifics" — it almost always can.

## Weekly store review intent

For "weekly store review", "how did my store do this week?", or similar broad weekly-performance requests, use this exact minimum data plan with `period=last_7_days` and `compare=true` unless the merchant gave explicit dates: call `woocommerce-claude-get-store-profile` once, `wc-analytics-totals` for revenue, orders, customers, and refunds (do not skip refunds), `wc-analytics-breakdown` for products by product, and `wc-analytics-breakdown` for attribution by channel. Use the tools quietly — do not tell the merchant you are loading schemas, selecting tools, or making parallel calls. Final answer sections: Headline, What changed, Products, Channels, Watch List, Next Actions. Failed and on-hold order value is checkout risk or payment pipeline, not confirmed lost revenue.

## Failed and on-hold order triage intent

For "triage failed orders", "what orders are stuck?", "which unpaid orders should I chase?", "payment pipeline", "checkout failures", or similar payment-risk requests, start with `wc-analytics-totals` subject=orders and read `status_breakdown` plus the `pipeline` diagnostic. Use `wc-analytics-rows` entity=orders with explicit status filters for on-hold and failed orders; rows mode is appropriate when producing an actionable queue, but keep customer details pseudonymised. On-hold is payment pipeline, failed is checkout risk, and neither is confirmed lost revenue. If on-hold value is material or a card gateway appears in the pipeline payment-method diagnostic, use attribution breakdown by channel to see whether one channel over-indexes on pipeline.

## Refund triage intent

For "refund triage", "what is driving refunds?", "are returns/refunds getting worse?", "which products are being refunded?", or similar refund-diagnostic requests, use `period=last_30_days` and `compare=true` unless the merchant gave explicit dates. Call `woocommerce-claude-get-store-profile` once, `wc-analytics-totals` with `subject=refunds`, `wc-analytics-breakdown` with `subject=refunds` by product, and `wc-analytics-breakdown` with `subject=refunds` by country. Refund periods mean refund-issued date, not original order date. Use returned refund-rate, timing, full/partial, and comparison fields directly — do not compute rates or shares yourself. Final answer sections: Snapshot, Severity, Refund Timing, Top Drivers, Likely Checks, Next Actions. Treat refund reasons and chargebacks as manual checks unless the merchant supplied them.

## Revenue drop triage intent

For "why is revenue down?", "sales dropped", "revenue drop triage", "month-over-month revenue decline", "what changed in a soft period?", or similar revenue-decline requests, use `period=last_30_days` and `compare=true` unless the merchant gave explicit dates. Call `woocommerce-claude-get-store-profile` once, `wc-analytics-totals` for revenue, orders, customers, and refunds, `wc-analytics-breakdown` for products by product, and `wc-analytics-breakdown` for attribution by channel. Lead with collected revenue and first decide whether it is actually down; if not, say so. Separate order volume, average order value, customer mix, refunds, pending/on-hold pipeline, product mix, and channel mix. Use returned comparison fields, dropped-out product/channel lists, and attribution coverage directly — do not compute deltas or claim ad spend, ROAS, sessions, conversion rate, competitor effects, stockouts, pricing changes, or seasonality unless the data or merchant supplies that signal. Final answer sections: Snapshot, Severity, Drop Drivers, Product and Channel Signals, Likely Checks, Next Actions.

## Coupon performance triage intent

For "are my coupons working?", "coupon performance triage", "which discount codes are performing?", "is discounting eating margin?", "what did the promotion cost?", "which coupons drive new customers?", or similar coupon-performance requests, use `period=last_30_days` and `compare=true` unless the merchant gave explicit dates. Call `woocommerce-claude-get-store-profile` once and `wc-analytics-breakdown` with subject=coupons, dimension=code, limit=10, orderby=discount_amount, compare=true. Lead with coupon attachment rate, orders with coupons, coupon-order revenue, total discount amount, AOV with versus without coupons, and whether coupon use moved versus the comparison. Use returned effective campaign cost, refund rate, new-customer share, share fields, dropped-out coupon list, and pipeline fields directly — do not compute ratios, call a coupon profitable, claim ROAS/conversion/profit margin, or treat pending coupon revenue as collected. Final answer sections: Snapshot, Severity, Coupon Economics, Top Coupon Signals, Movement and Pipeline, Likely Checks, Next Actions.

## Common routing mistakes — do not make these

- "Which customers ordered?" → `wc-analytics-rows` (entity=customers, mode=rows). NOT `wc-analytics-totals` subject=customers — that returns aggregates (new vs returning split, repeat rate, segment-level AOV), not per-customer rows.
- "Show me the on-hold orders" → `wc-analytics-rows` (entity=orders, with a status filter). NOT `wc-analytics-totals` subject=orders.
- "Which products haven't sold this month?" → `wc-analytics-rows` (entity=products, with a sales-velocity filter).
- "Is one channel filling my on-hold pipeline?" → `wc-analytics-breakdown` subject=attribution, dimension=channel. The `pipeline_over_index_points` field per row is the answer.
- "Is one payment gateway failing?" → `wc-analytics-totals` subject=orders, then read its `pipeline.payment_methods` diagnostic.
- "How is repeat rate trending month over month?" → `wc-analytics-series` subject=customers, interval=month. NOT `wc-analytics-totals` subject=customers — that's aggregate-only.
- "Top customers by lifetime spend" → `wc-analytics-totals` subject=customer_value. For the list of customer rows, follow up with `wc-analytics-rows` entity=customers, mode=rows. Both tools use the active-base frame (customers with ≥1 paid order in the selected period, summarised by their full lifetime). When the merchant asks about "best ever" customers, widen the period (e.g. last 12 months) and call out the frame in the response — otherwise inactive customers are silently excluded.

## Privacy model — surface it, do not refuse

This connector returns aggregated metrics and pseudonymised customer rows. It does NOT return real names, emails, or full street addresses, by design. When the merchant asks for individual customer details:

1. Call `wc-analytics-rows` with `entity=customers`, `mode=rows`. You receive pseudonymised IDs in the form `Customer #N`, lifetime stats, country / city / postcode, and a WP Admin URL on each row.
2. Render the pseudonymised IDs as clickable markdown links to the row's `admin_url` — that is where the merchant resolves a pseudonym to a real identity.
3. Do not refuse the question. The connector goes further than aggregated-tool documentation implies.

## Date ranges over 365 days

`wc-analytics-totals`, `wc-analytics-breakdown`, and `wc-analytics-series` fire a gate when the range exceeds 365 days, returning an `extended_range_required` error. `wc-analytics-rows` does NOT fire the gate — long-range row queries pass through. The error includes a `cost_estimate` block with `range_days`, `months`, and a `type` field. Flow:

1. Show the cost estimate from the error to the merchant.
2. Wait for explicit approval — never call `wc-analytics-confirm-large-range` autonomously.
3. Call `wc-analytics-confirm-large-range` with the same `date_start`, `date_end`, and the LITERAL `type` value from `cost_estimate.type` in the error. Types are tool-prefixed (`totals:revenue` / `breakdown:revenue` / `series:customers`) so approvals minted by one verb tool don't collide with another tool sharing the same subject — pass the value verbatim, not a re-mapping. Add a `description` field — a one-line plain-English summary of the query the merchant just approved (e.g. "3-year customer overview, monthly granularity").
4. Re-run the same analytics call with the same params.

Do not split the range into smaller chunks to bypass the gate — that defeats its purpose.

## Merchant-facing language

The reader is a shop owner, not a developer. In responses to the merchant:

- Never name internal tool identifiers (`wc-analytics-breakdown`, `wc-analytics-rows`), parameter names (`subject`, `dimension`, `mode`, `match`), or storage slugs (`bacs`, `wc-on-hold`).
- Phrase follow-ups as questions ("Want me to break this down by product?"), not tool invocations.
- Use plain-English revenue framing — "collected revenue", "pending revenue", "the dashboard-matching figure" — not internal field paths.
- Never sum the three revenue views — they overlap.
INSTRUCTIONS;
	}

	/**
	 * Tool ability IDs to expose on our MCP server.
	 *
	 * The four verb-shaped analytics tools (totals / breakdown / series /
	 * rows) plus the confirmation helper for the 365-day gate.
	 *
	 * @return array<int, string>
	 */
	private function mcp_tool_ability_ids() {
		$tools = array(
			// woocommerce-claude/* — store knowledge, readiness, product helpers.
			Abilities\GetStoreProfileAbility::ABILITY_NAME,
			Abilities\GetReadinessScoreAbility::ABILITY_NAME,
			Abilities\GetRecommendationsAbility::ABILITY_NAME,
			Abilities\GetProductDetailsAbility::ABILITY_NAME,
			Abilities\SearchProductsAbility::ABILITY_NAME,
			Abilities\SuggestImprovementsAbility::ABILITY_NAME,
			// wc-analytics/* — four verb-shaped tools + confirm-large-range.
			Abilities\AnalyticsTotalsAbility::ABILITY_NAME,
			Abilities\AnalyticsBreakdownAbility::ABILITY_NAME,
			Abilities\AnalyticsSeriesAbility::ABILITY_NAME,
			Abilities\AnalyticsRowsAbility::ABILITY_NAME,
			Abilities\ConfirmLargeRangeAbility::ABILITY_NAME,
		);

		// woocommerce-claude-integrations/* — dev/local only. The class is loaded under
		// the same environment gate in includes(), so check_class_exists
		// before referencing the constant to keep production safe.
		if ( class_exists( '\\WooCommerce\\Claude\\Abilities\\GoogleAnalyticsChannelsAbility' ) ) {
			$tools[] = \WooCommerce\Claude\Abilities\GoogleAnalyticsChannelsAbility::ABILITY_NAME;
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
	 * resources/read, etc.). WooCommerce for Claude provisions read-only keys by
	 * design, so the standard chain would 401 every MCP call. This
	 * callback authenticates the consumer key directly against
	 * `wp_woocommerce_api_keys`, sets the user, and returns true —
	 * bypassing WC's POST-as-write assumption while still requiring a
	 * valid key. Per-ability `permission_callback` handlers enforce the
	 * real capability gates (e.g. `manage_woocommerce` for analytics
	 * tools).
	 *
	 * Honours the WC key's permission scope: `read` and `read_write`
	 * authenticate; `write` does not. The MCP surface is read-only
	 * today (analytics fetches, knowledge resources, prompt
	 * descriptions), so a write-only key has nothing to authenticate
	 * for — letting it through would silently grant the read access
	 * the merchant explicitly excluded when they set the key to
	 * write-only in WooCommerce → Settings → Advanced → REST API.
	 *
	 * @param \WP_REST_Request $request The current REST request.
	 * @return bool True if authenticated, false otherwise.
	 */
	public function authenticate_mcp_request( $request ) {
		if ( ! ( $request instanceof \WP_REST_Request ) ) {
			return false;
		}

		list( $consumer_key, $consumer_secret ) = self::extract_basic_auth( $request );
		if ( '' === $consumer_key || '' === $consumer_secret ) {
			return false;
		}

		if ( ! function_exists( 'wc_api_hash' ) ) {
			return false;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- direct lookup against woocommerce_api_keys; table prefix is trusted; no caching surface for auth.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT key_id, user_id, consumer_secret, permissions FROM {$wpdb->prefix}woocommerce_api_keys WHERE consumer_key = %s",
				wc_api_hash( $consumer_key )
			)
		);

		if ( ! $row || ! hash_equals( (string) $row->consumer_secret, $consumer_secret ) ) {
			return false;
		}

		// Honour WC key scope: only `read` and `read_write` keys can read
		// from the MCP surface. `write` is a no-op for the current
		// read-only ability set.
		if ( ! in_array( (string) $row->permissions, array( 'read', 'read_write' ), true ) ) {
			return false;
		}

		$user = get_user_by( 'id', (int) $row->user_id );
		if ( ! $user ) {
			return false;
		}

		wp_set_current_user( $user->ID );
		if ( (int) get_option( Setup\RestApiKey::OPTION_KEY_ID, 0 ) === (int) $row->key_id ) {
			update_option( Setup\RestApiKey::OPTION_LAST_SEEN, time(), false );
		}
		return true;
	}

	/**
	 * Pull a Basic Auth credential pair off the current request.
	 *
	 * Tries `PHP_AUTH_USER`/`PHP_AUTH_PW` first (what mod_php and
	 * php-fpm normally populate from `Authorization: Basic …`). Then
	 * reads the request's `Authorization` header via WP's normalised
	 * accessor — `WP_REST_Server::get_headers()` already maps the raw
	 * `HTTP_AUTHORIZATION` *and* the `REDIRECT_HTTP_AUTHORIZATION`
	 * variant (common on CGI/FastCGI behind Apache `mod_rewrite`) onto
	 * the same `Authorization` request header, so a single
	 * `$request->get_header()` call covers every SAPI WP itself
	 * supports.
	 *
	 * Returns `['', '']` if no credential is present or the header is
	 * malformed — caller treats that as auth failure.
	 *
	 * @param \WP_REST_Request $request The current REST request.
	 * @return array{0:string,1:string} `[username, password]`.
	 */
	private static function extract_basic_auth( $request ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only inspection of an in-flight REST request.
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- credentials are byte-compared, not interpolated; sanitisation would corrupt them.
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			return array(
				trim( wp_unslash( (string) $_SERVER['PHP_AUTH_USER'] ) ),
				trim( wp_unslash( (string) $_SERVER['PHP_AUTH_PW'] ) ),
			);
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$header = $request->get_header( 'authorization' );
		if ( ! is_string( $header ) || 0 !== stripos( $header, 'Basic ' ) ) {
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
		do_action( 'woocommerce_claude_register_providers', $registry );
	}
}
