<?php
/**
 * Shared commerce abilities loader.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent package loader.
 *
 * This spike intentionally does not register abilities yet; analytics logic
 * remains in the product plugin until the extraction phase.
 */
final class Loader {

	/**
	 * Whether the loader has already been initialised in this request.
	 *
	 * @var bool
	 */
	private static $loaded = false;

	/**
	 * Initialise the shared package once.
	 */
	public static function init() {
		if ( self::$loaded ) {
			return;
		}

		self::$loaded = true;
	}
}
