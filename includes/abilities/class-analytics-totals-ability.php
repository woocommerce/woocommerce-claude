<?php
/**
 * `wc-analytics/totals` ability.
 *
 * Verb-shaped aggregate router for headline analytics totals.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;
use WooCommerce\Claude\Telemetry\SkillTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the analytics totals ability.
 */
class AnalyticsTotalsAbility {

	const ABILITY_NAME = 'wc-analytics/totals';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get analytics totals', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Placeholder heredoc; full verb-tool narrative follows in a later branch commit.
				'description'         => __(
					<<<'DESCRIPTION'
TOTALS - placeholder description. The full narrative (per-subject metrics-block reference, three-views framing for revenue/orders/customers/tax, customer_value two-frame distinction, refund period-semantics) will be filled in by a later commit on this branch. For now, read this as: returns the headline-aggregate payload for one of six subjects via the underlying fetch_X methods.
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
				'subject'    => array(
					'type' => 'string',
					'enum' => array(
						'revenue',
						'orders',
						'customers',
						'customer_value',
						'tax',
						'refunds',
					),
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
	 * Run the aggregate subject through the matching analytics fetch method.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error Response payload.
	 */
	public static function execute( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$subject    = isset( $input['subject'] ) ? (string) $input['subject'] : '';
		$period     = isset( $input['period'] ) ? (string) $input['period'] : 'last_30_days';
		$date_start = $input['date_start'] ?? null;
		$date_end   = $input['date_end'] ?? null;
		$compare    = array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true;

		$dates = AnalyticsController::resolve_dates( $period, $date_start, $date_end );

		$gate_result = LargeRangeGate::check_run( $dates['start'], $dates['end'], $subject );
		if ( is_wp_error( $gate_result ) ) {
			return $gate_result;
		}
		$series_cap = $gate_result;

		SkillTelemetry::suppress_dispatch();
		$start_ms = microtime( true );
		try {
			$result = self::dispatch( $subject, $period, $date_start, $date_end, $compare, $series_cap );
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
				'shape'         => 'aggregate',
				'duration_ms'   => (int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				'cache_hit'     => null,
				'rows_returned' => 1,
				'date_start'    => $dates['start'],
				'date_end'      => $dates['end'],
				'interval'      => null,
				'bucket_count'  => null,
			)
		);

		return array_merge( array( 'subject' => $subject ), $result );
	}

	/**
	 * Dispatch to the matching aggregate analytics method.
	 *
	 * @param string $subject    Analytics subject slug.
	 * @param string $period     Period shortcut.
	 * @param string $date_start Custom start date (YYYY-MM-DD), or null.
	 * @param string $date_end   Custom end date (YYYY-MM-DD), or null.
	 * @param bool   $compare    Include previous-period comparison.
	 * @param int    $series_cap Series cap from the session gate check.
	 * @return array|\WP_Error Response payload.
	 */
	private static function dispatch( $subject, $period, $date_start, $date_end, $compare, $series_cap ) {
		switch ( $subject ) {
			case 'revenue':
				return AnalyticsController::fetch_revenue_summary( $period, $date_start, $date_end, $compare );

			case 'orders':
				return AnalyticsController::fetch_orders_summary( $period, $date_start, $date_end, $compare );

			case 'customers':
				return AnalyticsController::fetch_customer_overview(
					$period,
					$date_start,
					$date_end,
					$compare,
					'',
					null,
					$series_cap
				);

			case 'customer_value':
				return AnalyticsController::fetch_customer_value( $period, $date_start, $date_end, $compare, 10, true );

			case 'tax':
				return AnalyticsController::fetch_tax_summary( $period, $date_start, $date_end, $compare, 10, 'total_tax' );

			case 'refunds':
				return AnalyticsController::fetch_refund_analysis( $period, $date_start, $date_end, $compare, 'none', 10, true );

			default:
				return new \WP_Error(
					'invalid_totals_subject',
					"Unknown analytics totals subject: {$subject}.",
					array( 'status' => 400 )
				);
		}
	}
}
