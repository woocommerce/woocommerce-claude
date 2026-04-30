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
 * Also registers the plugin-level customer-PII gate. Off by default so
 * the default experience matches the aggregated-only privacy rule.
 * Flip on when chaining Claude with an email/CRM MCP (Klaviyo, Mailchimp)
 * that needs real addresses to action a customer list.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the wc-analytics ability category, abilities, and PII gate option.
 */
class AbilitiesBootstrap {

	/**
	 * Category slug shared by all wc-analytics abilities.
	 */
	const CATEGORY = 'wc-analytics';

	/**
	 * WordPress option controlling whether top_customers payloads include
	 * real names/emails. Default false.
	 */
	const OPTION_ALLOW_CUSTOMER_PII = 'hey_woo_allow_customer_pii';

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

	/**
	 * Register the PII gate option. Called on admin_init.
	 */
	public static function register_option() {
		register_setting(
			'hey-woo',
			self::OPTION_ALLOW_CUSTOMER_PII,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'show_in_rest'      => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'description'       => __( 'Allow customer-level PII (names, emails) in AI analytics responses. Off by default. Turn on when chaining with email/CRM MCPs that need real addresses.', 'hey-woo' ),
			)
		);
	}

	/**
	 * Is the merchant opted in to PII in AI responses?
	 *
	 * Uses wc_string_to_bool() rather than a plain (bool) cast — once the
	 * option has a UI toggle under WooCommerce > Settings > Hey Woo,
	 * WC persists the checkbox state as the string 'yes' or 'no'. A plain
	 * cast treats 'no' as truthy (any non-empty string is), which would
	 * silently flip the gate open. wc_string_to_bool maps 'yes' / '1' /
	 * true / 1 → true; 'no' / '0' / '' / false → false — matching
	 * WooCommerce conventions throughout.
	 */
	public static function is_pii_allowed() {
		return wc_string_to_bool( get_option( self::OPTION_ALLOW_CUSTOMER_PII, false ) );
	}
}
