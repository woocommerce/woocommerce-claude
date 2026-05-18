<?php
/**
 * Backwards-compatible policy provider.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Knowledge\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility wrapper for the shared provider.
 */
class PolicyProvider extends \WooCommerce\CommerceAbilities\Knowledge\Providers\PolicyProvider implements \WooCommerce\Claude\Knowledge\KnowledgeProvider {}
