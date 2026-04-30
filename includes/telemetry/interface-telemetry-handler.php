<?php
/**
 * Contract for skill telemetry handlers.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Telemetry;

defined( 'ABSPATH' ) || exit;

/**
 * A handler receives the telemetry payload for one skill execution and decides
 * where to send it (log file, Tracks, remote endpoint, etc.).
 */
interface TelemetryHandlerInterface {

	/**
	 * Record a single skill-execution event.
	 *
	 * Keys in $data: duration_ms (int, ms elapsed), cache_hit (bool),
	 * rows_returned (int — count of top_groups/top_products rows, or 1 for scalar skills),
	 * date_start (string YYYY-MM-DD), date_end (string YYYY-MM-DD),
	 * interval (string|null — 'day', 'week', 'month', or null for non-series skills),
	 * bucket_count (int|null — number of time buckets: days/interval_size, null when no interval).
	 *
	 * @param string $skill_name Skill identifier, e.g. 'get_revenue_summary'.
	 * @param array  $data       Telemetry payload with duration_ms, cache_hit, rows_returned, date_start, date_end, interval, bucket_count.
	 */
	public function record( $skill_name, $data );
}
