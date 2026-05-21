<?php
/**
 * Revenue-win detector.
 *
 * Mirror of RevenueDropDetector for positive signals. Fires when the last
 * 7 days of paid revenue are materially up from the matching previous
 * 7-day window so the merchant gets a feel for momentum, not only risk.
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Detectors
 */

namespace WooCommerce\HeyWoo\ThisWeek\Detectors;

defined( 'ABSPATH' ) || exit;

/**
 * Detect week-over-week revenue wins worth surfacing.
 */
class RevenueWinDetector implements SignalDetectorInterface {

	/**
	 * Percent gain threshold (e.g. 10 means revenue must be at least 10% higher).
	 */
	const GAIN_PERCENT_THRESHOLD = 10.0;

	/**
	 * Minimum previous-period revenue (in store currency) below which the gain
	 * is statistically uninteresting — small bases produce big percent moves.
	 */
	const PREVIOUS_PERIOD_FLOOR = 100.0;

	/**
	 * Percent gain that bumps the signal to a higher-severity celebration.
	 */
	const HIGH_GAIN_THRESHOLD = 25.0;

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'revenue-win';
	}

	/**
	 * {@inheritDoc}
	 */
	public function workflow_slug() {
		return 'weekly-store-review';
	}

	/**
	 * {@inheritDoc}
	 */
	public function detect() {
		$totals = AbilityRunner::call(
			'wc-analytics/totals',
			array(
				'subject' => 'revenue',
				'period'  => 'last_7_days',
				'compare' => true,
			)
		);

		if ( ! is_array( $totals ) ) {
			return null;
		}

		$current_net_sales  = (float) ( $totals['metrics']['net_sales'] ?? 0 );
		$comparison         = isset( $totals['comparison'] ) && is_array( $totals['comparison'] ) ? $totals['comparison'] : null;
		$previous_net_sales = (float) ( $comparison['metrics']['net_sales'] ?? 0 );
		$change_percent     = (float) ( $comparison['changes']['net_sales']['percent'] ?? 0 );
		$change_amount      = (float) ( $comparison['changes']['net_sales']['amount'] ?? 0 );

		if ( $previous_net_sales < self::PREVIOUS_PERIOD_FLOOR ) {
			return null;
		}

		if ( $change_percent < self::GAIN_PERCENT_THRESHOLD ) {
			return null;
		}

		$severity = $change_percent >= self::HIGH_GAIN_THRESHOLD ? 'medium' : 'low';

		return array(
			'slug'          => $this->slug(),
			'severity'      => $severity,
			'tone'          => 'positive',
			'workflow_slug' => $this->workflow_slug(),
			'title'         => sprintf(
				/* translators: %s: signed percent change (e.g. "+18%%"). */
				__( 'Revenue is up %s week-over-week', 'hey-woo' ),
				self::format_percent( $change_percent )
			),
			'raw_evidence'  => array(
				'period'             => $totals['period'] ?? array(),
				'previous_period'    => $comparison['period'] ?? array(),
				'currency'           => $totals['currency'] ?? '',
				'current_net_sales'  => $current_net_sales,
				'previous_net_sales' => $previous_net_sales,
				'change_percent'     => $change_percent,
				'change_amount'      => $change_amount,
				'current_orders'     => (int) ( $totals['metrics']['orders_count'] ?? 0 ),
				'previous_orders'    => (int) ( $comparison['metrics']['orders_count'] ?? 0 ),
				'aov_current'        => (float) ( $totals['metrics']['average_order_value'] ?? 0 ),
				'aov_previous'       => (float) ( $comparison['metrics']['average_order_value'] ?? 0 ),
			),
		);
	}

	/**
	 * Format a percent figure with a leading sign for the deterministic title.
	 *
	 * @param float $value Percent value.
	 * @return string
	 */
	private static function format_percent( $value ) {
		$absolute = abs( $value );
		$rendered = $absolute >= 10 ? (string) (int) round( $absolute ) : number_format( $absolute, 1 );

		return ( $value < 0 ? '-' : '+' ) . $rendered . '%';
	}
}
