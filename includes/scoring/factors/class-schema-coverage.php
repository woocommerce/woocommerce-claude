<?php
/**
 * Schema Coverage Scoring Factor.
 *
 * Scores how well the store uses structured data — attributes,
 * category depth, tags, and product relationships.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Scoring\Factors;

defined( 'ABSPATH' ) || exit;

/**
 * Scores how well the store uses structured product data.
 */
class SchemaCoverage {

	/**
	 * Get the factor identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'schema-coverage';
	}

	/**
	 * Get the human-readable factor label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Schema Coverage';
	}

	/**
	 * Get the factor's weight in the overall score.
	 *
	 * @return float
	 */
	public function get_weight() {
		return 0.25;
	}

	/**
	 * Score the store's use of categories, attributes, tags, and product relationships.
	 *
	 * @return array
	 */
	public function score_store() {
		$score   = 0;
		$details = array();
		$recs    = array();

		// 1. Category structure (0-25 points).
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		$cat_count = is_wp_error( $categories ) ? 0 : count( $categories );
		$has_depth = false;

		if ( ! is_wp_error( $categories ) ) {
			foreach ( $categories as $cat ) {
				if ( $cat->parent > 0 ) {
					$has_depth = true;
					break;
				}
			}
		}

		$details['category_count']    = $cat_count;
		$details['has_subcategories'] = $has_depth;

		if ( $cat_count >= 5 && $has_depth ) {
			$score += 25;
		} elseif ( $cat_count >= 3 ) {
			$score += 15;
		} elseif ( $cat_count >= 1 ) {
			$score += 5;
		}

		if ( ! $has_depth && $cat_count > 0 ) {
			$recs[] = array(
				'id'          => 'add-subcategories',
				'title'       => 'Add subcategories to your catalog',
				'description' => 'A category hierarchy helps AI systems understand relationships between products. Consider organising categories into parent/child groups.',
				'priority'    => 'medium',
				'impact'      => 'medium',
			);
		}

		// 2. Product attributes (0-30 points).
		$attribute_taxonomies       = wc_get_attribute_taxonomies();
		$attr_count                 = count( $attribute_taxonomies );
		$details['attribute_count'] = $attr_count;

		// Check what percentage of products use attributes.
		$products_with_attrs = $this->count_products_with_attributes();
		$total_products      = absint( wp_count_posts( 'product' )->publish ?? 0 );
		$attr_pct            = $total_products > 0 ? round( ( $products_with_attrs / $total_products ) * 100 ) : 0;

		$details['products_with_attributes']   = $products_with_attrs;
		$details['attribute_usage_percentage'] = $attr_pct;

		if ( $attr_count >= 3 && $attr_pct >= 50 ) {
			$score += 30;
		} elseif ( $attr_count >= 1 && $attr_pct >= 25 ) {
			$score += 20;
		} elseif ( $attr_count >= 1 ) {
			$score += 10;
		}

		if ( 0 === $attr_count ) {
			$recs[] = array(
				'id'          => 'add-attributes',
				'title'       => 'Define product attributes',
				'description' => 'Product attributes (size, colour, material, etc.) provide structured data that AI systems can filter and compare. Define global attributes in Products > Attributes.',
				'priority'    => 'high',
				'impact'      => 'high',
			);
		} elseif ( $attr_pct < 50 ) {
			$recs[] = array(
				'id'          => 'increase-attribute-usage',
				'title'       => "Only {$attr_pct}% of products use attributes",
				'description' => 'Apply your defined attributes to more products so AI systems can compare and filter consistently.',
				'priority'    => 'medium',
				'impact'      => 'medium',
			);
		}

		// 3. Product tags (0-15 points).
		$tag_count            = wp_count_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
			)
		);
		$details['tag_count'] = is_wp_error( $tag_count ) ? 0 : $tag_count;

		if ( $tag_count >= 10 ) {
			$score += 15;
		} elseif ( $tag_count >= 3 ) {
			$score += 10;
		} elseif ( $tag_count >= 1 ) {
			$score += 5;
		}

		// 4. Product relationships (0-30 points).
		$products_with_upsells    = $this->count_products_with_meta( '_upsell_ids' );
		$products_with_crosssells = $this->count_products_with_meta( '_crosssell_ids' );

		$details['products_with_upsells']     = $products_with_upsells;
		$details['products_with_cross_sells'] = $products_with_crosssells;

		$rel_score = 0;
		if ( $products_with_upsells > 0 ) {
			$rel_score += 15;
		}
		if ( $products_with_crosssells > 0 ) {
			$rel_score += 15;
		}
		$score += $rel_score;

		if ( 0 === $products_with_upsells && 0 === $products_with_crosssells && $total_products > 5 ) {
			$recs[] = array(
				'id'          => 'add-product-relationships',
				'title'       => 'Add upsells and cross-sells to products',
				'description' => 'Product relationships help AI systems recommend complementary items and alternatives, improving the shopping experience.',
				'priority'    => 'low',
				'impact'      => 'medium',
			);
		}

		return array(
			'score'           => min( 100, $score ),
			'details'         => $details,
			'recommendations' => $recs,
		);
	}

	/**
	 * Score a single product's schema coverage.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @return array
	 */
	public function score_product( $product ) {
		$score  = 0;
		$checks = array();

		// Has categories.
		$cats                     = get_the_terms( $product->get_id(), 'product_cat' );
		$checks['has_categories'] = ! empty( $cats ) && ! is_wp_error( $cats );
		if ( $checks['has_categories'] ) {
			$score += 25;
		}

		// Has attributes.
		$checks['has_attributes'] = count( $product->get_attributes() ) > 0;
		if ( $checks['has_attributes'] ) {
			$score += 30;
		}

		// Has tags.
		$tags               = get_the_terms( $product->get_id(), 'product_tag' );
		$checks['has_tags'] = ! empty( $tags ) && ! is_wp_error( $tags );
		if ( $checks['has_tags'] ) {
			$score += 15;
		}

		// Has relationships.
		$checks['has_upsells']     = ! empty( $product->get_upsell_ids() );
		$checks['has_cross_sells'] = ! empty( $product->get_cross_sell_ids() );
		if ( $checks['has_upsells'] ) {
			$score += 15;
		}
		if ( $checks['has_cross_sells'] ) {
			$score += 15;
		}

		return array(
			'score'   => min( 100, $score ),
			'details' => $checks,
		);
	}

	/**
	 * Count published products that have at least one global attribute term.
	 *
	 * @return int
	 */
	private function count_products_with_attributes() {
		global $wpdb;

		$result = $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE p.post_type = 'product'
			AND p.post_status = 'publish'
			AND tt.taxonomy LIKE 'pa_%'"
		);

		return absint( $result );
	}

	/**
	 * Count published products that have a non-empty value for the given meta key.
	 *
	 * @param string $meta_key Post meta key to check (e.g. _upsell_ids).
	 * @return int
	 */
	private function count_products_with_meta( $meta_key ) {
		global $wpdb;

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'product'
				AND p.post_status = 'publish'
				AND pm.meta_key = %s
				AND pm.meta_value != ''
				AND pm.meta_value != 'a:0:{}'",
				$meta_key
			)
		);

		return absint( $result );
	}
}
