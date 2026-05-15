<?php
/**
 * Backwards-compatible alias for the shared large-range approval ability.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\ConfirmLargeRangeAbility', false ) ) {
	class_alias( \WooCommerce\CommerceAbilities\Abilities\ConfirmLargeRangeAbility::class, __NAMESPACE__ . '\\ConfirmLargeRangeAbility' );
}
