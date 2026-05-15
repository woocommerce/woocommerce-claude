<?php
/**
 * Backwards-compatible alias for the shared analytics service.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\API;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( __NAMESPACE__ . '\\AnalyticsController', false ) ) {
	class_alias( \WooCommerce\CommerceAbilities\Analytics\AnalyticsService::class, __NAMESPACE__ . '\\AnalyticsController' );
}
