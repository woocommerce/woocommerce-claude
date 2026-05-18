<?php
/**
 * Shared implementation for get-store-profile abilities.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities\Store;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Shared callbacks for store profile tool wrappers.
 */
trait GetStoreProfileAbilityTrait {

	/**
	 * Permission gate — same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input = null ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Run the ability.
	 *
	 * @param array $input Validated ability input (unused).
	 * @return array
	 */
	public static function execute( $input = null ) {
		unset( $input );
		return StoreKnowledge::get_profile( static::profile_version() );
	}
}
