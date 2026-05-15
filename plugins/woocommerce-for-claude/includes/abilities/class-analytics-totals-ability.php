<?php
/**
 * Backwards-compatible alias for the shared totals ability.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\AnalyticsTotalsAbility', false ) ) {
	class_alias( \WooCommerce\CommerceAbilities\Abilities\AnalyticsTotalsAbility::class, __NAMESPACE__ . '\\AnalyticsTotalsAbility' );
}
