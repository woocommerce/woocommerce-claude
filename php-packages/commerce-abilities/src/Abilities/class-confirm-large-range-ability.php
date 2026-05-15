<?php
/**
 * `wc-analytics/confirm-large-range` ability.
 *
 * Mandatory approval checkpoint before a large date range analytics query
 * runs via wc-analytics-totals / wc-analytics-breakdown / wc-analytics-series
 * (the three verb tools that call LargeRangeGate::check_run). Takes the date
 * range and the tool-prefixed type discriminator (e.g. `totals:revenue`) —
 * no opaque token. The server matches the confirmation to the pending
 * session transient that check_run() minted when the gate fired.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the confirm-large-range ability.
 */
class ConfirmLargeRangeAbility {

	const ABILITY_NAME = 'wc-analytics/confirm-large-range';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Approve large date range query', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
Approves a pending large date range analytics query. Call this after wc-analytics-totals, wc-analytics-breakdown, or wc-analytics-series returns an extended_range_required error, and only after presenting the cost estimate to the merchant and receiving their explicit go-ahead.

The merchant needs to confirm because queries spanning more than 365 days may temporarily impact site performance while the data is being loaded. Always show the date range and estimated impact before calling this tool.

FLOW — follow every step, in order:
1. The analytics tool you called returns extended_range_required with cost_estimate.
2. STOP. Present the cost estimate to the merchant. Example: "Your request covers 24 months of data — a larger query than usual that may briefly affect site performance. Should I proceed, or would you prefer a shorter window?"
3. Wait for the merchant to explicitly say yes.
4. Call this tool with the same date_start, date_end, and the LITERAL type value from cost_estimate.type. Types are tool-prefixed (totals:revenue / breakdown:revenue / series:customers) so approvals minted by one tool cannot be consumed by another tool sharing the same subject. Pass the value verbatim — copy it directly from the error response, do not re-map. Include a description so the merchant sees a meaningful label in the approval prompt.
5. Re-run the same analytics call with the same params — the gate will pass.

ANTI-SPLITTING RULE — ABSOLUTE: Do NOT split a large date range into smaller chunks (yearly, quarterly, or monthly segments) to avoid the gate. The gate exists so the merchant can decide the scope of a large query and understand the potential performance impact. Splitting without the merchant's knowledge defeats that purpose, even when each individual segment falls within the 365-day threshold.

Bad (split to avoid gate — do not do this): Merchant asks for 3 years. I notice yearly chunks are under 365 days. I make three parallel calls for 2022, 2023, and 2024.
Good (correct): Merchant asks for 3 years. Gate fires. I present options. Merchant says yes. I call this tool. I re-run the original analytics call.

Bad (autonomous — do not do this): Gate fires. I call this tool without waiting for the merchant's reply.
Good (correct): Gate fires. I present the cost estimate and the performance note. I wait for the merchant to say yes. Then I call this tool.

The approval window is 5 minutes. If it has expired, re-run the original analytics call to mint a fresh cost estimate, present it to the merchant, and wait for their reply.

Internal identifiers (date_start, date_end, range_days) are developer vocabulary and never belong in merchant-facing responses.
DESCRIPTION,
					'woocommerce-claude'
				),
				// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText
				'category'            => AnalyticsBootstrap::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'meta'                => array(
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission gate.
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
			'required'   => array( 'date_start', 'date_end', 'type', 'description' ),
			'properties' => array(
				'date_start'  => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Start date of the query being approved (YYYY-MM-DD). Must match the date_start in the params of the analytics call that triggered the gate.',
				),
				'date_end'    => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'End date of the query being approved (YYYY-MM-DD). Must match the date_end in the params of the analytics call that triggered the gate.',
				),
				'type'        => array(
					'type'        => 'string',
					'description' => 'The literal type discriminator from the failing call. Read this directly from the error response: cost_estimate.type carries the exact string to pass. Verb-tool failures emit a tool-prefixed value (totals:revenue, breakdown:revenue, series:customers, etc.) so approvals minted by one tool cannot be consumed by another tool sharing the same subject. The schema accepts any string; mismatches surface as no_pending_query so a careful copy from cost_estimate.type is always the right move.',
				),
				'description' => array(
					'type'        => 'string',
					'description' => 'Human-readable summary of the query being approved (e.g. "3-year customer overview, monthly granularity"). Shown in the approval prompt.',
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
			'type'       => 'object',
			'properties' => array(
				'confirmed' => array( 'type' => 'boolean' ),
				'message'   => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Approve the pending session scan.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error
	 */
	public static function execute( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$date_start = isset( $input['date_start'] ) ? (string) $input['date_start'] : '';
		$date_end   = isset( $input['date_end'] ) ? (string) $input['date_end'] : '';
		$type       = isset( $input['type'] ) ? (string) $input['type'] : '';

		if ( empty( $date_start ) || empty( $date_end ) ) {
			return new \WP_Error(
				'missing_date_range',
				'date_start and date_end are required.',
				array( 'status' => 400 )
			);
		}

		if ( ! LargeRangeGate::approve_scan( $date_start, $date_end, $type ) ) {
			return new \WP_Error(
				'no_pending_query',
				'No pending query found for this date range and type — the approval window may have expired (5 minutes) or the type value does not match what the failing call passed to the gate. Re-run the original analytics call to mint a fresh cost estimate, copy cost_estimate.type from the error verbatim, present the estimate to the merchant, and wait for their reply.',
				array( 'status' => 400 )
			);
		}

		return array(
			'confirmed' => true,
			'message'   => 'Query approved. Re-run the original analytics call with the same params to retrieve the data.',
		);
	}
}
