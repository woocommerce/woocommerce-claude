<?php
/**
 * Skill telemetry dispatcher.
 *
 * Listens on the woocommerce_claude_skill_executed action (fired by the
 * four verb-shaped analytics abilities — wc-analytics/totals,
 * wc-analytics/breakdown, wc-analytics/series, wc-analytics/rows — at
 * the end of every successful execute() call) and fans the payload out
 * to registered handlers.
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
	 * Hooked on woocommerce_claude_skill_executed at priority 10. The
	 * verb-tool abilities (wc-analytics-totals / breakdown / series / rows)
	 * are the only emission points, so each tool call produces exactly one
	 * event with the `(tool, subject, shape)` envelope on the payload.
	 *
	 * @param string $skill_name Skill identifier.
	 * @param array  $data       Telemetry payload.
	 */
	public static function dispatch( $skill_name, $data ) {
		foreach ( self::$handlers as $handler ) {
			$handler->record( $skill_name, $data );
		}
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
