<?php
/**
 * Large-range query gate.
 *
 * When a request spans more than GATE_THRESHOLD_DAYS (365) days this class
 * mints a one-use transient-backed token and returns a WP_Error asking the
 * caller to stop, present the cost estimate to the merchant, and re-call with
 * the token only after the merchant explicitly confirms.
 *
 * The token is unguessable (random) and validated server-side, so the gate
 * cannot be bypassed by description-level rules alone — the second call must
 * carry the exact token value that was returned in the first response.
 *
 * Usage:
 *   $gate = LargeRangeGate::check( $dates['start'], $dates['end'], $token );
 *   if ( is_wp_error( $gate ) ) {
 *       return $gate;  // stop; merchant confirmation required
 *   }
 *   $series_cap = $gate;  // int — use as SQL LIMIT for time-series queries
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable large-range query gate for analytics abilities.
 */
class LargeRangeGate {

	/**
	 * Date-range span (days, inclusive) above which the gate fires.
	 * Matches AnalyticsService::TIMESERIES_MAX_BUCKETS (365) so both
	 * caps align by default, but they are independent constants that can
	 * diverge if needed.
	 */
	const GATE_THRESHOLD_DAYS = 365;

	/**
	 * How long a minted token stays valid (seconds).
	 */
	const TOKEN_TTL_SECONDS = 300;

	/**
	 * WordPress transient key prefix. Combined with sha1(token) to produce
	 * a stable, length-capped option name (20 + 40 = 60 chars, well under
	 * the 172-char WordPress transient key limit).
	 */
	const TRANSIENT_PREFIX = 'wc_ai_large_range_';

	/**
	 * Check whether the resolved date range triggers the large-range gate.
	 *
	 * Three possible outcomes:
	 *
	 * 1. WP_Error('extended_range_required') — range exceeds threshold and no
	 *    valid token was supplied. The error data contains a freshly minted
	 *    confirmation_token. Callers must return this error immediately.
	 *
	 * 2. int $series_cap — gate did not fire (or was cleared by a valid
	 *    token). The returned integer is the series cap to use as the SQL
	 *    LIMIT on time-series queries:
	 *    - GATE_THRESHOLD_DAYS when the range is within the threshold.
	 *    - range_days + 1 when a confirmation token was valid (the +1 keeps
	 *      the safety-net check `count >= series_cap` from false-positiving
	 *      on daily series where count == range_days).
	 *
	 * @param string      $date_start        Resolved start date ('Y-m-d H:i:s').
	 * @param string      $date_end          Resolved end date ('Y-m-d H:i:s').
	 * @param string|null $confirmation_token Token from a prior extended_range_required response.
	 * @return \WP_Error|int
	 */
	public static function check( $date_start, $date_end, $confirmation_token = null ) {
		$range_days = (int) ( new \DateTime( $date_start ) )
			->diff( new \DateTime( $date_end ) )->days + 1;

		if ( $confirmation_token && self::validate_token( $confirmation_token ) ) {
			// Confirmed — lift the series cap to the full range so the safety-net
			// `count >= series_cap` cannot false-positive on daily granularity.
			return $range_days + 1;
		}

		if ( $range_days <= self::GATE_THRESHOLD_DAYS ) {
			// Sentinel: one above the maximum possible daily bucket count so the
			// series safety-net (`count >= series_cap`) never false-positives on
			// a full daily series for a range exactly at the threshold.
			return $range_days + 1;
		}

		$token  = self::mint_token( $date_start, $date_end );
		$months = max( 1, (int) round( $range_days / 30 ) );

		return new \WP_Error(
			'extended_range_required',
			"This request covers a large date range — {$range_days} days ({$months} months) — and may temporarily impact your site's performance while the query runs.\n\n"
			. 'STOP. Do not call any more tools until the merchant explicitly replies. '
			. "Present the cost estimate below and ask which option they prefer:\n\n"
			. "(1) Load the full {$months}-month history: (a) call confirm_large_range with confirmation_token: \"{$token}\", then (b) call this tool again with the same confirmation_token. Token valid for 5 minutes — act promptly once the merchant confirms.\n"
			. "(2) Narrow to a shorter range: ask the merchant to pick a start date within the last 12 months.\n\n"
			. 'ANTI-SPLITTING RULE: Do NOT split this date range into yearly, quarterly, or monthly chunks to avoid the gate. Each chunk would be under the threshold but the merchant would never see a cost estimate. Call confirm_large_range for the full intended range instead.\n\n'
			. 'IMPORTANT: Do NOT use the confirmation_token autonomously. It must only be passed after the merchant says yes and after confirm_large_range has been called.',
			array(
				'status'                => 400,
				'confirmation_required' => true,
				'confirmation_token'    => $token,
				'expires_in_seconds'    => self::TOKEN_TTL_SECONDS,
				'cost_estimate'         => array(
					'range_days' => $range_days,
					'months'     => $months,
					'threshold'  => self::GATE_THRESHOLD_DAYS,
				),
			)
		);
	}

