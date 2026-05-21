<?php
/**
 * Refund-spike detector.
 *
 * Fires when the last 7 days' refund rate (refunds as a share of paid gross
 * revenue) exceeds the previous 7-day window by more than 2 percentage points,
 * and at least three refund events were issued in the current period. The
 * small-sample guard prevents single-order refunds from triggering alarms on
 * low-volume stores.
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Detectors
 */

namespace WooCommerce\HeyWoo\ThisWeek\Detectors;

defined( 'ABSPATH' ) || exit;

/**
 * Detect refund-rate spikes worth surfacing.
 */
class RefundSpikeDetector implements SignalDetectorInterface {

	/**
	 * Percentage-point delta threshold against the previous period.
	 */
	const RATE_DELTA_THRESHOLD_PP = 2.0;

	/**
	 * Minimum refund count in the current period (small-sample guard).
	 */
	const MIN_REFUND_COUNT = 3;

	/**
	 * Delta in percentage points that escalates severity to "high".
	 */
	const HIGH_SEVERITY_THRESHOLD_PP = 5.0;

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'refund-spike';
	}

	/**
	 * {@inheritDoc}
	 */
	public function workflow_slug() {
		return 'refund-triage';
	}

	/**
	 * {@inheritDoc}
	 */
	public function detect() {
		$refunds = AbilityRunner::call(
			'wc-analytics/totals',
			array(
				'subject' => 'refunds',
				'period'  => 'last_7_days',
				'compare' => true,
			)
		);

		if ( ! is_array( $refunds ) ) {
			return null;
		}

		$current_rate   = self::numeric( $refunds['metrics']['refund_rate_percent'] ?? null );
		$refunds_count  = (int) ( $refunds['metrics']['refunds_count'] ?? 0 );
		$refunds_amount = (float) ( $refunds['metrics']['refunds_amount'] ?? 0 );

		if ( $refunds_count < self::MIN_REFUND_COUNT ) {
			return null;
		}

		$comparison    = isset( $refunds['comparison'] ) && is_array( $refunds['comparison'] ) ? $refunds['comparison'] : null;
		$previous_rate = self::numeric( $comparison['metrics']['refund_rate_percent'] ?? null );

		if ( null === $current_rate || null === $previous_rate ) {
			return null;
		}

		$delta_pp = $current_rate - $previous_rate;
		if ( $delta_pp < self::RATE_DELTA_THRESHOLD_PP ) {
			return null;
		}

		$severity = $delta_pp >= self::HIGH_SEVERITY_THRESHOLD_PP ? 'high' : 'medium';

		return array(
			'slug'          => $this->slug(),
			'severity'      => $severity,
			'workflow_slug' => $this->workflow_slug(),
			'title'         => sprintf(
				/* translators: %s: percentage points of rate increase, e.g. "+3.4pp". */
				__( 'Refund rate up %s week-over-week', 'hey-woo' ),
				self::format_pp_delta( $delta_pp )
			),
			'raw_evidence'  => array(
				'period'              => $refunds['period'] ?? array(),
				'previous_period'     => $comparison['period'] ?? array(),
				'currency'            => $refunds['currency'] ?? '',
				'current_rate_pct'    => $current_rate,
				'previous_rate_pct'   => $previous_rate,
				'delta_pp'            => $delta_pp,
				'refunds_count'       => $refunds_count,
				'refunds_amount'      => $refunds_amount,
				'orders_refunded'     => (int) ( $refunds['metrics']['orders_refunded_count'] ?? 0 ),
				'avg_days_to_refund'  => (float) ( $refunds['metrics']['avg_days_to_refund'] ?? 0 ),
				'partial_refunds'     => (int) ( $refunds['metrics']['partial_refunds_count'] ?? 0 ),
				'full_refunds'        => (int) ( $refunds['metrics']['full_refunds_count'] ?? 0 ),
			),
		);
	}

	/**
	 * Read a numeric metric defensively, treating null/non-numeric as absent.
	 *
	 * @param mixed $value Raw metric.
	 * @return float|null
	 */
	private static function numeric( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * Format a percentage-point delta with a leading sign.
	 *
	 * @param float $value Percentage-point delta.
	 * @return string
	 */
	private static function format_pp_delta( $value ) {
		$absolute = abs( $value );
		$rendered = $absolute >= 10 ? (string) (int) round( $absolute ) : number_format( $absolute, 1 );

		return ( $value < 0 ? '-' : '+' ) . $rendered . 'pp';
	}
}
