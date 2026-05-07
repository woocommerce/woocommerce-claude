<?php
/**
 * `hey-woo-integrations/google-analytics-channels` ability — flexible GA4 query.
 *
 * Prototype scaffold for cross-referencing GA4 data against our internal
 * analytics. The current build returns a not-implemented WP_Error on
 * invocation — real auth (service account) + SDK calls (google/analytics-data)
 * land in a follow-up commit on this branch. The registration / schema /
 * MCP-include wiring is testable end-to-end now; the data path is not.
 *
 * Pointed at channels as the obvious first cross-reference target with
 * `wc-analytics/get-attribution` (GA's source/medium vs our channel
 * taxonomy). Designed as a flexible passthrough so other cuts —
 * geography, search terms, landing pages — can be tried by varying the
 * dimensions/metrics inputs without rebuilding the ability. If the
 * integration ships to merchants, focused skills with channel-taxonomy
 * normalisation would layer on top of this passthrough.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the GA4-channels prototype ability under the plugin-owned
 * `hey-woo-integrations/` namespace. The ability is exposed on the Hey
 * Woo MCP server (`/wp-json/hey-woo/mcp`) by listing it in
 * Plugin::mcp_tool_ability_ids(). Using a plugin-owned prefix (rather
 * than the broader `integrations/`) keeps the curated tool list scoped
 * to abilities we own.
 */
class GoogleAnalyticsChannelsAbility {

	const ABILITY_NAME = 'hey-woo-integrations/google-analytics-channels';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Query Google Analytics 4 (channels-shaped)', 'hey-woo' ),
				'description'         => __( 'Prototype scaffold for querying a GA4 property via the Data API. Currently returns a not-implemented error on every call — real auth + SDK calls land in a follow-up commit on this branch. Designed as a flexible passthrough so the same ability can serve channels, geography, search terms, or landing-page cuts without rebuilding. Not for merchant use yet.', 'hey-woo' ),
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission gate — same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input = null ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * JSON Schema for the ability input.
	 *
	 * @return array
	 */
	private static function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date_start' => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Window start (YYYY-MM-DD).',
				),
				'date_end'   => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Window end (YYYY-MM-DD).',
				),
				'dimensions' => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'default'     => array( 'sessionDefaultChannelGroup' ),
					'description' => 'GA4 dimension API names (e.g. sessionDefaultChannelGroup, sessionSource, sessionMedium, country, landingPage).',
				),
				'metrics'    => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
					'default'     => array( 'sessions', 'totalRevenue', 'transactions' ),
					'description' => 'GA4 metric API names (e.g. sessions, totalRevenue, transactions, ecommercePurchases).',
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 25,
					'description' => 'Row cap.',
				),
			),
		);
	}

	/**
	 * JSON Schema for the ability output.
	 *
	 * @return array
	 */
	private static function output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period' => array( 'type' => 'object' ),
				'rows'   => array( 'type' => 'array' ),
				'note'   => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
			),
		);
	}

	/**
	 * Run the ability.
	 *
	 * Returns a not-implemented WP_Error until the real GA4 fetcher lands.
	 * Failing loud here (rather than fabricating zeros from a stub data
	 * source) ensures merchants and agents can't accidentally treat this
	 * ability's response as real GA4 data while the SDK + auth layer is
	 * still missing.
	 *
	 * @param array $input Validated ability input (unused while stubbed).
	 * @return \WP_Error
	 */
	public static function execute( $input ) {
		unset( $input );

		return new \WP_Error(
			'not_implemented',
			__( 'Google Analytics 4 integration is a prototype scaffold — the real fetcher (service-account auth + google/analytics-data SDK) lands in a follow-up commit on this branch. Calling this ability returns no data today.', 'hey-woo' ),
			array( 'status' => 501 )
		);
	}
}
