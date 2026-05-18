<?php
/**
 * Hey Woo store profile provider wrapper.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Knowledge\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility wrapper for the shared provider.
 */
class StoreProfileProvider extends \WooCommerce\CommerceAbilities\Knowledge\Providers\StoreProfileProvider implements \WooCommerce\HeyWoo\Knowledge\KnowledgeProvider {}
