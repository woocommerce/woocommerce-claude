<?php
/**
 * Failed/on-hold order pipeline detector.
 *
 * Fires when the on-hold pipeline value has grown materially compared to the
 * previous 7-day window, which usually means more checkouts are stalling
 * unpaid than the merchant has chased.
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Detectors
 */

namespace WooCommerce\HeyWoo\ThisWeek\Detectors;

defined( 'ABSPATH' ) || exit;

/**
 * Detect on-hold pipeline growth worth surfacing.
 */
class FailedOrderDetector implements SignalDetectorInterface {

	/**
	 * Pipeline value growth percent that triggers a signal.
	 */
	const GROWTH_PERCENT_THRESHOLD = 25.0;

	/**
	 * Minimum current-period on-hold value below which we treat the pipeline as inactive.
	 */
	const MIN_PIPELINE_VALUE = 50.0;

	/**
	 * Minimum on-hold order count to fire (small-sample guard).
	 */
	const MIN_PIPELINE_ORDERS = 2;

	/**
	 * Growth percent that escalates severity to "high".
	 */
	const HIGH_SEVERITY_GROWTH = 75.0;

	/**
	 * Age (days) of the oldest on-hold order that escalates severity to "high".
	 */
	const HIGH_SEVERITY_OLDEST_DAYS = 14;

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'failed-order-pipeline';
	}

	/**
	 * {@inheritDoc}
	 */
	public function workflow_slug() {
		return 'failed-order-triage';
	}

	/**
	 * {@inheritDoc}
	 */
	public function detect() {
		$orders = AbilityRunner::call(
			'wc-analytics/totals',
			array(
				'subject' => 'orders',
				'period'  => 'last_7_days',
				'compare' => true,
			)
		);

		if ( ! is_array( $orders ) ) {
			return null;
		}

		$pipeline_current = isset( $orders['pipeline'] ) && is_array( $orders['pipeline'] ) ? $orders['pipeline'] : array();
		$pipeline_count   = (int) ( $pipeline_current['orders_count'] ?? 0 );
		$pipeline_value   = (float) ( $pipeline_current['revenue'] ?? 0 );
		$oldest_days      = isset( $pipeline_current['oldest_order_days'] ) ? (int) $pipeline_current['oldest_order_days'] : 0;

		if ( $pipeline_count < self::MIN_PIPELINE_ORDERS || $pipeline_value < self::MIN_PIPELINE_VALUE ) {
			return null;
		}

		$comparison        = isset( $orders['comparison'] ) && is_array( $orders['comparison'] ) ? $orders['comparison'] : null;
		$pipeline_previous = $comparison && isset( $comparison['pipeline'] ) && is_array( $comparison['pipeline'] ) ? $comparison['pipeline'] : array();
		$previous_value    = (float) ( $pipeline_previous['revenue'] ?? 0 );

		$growth_percent = 0.0;
		if ( $previous_value > 0 ) {
			$growth_percent = round( ( ( $pipeline_value - $previous_value ) / $previous_value ) * 100, 1 );
		} elseif ( $pipeline_value > self::MIN_PIPELINE_VALUE ) {
			$growth_percent = 100.0;
		}

		$age_signal = $oldest_days >= self::HIGH_SEVERITY_OLDEST_DAYS;

		if ( ! $age_signal && $growth_percent < self::GROWTH_PERCENT_THRESHOLD ) {
			return null;
		}

		$severity = ( $growth_percent >= self::HIGH_SEVERITY_GROWTH || $age_signal ) ? 'high' : 'medium';

		return array(
			'slug'          => $this->slug(),
			'severity'      => $severity,
			'workflow_slug' => $this->workflow_slug(),
			'title'         => sprintf(
				/* translators: 1: pipeline order count, 2: signed percent growth. */
				_n(
					'%1$d on-hold order — pipeline up %2$s vs last week',
					'%1$d on-hold orders — pipeline up %2$s vs last week',
					$pipeline_count,
					'hey-woo'
				),
				$pipeline_count,
				self::format_percent( $growth_percent )
			),
			'raw_evidence'  => array(
				'period'                  => $orders['period'] ?? array(),
				'previous_period'         => $comparison['period'] ?? array(),
				'currency'                => $orders['currency'] ?? '',
				'pipeline_value_current'  => $pipeline_value,
				'pipeline_value_previous' => $previous_value,
				'pipeline_growth_percent' => $growth_percent,
				'pipeline_orders_current' => $pipeline_count,
				'pipeline_oldest_days'    => $oldest_days,
				'pipeline_age_buckets'    => is_array( $pipeline_current['age_buckets'] ?? null ) ? $pipeline_current['age_buckets'] : array(),
				'pipeline_payments'       => is_array( $pipeline_current['payment_methods'] ?? null ) ? $pipeline_current['payment_methods'] : array(),
			),
		);
	}

	/**
	 * Format a percent growth value with a sign and trimmed precision.
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
