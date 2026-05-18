<?php
/**
 * Shared implementation for get-readiness-score abilities.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities\Store;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Shared callbacks for readiness score tool wrappers.
 */
trait GetReadinessScoreAbilityTrait {

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
		return StoreKnowledge::get_readiness_score();
	}
}
