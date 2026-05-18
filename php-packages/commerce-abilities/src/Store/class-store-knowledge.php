<?php
/**
 * Shared store knowledge and readiness service.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Store;

use WooCommerce\CommerceAbilities\Knowledge\KnowledgeRegistry;
use WooCommerce\CommerceAbilities\Knowledge\Providers\CatalogProvider;
use WooCommerce\CommerceAbilities\Knowledge\Providers\PolicyProvider;
use WooCommerce\CommerceAbilities\Knowledge\Providers\ProductProvider;
use WooCommerce\CommerceAbilities\Knowledge\Providers\StoreProfileProvider;
use WooCommerce\CommerceAbilities\Scoring\ScoringEngine;

defined( 'ABSPATH' ) || exit;

/**
 * Shared implementation behind store, product, and readiness surfaces.
 */
class StoreKnowledge {

	/**
	 * Register the default store knowledge providers.
	 *
	 * @return KnowledgeRegistry
	 */
	public static function register_default_providers() {
		$registry = KnowledgeRegistry::instance();

		$registry->register( new StoreProfileProvider() );
		$registry->register( new CatalogProvider() );
		$registry->register( new ProductProvider() );
		$registry->register( new PolicyProvider() );

		return $registry;
	}

	/**
	 * Return the store profile payload.
	 *
	 * @param string $version Product/plugin version to include in the response.
	 * @return array
	 */
	public static function get_profile( $version ) {
		$registry = KnowledgeRegistry::instance();
		$data     = $registry->get_knowledge( 'store-profile' );

		return array(
			'version' => $version,
			'data'    => $data,
		);
	}

	/**
	 * Return the store policy payload.
	 *
	 * @return array|null
	 */
	public static function get_policies() {
		return KnowledgeRegistry::instance()->get_knowledge( 'policies' );
	}

	/**
	 * Return registered knowledge provider status.
	 *
	 * @return array
	 */
	public static function get_providers_status() {
		return KnowledgeRegistry::instance()->get_providers_status();
	}

	/**
	 * Return catalogue schema knowledge.
	 *
	 * @return array|null
	 */
	public static function get_catalog_schema() {
		return KnowledgeRegistry::instance()->get_knowledge( 'catalog' );
	}

	/**
	 * Return a paginated, optionally filtered product list.
	 *
	 * @param array $args Product query arguments.
	 * @return array|null
	 */
	public static function get_products( $args ) {
		return KnowledgeRegistry::instance()->get_knowledge( 'products', $args );
	}

	/**
	 * Return a single product's enriched knowledge payload.
	 *
	 * @param int $product_id Product ID.
	 * @return array|\WP_Error
	 */
	public static function get_product( $product_id ) {
		$data = KnowledgeRegistry::instance()->get_knowledge(
			'products',
			array( 'product_id' => absint( $product_id ) )
		);

		if ( ! is_array( $data ) || isset( $data['error'] ) ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : 'Product not found';
			return new \WP_Error( 'product_not_found', $message, array( 'status' => 404 ) );
		}

		return $data;
	}

	/**
	 * Return a readiness score for a single product.
	 *
	 * @param int $product_id Product ID.
	 * @return array|\WP_Error
	 */
	public static function get_product_score( $product_id ) {
		$product = wc_get_product( absint( $product_id ) );

		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', 'Product not found', array( 'status' => 404 ) );
		}

		$engine = new ScoringEngine();
		return $engine->get_product_score( $product );
	}

	/**
	 * Return the overall store readiness score.
	 *
	 * @return array
	 */
	public static function get_readiness_score() {
		$engine = new ScoringEngine();
		return $engine->get_store_score();
	}

	/**
	 * Return prioritised readiness recommendations.
	 *
	 * @return array
	 */
	public static function get_recommendations() {
		$engine = new ScoringEngine();
		return array(
			'recommendations' => $engine->get_recommendations(),
		);
	}

	/**
	 * Return product-specific or store-wide improvement suggestions.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public static function suggest_improvements( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$focus      = isset( $input['focus'] ) && is_string( $input['focus'] ) ? $input['focus'] : 'all';

		if ( $product_id > 0 ) {
			$product = self::get_product( $product_id );
			if ( is_wp_error( $product ) ) {
				return $product;
			}

			$instruction = 'Analyse this product data and suggest specific improvements'
				. ( 'all' !== $focus ? ' focusing on ' . $focus : '' )
				. '. Look at completeness scores, missing fields, and content quality.';

			return array(
				'product'     => $product,
				'instruction' => $instruction,
			);
		}

		return self::get_recommendations();
	}
}
