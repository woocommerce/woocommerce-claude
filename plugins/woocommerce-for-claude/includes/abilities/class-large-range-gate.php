<?php
/**
 * Backwards-compatible alias for the shared large-range gate.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\LargeRangeGate', false ) ) {
	class_alias( \WooCommerce\CommerceAbilities\Abilities\LargeRangeGate::class, __NAMESPACE__ . '\\LargeRangeGate' );
}
