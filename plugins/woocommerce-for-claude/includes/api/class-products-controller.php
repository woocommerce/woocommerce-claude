<?php
/**
 * REST API controller for enriched product knowledge.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\API;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce for Claude REST wrapper for shared product knowledge routes.
 */
class ProductsController extends \WooCommerce\CommerceAbilities\API\AbstractProductsController {
	const NAMESPACE = 'woocommerce-claude/v1';
	const CONSUMER  = 'woocommerce-claude';
}
