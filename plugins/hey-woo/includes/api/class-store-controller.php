<?php
/**
 * REST API controller for store knowledge.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\API;

defined( 'ABSPATH' ) || exit;

/**
 * Hey Woo REST wrapper for shared store knowledge routes.
 */
class StoreController extends \WooCommerce\CommerceAbilities\API\AbstractStoreController {
	const NAMESPACE = 'hey-woo/v1';
	const VERSION   = HEY_WOO_VERSION;
}
