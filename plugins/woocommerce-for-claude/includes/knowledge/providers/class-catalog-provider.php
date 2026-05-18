<?php
/**
 * Backwards-compatible catalogue provider.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Knowledge\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility wrapper for the shared provider.
 */
class CatalogProvider extends \WooCommerce\CommerceAbilities\Knowledge\Providers\CatalogProvider implements \WooCommerce\Claude\Knowledge\KnowledgeProvider {}
