<?php
/**
 * Shared implementation for suggest-improvements abilities.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities\Store;

use WooCommerce\CommerceAbilities\Store\StoreKnowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Shared callbacks and schemas for improvement suggestion tool wrappers.
 */
trait SuggestImprovementsAbilityTrait {

	/**
	 * Permission gate — same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * JSON Schema for the ability input.
	 *
	 * @return array
	 */
	private static function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => 'Optional: specific product ID to get improvements for.',
				),
				'focus'      => array(
					'type'        => 'string',
					'enum'        => array( 'description', 'images', 'seo', 'attributes', 'all' ),
					'default'     => 'all',
					'description' => 'Area to focus improvements on.',
				),
			),
		);
	}

	/**
	 * Run the ability.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( $input ) {
		return StoreKnowledge::suggest_improvements( $input );
	}
}
