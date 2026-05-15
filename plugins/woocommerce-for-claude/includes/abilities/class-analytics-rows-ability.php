<?php
/**
 * Backwards-compatible alias for the shared rows ability.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\AnalyticsRowsAbility', false ) ) {
	class_alias( \WooCommerce\CommerceAbilities\Abilities\AnalyticsRowsAbility::class, __NAMESPACE__ . '\\AnalyticsRowsAbility' );
}
