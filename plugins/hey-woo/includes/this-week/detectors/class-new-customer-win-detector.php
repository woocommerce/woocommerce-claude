<?php
/**
 * New-customer-win detector.
 *
 * Fires when the last 7 days brought materially more first-time customers
 * than the previous 7-day window. Surfaces acquisition momentum so the
 * merchant sees positive signals alongside risk-focused detectors.
 *
 * @package WooCommerce\HeyWoo\ThisWeek\Detectors
 */

namespace WooCommerce\HeyWoo\ThisWeek\Detectors;

defined( 'ABSPATH' ) || exit;

/**
 * Detect week-over-week new-customer wins worth surfacing.
 */
class NewCustomerWinDetector implements SignalDetectorInterface {

	/**
	 * Percent gain threshold against the previous period.
	 */
	const GAIN_PERCENT_THRESHOLD = 25.0;

	/**
	 * Minimum current-period new-customer count (small-sample guard).
	 */
	const MIN_NEW_CUSTOMERS = 5;

	/**
	 * Percent gain that bumps the signal to a higher-severity celebration.
	 */
	const HIGH_GAIN_THRESHOLD = 75.0;

	/**
	 * {@inheritDoc}
	 */
	public function slug() {
		return 'new-customer-win';
	}

	/**
	 * {@inheritDoc}
	 */
	public function workflow_slug() {
		return 'customer-acquisition-review';
	}

	/**
	 * {@inheritDoc}
	 */
	public function detect() {
		$customers = AbilityRunner::call(
			'wc-analytics/totals',
			array(
				'subject' => 'customers',
				'period'  => 'last_7_days',
				'compare' => true,
			)
		);

		if ( ! is_array( $customers ) ) {
			return null;
		}

		$current_new    = (int) ( $customers['metrics']['new_customers'] ?? 0 );
		$comparison     = isset( $customers['comparison'] ) && is_array( $customers['comparison'] ) ? $customers['comparison'] : null;
		$previous_new   = (int) ( $comparison['metrics']['new_customers'] ?? 0 );
		$change_percent = (float) ( $comparison['changes']['new_customers']['percent'] ?? 0 );
		$change_amount  = (float) ( $comparison['changes']['new_customers']['amount'] ?? 0 );

		if ( $current_new < self::MIN_NEW_CUSTOMERS ) {
			return null;
		}

		if ( $previous_new < 1 ) {
			// Can't compute a meaningful percentage from a zero baseline; bail.
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
				/* translators: 1: current new-customer count, 2: signed percent change. */
				_n(
					'%1$d new customer — %2$s vs last week',
					'%1$d new customers — %2$s vs last week',
					$current_new,
					'hey-woo'
				),
				$current_new,
				self::format_percent( $change_percent )
			),
			'raw_evidence'  => array(
				'period'              => $customers['period'] ?? array(),
				'previous_period'     => $comparison['period'] ?? array(),
				'current_new'         => $current_new,
				'previous_new'        => $previous_new,
				'change_percent'      => $change_percent,
				'change_amount'       => $change_amount,
				'current_total'       => (int) ( $customers['metrics']['total_customers'] ?? 0 ),
				'returning_customers' => (int) ( $customers['metrics']['returning_customers'] ?? 0 ),
			),
		);
	}

	/**
	 * Format a percent figure with a leading sign.
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
