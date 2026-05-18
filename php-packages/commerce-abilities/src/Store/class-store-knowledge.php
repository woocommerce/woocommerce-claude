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

	const DEFAULT_CONSUMER = 'woocommerce-claude';

	/**
	 * Register the default store knowledge providers.
	 *
	 * @param string $consumer Consumer ID.
	 * @return KnowledgeRegistry
	 */
	public static function register_default_providers( $consumer = self::DEFAULT_CONSUMER ) {
		$registry = KnowledgeRegistry::instance( $consumer );

		self::register_default_provider( $registry, new StoreProfileProvider() );
		self::register_default_provider( $registry, new CatalogProvider() );
		self::register_default_provider( $registry, new ProductProvider( $consumer ) );
		self::register_default_provider( $registry, new PolicyProvider() );

		return $registry;
	}

	/**
	 * Return the store profile payload.
	 *
	 * @param string $version Product/plugin version to include in the response.
	 * @param string $consumer Consumer ID.
	 * @return array
	 */
	public static function get_profile( $version, $consumer = self::DEFAULT_CONSUMER ) {
		$registry = KnowledgeRegistry::instance( $consumer );
		$data     = $registry->get_knowledge( 'store-profile' );

		return array(
			'version' => $version,
			'data'    => $data,
		);
	}

	/**
	 * Return the store policy payload.
	 *
	 * @param string $consumer Consumer ID.
	 * @return array|null
	 */
	public static function get_policies( $consumer = self::DEFAULT_CONSUMER ) {
		return KnowledgeRegistry::instance( $consumer )->get_knowledge( 'policies' );
	}

	/**
	 * Return registered knowledge provider status.
	 *
	 * @param string $consumer Consumer ID.
	 * @return array
	 */
	public static function get_providers_status( $consumer = self::DEFAULT_CONSUMER ) {
		return KnowledgeRegistry::instance( $consumer )->get_providers_status();
	}

	/**
	 * Return catalogue schema knowledge.
	 *
	 * @param string $consumer Consumer ID.
	 * @return array|null
	 */
	public static function get_catalog_schema( $consumer = self::DEFAULT_CONSUMER ) {
		return KnowledgeRegistry::instance( $consumer )->get_knowledge( 'catalog' );
	}

	/**
	 * Return a paginated, optionally filtered product list.
	 *
	 * @param array  $args Product query arguments.
	 * @param string $consumer Consumer ID.
	 * @return array|null
	 */
	public static function get_products( $args, $consumer = self::DEFAULT_CONSUMER ) {
		return KnowledgeRegistry::instance( $consumer )->get_knowledge( 'products', $args );
	}

	/**
	 * Return a single product's enriched knowledge payload.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $consumer Consumer ID.
	 * @return array|\WP_Error
	 */
	public static function get_product( $product_id, $consumer = self::DEFAULT_CONSUMER ) {
		$data = KnowledgeRegistry::instance( $consumer )->get_knowledge(
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
	 * @param int    $product_id Product ID.
	 * @param string $consumer Consumer ID.
	 * @return array|\WP_Error
	 */
	public static function get_product_score( $product_id, $consumer = self::DEFAULT_CONSUMER ) {
		$product = wc_get_product( absint( $product_id ) );

		if ( ! $product ) {
			return new \WP_Error( 'product_not_found', 'Product not found', array( 'status' => 404 ) );
		}

		$engine = new ScoringEngine( $consumer );
		return $engine->get_product_score( $product );
	}

	/**
	 * Return the overall store readiness score.
	 *
	 * @param string $consumer Consumer ID.
	 * @return array
	 */
	public static function get_readiness_score( $consumer = self::DEFAULT_CONSUMER ) {
		$engine = new ScoringEngine( $consumer );
		return $engine->get_store_score();
	}

	/**
	 * Return prioritised readiness recommendations.
	 *
	 * @param string $consumer Consumer ID.
	 * @return array
	 */
	public static function get_recommendations( $consumer = self::DEFAULT_CONSUMER ) {
		$engine = new ScoringEngine( $consumer );
		return array(
			'recommendations' => $engine->get_recommendations(),
		);
	}

	/**
	 * Return product-specific or store-wide improvement suggestions.
	 *
	 * @param array  $input Ability input.
	 * @param string $consumer Consumer ID.
	 * @return array|\WP_Error
	 */
	public static function suggest_improvements( $input, $consumer = self::DEFAULT_CONSUMER ) {
		$input      = is_array( $input ) ? $input : array();
		$product_id = isset( $input['product_id'] ) ? absint( $input['product_id'] ) : 0;
		$focus      = isset( $input['focus'] ) && is_string( $input['focus'] ) ? $input['focus'] : 'all';

		if ( $product_id > 0 ) {
			$product = self::get_product( $product_id, $consumer );
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

		return self::get_recommendations( $consumer );
	}

	/**
	 * Infer a consumer ID from an ability ID.
	 *
	 * @param string $ability_name Ability ID.
	 * @return string
	 */
	public static function consumer_from_ability( $ability_name ) {
		if ( is_string( $ability_name ) && 0 === strpos( $ability_name, 'hey-woo/' ) ) {
			return 'hey-woo';
		}

		return self::DEFAULT_CONSUMER;
	}

	/**
	 * Register a default provider only when the consumer registry does not
	 * already have a provider for the same ID.
	 *
	 * @param KnowledgeRegistry $registry Registry instance.
	 * @param object            $provider Provider instance.
	 */
	private static function register_default_provider( KnowledgeRegistry $registry, $provider ) {
		if ( ! $registry->get_provider( $provider->get_id() ) ) {
			$registry->register( $provider );
		}
	}
}
