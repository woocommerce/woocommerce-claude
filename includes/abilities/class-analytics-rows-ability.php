<?php
/**
 * `wc-analytics/rows` ability.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;
use WooCommerce\Claude\Telemetry\SkillTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the analytics rows ability.
 */
class AnalyticsRowsAbility {
	const ABILITY_NAME = 'wc-analytics/rows';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get analytics rows', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Placeholder heredoc; full verb-tool narrative follows in a later branch commit.
				'description'         => __(
					<<<'DESCRIPTION'
ROWS — placeholder description. The full narrative (entity registry, filter operators, match semantics, mode=aggregate vs mode=rows shape, customer-row pseudonymisation, admin_url linking, no-three-views distinction, what-this-can't-answer, narrative-language-discipline) will be filled in by a later commit on this branch. For now, treat this as the same surface as the existing wc-analytics/query-analytics tool — same inputs, same outputs.
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
					// phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Compact metadata keeps this thin wrapper in the target line range.
					'annotations'  => array( 'readonly' => true, 'idempotent' => true ),
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

	// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- Mirrors query-analytics schema values in a compact wrapper.
	/**
	 * JSON Schema for the ability input.
	 *
	 * @return array
	 */
	private static function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'entity'     => array( 'type' => 'string', 'enum' => array( 'orders', 'products', 'customers' ), 'default' => 'orders', 'description' => 'Which entity to filter. Each has its own field registry — see the full list in the tool description.' ),
				'filters'    => array( 'type' => 'array', 'default' => array(), 'description' => 'Filter specs. Each is {field, operator, value}. Field must exist in the entity registry; operator must be allowed for the field type. Value shape varies by operator — scalar for is/greater_than/etc., array for is_in/between. Full validation happens inside the ability; the schema is intentionally loose so mixed value types land as-is.' ),
				'match'      => array( 'type' => 'string', 'enum' => array( 'all', 'any' ), 'default' => 'all', 'description' => 'all = AND across filters, any = OR. Default all.' ),
				'period'     => array( 'type' => 'string', 'enum' => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ), 'default' => 'last_30_days', 'description' => 'Time window. Custom date_start/date_end overrides this.' ),
				'date_start' => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$', 'description' => 'Custom start date (YYYY-MM-DD).' ),
				'date_end'   => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$', 'description' => 'Custom end date (YYYY-MM-DD).' ),
				'mode'       => array( 'type' => 'string', 'enum' => array( 'aggregate', 'rows' ), 'default' => 'aggregate', 'description' => 'aggregate = summary counts / sums (default); rows = top-N row list (customer identities are always pseudonymised — real names / emails are never returned).' ),
				'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 25, 'description' => 'Row cap for rows mode (ignored in aggregate mode).' ),
				'orderby'    => array( 'type' => 'string', 'description' => 'Column to sort rows by. Must be a filterable field on the chosen entity; each entity has its own default. Ignored in aggregate mode.' ),
				'order'      => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'default' => 'DESC', 'description' => 'Sort direction for rows mode.' ),
			),
		);
	}
	// phpcs:enable WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

	/**
	 * JSON Schema for the ability output.
	 *
	 * Response shape varies by entity + mode; the envelope is stable.
	 *
	 * @return array
	 */
	private static function output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period'             => array( 'type' => 'object' ),
				'entity'             => array( 'type' => 'string' ),
				'mode'               => array( 'type' => 'string' ),
				'match'              => array( 'type' => 'string' ),
				'filters_applied'    => array( 'type' => 'array' ),
				'currency'           => array( 'type' => 'string' ),
				'summary'            => array( 'oneOf' => array( array( 'type' => 'object' ), array( 'type' => 'null' ) ) ),
				'rows'               => array( 'oneOf' => array( array( 'type' => 'array' ), array( 'type' => 'null' ) ) ),
				'universe'           => array( 'type' => 'object' ),
				'share_of_universe'  => array( 'type' => 'object' ),
				'pipeline'           => array( 'oneOf' => array( array( 'type' => 'object' ), array( 'type' => 'null' ) ) ),
				'admin_equivalent'   => array( 'oneOf' => array( array( 'type' => 'object' ), array( 'type' => 'null' ) ) ),
				'sample_size_caveat' => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ) ),
				'privacy_mode'       => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ) ),
				'note'               => array( 'oneOf' => array( array( 'type' => 'string' ), array( 'type' => 'null' ) ) ),
			),
		);
	}

	/**
	 * Run the ability — delegates to AnalyticsController::fetch_query_analytics().
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error Response payload, or WP_Error on invalid filters.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		$entity     = isset( $input['entity'] ) ? (string) $input['entity'] : '';
		$filters    = is_array( $input['filters'] ?? null ) ? $input['filters'] : array();
		$match      = isset( $input['match'] ) ? (string) $input['match'] : 'all';
		$period     = isset( $input['period'] ) ? (string) $input['period'] : 'last_30_days';
		$date_start = $input['date_start'] ?? null;
		$date_end   = $input['date_end'] ?? null;
		$mode       = isset( $input['mode'] ) ? (string) $input['mode'] : 'aggregate';
		$limit      = isset( $input['limit'] ) ? (int) $input['limit'] : 25;
		$orderby    = isset( $input['orderby'] ) ? (string) $input['orderby'] : '';
		$order      = isset( $input['order'] ) ? (string) $input['order'] : 'DESC';

		SkillTelemetry::suppress_dispatch();
		$start_ms = microtime( true );

		try {
			$result = AnalyticsController::fetch_query_analytics(
				$entity,
				$filters,
				$match,
				$period,
				$date_start,
				$date_end,
				$mode,
				$limit,
				$orderby,
				$order
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
				'subject'       => $entity,
				'shape'         => $mode,
				'duration_ms'   => (int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				'cache_hit'     => null,
				'rows_returned' => self::row_count_from( $result, $mode ),
				'date_start'    => $result['period']['start'] ?? null,
				'date_end'      => $result['period']['end'] ?? null,
				'interval'      => null,
				'bucket_count'  => null,
			)
		);

		return $result;
	}

	/**
	 * Count rows returned by the delegated analytics method.
	 *
	 * @param array  $result Response payload.
	 * @param string $mode   Response mode.
	 * @return int
	 */
	private static function row_count_from( $result, $mode ) {
		if ( 'rows' === $mode ) {
			return is_array( $result['rows'] ?? null ) ? count( $result['rows'] ) : 0;
		}
		return 1;
	}
}
