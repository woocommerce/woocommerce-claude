<?php
/**
 * Hey Woo policy provider wrapper.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Knowledge\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility wrapper for the shared provider.
 */
class PolicyProvider extends \WooCommerce\CommerceAbilities\Knowledge\Providers\PolicyProvider implements \WooCommerce\HeyWoo\Knowledge\KnowledgeProvider {}
