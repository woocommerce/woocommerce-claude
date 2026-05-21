<?php
/**
 * Backwards-compatible store profile provider.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Knowledge\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility wrapper for the shared provider.
 */
class StoreProfileProvider extends \WooCommerce\CommerceAbilities\Knowledge\Providers\StoreProfileProvider implements \WooCommerce\Claude\Knowledge\KnowledgeProvider {}
