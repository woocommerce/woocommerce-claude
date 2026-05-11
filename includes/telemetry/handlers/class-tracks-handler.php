<?php
/**
 * Telemetry handler — forwards events to WooCommerce Tracks.
 *
 * Activated by Plugin::maybe_add_tracks_handler when the "Enable telemetry"
 * setting is on (WooCommerce > Settings > WooCommerce for Claude). Also respects
 * WC_Site_Tracking::is_tracking_enabled() as an additional safety gate.
 * Silently skips if WC_Tracks isn't loaded.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Telemetry\Handlers;

use WooCommerce\Claude\Telemetry\TelemetryHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Routes skill-execution events to WooCommerce Tracks (wcadmin_ prefix).
 */
class TracksHandler implements TelemetryHandlerInterface {

	/**
	 * Tracks event name (without the wcadmin_ prefix that WC_Tracks adds).
	 */
	const EVENT_NAME = 'woocommerce_claude_skill_executed';

	/**
	 * Send the event to Tracks.
	 *
	 * Always emits the legacy `skill` property for backwards compatibility with
	 * dashboards aggregating on `wcadmin_woocommerce_claude_skill_executed.skill`.
	 * When the payload carries the verb-tool envelope (`tool` / `subject` /
	 * `shape`), those properties are added so new dashboards can pivot on the
	 * (tool, subject, shape) triple. Legacy emissions (fetch_X called outside
	 * a verb tool) send `null` for the new properties — schema-stable, narrower
	 * content. PR 3 will drop the legacy `skill` property when the legacy
	 * fetch-level hook firing is removed.
	 *
	 * @param string $skill_name Skill identifier (legacy slug for legacy emissions,
	 *                          verb-tool ability ID for verb-tool emissions).
	 * @param array  $data       Telemetry payload. Legacy keys: duration_ms,
	 *                          cache_hit, rows_returned, date_start, date_end,
	 *                          interval, bucket_count. Verb-tool keys add:
	 *                          tool, subject, shape.
	 */
	public function record( $skill_name, $data ) {
		if ( ! class_exists( 'WC_Tracks' ) ) {
			return;
		}

		\WC_Tracks::record_event(
			self::EVENT_NAME,
			array(
				'skill'         => $skill_name,
				'tool'          => $data['tool'] ?? null,
				'subject'       => $data['subject'] ?? null,
				'shape'         => $data['shape'] ?? null,
				'duration_ms'   => (int) ( $data['duration_ms'] ?? 0 ),
				'cache_hit'     => ! empty( $data['cache_hit'] ) ? 'yes' : 'no',
				'rows_returned' => (int) ( $data['rows_returned'] ?? 0 ),
				'date_start'    => $data['date_start'] ?? null,
				'date_end'      => $data['date_end'] ?? null,
				'interval'      => $data['interval'] ?? null,
				'bucket_count'  => isset( $data['bucket_count'] ) ? (int) $data['bucket_count'] : null,
			)
		);
	}
}
