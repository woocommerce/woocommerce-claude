<?php
/**
 * Hey Woo product provider wrapper.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Knowledge\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility wrapper for the shared provider.
 */
class ProductProvider extends \WooCommerce\CommerceAbilities\Knowledge\Providers\ProductProvider implements \WooCommerce\HeyWoo\Knowledge\KnowledgeProvider {}
