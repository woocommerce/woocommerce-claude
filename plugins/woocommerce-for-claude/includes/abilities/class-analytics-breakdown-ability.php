<?php
/**
 * Backwards-compatible alias for the shared breakdown ability.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\AnalyticsBreakdownAbility', false ) ) {
	class_alias( \WooCommerce\CommerceAbilities\Abilities\AnalyticsBreakdownAbility::class, __NAMESPACE__ . '\\AnalyticsBreakdownAbility' );
}
