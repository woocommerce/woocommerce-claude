<?php
/**
 * Telemetry handler registry.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Telemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Stores telemetry handlers and fans events out to each handler.
 */
class TelemetryHandler {

	/**
	 * Registered handlers.
	 *
	 * @var TelemetryHandlerInterface[]
	 */
	private static $handlers = array();

	/**
	 * Build the handler registry.
	 *
	 * Called once from the plugin bootstrap. The hey_woo_telemetry_handlers
	 * filter runs here, so handlers must be added to the filter before
	 * plugins_loaded or init at the latest.
	 *
	 * @return void
	 */
	public static function init() {
		self::$handlers = array();

		/**
		 * Filter the telemetry handlers that receive events.
		 *
		 * Return an array of TelemetryHandlerInterface instances.
		 * The default set contains LogHandler on non-production environments.
		 * TelemetryHandler::maybe_add_tracks_handler() adds TracksHandler via this filter
		 * when usage tracking is on.
		 *
		 * @since 0.1.0
		 *
		 * @param TelemetryHandlerInterface[] $handlers Default handler list.
		 */
		$candidates = apply_filters( 'hey_woo_telemetry_handlers', self::default_handlers() );

		foreach ( $candidates as $handler ) {
			self::add_handler( $handler );
		}
	}

	/**
	 * Add a handler at runtime.
	 *
	 * @param TelemetryHandlerInterface $handler Handler to add.
	 * @return void
	 */
	public static function add_handler( $handler ) {
		if ( $handler instanceof TelemetryHandlerInterface ) {
			self::$handlers[] = $handler;
		}
	}

	/**
	 * Conditionally add TracksHandler to the telemetry handler list.
	 *
	 * Hooked before init() so the option is evaluated when the handler set is
	 * first built.
	 *
	 * @param TelemetryHandlerInterface[] $handlers Current handler list.
	 * @return TelemetryHandlerInterface[]
	 */
	public static function maybe_add_tracks_handler( $handlers ) {
		// Default 'yes' matches the activation-hook default in hey_woo_activate()
		// and the settings-page render default, so a missing option behaves the
		// same as an opted-in install rather than silently dropping events.
		// Merchants who explicitly turn the setting off get 'no' stored and the
		// handler stays absent.
		if ( 'yes' === get_option( 'hey_woo_telemetry_enabled', 'yes' ) ) {
			$handlers[] = new Handlers\TracksHandler();
		}

		return $handlers;
	}

	/**
	 * Record an event with every registered handler.
	 *
	 * @param string $event_name Event or skill identifier.
	 * @param array  $data       Telemetry payload.
	 * @return void
	 */
	public static function record( $event_name, array $data ) {
		foreach ( self::$handlers as $handler ) {
			$handler->record( $event_name, $data );
		}
	}

	/**
	 * Build the default handler list.
	 *
	 * LogHandler is included on non-production environments (local/development/staging)
	 * so telemetry events appear in WC logs during development without any setup.
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
