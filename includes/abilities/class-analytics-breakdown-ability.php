<?php
/**
 * `wc-analytics/breakdown` ability.
 *
 * Verb-shaped aggregate router for grouped analytics breakdowns.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\Abilities\LargeRangeGate;
use WooCommerce\Claude\API\AnalyticsController;
use WooCommerce\Claude\Telemetry\SkillTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the analytics breakdown ability.
 */
class AnalyticsBreakdownAbility {

	const ABILITY_NAME = 'wc-analytics/breakdown';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get analytics breakdown', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Placeholder heredoc; full verb-tool narrative follows in a later branch commit.
				'description'         => __(
					<<<'DESCRIPTION'
BREAKDOWN — placeholder description. The full narrative (per-subject dimension reference, three-views-per-row framing, coverage / (Unassigned) framing for revenue and attribution, denominator-picking for revenue, the over-index narrative for attribution, products coverage, refund period semantics, tax effective-rate framing, coupon effective-campaign-cost framing) will be filled in by a later commit on this branch. For now, read this as: returns the broken-down-by-X view of one of six subjects via the underlying fetch_X methods.
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
			'required'   => array( 'subject' ),
			'properties' => array(
				'subject'            => array(
					'type' => 'string',
					'enum' => array(
						'revenue',
						'attribution',
						'products',
						'refunds',
						'tax',
						'coupons',
					),
				),
				'dimension'          => array(
					'type' => 'string',
					'enum' => array(
						'category',
						'country',
						'payment_method',
						'shipping_method',
						'channel',
						'source',
						'medium',
						'campaign',
						'term',
						'content',
						'device',
						'channel_source',
						'product',
						'variation',
						'rate',
						'code',
					),
				),
				'limit'              => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 10,
				),
				'orderby'            => array(
					'type' => 'string',
				),
				'include_unassigned' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'period'             => array(
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
				'date_start'         => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'date_end'           => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'compare'            => array(
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
	 * Run the grouped subject through the matching analytics fetch method.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error Response payload.
	 */
	public static function execute( $input ) {
		$input              = is_array( $input ) ? $input : array();
		$subject            = isset( $input['subject'] ) ? (string) $input['subject'] : '';
		$dimension          = isset( $input['dimension'] ) ? (string) $input['dimension'] : self::default_dimension_for( $subject );
		$period             = isset( $input['period'] ) ? (string) $input['period'] : 'last_30_days';
		$date_start         = $input['date_start'] ?? null;
		$date_end           = $input['date_end'] ?? null;
		$compare            = array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true;
		$limit              = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$orderby            = isset( $input['orderby'] ) ? (string) $input['orderby'] : self::default_orderby_for( $subject );
		$include_unassigned = array_key_exists( 'include_unassigned', $input )
			? rest_sanitize_boolean( $input['include_unassigned'] )
			: true;
		$dimension_check    = self::validate_dimension( $subject, $dimension );
		if ( is_wp_error( $dimension_check ) ) {
			return $dimension_check;
		}
		$dates = AnalyticsController::resolve_dates( $period, $date_start, $date_end );
		$gate  = LargeRangeGate::check_run( $dates['start'], $dates['end'], $subject );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$series_cap = $gate;
		SkillTelemetry::suppress_dispatch();
		$start_ms = microtime( true );
		try {
				$result = self::dispatch( $subject, $dimension, $period, $date_start, $date_end, $compare, $limit, $orderby, $include_unassigned, $series_cap );
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
				'shape'         => 'groups',
				'duration_ms'   => (int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				'cache_hit'     => null,
				'rows_returned' => self::row_count_from( $result ),
				'date_start'    => $dates['start'],
				'date_end'      => $dates['end'],
				'interval'      => null,
				'bucket_count'  => null,
			)
		);

		return array_merge(
			array(
				'subject'   => $subject,
				'dimension' => $dimension,
			),
			$result
		);
	}

	/**
	 * Count the grouped rows returned by the delegated analytics method.
	 *
	 * @param array $result Response payload.
	 * @return int
	 */
	private static function row_count_from( $result ) {
		$rows = $result['top_groups'] ?? $result['top_products'] ?? $result['top_rates'] ?? null;

		if ( is_array( $rows ) ) {
			return count( $rows );
		}

		return 1;
	}

	/**
	 * Dispatch to the matching grouped analytics method.
	 *
	 * @param string $subject            Analytics subject slug.
	 * @param string $dimension          Grouping dimension.
	 * @param string $period             Period shortcut.
	 * @param string $date_start         Custom start date (YYYY-MM-DD), or null.
	 * @param string $date_end           Custom end date (YYYY-MM-DD), or null.
	 * @param bool   $compare            Include previous-period comparison.
	 * @param int    $limit              Top N rows to return.
	 * @param string $orderby            Sort column.
	 * @param bool   $include_unassigned Include unassigned rows where supported.
	 * @param int    $series_cap         Series cap from the session gate check.
	 * @return array|\WP_Error Response payload.
	 */
	private static function dispatch( $subject, $dimension, $period, $date_start, $date_end, $compare, $limit, $orderby, $include_unassigned, $series_cap ) {
		switch ( $subject ) {
			case 'revenue':
				return AnalyticsController::fetch_revenue_breakdown( $period, $date_start, $date_end, $compare, $limit, $orderby, $dimension, $include_unassigned );

			case 'attribution':
				return AnalyticsController::fetch_attribution( $period, $date_start, $date_end, $compare, $limit, $orderby, $dimension, $include_unassigned );

			case 'products':
				return AnalyticsController::fetch_product_performance( $period, $date_start, $date_end, $compare, $limit, $orderby, $dimension, '', null, $series_cap );

			case 'refunds':
				return AnalyticsController::fetch_refund_analysis( $period, $date_start, $date_end, $compare, $dimension, $limit, $include_unassigned );

			case 'tax':
				return AnalyticsController::fetch_tax_summary( $period, $date_start, $date_end, $compare, $limit, $orderby );

			case 'coupons':
				return AnalyticsController::fetch_coupon_performance( $period, $date_start, $date_end, $compare, $limit, $orderby );

			default:
				return new \WP_Error( 'invalid_breakdown_subject', "Unknown breakdown subject: {$subject}.", array( 'status' => 400 ) );
		}
	}

	/**
	 * Validate that the requested dimension is available for the subject.
	 *
	 * @param string $subject   Analytics subject slug.
	 * @param string $dimension Grouping dimension.
	 * @return true|\WP_Error
	 */
	private static function validate_dimension( $subject, $dimension ) {
		static $allowed = array(
			'revenue'     => array( 'category', 'country', 'payment_method', 'shipping_method' ),
			'attribution' => array( 'channel', 'source', 'medium', 'campaign', 'term', 'content', 'device', 'channel_source' ),
			'products'    => array( 'product', 'variation' ),
			'refunds'     => array( 'product', 'country' ),
			'tax'         => array( 'rate' ),
			'coupons'     => array( 'code' ),
		);

		if ( ! isset( $allowed[ $subject ] ) ) {
			return new \WP_Error( 'invalid_breakdown_subject', "Unknown breakdown subject: {$subject}.", array( 'status' => 400 ) );
		}

		if ( ! in_array( $dimension, $allowed[ $subject ], true ) ) {
			return new \WP_Error(
				'invalid_breakdown_dimension',
				sprintf(
					"Dimension '%s' is not valid for subject '%s'. Valid dimensions: %s.",
					$dimension,
					$subject,
					implode( ', ', $allowed[ $subject ] )
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Get the default grouping dimension for a subject.
	 *
	 * @param string $subject Analytics subject slug.
	 * @return string
	 */
	private static function default_dimension_for( $subject ) {
		switch ( $subject ) {
			case 'revenue':
				return 'category';
			case 'attribution':
				return 'channel';
			case 'products':
			case 'refunds':
				return 'product';
			case 'tax':
				return 'rate';
			case 'coupons':
				return 'code';
			default:
				return '';
		}
	}

	/**
	 * Get the default orderby metric for a subject.
	 *
	 * @param string $subject Analytics subject slug.
	 * @return string
	 */
	private static function default_orderby_for( $subject ) {
		switch ( $subject ) {
			case 'revenue':
			case 'attribution':
			case 'products':
				return 'net_revenue';
			case 'tax':
				return 'total_tax';
			case 'coupons':
				return 'discount_amount';
			default:
				return '';
		}
	}
}
