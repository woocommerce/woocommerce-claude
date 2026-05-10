<?php
/**
 * `wc-analytics/series` ability.
 *
 * Verb-shaped aggregate router for time-series analytics.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;
use WooCommerce\Claude\Telemetry\SkillTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the analytics series ability.
 */
class AnalyticsSeriesAbility {

	const ABILITY_NAME = 'wc-analytics/series';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get analytics series', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Placeholder heredoc; full verb-tool narrative follows in a later branch commit.
				'description'         => __(
					<<<'DESCRIPTION'
SERIES — placeholder description. The full narrative (interval=auto rules, series_cap and series_range_days framing, when to use day vs week vs month, how to read per-bucket trends, products-coverage signal at series scale) will be filled in by a later commit on this branch. For now, read this as: returns time-series for either customers (active-base counts and revenue per bucket) or products (top-N products with per-product per-bucket series).
DESCRIPTION,
					'woocommerce-claude'
				),
				// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission gate. Aggregated reads only — same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * JSON Schema for the ability input.
	 *
	 * @return array
	 */
	private static function input_schema() {
		return array(
			'type'       => 'object',
			'required'   => array( 'subject', 'interval' ),
			'properties' => array(
				'subject'    => array(
					'type'        => 'string',
					'enum'        => array( 'customers', 'products' ),
					'description' => "Which subject's time series to return.",
				),
				'interval'   => array(
					'type'        => 'string',
					'enum'        => array( 'day', 'week', 'month', 'auto' ),
					'description' => "Bucket size for the time series. 'auto' picks day/week/month based on range length (≤90 days → day; ≤730 → week; otherwise month).",
				),
				'group_by'   => array(
					'type'        => 'string',
					'enum'        => array( 'product', 'variation' ),
					'default'     => 'product',
					'description' => 'Products subject only — row grouping. Ignored for customers.',
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Products subject only — top-N products to series. Ignored for customers.',
				),
				'orderby'    => array(
					'type'        => 'string',
					'default'     => 'net_revenue',
					'description' => 'Products subject only — ordering field for the top-N. Ignored for customers.',
				),
				'period'     => array(
					'type'    => 'string',
					'enum'    => array(
						'today',
						'yesterday',
						'last_7_days',
						'last_30_days',
						'this_month',
						'last_month',
						'this_quarter',
						'this_year',
						'custom',
					),
					'default' => 'last_30_days',
				),
				'date_start' => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'date_end'   => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'compare'    => array(
					'type'    => 'boolean',
					'default' => true,
				),
			),
		);
	}

	/**
	 * JSON Schema for the ability output.
	 *
	 * @return array
	 */
	private static function output_schema() {
		return array(
			'type' => 'object',
		);
	}

	/**
	 * Run the time-series subject through the matching analytics fetch method.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error Response payload.
	 */
	public static function execute( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$subject    = isset( $input['subject'] ) ? (string) $input['subject'] : '';
		$interval   = isset( $input['interval'] ) ? (string) $input['interval'] : '';
		$group_by   = isset( $input['group_by'] ) ? (string) $input['group_by'] : 'product';
		$limit      = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$orderby    = isset( $input['orderby'] ) ? (string) $input['orderby'] : 'net_revenue';
		$period     = isset( $input['period'] ) ? (string) $input['period'] : 'last_30_days';
		$date_start = $input['date_start'] ?? null;
		$date_end   = $input['date_end'] ?? null;
		$compare    = array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true;
		if ( ! in_array( $subject, array( 'customers', 'products' ), true ) ) {
			return new \WP_Error(
				'invalid_series_subject',
				"Unknown series subject: {$subject}.",
				array( 'status' => 400 )
			);
		}
		$dates       = AnalyticsController::resolve_dates( $period, $date_start, $date_end );
		$gate_result = LargeRangeGate::check_run( $dates['start'], $dates['end'], $subject );
		if ( is_wp_error( $gate_result ) ) {
			return $gate_result;
		}
		$series_cap = $gate_result;
		SkillTelemetry::suppress_dispatch();
		$start_ms = microtime( true );
		try {
			$result = self::dispatch(
				$subject,
				$interval,
				$period,
				$date_start,
				$date_end,
				$compare,
				$limit,
				$orderby,
				$group_by,
				$series_cap
			);
		} finally {
			SkillTelemetry::resume_dispatch();
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		/**
		 * This action is documented in class-analytics-controller.php::fetch_revenue_summary().
		 * Verb-shaped tools add tool, subject, and shape metadata for telemetry.
		 *
		 * @since 0.1.0
		 */
		do_action(
			'woocommerce_claude_skill_executed',
			self::ABILITY_NAME,
			array(
				'tool'          => self::ABILITY_NAME,
				'subject'       => $subject,
				'shape'         => 'series',
				'duration_ms'   => (int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				'cache_hit'     => null,
				'rows_returned' => self::row_count_from( $result ),
				'date_start'    => $dates['start'],
				'date_end'      => $dates['end'],
				'interval'      => $interval,
				'bucket_count'  => AnalyticsController::calculate_bucket_count( $interval, $dates['start'], $dates['end'] ),
			)
		);

		return array_merge(
			array(
				'subject'  => $subject,
				'interval' => $interval,
			),
			$result
		);
	}

	/**
	 * Count the primary series rows returned by the delegated analytics method.
	 *
	 * @param array $result Response payload.
	 * @return int
	 */
	private static function row_count_from( $result ) {
		if ( isset( $result['top_products'] ) && is_array( $result['top_products'] ) ) {
			return count( $result['top_products'] );
		}

		if ( isset( $result['series'] ) && is_array( $result['series'] ) ) {
			return count( $result['series'] );
		}

		return 1;
	}

	/**
	 * Dispatch to the matching time-series analytics method.
	 *
	 * @param string $subject    Analytics subject slug.
	 * @param string $interval   Time-series bucket interval.
	 * @param string $period     Period shortcut.
	 * @param string $date_start Custom start date (YYYY-MM-DD), or null.
	 * @param string $date_end   Custom end date (YYYY-MM-DD), or null.
	 * @param bool   $compare    Include previous-period comparison.
	 * @param int    $limit      Top N products to return.
	 * @param string $orderby    Sort column.
	 * @param string $group_by   Product grouping dimension.
	 * @param int    $series_cap Series cap from the session gate check.
	 * @return array|\WP_Error Response payload.
	 */
	private static function dispatch( $subject, $interval, $period, $date_start, $date_end, $compare, $limit, $orderby, $group_by, $series_cap ) {
		switch ( $subject ) {
			case 'customers':
				return AnalyticsController::fetch_customer_overview(
					$period,
					$date_start,
					$date_end,
					$compare,
					$interval,
					null,
					$series_cap
				);

			case 'products':
				return AnalyticsController::fetch_product_performance(
					$period,
					$date_start,
					$date_end,
					$compare,
					$limit,
					$orderby,
					$group_by,
					$interval,
					null,
					$series_cap
				);

			default:
				return new \WP_Error(
					'invalid_series_subject',
					"Unknown series subject: {$subject}.",
					array( 'status' => 400 )
				);
		}
	}
}
