<?php
/**
 * Telemetry handler — forwards events to WooCommerce Tracks.
 *
 * Activated by hey_woo_maybe_add_tracks_handler() when usage tracking is on.
 * Also respects WC_Site_Tracking::is_tracking_enabled() as an additional
 * safety gate. Silently skips if WC_Tracks is not loaded.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Telemetry\Handlers;

use WooCommerce\HeyWoo\Telemetry\TelemetryHandlerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Routes telemetry events to WooCommerce Tracks (wcadmin_ prefix).
 *
 * Per Tracks naming conventions, each logical event must be its own hardcoded
 * Tracks name in the form <source>_<context>_<subcontext>_<action>. Callers
 * pass the <subcontext>_<action> tail; this handler prepends the hey_woo_
 * context, and WC_Tracks adds the wcadmin_ source. So a caller-supplied
 * 'message_feedback_submitted' becomes wcadmin_hey_woo_message_feedback_submitted
 * in Tracks.
 */
class TracksHandler implements TelemetryHandlerInterface {

	/**
	 * Context segment for every Hey Woo Tracks event.
	 */
	const EVENT_PREFIX = 'hey_woo_';

	/**
	 * Send the whole telemetry payload to Tracks under a per-event name.
	 *
	 * @param string $event_name Hardcoded event tail (e.g. 'message_feedback_submitted').
	 * @param array  $data       Telemetry payload.
	 * @return void
	 */
	public function record( $event_name, $data ) {
		if ( ! class_exists( 'WC_Tracks' ) ) {
			return;
		}

		if ( class_exists( 'WC_Site_Tracking' )
			&& is_callable( array( 'WC_Site_Tracking', 'is_tracking_enabled' ) )
			&& ! \WC_Site_Tracking::is_tracking_enabled()
		) {
			return;
		}

		\WC_Tracks::record_event(
			self::EVENT_PREFIX . (string) $event_name,
			$this->normalise_data( $data )
		);
	}

	/**
	 * Convert telemetry payload values into Tracks-safe scalar properties.
	 *
	 * @param array $data Telemetry payload.
	 * @return array
	 */
	private function normalise_data( array $data ) {
		$normalised = array();

		foreach ( $data as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'yes' : 'no';
			} elseif ( null === $value ) {
				$value = 'null';
			} elseif ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
				$value = is_string( $value ) ? $value : gettype( $value );
			}

			if ( is_string( $value ) ) {
				$value = str_replace( array( "\n", "\r", "\t" ), ' ', $value );
			}

			$normalised[ (string) $key ] = $value;
		}

		return $normalised;
	}
}
