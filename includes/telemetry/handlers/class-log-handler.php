<?php
/**
 * Telemetry handler — writes to the WooCommerce logger.
 *
 * Active on non-production environments (local/development/staging) so devs
 * can observe event volume and payload shape in WC > Status > Logs without
 * any extra setup. Not loaded in production — use TracksHandler there.
 * Logs at INFO level under the 'woocommerce-claude' source.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Telemetry\Handlers;

use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Routes skill-execution events to the WooCommerce logger.
 */
class LogHandler implements TelemetryHandlerInterface {

	/**
	 * Log source label used in WooCommerce > Status > Logs.
	 */
	const LOG_SOURCE = 'woocommerce-claude';

	/**
	 * Write the event to the WC logger at INFO level.
	 *
	 * @param string $skill_name Skill identifier.
	 * @param array  $data       Telemetry payload (duration_ms, cache_hit, rows_returned, date_start, date_end, interval, bucket_count).
	 */
	public function record( $skill_name, $data ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger  = wc_get_logger();
		$message = sprintf(
			'skill=%s duration_ms=%d cache_hit=%s rows_returned=%d date_start=%s date_end=%s interval=%s bucket_count=%s',
			$skill_name,
			(int) ( $data['duration_ms'] ?? 0 ),
			! empty( $data['cache_hit'] ) ? 'true' : 'false',
			(int) ( $data['rows_returned'] ?? 0 ),
			$data['date_start'] ?? 'null',
			$data['date_end'] ?? 'null',
			$data['interval'] ?? 'null',
			isset( $data['bucket_count'] ) ? (string) $data['bucket_count'] : 'null'
		);

		$logger->info( $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
