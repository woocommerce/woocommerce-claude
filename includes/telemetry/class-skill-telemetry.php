<?php
/**
 * Skill telemetry dispatcher.
 *
 * Listens on the woocommerce_claude_skill_executed action (fired by
 * AnalyticsController and GetCustomerValueAbility after every fetch)
 * and fans the payload out to registered handlers.
 *
 * Default handler set:
 *   - LogHandler: active on non-production environments (local/development/staging).
 *   - TracksHandler: active when the "Enable telemetry" setting is on
 *     (wired in Plugin::maybe_add_tracks_handler via the filter below).
 *
 * Custom handlers can be added via the woocommerce_claude_telemetry_handlers filter:
 *
 *   add_filter(
 *       'woocommerce_claude_telemetry_handlers',
 *       function ( $handlers ) {
 *           $handlers[] = new My_Custom_Handler();
 *           return $handlers;
 *       }
 *   );
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Telemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Central dispatcher for skill-execution telemetry.
 */
class SkillTelemetry {

	/**
	 * Registered handlers.
	 *
	 * @var TelemetryHandlerInterface[]
	 */
	private static $handlers = array();

	/**
	 * When true, `dispatch()` returns without invoking any handlers.
	 *
	 * Set by verb-tool abilities (wc-analytics-totals, wc-analytics-breakdown,
	 * wc-analytics-series, wc-analytics-rows) around the inner `fetch_X()` call
	 * they delegate to. The fetch method still fires its legacy
	 * `woocommerce_claude_skill_executed` action — but with this flag set,
	 * dispatch skips, so handlers never see the legacy payload. After the
	 * fetch returns, the verb tool clears the flag and re-fires the action
	 * with the enriched `(tool, subject, shape)` payload — that second
	 * invocation propagates to handlers normally.
	 *
	 * Why this dance: direct callers of `AnalyticsController::fetch_X()`
	 * (admin UIs, CLI, any future internal consumer) keep getting telemetry
	 * automatically because the action still fires inside the fetch. Only
	 * the verb-tool path, which knows it's about to re-emit with the
	 * enriched shape, suppresses the inner emission.
	 *
	 * @var bool
	 */
	private static $suppress_dispatch = false;

	/**
	 * Wire up the action listener and build the default handler set.
	 *
	 * Called once from Plugin::init_hooks(). The woocommerce_claude_telemetry_handlers
	 * filter runs here, so handlers must be added to the filter before plugins_loaded
	 * or init at the latest.
	 */
	public static function init() {
		/**
		 * Filter the telemetry handlers that receive skill-execution events.
		 *
		 * Return an array of TelemetryHandlerInterface instances. The default
		 * set contains LogHandler (non-production only). Plugin::maybe_add_tracks_handler
		 * adds TracksHandler via this filter when the "Enable telemetry" setting is on.
		 *
		 * @since 0.1.0
		 *
		 * @param TelemetryHandlerInterface[] $handlers Default handler list.
		 */
		$candidates = apply_filters( 'woocommerce_claude_telemetry_handlers', self::default_handlers() );

		foreach ( $candidates as $handler ) {
			if ( $handler instanceof TelemetryHandlerInterface ) {
				self::$handlers[] = $handler;
			}
		}

		add_action( 'woocommerce_claude_skill_executed', array( self::class, 'dispatch' ), 10, 2 );
	}

	/**
	 * Add a handler at runtime (useful for tests or late-binding integrations).
	 *
	 * @param TelemetryHandlerInterface $handler Handler to add.
	 */
	public static function add_handler( $handler ) {
		if ( $handler instanceof TelemetryHandlerInterface ) {
			self::$handlers[] = $handler;
		}
	}

	/**
	 * Dispatch a skill-execution event to all registered handlers.
	 *
	 * Hooked on woocommerce_claude_skill_executed at priority 10.
	 *
	 * @param string $skill_name Skill identifier.
	 * @param array  $data       Telemetry payload.
	 */
	public static function dispatch( $skill_name, $data ) {
		if ( self::$suppress_dispatch ) {
			return;
		}
		foreach ( self::$handlers as $handler ) {
			$handler->record( $skill_name, $data );
		}
	}

	/**
	 * Begin a window during which `dispatch()` is a no-op.
	 *
	 * Verb-tool abilities call this before delegating to a `fetch_X()`
	 * method, then call `resume_dispatch()` immediately after the fetch
	 * returns. Within the window, the inner fetch's legacy
	 * `woocommerce_claude_skill_executed` action still fires — its
	 * payload just doesn't reach handlers via this dispatcher.
	 *
	 * Other listeners on `woocommerce_claude_skill_executed` (debug
	 * plugins, third-party code) still see the action in both forms
	 * (legacy from inside the fetch, enriched from the verb tool). The
	 * suppression is scoped to this dispatcher only.
	 *
	 * Idempotent — calling twice in a row is harmless. Always pair with
	 * `resume_dispatch()` (in a try/finally) to avoid wedging the
	 * dispatcher closed if a fetch throws.
	 */
	public static function suppress_dispatch() {
		self::$suppress_dispatch = true;
	}

	/**
	 * End the suppression window opened by `suppress_dispatch()`.
	 *
	 * Safe to call even if no suppression is active.
	 */
	public static function resume_dispatch() {
		self::$suppress_dispatch = false;
	}

	/**
	 * Build the default handler list.
	 *
	 * LogHandler is included on non-production environments (local/development/staging)
	 * so skill events appear in WC logs during development without any setup.
	 * On production, only TracksHandler runs — and only when the setting is on.
	 *
	 * @return TelemetryHandlerInterface[]
	 */
	private static function default_handlers() {
		$handlers = array();

		if ( 'production' !== wp_get_environment_type() ) {
			$handlers[] = new Handlers\LogHandler();
		}

		return $handlers;
	}
}
