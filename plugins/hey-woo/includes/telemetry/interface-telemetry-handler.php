<?php
/**
 * Contract for telemetry handlers.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Telemetry;

defined( 'ABSPATH' ) || exit;

/**
 * A handler receives the telemetry payload for one event and decides where to
 * send it (log file, Tracks, remote endpoint, etc.).
 */
interface TelemetryHandlerInterface {

	/**
	 * Record a single telemetry event.
	 *
	 * @param string $event_name Skill or event identifier, e.g. 'wc-analytics/totals'.
	 * @param array  $data       Telemetry payload.
	 */
	public function record( $event_name, $data );
}
