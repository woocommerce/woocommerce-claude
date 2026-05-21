<?php
/**
 * Shared implementation for get-recommendations abilities.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities\Store;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Shared callbacks for readiness recommendation tool wrappers.
 */
trait GetRecommendationsAbilityTrait {

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
		return StoreKnowledge::get_recommendations( StoreKnowledge::consumer_from_ability( static::ABILITY_NAME ) );
	}
}
