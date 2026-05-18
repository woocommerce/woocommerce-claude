<?php
/**
 * Hey Woo catalogue provider wrapper.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Knowledge\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility wrapper for the shared provider.
 */
class CatalogProvider extends \WooCommerce\CommerceAbilities\Knowledge\Providers\CatalogProvider implements \WooCommerce\HeyWoo\Knowledge\KnowledgeProvider {}