	/**
	 * Check whether a confirmation token exists without consuming it.
	 *
	 * Use this in the confirm-large-range ability to verify the token is
	 * still valid before the permission dialog is shown. The data tool
	 * then calls validate_token() on the follow-up call, which is the
	 * one-use consumption step.
	 *
	 * @param string $token The token to check.
	 * @return bool True if the token exists; false if missing or expired.
	 */
	public static function peek_token( $token ) {
		if ( empty( $token ) ) {
			return false;
		}
		$key    = self::transient_key( $token );
		$stored = get_transient( $key );
		return $stored && $stored['token'] === $token;
	}

	/**
	 * Session-keyed gate check for use in the wc-analytics/get-data ability.
	 *
	 * Unlike check() which uses a passed confirmation token, this method uses a
	 * WordPress transient keyed to the current user + type + date range. No token
	 * is passed through request params — the approval is stored server-side after
	 * confirm-large-range is called.
	 *
	 * Returns range_days + 1 (sentinel) when the range is within the threshold —
	 * one above the maximum possible bucket count so the series safety-net cannot
	 * false-positive on a complete daily series for the exact threshold length.
	 * Returns range_days + 1 when a session approval was found (and consumed).
	 * Returns WP_Error('extended_range_required') and mints a pending transient when
	 * the range is long and no approval exists.
	 *
	 * @param string $date_start Resolved start date ('Y-m-d H:i:s' or 'Y-m-d').
	 * @param string $date_end   Resolved end date ('Y-m-d H:i:s' or 'Y-m-d').
	 * @param string $type       Analytics type slug (e.g. 'product_performance').
	 * @return \WP_Error|int
	 */
	public static function check_run( $date_start, $date_end, $type = '' ) {
		$range_days = (int) ( new \DateTime( $date_start ) )
			->diff( new \DateTime( $date_end ) )->days + 1;

		if ( $range_days <= self::GATE_THRESHOLD_DAYS ) {
			// Sentinel: one above the maximum possible daily bucket count so the
			// series safety-net (`count >= series_cap`) never false-positives on
			// a full daily series for a range exactly at the threshold.
			return $range_days + 1;
		}

		if ( self::consume_session_approval( $date_start, $date_end, $type ) ) {
			return $range_days + 1;
		}

		self::mint_session_pending( $date_start, $date_end, $type );
		$months = max( 1, (int) round( $range_days / 30 ) );

		return new \WP_Error(
			'extended_range_required',
			"This request covers a large date range — {$range_days} days ({$months} months) — and may temporarily impact your site's performance while the query runs.\n\n"
			. "STOP. Present the cost estimate below and ask the merchant which option they prefer:\n\n"
			. "(1) Load the full {$months}-month history: call wc-analytics-confirm-large-range with date_start='{$date_start}', date_end='{$date_end}', type='{$type}' (copy these values verbatim — types are tool-prefixed like 'totals:revenue' to disambiguate approvals across tools sharing a subject). Then re-run the same analytics call with the same params.\n"
			. "(2) Narrow to a shorter range: ask the merchant to pick a start date within the last 12 months.\n\n"
			. 'ANTI-SPLITTING RULE: Do NOT split this date range into yearly, quarterly, or monthly chunks to avoid the gate. '
			. "Confirm and re-run for the full intended range instead.\n\n"
			. 'IMPORTANT: Do NOT call wc-analytics-confirm-large-range autonomously. It must only be called after the merchant says yes.',
			array(
				'status'                => 400,
				'confirmation_required' => true,
				'expires_in_seconds'    => self::TOKEN_TTL_SECONDS,
				'cost_estimate'         => array(
					'range_days' => $range_days,
					'months'     => $months,
					'threshold'  => self::GATE_THRESHOLD_DAYS,
					// Machine-readable type value the model must pass verbatim to
					// confirm-large-range. Tool-prefixed (e.g. 'totals:revenue')
					// so approvals are disjoint across tools sharing a subject.
					'type'       => $type,
				),
			)
		);
	}

