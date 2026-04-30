<?php
/**
 * Content Quality Scoring Factor.
 *
 * Scores the quality of product content — description depth,
 * FAQ presence, image alt text, SEO metadata.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Scoring\Factors;

defined( 'ABSPATH' ) || exit;

/**
 * Scores the quality of product content across the catalog.
 */
class ContentQuality {

	/**
	 * Get the factor identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'content-quality';
	}

	/**
	 * Get the human-readable factor label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Content Quality';
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
	 * Score the store's content quality across a sample of products.
	 *
	 * @return array
	 */
	public function score_store() {
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'limit'  => 100,
				'return' => 'objects',
			)
		);

		if ( empty( $products ) ) {
			return array(
				'score'           => 0,
				'details'         => array(),
				'recommendations' => array(),
			);
		}

		$total_score = 0;
		$issues      = array(
			'no_paragraphs'      => 0,
			'no_seo_title'       => 0,
			'no_seo_description' => 0,
			'no_alt_text'        => 0,
			'boilerplate_desc'   => 0,
		);

		$has_seo_plugin = $this->detect_seo_plugin();

		foreach ( $products as $product ) {
			$result       = $this->score_product( $product );
			$total_score += $result['score'];

			$details = $result['details'];
			if ( ! $details['has_structured_description'] ) {
				++$issues['no_paragraphs'];
			}
			if ( $has_seo_plugin && ! $details['has_seo_title'] ) {
				++$issues['no_seo_title'];
			}
			if ( $has_seo_plugin && ! $details['has_seo_description'] ) {
				++$issues['no_seo_description'];
			}
			if ( ! $details['has_image_alt_text'] ) {
				++$issues['no_alt_text'];
			}
		}

		$count         = count( $products );
		$average_score = round( $total_score / $count );
		$recs          = $this->generate_recommendations( $issues, $count, $has_seo_plugin );

		return array(
			'score'           => $average_score,
			'details'         => array(
				'products_sampled' => $count,
				'has_seo_plugin'   => $has_seo_plugin,
				'issues'           => $issues,
			),
			'recommendations' => $recs,
		);
	}

	/**
	 * Score a single product's content quality.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @return array
	 */
	public function score_product( $product ) {
		$score       = 0;
		$description = $product->get_description();
		$product_id  = $product->get_id();

		// 1. Structured description — has paragraphs/sections (0-30 points).
		$has_structure = false;
		if ( ! empty( $description ) ) {
			$has_structure = preg_match( '/<(p|h[2-6]|ul|ol|li)[^>]*>/i', $description )
				|| substr_count( $description, "\n\n" ) >= 2;
		}
		if ( $has_structure ) {
			$score += 30;
		} elseif ( ! empty( $description ) ) {
			$score += 10; // Has content but unstructured.
		}

		// 2. Description depth — substantive content (0-20 points).
		$word_count = ! empty( $description ) ? str_word_count( wp_strip_all_tags( $description ) ) : 0;
		if ( $word_count >= 150 ) {
			$score += 20;
		} elseif ( $word_count >= 75 ) {
			$score += 12;
		} elseif ( $word_count >= 30 ) {
			$score += 5;
		}

		// 3. SEO metadata (0-25 points).
		$has_seo_title = false;
		$has_seo_desc  = false;

		// Check Yoast.
		$yoast_title = get_post_meta( $product_id, '_yoast_wpseo_title', true );
		$yoast_desc  = get_post_meta( $product_id, '_yoast_wpseo_metadesc', true );
		if ( ! empty( $yoast_title ) ) {
			$has_seo_title = true;
		}
		if ( ! empty( $yoast_desc ) ) {
			$has_seo_desc = true;
		}

		// Check RankMath.
		$rank_title = get_post_meta( $product_id, 'rank_math_title', true );
		$rank_desc  = get_post_meta( $product_id, 'rank_math_description', true );
		if ( ! empty( $rank_title ) ) {
			$has_seo_title = true;
		}
		if ( ! empty( $rank_desc ) ) {
			$has_seo_desc = true;
		}

		if ( $has_seo_title ) {
			$score += 12;
		}
		if ( $has_seo_desc ) {
			$score += 13;
		}

		// 4. Image alt text (0-25 points).
		$main_image_id = $product->get_image_id();
		$has_alt       = false;

		if ( $main_image_id ) {
			$alt     = get_post_meta( $main_image_id, '_wp_attachment_image_alt', true );
			$has_alt = ! empty( $alt );
		}

		if ( $has_alt ) {
			$score += 25;
		}

		return array(
			'score'   => min( 100, $score ),
			'details' => array(
				'has_structured_description' => $has_structure,
				'description_word_count'     => $word_count,
				'has_seo_title'              => $has_seo_title,
				'has_seo_description'        => $has_seo_desc,
				'has_image_alt_text'         => $has_alt,
			),
		);
	}

	/**
	 * Detect whether a known SEO plugin is active.
	 *
	 * @return bool
	 */
	private function detect_seo_plugin() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * Generate store-level recommendations from aggregated content issues.
	 *
	 * @param array $issues         Counts of products failing each content check.
	 * @param int   $total          Total number of products sampled.
	 * @param bool  $has_seo_plugin Whether a known SEO plugin is active.
	 * @return array
	 */
	private function generate_recommendations( $issues, $total, $has_seo_plugin ) {
		$recs = array();

		if ( $issues['no_paragraphs'] > $total * 0.5 ) {
			$recs[] = array(
				'id'          => 'unstructured-descriptions',
				'title'       => "{$issues['no_paragraphs']} products have unstructured descriptions",
				'description' => 'Use paragraphs, headings, and lists in product descriptions. Structured content helps AI systems extract specific product details.',
				'priority'    => 'medium',
				'impact'      => 'medium',
			);
		}

		if ( $issues['no_alt_text'] > $total * 0.3 ) {
			$recs[] = array(
				'id'          => 'missing-alt-text',
				'title'       => "{$issues['no_alt_text']} products missing image alt text",
				'description' => 'Alt text helps AI systems understand product images. Describe what the image shows, including key product features.',
				'priority'    => 'medium',
				'impact'      => 'medium',
			);
		}

		if ( ! $has_seo_plugin ) {
			$recs[] = array(
				'id'          => 'install-seo-plugin',
				'title'       => 'No SEO plugin detected',
				'description' => 'An SEO plugin (Yoast, RankMath) adds metadata that AI systems use to understand products. SEO meta descriptions are particularly valuable for AI summarisation.',
				'priority'    => 'medium',
				'impact'      => 'medium',
			);
		} elseif ( $issues['no_seo_description'] > $total * 0.5 ) {
			$recs[] = array(
				'id'          => 'missing-seo-descriptions',
				'title'       => "{$issues['no_seo_description']} products missing SEO meta descriptions",
				'description' => 'SEO meta descriptions give AI systems a concise product summary. Fill these in for all products.',
				'priority'    => 'low',
				'impact'      => 'medium',
			);
		}

		return $recs;
	}
}
