<?php
/**
 * Telemetry handler — writes to the WooCommerce logger.
 *
 * Active on non-production environments (local/development/staging) so devs
 * can observe event volume and payload shape in WC > Status > Logs without
 * any extra setup. Not loaded in production.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Telemetry\Handlers;

use WooCommerce\HeyWoo\Telemetry\TelemetryHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Routes telemetry events to the WooCommerce logger.
 */
class LogHandler implements TelemetryHandlerInterface {

	/**
	 * Log source label used in WooCommerce > Status > Logs.
	 */
	const LOG_SOURCE = 'hey-woo';

	/**
	 * Write the whole telemetry payload to the WC logger at INFO level.
	 *
	 * @param string $event_name Event or skill identifier.
	 * @param array  $data       Telemetry payload.
	 * @return void
	 */
	public function record( $event_name, $data ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$payload = function_exists( 'wc_print_r' ) ? wc_print_r( $data, true ) : wp_json_encode( $data );
		$payload = is_string( $payload ) ? $payload : '';

		wc_get_logger()->info(
			(string) $event_name . ' ' . $payload,
			array(
				'source' => self::LOG_SOURCE,
				'data'   => $data,
			)
		);
	}
}
