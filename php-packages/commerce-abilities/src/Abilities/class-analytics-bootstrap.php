<?php
/**
 * Shared analytics abilities bootstrap.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the shared wc-analytics category and abilities.
 */
class AnalyticsBootstrap {

	/**
	 * Category slug shared by commerce analytics abilities.
	 */
	const CATEGORY = 'wc-analytics';

	/**
	 * Register the ability category.
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'WooCommerce Analytics', 'woocommerce-claude' ),
				'description' => __( 'Store analytics abilities — revenue, orders, products, customers, attribution, cohorts.', 'woocommerce-claude' ),
			)
		);
	}

	/**
	 * Register all shared analytics abilities.
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_if_missing( ConfirmLargeRangeAbility::class );
		self::register_if_missing( AnalyticsTotalsAbility::class );
		self::register_if_missing( AnalyticsBreakdownAbility::class );
		self::register_if_missing( AnalyticsSeriesAbility::class );
		self::register_if_missing( AnalyticsRowsAbility::class );
	}

	/**
	 * Register an ability class unless another plugin already owns the ID.
	 *
	 * @param string $class_name Fully-qualified ability class name.
	 */
	private static function register_if_missing( $class_name ) {
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $class_name::ABILITY_NAME ) ) {
			return;
		}

		$class_name::register();
	}
}
