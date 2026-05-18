<?php
/**
 * Backwards-compatible product provider.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Knowledge\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility wrapper for the shared provider.
 */
class ProductProvider extends \WooCommerce\CommerceAbilities\Knowledge\Providers\ProductProvider implements \WooCommerce\Claude\Knowledge\KnowledgeProvider {}
