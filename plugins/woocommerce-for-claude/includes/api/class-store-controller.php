<?php
/**
 * REST API controller for store knowledge.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\API;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce for Claude REST wrapper for shared store knowledge routes.
 */
class StoreController extends \WooCommerce\CommerceAbilities\API\AbstractStoreController {
	const NAMESPACE = 'woocommerce-claude/v1';
	const VERSION   = WOOCOMMERCE_CLAUDE_VERSION;
	const CONSUMER  = 'woocommerce-claude';
}
