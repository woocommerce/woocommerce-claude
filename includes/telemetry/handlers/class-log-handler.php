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
	 * Always emits the legacy `skill=<name>` field for backwards compatibility
	 * with dashboards reading the original schema. When the payload carries
	 * the verb-tool envelope (`tool` / `subject` / `shape`), those fields are
	 * appended so log readers can pivot on the new vocabulary. Legacy
	 * emissions (fetch_X called outside a verb tool) log with the new fields
	 * as `null` — same schema, narrower content.
	 *
	 * @param string $skill_name Skill identifier (legacy slug for legacy emissions,
	 *                          verb-tool ability ID for verb-tool emissions).
	 * @param array  $data       Telemetry payload. Legacy keys: duration_ms,
	 *                          cache_hit, rows_returned, date_start, date_end,
	 *                          interval, bucket_count. Verb-tool keys add:
	 *                          tool, subject, shape.
	 */
	public function record( $skill_name, $data ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$logger  = wc_get_logger();
		$message = sprintf(
			'skill=%s tool=%s subject=%s shape=%s duration_ms=%d cache_hit=%s rows_returned=%d date_start=%s date_end=%s interval=%s bucket_count=%s',
			$skill_name,
			$data['tool'] ?? 'null',
			$data['subject'] ?? 'null',
			$data['shape'] ?? 'null',
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
