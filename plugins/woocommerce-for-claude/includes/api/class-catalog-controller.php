<?php
/**
 * REST API controller for catalogue knowledge.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\API;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce for Claude REST wrapper for shared catalogue routes.
 */
class CatalogController extends \WooCommerce\CommerceAbilities\API\AbstractCatalogController {
	const NAMESPACE = 'woocommerce-claude/v1';
}
