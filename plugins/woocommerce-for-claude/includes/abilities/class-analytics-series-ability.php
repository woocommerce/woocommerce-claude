<?php
/**
 * Backwards-compatible alias for the shared series ability.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\AnalyticsSeriesAbility', false ) ) {
	class_alias( \WooCommerce\CommerceAbilities\Abilities\AnalyticsSeriesAbility::class, __NAMESPACE__ . '\\AnalyticsSeriesAbility' );
}
