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
	 * Production defaults to no telemetry handlers.
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
