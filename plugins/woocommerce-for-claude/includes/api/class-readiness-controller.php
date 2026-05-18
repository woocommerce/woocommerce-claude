<?php
/**
 * REST API controller for AI readiness scoring.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\API;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce for Claude REST wrapper for shared readiness routes.
 */
class ReadinessController extends \WooCommerce\CommerceAbilities\API\AbstractReadinessController {
	const NAMESPACE = 'woocommerce-claude/v1';
}
