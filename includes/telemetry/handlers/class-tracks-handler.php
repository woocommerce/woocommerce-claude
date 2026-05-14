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
 * Routes telemetry events to WooCommerce Tracks (wcadmin_ prefix).
 */
class TracksHandler implements TelemetryHandlerInterface {

	/**
	 * Generic Tracks event name (without the wcadmin_ prefix that WC_Tracks adds).
	 */
	const EVENT_NAME = 'woocommerce_claude_telemetry_event';

	/**
	 * Send the whole telemetry payload to Tracks.
	 *
	 * @param string $event_name Event or skill identifier.
	 * @param array  $data       Telemetry payload.
	 * @return void
	 */
	public function record( $event_name, $data ) {
		unset( $event_name );

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
			self::EVENT_NAME,
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
