<?php
/**
 * Thin helper for invoking registered WordPress abilities from PHP.
 *
 * Detectors and the runner reuse this so they don't depend on the REST
 * controller and don't repeat the same `wp_get_ability` plumbing.
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Detectors
 */

namespace WooCommerce\HeyWoo\ThisWeek\Detectors;

defined( 'ABSPATH' ) || exit;

/**
 * Direct ability invoker.
 */
class AbilityRunner {

	/**
	 * Call an ability and return its array result, or null on failure.
	 *
	 * Returning null rather than WP_Error keeps detector code linear — a missing
	 * ability or a broken response simply means "no signal", which is the right
	 * default for proactive monitoring.
	 *
	 * @param string              $ability_id Ability identifier, e.g. `wc-analytics/totals`.
	 * @param array<string,mixed> $input      Ability input payload.
	 * @return array<string,mixed>|null
	 */
	public static function call( $ability_id, array $input ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $ability_id ) ) {
			return null;
		}

		$ability = wp_get_ability( $ability_id );
		if ( ! $ability ) {
			return null;
		}

		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return is_array( $result ) ? $result : null;
	}
}
