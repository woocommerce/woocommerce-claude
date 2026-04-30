<?php
/**
 * Policy Completeness Scoring Factor.
 *
 * Scores whether the store has key policy pages (shipping, returns,
 * privacy, terms) and whether they contain meaningful content.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Scoring\Factors;

use HeyWoo\Knowledge\KnowledgeRegistry;

defined( 'ABSPATH' ) || exit;

/**
 * Scores whether the store has key policy pages with meaningful content.
 */
class PolicyCompleteness {

	/**
	 * Get the factor identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'policy-completeness';
	}

	/**
	 * Get the human-readable factor label.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Policy Completeness';
	}

	/**
	 * Get the factor's weight in the overall score.
	 *
	 * @return float
	 */
	public function get_weight() {
		return 0.15;
	}

	/**
	 * Score the store's policy pages and return details with recommendations.
	 *
	 * @return array
	 */
	public function score_store() {
		$registry = KnowledgeRegistry::instance();
		$policies = $registry->get_knowledge( 'policies' );

		if ( ! $policies ) {
			return array(
				'score'           => 0,
				'details'         => array(),
				'recommendations' => array(),
			);
		}

		$total_score = 0;
		$details     = array();
		$recs        = array();

		$policy_map = array(
			'privacy'          => array(
				'label'  => 'Privacy Policy',
				'weight' => 25,
			),
			'terms_conditions' => array(
				'label'  => 'Terms & Conditions',
				'weight' => 20,
			),
			'refund_returns'   => array(
				'label'  => 'Refund/Returns Policy',
				'weight' => 30,
			),
			'shipping'         => array(
				'label'  => 'Shipping Policy',
				'weight' => 25,
			),
		);

		foreach ( $policy_map as $key => $config ) {
			$policy     = $policies[ $key ] ?? array();
			$exists     = ! empty( $policy['exists'] );
			$word_count = $policy['word_count'] ?? 0;

			$page_score = 0;
			if ( $exists && $word_count >= 100 ) {
				$page_score = $config['weight'];
			} elseif ( $exists && $word_count >= 30 ) {
				$page_score = round( $config['weight'] * 0.6 );
			} elseif ( $exists ) {
				$page_score = round( $config['weight'] * 0.3 );
			}

			$total_score += $page_score;

			$details[ $key ] = array(
				'exists'     => $exists,
				'word_count' => $word_count,
				'score'      => $page_score,
				'max_score'  => $config['weight'],
			);

			if ( ! $exists ) {
				$recs[] = array(
					'id'          => 'missing-' . $key,
					'title'       => "Missing {$config['label']}",
					'description' => "AI systems reference store policies when answering shopper questions. A {$config['label']} page helps AI provide accurate information about your store.",
					'priority'    => 'refund_returns' === $key ? 'high' : 'medium',
					'impact'      => 'medium',
				);
			} elseif ( $word_count < 100 ) {
				$recs[] = array(
					'id'          => 'thin-' . $key,
					'title'       => "{$config['label']} is thin ({$word_count} words)",
					'description' => "Your {$config['label']} exists but has limited content. Aim for 100+ words covering key details so AI systems can answer shopper questions accurately.",
					'priority'    => 'low',
					'impact'      => 'low',
				);
			}
		}

		return array(
			'score'           => min( 100, $total_score ),
			'details'         => $details,
			'recommendations' => $recs,
		);
	}
}
