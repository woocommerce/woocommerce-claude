<?php
/**
 * Product Completeness Scoring Factor.
 *
 * Scores how complete product data is across the catalogue:
 * descriptions, images, categories, attributes, pricing, stock.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Scoring\Factors;

defined( 'ABSPATH' ) || exit;

/**
 * Scores how complete product data is across the catalogue.
 */
class ProductCompleteness {

	/**
	 * Get the factor identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'product-completeness';
	}

	/**
	 * Get the human-readable factor label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Product Completeness';
	}

	/**
	 * Get the factor's weight in the overall score.
	 *
	 * @return float
	 */
	public function get_weight() {
		return 0.35; // Highest weight — product data is the foundation.
	}

	/**
	 * Score the entire store's product completeness.
	 *
	 * @return array
	 */
	public function score_store() {
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 100, // Sample up to 100 products for POC performance.
				'return' => 'objects',
			)
		);

		if ( empty( $products ) ) {
			return array(
				'score'           => 0,
				'details'         => array( 'message' => 'No published products found.' ),
				'recommendations' => array(
					array(
						'id'          => 'no-products',
						'title'       => 'Add products to your store',
						'description' => 'Your store has no published products. AI systems cannot discover an empty catalogue.',
						'priority'    => 'high',
					),
				),
			);
		}

		$total_score = 0;
		$issues      = array(
			'missing_description'       => 0,
			'missing_short_description' => 0,
			'missing_images'            => 0,
			'missing_categories'        => 0,
			'missing_attributes'        => 0,
			'missing_price'             => 0,
			'short_descriptions'        => 0, // Under 50 words.
			'single_image'              => 0,
		);

		foreach ( $products as $product ) {
			$result       = $this->score_product( $product );
			$total_score += $result['score'];

			// Track issues.
			foreach ( $result['details'] as $key => $passed ) {
				if ( ! $passed && isset( $issues[ 'missing_' . str_replace( 'has_', '', $key ) ] ) ) {
					++$issues[ 'missing_' . str_replace( 'has_', '', $key ) ];
				}
			}

			if ( ! empty( $product->get_description() ) && str_word_count( $product->get_description() ) < 50 ) {
				++$issues['short_descriptions'];
			}

			if ( $product->get_image_id() && empty( $product->get_gallery_image_ids() ) ) {
				++$issues['single_image'];
			}
		}

		$count           = count( $products );
		$average_score   = round( $total_score / $count );
		$recommendations = $this->generate_recommendations( $issues, $count );

		return array(
			'score'           => $average_score,
			'details'         => array(
				'products_sampled' => $count,
				'average_score'    => $average_score,
				'issues'           => $issues,
			),
			'recommendations' => $recommendations,
		);
	}

	/**
	 * Score a single product.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @return array
	 */
	public function score_product( $product ) {
		$description = $product->get_description();
		$is_virtual  = $product->is_virtual();

		$checks = array(
			'has_description'       => ! empty( $description ),
			'has_short_description' => ! empty( $product->get_short_description() ),
			'has_price'             => '' !== $product->get_price(),
			'has_categories'        => ! empty( get_the_terms( $product->get_id(), 'product_cat' ) ),
			'has_main_image'        => ! empty( $product->get_image_id() ),
			'has_gallery_images'    => count( $product->get_gallery_image_ids() ) >= 1,
			'has_attributes'        => count( $product->get_attributes() ) > 0,
			'has_weight'            => ! empty( $product->get_weight() ) || $is_virtual,
			'has_stock_info'        => ! empty( $product->get_stock_status() ),
		);

		// Bonus checks (worth partial credit).
		$bonus     = 0;
		$bonus_max = 0;

		// Description quality — longer is better for AI understanding.
		$bonus_max += 10;
		if ( ! empty( $description ) ) {
			$word_count = str_word_count( $description );
			if ( $word_count >= 100 ) {
				$bonus += 10;
			} elseif ( $word_count >= 50 ) {
				$bonus += 5;
			}
		}

		// Multiple images help AI understand the product visually.
		$bonus_max    += 10;
		$gallery_count = count( $product->get_gallery_image_ids() );
		if ( $gallery_count >= 3 ) {
			$bonus += 10;
		} elseif ( $gallery_count >= 1 ) {
			$bonus += 5;
		}

		$base_passed = count( array_filter( $checks ) );
		$base_total  = count( $checks );
		$base_score  = round( ( $base_passed / $base_total ) * 80 ); // Base checks worth up to 80.
		$bonus_score = $bonus_max > 0 ? round( ( $bonus / $bonus_max ) * 20 ) : 0; // Bonus worth up to 20.
		$total_score = $base_score + $bonus_score;

		$recommendations = array();
		if ( ! $checks['has_description'] ) {
			$recommendations[] = array(
				'id'          => 'add-description-' . $product->get_id(),
				'title'       => 'Add a description to "' . $product->get_name() . '"',
				'description' => 'Product descriptions help AI systems understand what this product is and recommend it accurately.',
				'priority'    => 'high',
			);
		}
		if ( ! $checks['has_main_image'] ) {
			$recommendations[] = array(
				'id'          => 'add-image-' . $product->get_id(),
				'title'       => 'Add an image to "' . $product->get_name() . '"',
				'description' => 'Products without images are much less likely to be surfaced by AI shopping agents.',
				'priority'    => 'high',
			);
		}

		return array(
			'score'           => $total_score,
			'details'         => $checks,
			'recommendations' => $recommendations,
		);
	}

	/**
	 * Generate store-level recommendations from aggregated issues.
	 *
	 * @param array $issues Counts of products failing each completeness check.
	 * @param int   $total  Total number of products sampled.
	 * @return array
	 */
	private function generate_recommendations( $issues, $total ) {
		$recs = array();

		if ( $issues['missing_description'] > 0 ) {
			$pct    = round( ( $issues['missing_description'] / $total ) * 100 );
			$recs[] = array(
				'id'          => 'missing-descriptions',
				'title'       => "{$issues['missing_description']} products missing descriptions ({$pct}%)",
				'description' => 'Product descriptions are the primary content AI systems use to understand and recommend products. Add descriptions to improve AI discoverability.',
				'priority'    => $pct > 50 ? 'high' : 'medium',
				'impact'      => 'high',
			);
		}

		if ( $issues['missing_images'] > 0 ) {
			$pct    = round( ( $issues['missing_images'] / $total ) * 100 );
			$recs[] = array(
				'id'          => 'missing-images',
				'title'       => "{$issues['missing_images']} products missing images ({$pct}%)",
				'description' => 'Products without images are significantly less likely to be recommended by AI shopping agents.',
				'priority'    => $pct > 30 ? 'high' : 'medium',
				'impact'      => 'high',
			);
		}

		if ( $issues['missing_categories'] > 0 ) {
			$recs[] = array(
				'id'          => 'missing-categories',
				'title'       => "{$issues['missing_categories']} products not categorised",
				'description' => 'Categories help AI systems understand your catalogue structure and make relevant recommendations.',
				'priority'    => 'medium',
				'impact'      => 'medium',
			);
		}

		if ( $issues['missing_attributes'] > 0 ) {
			$pct    = round( ( $issues['missing_attributes'] / $total ) * 100 );
			$recs[] = array(
				'id'          => 'missing-attributes',
				'title'       => "{$issues['missing_attributes']} products have no attributes ({$pct}%)",
				'description' => 'Product attributes (size, colour, material, etc.) give AI systems structured data to compare and filter products.',
				'priority'    => $pct > 70 ? 'high' : 'medium',
				'impact'      => 'medium',
			);
		}

		if ( $issues['short_descriptions'] > 0 ) {
			$recs[] = array(
				'id'          => 'short-descriptions',
				'title'       => "{$issues['short_descriptions']} products have descriptions under 50 words",
				'description' => 'Short descriptions give AI systems less to work with. Aim for 100+ words covering features, use cases, and specifications.',
				'priority'    => 'low',
				'impact'      => 'medium',
			);
		}

		return $recs;
	}
}
