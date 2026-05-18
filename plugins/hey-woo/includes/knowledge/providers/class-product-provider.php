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
class ProductProvider extends \WooCommerce\CommerceAbilities\Knowledge\Providers\ProductProvider implements \WooCommerce\HeyWoo\Knowledge\KnowledgeProvider {

	/**
	 * Build the provider with Hey Woo's compatibility hook scope.
	 */
	public function __construct() {
		parent::__construct( 'hey-woo' );
	}
}
