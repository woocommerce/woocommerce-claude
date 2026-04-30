<?php
/**
 * Product Knowledge Provider.
 *
 * Exposes enriched product data — completeness metadata, structured descriptions,
 * relationships — for AI consumption.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Knowledge\Providers;

use HeyWoo\Knowledge\KnowledgeProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge provider for enriched product data — content, pricing, stock, taxonomy, and completeness.
 */
class ProductProvider implements KnowledgeProvider {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'products';
	}

	/**
	 * Human-readable label for the provider.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Product Knowledge';
	}

	/**
	 * Whether this provider's data source is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Get enriched product data.
	 *
	 * @param array $args {
	 *     Optional. Arguments for filtering.
	 *     @type int    $product_id   Single product ID.
	 *     @type int    $page         Page number (default 1).
	 *     @type int    $per_page     Products per page (default 20).
	 *     @type string $category     Filter by category slug.
	 *     @type string $search       Search query.
	 *     @type string $orderby      Order by field (default 'date').
	 *     @type string $order        Sort direction (default 'DESC').
	 * }
	 * @return array
	 */
	public function get_data( $args = array() ) {
		// Single product mode.
		if ( ! empty( $args['product_id'] ) ) {
			$product = wc_get_product( $args['product_id'] );
			if ( ! $product ) {
				return array( 'error' => 'Product not found' );
			}
			return $this->enrich_product( $product );
		}

		// List mode.
		$defaults = array(
			'page'     => 1,
			'per_page' => 20,
			'category' => '',
			'search'   => '',
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		$args = wp_parse_args( $args, $defaults );

		$query_args = array(
			'status'  => 'publish',
			'limit'   => $args['per_page'],
			'page'    => $args['page'],
			'orderby' => $args['orderby'],
			'order'   => $args['order'],
			'return'  => 'objects',
		);

		if ( ! empty( $args['category'] ) ) {
			$query_args['category'] = array( $args['category'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = $args['search'];
		}

		/**
		 * Filter the product query args before execution.
		 *
		 * @since 0.1.0
		 *
		 * @param array $query_args WC product query arguments.
		 * @param array $args       Original request arguments.
		 */
		$query_args = apply_filters( 'hey_woo_product_query_args', $query_args, $args );

		$products = wc_get_products( $query_args );
		$enriched = array();

		foreach ( $products as $product ) {
			$enriched[] = $this->enrich_product( $product );
		}

		// Get total for pagination.
		$count_args           = $query_args;
		$count_args['limit']  = -1;
		$count_args['return'] = 'ids';
		$total                = count( wc_get_products( $count_args ) );

		return array(
			'products'   => $enriched,
			'pagination' => array(
				'page'        => (int) $args['page'],
				'per_page'    => (int) $args['per_page'],
				'total'       => $total,
				'total_pages' => ceil( $total / $args['per_page'] ),
			),
		);
	}

	/**
	 * Enrich a single product with AI-relevant metadata.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @return array
	 */
	public function enrich_product( $product ) {
		$description       = $product->get_description();
		$short_description = $product->get_short_description();
		$image_ids         = $product->get_gallery_image_ids();
		$main_image_id     = $product->get_image_id();

		$data = array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'type'              => $product->get_type(),
			'status'            => $product->get_status(),
			'url'               => $product->get_permalink(),

			// Content.
			'description'       => $description,
			'short_description' => $short_description,

			// Pricing.
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'on_sale'           => $product->is_on_sale(),

			// Stock.
			'stock_status'      => $product->get_stock_status(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'manage_stock'      => $product->get_manage_stock(),
			'backorders'        => $product->get_backorders(),

			// Physical.
			'weight'            => $product->get_weight(),
			'dimensions'        => array(
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			),
			'virtual'           => $product->is_virtual(),
			'downloadable'      => $product->is_downloadable(),

			// Taxonomy.
			'categories'        => $this->get_product_terms( $product->get_id(), 'product_cat' ),
			'tags'              => $this->get_product_terms( $product->get_id(), 'product_tag' ),
			'attributes'        => $this->get_product_attributes( $product ),

			// Images.
			'main_image'        => $main_image_id ? wp_get_attachment_url( $main_image_id ) : null,
			'gallery_images'    => array_map( 'wp_get_attachment_url', $image_ids ),

			// Relationships.
			'upsell_ids'        => $product->get_upsell_ids(),
			'cross_sell_ids'    => $product->get_cross_sell_ids(),

			// AI metadata.
			'completeness'      => $this->compute_completeness( $product ),
		);

		/**
		 * Filter the enriched product data.
		 *
		 * @since 0.1.0
		 *
		 * @param array       $data    Enriched product data.
		 * @param \WC_Product $product WooCommerce product object.
		 */
		return apply_filters( 'hey_woo_enriched_product', $data, $product );
	}

	/**
	 * Get term names for a product taxonomy.
	 *
	 * @param int    $product_id Product post ID.
	 * @param string $taxonomy   Taxonomy name (e.g. 'product_cat').
	 * @return array
	 */
	private function get_product_terms( $product_id, $taxonomy ) {
		$terms = get_the_terms( $product_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			function ( $term ) {
				return array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			},
			$terms
		);
	}

	/**
	 * Get product attributes in structured format.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @return array
	 */
	private function get_product_attributes( $product ) {
		$attributes = $product->get_attributes();
		$result     = array();

		foreach ( $attributes as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute ) {
				$result[] = array(
					'name'    => $attribute->get_name(),
					'options' => $attribute->get_options(),
					'visible' => $attribute->get_visible(),
				);
			}
		}

		return $result;
	}

	/**
	 * Compute a quick completeness breakdown for a product.
	 *
	 * Full scoring is handled by the ScoringEngine; this is a lightweight inline version.
	 *
	 * @param \WC_Product $product WooCommerce product object.
	 * @return array
	 */
	private function compute_completeness( $product ) {
		$checks = array(
			'has_description'       => ! empty( $product->get_description() ),
			'has_short_description' => ! empty( $product->get_short_description() ),
			'has_price'             => '' !== $product->get_price(),
			'has_categories'        => count( $this->get_product_terms( $product->get_id(), 'product_cat' ) ) > 0,
			'has_main_image'        => ! empty( $product->get_image_id() ),
			'has_gallery_images'    => count( $product->get_gallery_image_ids() ) > 0,
			'has_stock_status'      => ! empty( $product->get_stock_status() ),
			'has_weight'            => ! empty( $product->get_weight() ) || $product->is_virtual(),
			'has_attributes'        => count( $product->get_attributes() ) > 0,
			'has_tags'              => count( $this->get_product_terms( $product->get_id(), 'product_tag' ) ) > 0,
		);

		$passed = count( array_filter( $checks ) );
		$total  = count( $checks );

		return array(
			'score'   => round( ( $passed / $total ) * 100 ),
			'passed'  => $passed,
			'total'   => $total,
			'details' => $checks,
		);
	}
}
