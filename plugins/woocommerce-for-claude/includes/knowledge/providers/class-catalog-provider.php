<?php
/**
 * Catalog Knowledge Provider.
 *
 * Exposes the catalog structure — categories, attributes, product type
 * distribution — as structured knowledge for AI consumption.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Knowledge\Providers;

use WooCommerce\Claude\Knowledge\KnowledgeProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge provider for catalog structure — counts, category tree, attributes, and product type mix.
 */
class CatalogProvider implements KnowledgeProvider {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'catalog';
	}

	/**
	 * Human-readable label for the provider.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Catalog Structure';
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
	 * Assemble the full catalog payload.
	 *
	 * @param array $args Optional arguments (unused).
	 * @return array
	 */
	public function get_data( $args = array() ) {
		return array(
			'summary'       => $this->get_summary(),
			'categories'    => $this->get_category_tree(),
			'attributes'    => $this->get_attributes(),
			'product_types' => $this->get_product_type_distribution(),
		);
	}

	/**
	 * Catalog totals: products, drafts, categories, tags, attributes.
	 *
	 * @return array
	 */
	private function get_summary() {
		$product_counts = wp_count_posts( 'product' );

		return array(
			'total_products'   => absint( $product_counts->publish ?? 0 ),
			'draft_products'   => absint( $product_counts->draft ?? 0 ),
			'total_categories' => wp_count_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
				)
			),
			'total_tags'       => wp_count_terms(
				array(
					'taxonomy'   => 'product_tag',
					'hide_empty' => false,
				)
			),
			'total_attributes' => count( wc_get_attribute_taxonomies() ),
		);
	}

	/**
	 * Product category taxonomy as a nested tree.
	 *
	 * @return array
	 */
	private function get_category_tree() {
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $categories ) ) {
			return array();
		}

		return $this->build_tree( $categories );
	}

	/**
	 * Build a nested tree from flat terms.
	 *
	 * @param array $terms     Flat list of term objects.
	 * @param int   $parent_id Parent term ID to start recursion from.
	 * @return array
	 */
	private function build_tree( $terms, $parent_id = 0 ) {
		$tree = array();

		foreach ( $terms as $term ) {
			if ( (int) $term->parent !== $parent_id ) {
				continue;
			}

			$node = array(
				'id'            => $term->term_id,
				'name'          => $term->name,
				'slug'          => $term->slug,
				'description'   => $term->description,
				'product_count' => $term->count,
				'children'      => $this->build_tree( $terms, $term->term_id ),
			);

			$tree[] = $node;
		}

		return $tree;
	}

	/**
	 * Global attribute taxonomies with their terms (capped per attribute for response size).
	 *
	 * @return array
	 */
	private function get_attributes() {
		$attribute_taxonomies = wc_get_attribute_taxonomies();
		$attributes           = array();

		foreach ( $attribute_taxonomies as $attribute ) {
			$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );
			$terms    = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);

			$term_names = array();
			if ( ! is_wp_error( $terms ) ) {
				$term_names = wp_list_pluck( $terms, 'name' );
			}

			$attributes[] = array(
				'id'         => (int) $attribute->attribute_id,
				'name'       => $attribute->attribute_label,
				'slug'       => $attribute->attribute_name,
				'type'       => $attribute->attribute_type,
				'order_by'   => $attribute->attribute_orderby,
				'has_terms'  => count( $term_names ) > 0,
				'term_count' => count( $term_names ),
				'terms'      => array_slice( $term_names, 0, 50 ), // Cap at 50 for API response size.
			);
		}

		return $attributes;
	}

	/**
	 * Count of published products grouped by product type slug.
	 *
	 * @return array
	 */
	private function get_product_type_distribution() {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT t.slug AS product_type, COUNT(*) AS count
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND tt.taxonomy = 'product_type'
			GROUP BY t.slug
			ORDER BY count DESC"
		);

		$distribution = array();
		foreach ( $results as $row ) {
			$distribution[ $row->product_type ] = absint( $row->count );
		}

		return $distribution;
	}
}
