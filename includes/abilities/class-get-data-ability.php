<?php
/**
 * `wc-analytics/get-data` ability.
 *
 * Single entry point for all WooCommerce analytics. Checks the large-range
 * gate before routing to the appropriate analytics method.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

use HeyWoo\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-data ability.
 */
class GetDataAbility {

	const ABILITY_NAME = 'wc-analytics/get-data';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get analytics data', 'hey-woo' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
Single entry point for all WooCommerce analytics. Routes to one of eleven analytics types.

BEFORE CALLING — if this is your first time using a specific type in this session, call wc-analytics/describe with that type first to get the full documentation, parameter reference, and narrative guidance for it. Analytics types: revenue_summary, orders_summary, product_performance, customer_overview, attribution, customer_value, revenue_breakdown, coupon_performance, refund_analysis, tax_summary, query_analytics.

LARGE DATE RANGE GATE: When the date range in params spans more than 365 days this tool returns an extended_range_required error (HTTP 400) before any SQL runs — queries that large may temporarily impact site performance. STOP. Present the cost_estimate from the error to the merchant and ask which option they prefer. When the merchant confirms, call confirm_large_range with the date_start, date_end, and type. Then call this tool again with the same params — the gate will pass. There is no other bypass.

ANTI-SPLITTING RULE — ABSOLUTE: Do NOT split a large date range into smaller chunks (yearly, quarterly, or monthly segments) to avoid the gate. If the merchant asks for 3 years of data, call this tool once for the full range, let the gate fire, present the cost estimate, wait for the merchant, call confirm_large_range, then call this tool again.

Bad (split to avoid gate — do not do this): Merchant asks for 3 years. I notice each year is under 365 days. I make three parallel calls for 2022, 2023, 2024. No gate fires; merchant never approves anything.
Good (correct): Merchant asks for 3 years. I call this tool for the full range. Gate fires. I present options and wait for the merchant's reply.

Bad (autonomous confirm — do not do this): Gate fires. I call confirm_large_range without showing the cost estimate to the merchant or waiting for their reply.
Good (correct): Gate fires. I present the cost estimate and performance note. I wait for the merchant to say yes. Then I call confirm_large_range. Then I call this tool again.

PERMISSIONS: Requires manage_woocommerce capability.
DESCRIPTION,
					'hey-woo'
				),
				// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText
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
				'type'   => array(
					'type'        => 'string',
					'enum'        => array(
						'revenue_summary',
						'orders_summary',
						'product_performance',
						'customer_overview',
						'attribution',
						'customer_value',
						'revenue_breakdown',
						'coupon_performance',
						'refund_analysis',
						'tax_summary',
						'query_analytics',
					),
					'description' => 'Which analytics report to run. Call wc-analytics/describe with this type first to get the full parameter reference.',
				),
				'params' => array(
					'type'        => 'object',
					'default'     => array(),
					'description' => 'Type-specific parameters. Call wc-analytics/describe for the full schema for the selected type.',
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
			'type' => 'object',
		);
	}

	/**
	 * Check the large-range gate then route to the right analytics method.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$type   = isset( $input['type'] ) ? (string) $input['type'] : '';
		$params = is_array( $input['params'] ?? null ) ? $input['params'] : array();

		// Resolve dates for the gate check — same logic the individual abilities use.
		$period     = $params['period'] ?? 'last_30_days';
		$date_start = $params['date_start'] ?? null;
		$date_end   = $params['date_end'] ?? null;

		$dates = AnalyticsController::resolve_dates( $period, $date_start, $date_end );

		// Session-keyed large-range gate — fires before any SQL runs.
		// Type is included in the transient key so an approval for one report type
		// cannot be consumed by a different type on the same date range.
		$gate_result = LargeRangeGate::check_run( $dates['start'], $dates['end'], $type );
		if ( is_wp_error( $gate_result ) ) {
			return $gate_result;
		}
		$series_cap = $gate_result;

		return self::route( $type, $params, $series_cap );
	}

	/**
	 * Route to the right analytics method.
	 *
	 * For types with their own internal large-range gate (product_performance and
	 * customer_overview) the resolved $series_cap is passed as an override so
	 * the gate is not checked twice.
	 *
	 * @param string $type       Analytics type slug.
	 * @param array  $params     Type-specific parameters.
	 * @param int    $series_cap Series cap from the session gate check.
	 * @return array|\WP_Error
	 */
	private static function route( $type, $params, $series_cap ) {
		switch ( $type ) {
			case 'revenue_summary':
				return GetRevenueSummaryAbility::execute( $params );

			case 'orders_summary':
				return GetOrdersSummaryAbility::execute( $params );

			case 'product_performance':
				return AnalyticsController::fetch_product_performance(
					$params['period'] ?? 'last_30_days',
					$params['date_start'] ?? null,
					$params['date_end'] ?? null,
					array_key_exists( 'compare', $params ) ? rest_sanitize_boolean( $params['compare'] ) : true,
					isset( $params['limit'] ) ? (int) $params['limit'] : 10,
					$params['orderby'] ?? 'net_revenue',
					$params['group_by'] ?? 'product',
					$params['interval'] ?? '',
					null,        // confirmation_token — not used via get-data.
					$series_cap  // gate already cleared; skip internal check.
				);

			case 'customer_overview':
				return AnalyticsController::fetch_customer_overview(
					$params['period'] ?? 'last_30_days',
					$params['date_start'] ?? null,
					$params['date_end'] ?? null,
					array_key_exists( 'compare', $params ) ? rest_sanitize_boolean( $params['compare'] ) : true,
					$params['interval'] ?? '',
					null,        // confirmation_token — not used via get-data.
					$series_cap  // gate already cleared; skip internal check.
				);

			case 'attribution':
				return GetAttributionAbility::execute( $params );

			case 'customer_value':
				return GetCustomerValueAbility::execute( $params );

			case 'revenue_breakdown':
				return GetRevenueBreakdownAbility::execute( $params );

			case 'coupon_performance':
				return GetCouponPerformanceAbility::execute( $params );

			case 'refund_analysis':
				return GetRefundAnalysisAbility::execute( $params );

			case 'tax_summary':
				return GetTaxSummaryAbility::execute( $params );

			case 'query_analytics':
				return QueryAnalyticsAbility::execute( $params );

			default:
				return new \WP_Error(
					'invalid_analytics_type',
					"Unknown analytics type: {$type}. Call wc-analytics/describe to see available types.",
					array( 'status' => 400 )
				);
		}
	}
}
