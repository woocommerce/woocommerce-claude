<?php
/**
 * Abilities API bootstrap.
 *
 * Registers the `wc-analytics` ability category and wires up every
 * analytics skill under the WordPress 6.9 Abilities API. Skills 1–5
 * originally used custom `hey-woo/v1/analytics/` REST routes;
 * E5 (2026-04-17) folded them into this file alongside the original
 * Abilities-first skill (get-customer-value) so everything ships on
 * `wp-abilities/v1` and only one namespace is callable.
 *
 * Customer-level PII (real names / emails) is never surfaced to AI
 * analytics responses — every skill returns pseudonymised `Customer #N`
 * identifiers only. There is no opt-in toggle.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the wc-analytics ability category and abilities.
 */
class AbilitiesBootstrap {

	/**
	 * Category slug shared by all wc-analytics abilities.
	 */
	const CATEGORY = 'wc-analytics';

	/**
	 * Register the ability category. Must be called on the
	 * wp_abilities_api_categories_init action (separate from the ability
	 * init hook).
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WooCommerce Analytics', 'hey-woo' ),
				'description' => __( 'Store analytics abilities — revenue, orders, products, customers, attribution, cohorts.', 'hey-woo' ),
			)
		);
	}

	/**
	 * Register all analytics abilities. Called on wp_abilities_api_init.
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		DescribeAbility::register();
		GetDataAbility::register();
		ConfirmLargeRangeAbility::register();
		GetRevenueSummaryAbility::register();
		GetOrdersSummaryAbility::register();
		GetProductPerformanceAbility::register();
		GetCustomerOverviewAbility::register();
		GetAttributionAbility::register();
		GetCustomerValueAbility::register();
		GetRevenueBreakdownAbility::register();
		GetCouponPerformanceAbility::register();
		GetRefundAnalysisAbility::register();
		GetTaxSummaryAbility::register();
		QueryAnalyticsAbility::register();

		// External integrations (hey-woo-integrations/*) — dev/local only.
		// Prototype scaffold; only register in local/development so merchants
		// on production never see a not-implemented ability.
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			GoogleAnalyticsChannelsAbility::register();
		}

		// Non-analytics tools (hey-woo/*) — store knowledge, readiness,
		// and suggestion helpers. Picked up by the woocommerce_mcp_include_ability
		// filter alongside wc-analytics/*.
		GetStoreProfileAbility::register();
		SearchProductsAbility::register();
		GetProductDetailsAbility::register();
		GetReadinessScoreAbility::register();
		GetRecommendationsAbility::register();
		SuggestImprovementsAbility::register();

		// Resources (wc-knowledge/*) and prompts (wc-prompts/*) — wired into
		// the Woo core MCP server by Plugin::inject_mcp_components, not by
		// the tools include filter.
		StoreProfileAbility::register();
		CatalogSchemaAbility::register();
		StorePoliciesAbility::register();
		CatalogAuditAbility::register();
		ProductImproveAbility::register();
	}
}
