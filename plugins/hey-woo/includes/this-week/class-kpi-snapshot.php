<?php
/**
 * Last-7-days KPI snapshot used by the Today home surface.
 *
 * The Today page shows a 4-cell metric tape (revenue, orders, AOV, new
 * customers) with the week-over-week change next to each value. This is
 * pure server-side analytics — no AI involved — so the merchant always
 * sees the latest numbers regardless of whether the runner has fired
 * recently. The runner remains responsible for the signal feed below
 * the tape.
 *
 * @package WooCommerce\HeyWoo\ThisWeek
 */

namespace WooCommerce\HeyWoo\ThisWeek;

use WooCommerce\HeyWoo\ThisWeek\Detectors\AbilityRunner;

defined( 'ABSPATH' ) || exit;

/**
 * Compose the four-cell KPI snapshot from analytics totals.
 */
class KpiSnapshot {

	/**
	 * Return the snapshot as an ordered array of KPI cells.
	 *
	 * Each cell is shape:
	 *   id (string), label (string), value (string), value_raw (float|int),
	 *   currency (string|null), change_percent (float), change_direction
	 *   ('up'|'down'|'flat'), tone ('positive'|'negative'|'neutral')
	 *
	 * `tone` reflects how the merchant should read the change for that
	 * specific KPI — a refund increase is "negative" while an orders
	 * increase is "positive". Today's mockup uses only revenue/orders/aov/
	 * new-customers, where up is good for all four.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function build() {
		$revenue   = AbilityRunner::call(
			'wc-analytics/totals',
			array(
				'subject' => 'revenue',
				'period'  => 'last_7_days',
				'compare' => true,
			)
		);
		$orders    = AbilityRunner::call(
			'wc-analytics/totals',
			array(
				'subject' => 'orders',
				'period'  => 'last_7_days',
				'compare' => true,
			)
		);
		$customers = AbilityRunner::call(
			'wc-analytics/totals',
			array(
				'subject' => 'customers',
				'period'  => 'last_7_days',
				'compare' => true,
			)
		);

		$currency = is_array( $revenue ) && isset( $revenue['currency'] ) ? (string) $revenue['currency'] : get_woocommerce_currency();

		return array(
			self::revenue_cell( $revenue, $currency ),
			self::orders_cell( $orders ),
			self::aov_cell( $revenue, $currency ),
			self::new_customers_cell( $customers ),
		);
	}

	/**
	 * Revenue cell — net sales in store currency.
	 *
	 * @param array<string,mixed>|null $revenue  Analytics totals response.
	 * @param string                   $currency Store currency code.
	 * @return array<string,mixed>
	 */
	private static function revenue_cell( $revenue, $currency ) {
		$value          = is_array( $revenue ) ? (float) ( $revenue['metrics']['net_sales'] ?? 0 ) : 0.0;
		$change_percent = is_array( $revenue ) ? (float) ( $revenue['comparison']['changes']['net_sales']['percent'] ?? 0 ) : 0.0;

		return array(
			'id'               => 'revenue',
			'label'            => __( 'Revenue', 'hey-woo' ),
			'value'            => self::format_currency( $value, $currency ),
			'value_raw'        => $value,
			'currency'         => $currency,
			'change_percent'   => $change_percent,
			'change_direction' => self::direction( $change_percent ),
			'tone'             => self::tone_for_change( $change_percent, false ),
		);
	}

	/**
	 * Orders cell — paid order count.
	 *
	 * @param array<string,mixed>|null $orders Analytics totals response.
	 * @return array<string,mixed>
	 */
	private static function orders_cell( $orders ) {
		$value          = is_array( $orders ) ? (int) ( $orders['metrics']['orders_count'] ?? 0 ) : 0;
		$change_percent = is_array( $orders ) ? (float) ( $orders['comparison']['changes']['orders_count']['percent'] ?? 0 ) : 0.0;

		return array(
			'id'               => 'orders',
			'label'            => __( 'Orders', 'hey-woo' ),
			'value'            => self::format_count( $value ),
			'value_raw'        => $value,
			'currency'         => null,
			'change_percent'   => $change_percent,
			'change_direction' => self::direction( $change_percent ),
			'tone'             => self::tone_for_change( $change_percent, false ),
		);
	}

	/**
	 * AOV cell — average paid order value, derived from the revenue response.
	 *
	 * @param array<string,mixed>|null $revenue  Analytics totals response.
	 * @param string                   $currency Store currency code.
	 * @return array<string,mixed>
	 */
	private static function aov_cell( $revenue, $currency ) {
		$value          = is_array( $revenue ) ? (float) ( $revenue['metrics']['average_order_value'] ?? 0 ) : 0.0;
		$change_percent = is_array( $revenue ) ? (float) ( $revenue['comparison']['changes']['average_order_value']['percent'] ?? 0 ) : 0.0;

		return array(
			'id'               => 'aov',
			'label'            => __( 'AOV', 'hey-woo' ),
			'value'            => self::format_currency( $value, $currency ),
			'value_raw'        => $value,
			'currency'         => $currency,
			'change_percent'   => $change_percent,
			'change_direction' => self::direction( $change_percent ),
			'tone'             => self::tone_for_change( $change_percent, false ),
		);
	}