	/**
	 * Mark a pending session scan as approved.
	 *
	 * Called by the confirm-large-range ability after the merchant has confirmed.
	 * The consuming data call (via check_run) removes the approval on success so
	 * it cannot be replayed.
	 *
	 * @param string $date_start Date range start (YYYY-MM-DD or Y-m-d H:i:s).
	 * @param string $date_end   Date range end (YYYY-MM-DD or Y-m-d H:i:s).
	 * @param string $type       Analytics type slug that was approved.
	 * @return bool True if a pending scan was found and approved; false if none.
	 */
	public static function approve_scan( $date_start, $date_end, $type = '' ) {
		$key    = self::session_transient_key( $date_start, $date_end, $type );
		$stored = get_transient( $key );
		if ( ! $stored ) {
			return false;
		}
		set_transient(
			$key,
			array(
				'state'      => 'approved',
				'date_start' => $date_start,
				'date_end'   => $date_end,
				'type'       => $type,
			),
			self::TOKEN_TTL_SECONDS
		);
		return true;
	}

	/**
	 * Store a pending-confirmation state for the current user + type + date range.
	 *
	 * @param string $date_start Resolved start date.
	 * @param string $date_end   Resolved end date.
	 * @param string $type       Analytics type slug.
	 * @return void
	 */
	private static function mint_session_pending( $date_start, $date_end, $type = '' ) {
		set_transient(
			self::session_transient_key( $date_start, $date_end, $type ),
			array(
				'state'      => 'pending',
				'date_start' => $date_start,
				'date_end'   => $date_end,
				'type'       => $type,
			),
			self::TOKEN_TTL_SECONDS
		);
	}

	/**
	 * Validate and consume a session approval for the current user + type + date range.
	 *
	 * Deletes the transient on success so the same approval cannot be replayed.
	 *
	 * @param string $date_start Date range start.
	 * @param string $date_end   Date range end.
	 * @param string $type       Analytics type slug.
	 * @return bool True if an approved transient was found and consumed.
	 */
	private static function consume_session_approval( $date_start, $date_end, $type = '' ) {
		$key    = self::session_transient_key( $date_start, $date_end, $type );
		$stored = get_transient( $key );
		if ( $stored && 'approved' === $stored['state'] ) {
			delete_transient( $key );
			return true;
		}
		return false;
	}

	/**
	 * Transient key for a session-keyed pending/approved scan.
	 *
	 * Binds approval to user + analytics type + normalised date range (YYYY-MM-DD)
	 * so approvals cannot be consumed by a different type on the same date range.
	 *
	 * @param string $date_start Date range start.
	 * @param string $date_end   Date range end.
	 * @param string $type       Analytics type slug.
	 * @return string
	 */
	private static function session_transient_key( $date_start, $date_end, $type = '' ) {
		$user_id   = (string) get_current_user_id();
		$start_key = substr( $date_start, 0, 10 );
		$end_key   = substr( $date_end, 0, 10 );
		return self::TRANSIENT_PREFIX . 'run_' . sha1( $user_id . '_' . $start_key . '_' . $end_key . '_' . $type );
	}

	/**
	 * Validate a confirmation token.
	 *
	 * One-use — deletes the transient on first successful validation so the
	 * same token cannot be replayed on a second call.
	 *
	 * @param string $token The token to validate.
	 * @return bool True if the token exists and matches; false otherwise.
	 */
	public static function validate_token( $token ) {
		if ( empty( $token ) ) {
			return false;
		}
		$key    = self::transient_key( $token );
		$stored = get_transient( $key );
		if ( ! $stored || $stored['token'] !== $token ) {
			return false;
		}
		delete_transient( $key );
		return true;
	}

	/**
	 * Mint a one-use confirmation token and store it as a WordPress transient.
	 *
	 * @param string $date_start Resolved start date.
	 * @param string $date_end   Resolved end date.
	 * @return string The 32-char random token to embed in the WP_Error data.
	 */
	private static function mint_token( $date_start, $date_end ) {
		$token = wp_generate_password( 32, false );
		set_transient(
			self::transient_key( $token ),
			array(
				'token'      => $token,
				'date_start' => $date_start,
				'date_end'   => $date_end,
				'created'    => time(),
			),
			self::TOKEN_TTL_SECONDS
		);
		return $token;
	}

	/**
	 * Derive a stable WordPress transient key from a token value.
	 *
	 * WordPress option names are limited to 191 chars (utf8mb4). The full
	 * transient option name is '_transient_' (11) + key. With prefix (20)
	 * + sha1 (40) = 60 chars the key is well within the limit.
	 *
	 * @param string $token The raw token value.
	 * @return string Transient key.
	 */
	private static function transient_key( $token ) {
		return self::TRANSIENT_PREFIX . sha1( $token );
	}
}
