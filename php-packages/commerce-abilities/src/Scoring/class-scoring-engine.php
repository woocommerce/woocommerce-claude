<?php
/**
 * Scoring Engine — orchestrates all scoring factors to produce
 * overall store readiness and per-product scores.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Scoring;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates scoring factors to produce store readiness and per-product scores.
 */
class ScoringEngine {

	/**
	 * Scoring factor instances.
	 *
	 * @var array
	 */
	private $factors = array();

	/**
	 * Build the engine and register the default scoring factors.
	 */
	public function __construct() {
		$this->factors = array(
			new Factors\ProductCompleteness(),
			new Factors\SchemaCoverage(),
			new Factors\PolicyCompleteness(),
			new Factors\ContentQuality(),
		);

		/**
		 * Filter scoring factors for all commerce-abilities consumers.
		 *
		 * @since 0.2.0
		 *
		 * @param array $factors Array of scoring factor instances.
		 */
		$this->factors = apply_filters( 'woocommerce_commerce_abilities_scoring_factors', $this->factors );

		/**
		 * Filter scoring factors for WooCommerce for Claude consumers.
		 *
		 * @since 0.1.0
		 *
		 * @param array $factors Array of scoring factor instances.
		 */
		$this->factors = apply_filters( 'woocommerce_claude_scoring_factors', $this->factors );

		/**
		 * Filter scoring factors for Hey Woo consumers.
		 *
		 * @since 0.4.2
		 *
		 * @param array $factors Array of scoring factor instances.
		 */
		$this->factors = apply_filters( 'hey_woo_scoring_factors', $this->factors );
	}

	/**
	 * Get the overall store readiness score.
	 *
	 * @return array
	 */
	public function get_store_score() {
		$factor_scores = array();
		$total_score   = 0;
		$total_weight  = 0;

		foreach ( $this->factors as $factor ) {
			$result = $factor->score_store();

			$factor_scores[] = array(
				'id'              => $factor->get_id(),
				'label'           => $factor->get_label(),
				'score'           => $result['score'],
				'max_score'       => 100,
				'weight'          => $factor->get_weight(),
				'details'         => $result['details'] ?? array(),
				'recommendations' => $result['recommendations'] ?? array(),
			);

			$total_score  += $result['score'] * $factor->get_weight();
			$total_weight += $factor->get_weight();
		}

		$overall = $total_weight > 0 ? round( $total_score / $total_weight ) : 0;

		// Grade the score.
		$grade = 'poor';
		if ( $overall >= 80 ) {
			$grade = 'excellent';
		} elseif ( $overall >= 60 ) {
			$grade = 'good';
		} elseif ( $overall >= 40 ) {
			$grade = 'fair';
		}

		return array(
			'overall_score' => $overall,
			'grade'         => $grade,
			'factors'       => $factor_scores,
			'scored_at'     => current_time( 'c' ),
		);
	}

	/**
	 * Get the score for a specific product.
	 *
	 * @param \WC_Product $product WooCommerce product.
	 * @return array
	 */
	public function get_product_score( $product ) {
		$factor_scores = array();
		$total_score   = 0;
		$total_weight  = 0;

		foreach ( $this->factors as $factor ) {
			if ( ! method_exists( $factor, 'score_product' ) ) {
				continue;
			}

			$result = $factor->score_product( $product );

			$factor_scores[] = array(
				'id'              => $factor->get_id(),
				'label'           => $factor->get_label(),
				'score'           => $result['score'],
				'weight'          => $factor->get_weight(),
				'details'         => $result['details'] ?? array(),
				'recommendations' => $result['recommendations'] ?? array(),
			);

			$total_score  += $result['score'] * $factor->get_weight();
			$total_weight += $factor->get_weight();
		}

		$overall = $total_weight > 0 ? round( $total_score / $total_weight ) : 0;

		return array(
			'product_id'    => $product->get_id(),
			'product_name'  => $product->get_name(),
			'overall_score' => $overall,
			'factors'       => $factor_scores,
			'scored_at'     => current_time( 'c' ),
		);
	}

	/**
	 * Get all recommendations across all factors, sorted by priority.
	 *
	 * @return array
	 */
	public function get_recommendations() {
		$all_recs = array();

		foreach ( $this->factors as $factor ) {
			$result = $factor->score_store();
			if ( ! empty( $result['recommendations'] ) ) {
				foreach ( $result['recommendations'] as $rec ) {
					$rec['factor'] = $factor->get_id();
					$all_recs[]    = $rec;
				}
			}
		}

		// Sort by impact (high > medium > low).
		$priority_map = array(
			'high'   => 3,
			'medium' => 2,
			'low'    => 1,
		);
		usort(
			$all_recs,
			function ( $a, $b ) use ( $priority_map ) {
				return ( $priority_map[ $b['priority'] ] ?? 0 ) - ( $priority_map[ $a['priority'] ] ?? 0 );
			}
		);

		return $all_recs;
	}
}