	/**
	 * New-customers cell.
	 *
	 * @param array<string,mixed>|null $customers Analytics totals response.
	 * @return array<string,mixed>
	 */
	private static function new_customers_cell( $customers ) {
		$value          = is_array( $customers ) ? (int) ( $customers['metrics']['new_customers'] ?? 0 ) : 0;
		$change_percent = is_array( $customers ) ? (float) ( $customers['comparison']['changes']['new_customers']['percent'] ?? 0 ) : 0.0;

		return array(
			'id'               => 'new_customers',
			'label'            => __( 'New customers', 'hey-woo' ),
			'value'            => self::format_count( $value ),
			'value_raw'        => $value,
			'currency'         => null,
			'change_percent'   => $change_percent,
			'change_direction' => self::direction( $change_percent ),
			'tone'             => self::tone_for_change( $change_percent, false ),
		);
	}

	/**
	 * Return the analytics period (date range + label) attached to the cells.
	 *
	 * Used by the frontend to show "Last 7 days: 14 May – 21 May" above the
	 * tape. Reads the period from the revenue call so the timestamps come
	 * from the same source the merchant sees in WC Analytics.
	 *
	 * @return array<string,string>
	 */
	public static function period() {
		$revenue = AbilityRunner::call(
			'wc-analytics/totals',
			array(
				'subject' => 'revenue',
				'period'  => 'last_7_days',
				'compare' => true,
			)
		);

		if ( ! is_array( $revenue ) || ! isset( $revenue['period'] ) || ! is_array( $revenue['period'] ) ) {
			return array();
		}

		return array(
			'start' => isset( $revenue['period']['start'] ) ? (string) $revenue['period']['start'] : '',
			'end'   => isset( $revenue['period']['end'] ) ? (string) $revenue['period']['end'] : '',
			'label' => isset( $revenue['period']['label'] ) ? (string) $revenue['period']['label'] : '',
		);
	}

	/**
	 * Format a currency amount for human-readable display.
	 *
	 * Values >= 10,000 collapse to "$10k" / "$1.2m" to keep the tape compact.
	 *
	 * @param float  $amount   Amount in store currency.
	 * @param string $currency ISO currency code.
	 * @return string
	 */
	private static function format_currency( $amount, $currency ) {
		$symbol = function_exists( 'get_woocommerce_currency_symbol' )
			? html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' )
			: '';

		$abs = abs( $amount );
		if ( $abs >= 1_000_000 ) {
			return $symbol . self::trim_decimal( $amount / 1_000_000 ) . 'm';
		}
		if ( $abs >= 10_000 ) {
			return $symbol . self::trim_decimal( $amount / 1_000 ) . 'k';
		}
		if ( $abs >= 1_000 ) {
			return $symbol . number_format( $amount, 0 );
		}

		return $symbol . number_format( $amount, 2 );
	}

	/**
	 * Format an integer count, collapsing thousands above 10k.
	 *
	 * @param int $count Count value.
	 * @return string
	 */
	private static function format_count( $count ) {
		$abs = abs( $count );
		if ( $abs >= 1_000_000 ) {
			return self::trim_decimal( $count / 1_000_000 ) . 'm';
		}
		if ( $abs >= 10_000 ) {
			return self::trim_decimal( $count / 1_000 ) . 'k';
		}

		return number_format( $count );
	}

	/**
	 * Trim trailing zeros from a decimal compact-number string.
	 *
	 * @param float $value Number to format.
	 * @return string
	 */
	private static function trim_decimal( $value ) {
		$formatted = number_format( $value, 1, '.', '' );
		return rtrim( rtrim( $formatted, '0' ), '.' );
	}

	/**
	 * Map a percent change to the up/down/flat direction tokens.
	 *
	 * @param float $change_percent Percent change.
	 * @return string
	 */
	private static function direction( $change_percent ) {
		if ( $change_percent > 0 ) {
			return 'up';
		}
		if ( $change_percent < 0 ) {
			return 'down';
		}
		return 'flat';
	}

	/**
	 * Map a percent change to a tone token for the merchant-facing colour.
	 *
	 * `$invert` should be true for metrics where up is bad (refunds, on-hold
	 * pipeline) — none of Today's four KPIs invert, but the helper supports
	 * it so the tape can extend later.
	 *
	 * @param float $change_percent Percent change.
	 * @param bool  $invert         When true, up = negative, down = positive.
	 * @return string
	 */
	private static function tone_for_change( $change_percent, $invert ) {
		if ( 0.0 === (float) $change_percent ) {
			return 'neutral';
		}

		$up = $change_percent > 0;
		if ( $invert ) {
			return $up ? 'negative' : 'positive';
		}

		return $up ? 'positive' : 'negative';
	}
}
