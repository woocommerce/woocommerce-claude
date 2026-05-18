<?php
/**
 * REST API controller for enriched product knowledge.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\API;

defined( 'ABSPATH' ) || exit;

/**
 * Hey Woo REST wrapper for shared product knowledge routes.
 */
class ProductsController extends \WooCommerce\CommerceAbilities\API\AbstractProductsController {
	const NAMESPACE = 'hey-woo/v1';
}
