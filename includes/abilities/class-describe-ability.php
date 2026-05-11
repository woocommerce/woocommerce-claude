<?php
/**
 * `wc-analytics/describe` ability.
 *
 * Returns the full documentation for a specific analytics type — call this
 * before using wc-analytics/get-data with a type you haven't used before.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the describe ability.
 */
class DescribeAbility {

	const ABILITY_NAME = 'wc-analytics/describe';

	/**
	 * Ability name → type slug mapping.
	 *
	 * @var array<string, string>
	 */
	const ABILITY_MAP = array(
		'revenue_summary'     => 'wc-analytics/get-revenue-summary',
		'orders_summary'      => 'wc-analytics/get-orders-summary',
		'product_performance' => 'wc-analytics/get-product-performance',
		'customer_overview'   => 'wc-analytics/get-customer-overview',
		'attribution'         => 'wc-analytics/get-attribution',
		'customer_value'      => 'wc-analytics/get-customer-value',
		'revenue_breakdown'   => 'wc-analytics/get-revenue-breakdown',
		'coupon_performance'  => 'wc-analytics/get-coupon-performance',
		'refund_analysis'     => 'wc-analytics/get-refund-analysis',
		'tax_summary'         => 'wc-analytics/get-tax-summary',
		'query_analytics'     => 'wc-analytics/query-analytics',
	);

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Describe analytics type', 'woocommerce-claude' ),
				'description'         => __( 'TRANSITIONAL — the verb-shaped tools (wc-analytics-totals, wc-analytics-breakdown, wc-analytics-series, wc-analytics-rows) carry their consolidated describe docs inline; for the verb tools you do NOT need to call this helper. This helper remains for backwards compatibility with the legacy wc-analytics-get-data router — it returns the per-type narrative guidance, what each analytics type can and cannot answer, and the parameter reference. Call this only before using wc-analytics-get-data with a legacy type you have not used before in this session.', 'woocommerce-claude' ),
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
	 * Permission gate.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input ) {
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
			'required'   => array( 'type' ),
			'properties' => array(
				'type' => array(
					'type'        => 'string',
					'enum'        => array_keys( self::ABILITY_MAP ),
					'description' => 'The analytics type to describe.',
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
				'type'          => array( 'type' => 'string' ),
				'documentation' => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Return the documentation for the requested analytics type.
	 *
	 * Reads the description from the WP Abilities API registry where the
	 * individual analytics abilities are still registered (but hidden from MCP).
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();
		$type  = isset( $input['type'] ) ? (string) $input['type'] : '';

		if ( ! isset( self::ABILITY_MAP[ $type ] ) ) {
			return new \WP_Error(
				'invalid_analytics_type',
				"Unknown analytics type: {$type}. Valid types: " . implode( ', ', array_keys( self::ABILITY_MAP ) ),
				array( 'status' => 400 )
			);
		}

		$ability_name = self::ABILITY_MAP[ $type ];

		// Prepend a context note so that any gate/token instructions inside the
		// underlying ability description (written for direct REST callers) cannot
		// confuse a model that is reading them in a get-data session.
		$gate_note = "CONTEXT — USING VIA get-data: You are reading this documentation because you called wc-analytics/describe. When you execute this type through wc-analytics/get-data the large date range gate is managed by get-data before any routing — you never call this individual ability directly.\n\nIf wc-analytics/get-data returns extended_range_required: call wc-analytics/confirm-large-range with date_start, date_end, and type, then call wc-analytics/get-data again with the same params. Do NOT pass a confirmation_token to get-data. Any section in the documentation below that mentions a confirmation_token or re-calling this specific tool directly applies only to direct REST calls — ignore it when using wc-analytics/get-data.\n\n---\n\n";

		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( $ability->get_name() === $ability_name ) {
					return array(
						'type'          => $type,
						'documentation' => $gate_note . (string) $ability->get_description(),
					);
				}
			}
		}

		return new \WP_Error(
			'ability_not_found',
			"Documentation for {$type} is not available — the ability may not be registered.",
			array( 'status' => 500 )
		);
	}
}
