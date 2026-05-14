<?php
/**
 * Skill telemetry dispatcher.
 *
 * Listens on the woocommerce_claude_skill_executed action (fired by the
 * four verb-shaped analytics abilities — wc-analytics/totals,
 * wc-analytics/breakdown, wc-analytics/series, wc-analytics/rows — at
 * the end of every successful execute() call) and prepares skill-shaped
 * telemetry payloads before handing them to the handler registry.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Telemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Prepares WooCommerce for Claude skill telemetry payloads.
 */
class SkillTelemetry {

	/**
	 * Wire up the skill-execution action listener.
	 *
	 * Called once from Plugin::init_hooks().
	 */
	public static function init() {
		add_action( 'woocommerce_claude_skill_executed', array( self::class, 'dispatch' ), 10, 2 );
	}

	/**
	 * Dispatch a telemetry event to all registered handlers.
	 *
	 * Hooked on woocommerce_claude_skill_executed at priority 10. The
	 * verb-tool abilities (wc-analytics-totals / breakdown / series / rows)
	 * are the only emission points, so each tool call produces exactly one
	 * skill-execution event with the `(tool, subject, shape)` envelope on the
	 * payload.
	 *
	 * @param string $skill_name Skill identifier.
	 * @param array  $data       Telemetry payload.
	 */
	public static function dispatch( $skill_name, $data ) {
		$data = self::prepare_data( $skill_name, $data );

		TelemetryHandler::record( $skill_name, $data );
	}

	/**
	 * Prepare telemetry payloads before handlers receive them.
	 *
	 * @param string $event_name Skill identifier.
	 * @param mixed  $data       Telemetry payload.
	 * @return array
	 */
	private static function prepare_data( $event_name, $data ) {
		$data          = is_array( $data ) ? $data : array( 'value' => $data );
		$data['skill'] = (string) $event_name;
		$data          = self::only_accepted_fields( $data, self::accepted_keys( $event_name, $data ) );

		/**
		 * Filter the telemetry payload before handlers receive it.
		 *
		 * Use this for final event-specific additions or removals after the
		 * accepted-key pass.
		 *
		 * @since 0.2.0
		 *
		 * @param array  $data       Filtered telemetry payload.
		 * @param string $event_name Skill identifier.
		 */
		return (array) apply_filters( 'woocommerce_claude_telemetry_data', $data, (string) $event_name );
	}

	/**
	 * Return the list of telemetry keys accepted before dispatch.
	 *
	 * @param string $event_name Skill identifier.
	 * @param array  $data       Telemetry payload.
	 * @return array
	 */
	private static function accepted_keys( $event_name, array $data ) {
		$keys = array(
			'skill',
			'tool',
			'subject',
			'shape',
			'duration_ms',
			'cache_hit',
			'rows_returned',
			'date_start',
			'date_end',
			'interval',
			'bucket_count',
		);

		/**
		 * Filter the telemetry keys accepted before handlers receive data.
		 *
		 * @since 0.2.0
		 *
		 * @param string[] $keys       Key names accepted into the telemetry payload.
		 * @param string   $event_name Skill identifier.
		 * @param array    $data       Telemetry payload before filtering.
		 */
		$keys = (array) apply_filters( 'woocommerce_claude_telemetry_accepted_keys', $keys, (string) $event_name, $data );

		return array_map( 'strtolower', $keys );
	}

	/**
	 * Keep only accepted telemetry keys.
	 *
	 * @param array $data Telemetry payload.
	 * @param array $keys Lowercase key names to keep.
	 * @return array
	 */
	private static function only_accepted_fields( array $data, array $keys ) {
		$filtered = array();

		foreach ( $data as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $keys, true ) ) {
				$filtered[ $key ] = $value;
			}
		}

		return $filtered;
	}
}
