<?php
/**
 * `wc-analytics/get-customer-value` ability.
 *
 * Lifetime (not period-scoped) view of who's valuable to the store. The
 * period parameter filters which customers are summarised; the metrics
 * themselves are lifetime aggregates.
 *
 * Two inclusion frames in one response:
 * - `metrics`, `segments`, `top_customers`, `items_over_lifetime`,
 *   `time_between_orders` — ACTIVE-BASE view. Customers with at least one
 *   paid order in the period, with their full lifetime spend summarised.
 *   Answers "who's buying from me right now, and what have they been worth
 *   across their whole relationship?"
 * - `cohorts` — ACQUISITION-COHORT view. Customers whose FIRST paid order
 *   falls in the period, tracked forward through time. Answers "of the
 *   customers I acquired this period, who came back and when?"
 *
 * Each block carries a `definition` string so Claude can explain the
 * difference in narration rather than guess.
 *
 * Privacy: `top_customers` always returns `id` as a pseudonymised
 * `Customer #{customer_id}` handle. When the merchant has opted in via
 * `hey_woo_allow_customer_pii`, real `name` / `email` are also
 * included so the list can be piped to a CRM/email MCP. Claude is
 * instructed to always narrate with the pseudonymised id.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

use HeyWoo\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Cohort + lifetime SQL builds variable-length IN-clause placeholders dynamically with $ph = implode(',', array_fill(0, count($values), '%s')) and supplies values via array_merge() into $wpdb->prepare(). Same pattern as class-analytics-controller.php — see that file's header for full rationale.

/**
 * Registers the get-customer-value ability.
 */
class GetCustomerValueAbility {

	const ABILITY_NAME              = 'wc-analytics/get-customer-value';
	const CACHE_TTL                 = HOUR_IN_SECONDS;
	const MATURITY_THRESHOLD_MONTHS = 2;

	/**
	 * Register the ability. Called from AbilitiesBootstrap on
	 * wp_abilities_api_init.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get customer value', 'hey-woo' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
Get LIFETIME customer value — LTV stats, one-time vs repeat segmentation, top customers, cohort retention, items-over-lifetime histogram, and time between orders. The period parameter filters which customers are summarised; the metrics are lifetime aggregates.

TWO INCLUSION FRAMES IN ONE RESPONSE — teach the merchant which block answers which question:
- ACTIVE-BASE VIEW (metrics, segments, top_customers, items_over_lifetime, time_between_orders): customers who placed at least one paid order in this period, summarised by their FULL lifetime history. Answers "who's buying from me right now, and what have they been worth across their whole relationship?" Use for top-customer narratives, one-time vs repeat splits, current-value narratives.
- ACQUISITION-COHORT VIEW (cohorts): customers whose FIRST paid order falls in this period, tracked forward through time. Answers "of the customers I acquired this period, who came back and when?" Use for retention-curve narratives, comparing acquisition vintages, and questions like "are recent customers as valuable as old ones?"

Different questions map to different blocks. Lead with whichever block directly answers the merchant. Don't sum across frames — they describe different customer sets.

NO THREE-VIEW PATTERN (unlike revenue/orders/products/attribution/customer_overview): lifetime spend is inherently longitudinal — on-hold orders shouldn't count as "value delivered" and refund netting happens over years. Pipeline-scoped questions belong to get_customer_overview. If a merchant asks "why doesn't this match my admin Customers report?", explain that this skill is lifetime-shaped and the admin report is period-shaped; they answer different questions.

CRITICAL PRIVACY RULES — read before every response:
- top_customers always carries a pseudonymised `id` field ("Customer #1247"). ALWAYS refer to customers by this id in narration, even when name/email are present.
- privacy_mode === "pseudonymised" (default): name/email are NOT returned. DO NOT invent them. If the merchant asks "who is Customer #1247?", point at WP Admin > WooCommerce > Customers and mention that switching on "Allow customer-level data in AI responses" in plugin settings will include names/emails on this tool's responses.
- privacy_mode === "full" (merchant has opted in): name and email are present on each top_customers entry. These can be passed to a CRM/email MCP (Klaviyo, Mailchimp, etc.) when the merchant asks to action the list ("email my top 10 a 20% discount", "add these to my VIP segment"). Even then, narrate back to the merchant using the pseudonymised id — the name/email are for machine-to-machine handoff, not for read-aloud.
- Never list all customers (the tool returns top-N by design) — "show me everyone who spent over £500" needs a different approach and isn't available here.
- Guest-checkout customers (customer_id = 0) are not tracked in lifetime stats — WooCommerce can't attach orders to a persistent guest identity. Mention this honestly if the merchant's store leans heavily on guest checkout.

KEY METRICS to lead with:
- metrics.active_customers: headcount the response is summarising.
- metrics.avg_lifetime_spend / median_lifetime_spend / max_lifetime_spend: the "how valuable is my active base?" headline. Median vs avg gap tells you if a few whales are distorting the average.
- segments.one_time.share_percent vs segments.repeat.share_percent: conversion-to-repeat opportunity if one_time dominates. Pair with avg_lifetime_spend per segment — repeats typically spend 3-10x lifetime.
- opportunities.one_to_repeat_conversion.scenarios: pre-computed lever table showing estimated_uplift at 10% / 25% / 50% conversion rates. READ THESE NUMBERS DIRECTLY. Do not multiply stranded_customers × rate × uplift_per_conversion yourself — the scenarios array already did it. If uplift_per_conversion is negative, report that honestly ("no lever here — your active one-time base has actually out-spent repeat customers per head") instead of presenting a negative uplift as opportunity.
- cohorts[N].offsets[month=1].retention_percent: classic 30-day retention curve. Use to answer shots like "what % of 3-month-ago new customers came back?"
- cohorts[N].lifetime_retention_percent: "ever returned" rate — share of the cohort with ≥ 2 lifetime paid orders. The cleanest longitudinal retention number. Prefer this over summing month-by-month retention when the merchant asks "what % of my January cohort came back (at all)?" — the per-month view looks artificially thin for early months.
- cohorts[N].maturity + months_since_acquisition + maturity_threshold_months + flips_to_mature_on: "ongoing" cohorts are still maturing (below the threshold months since their month anchor), so their later-month retention is incomplete by definition. The threshold is a FIXED STRUCTURAL cutoff carried on every cohort row (currently 2 months). DO NOT attribute the threshold to the store's average time between orders, a "typical repurchase window", or any other store metric — it's a hard-coded classification, not data-driven. For ongoing cohorts, the flip-to-mature date is the exact YYYY-MM-DD on which the cohort reaches the threshold — report that date verbatim ("flips to mature on 2026-05-01"), not an approximation ("end of April" / "mid-June"). Mature cohorts have a null flip-to-mature date. Don't compare an ongoing cohort's retention to a mature one head-to-head.
- cohorts[N].offsets[month=N].cumulative_avg_ltv: cohort maturation curve — directly answers "are recent customers as valuable as older ones at the same age?"
- time_between_orders.avg_days + time_between_orders.buckets: re-order cadence — drives email timing, replenishment reminders.
- items_over_lifetime.buckets: basket-depth distribution — informs bundle / cross-sell opportunity.

NARRATIVE GUIDANCE — COMPARISON: comparison.changes carries pre-computed percent/amount/direction for headline metrics. Use those numbers directly; do not recompute. Metrics themselves are stable-ish across periods (they're lifetime) — but active_customers shifting tells you your base is growing or shrinking.

NARRATIVE GUIDANCE — OPPORTUNITY FRAMING: when the one_to_repeat_conversion block is present and uplift_per_conversion is positive, frame scenarios as "if you converted N% of your one-time buyers to repeaters, you'd unlock £X over their lifetimes." Always read conversions and estimated_uplift from the scenarios array — never derive them narratively. Do NOT show the intermediate multiplication ("£2,264 per conversion × 18 = £40,756") — the scenario row already presents both figures side by side; the merchant doesn't need to see the working. Report the estimated_uplift as the answer; reach for the other fields only if the merchant asks "where does that number come from?"

WHAT THIS CAN'T ANSWER:
- Individual customer names/emails unless the merchant has enabled the PII setting (see privacy rules above). Default is pseudonymised.
- Churn prediction or at-risk customer identification. We show past behaviour, not forecasts. For churn, cohort retention curves (cohorts block) show historic drop-off — the merchant interprets.
- LTV forecasting ("what will Customer #1247 be worth next year?"). We only report actuals.
- Why a specific customer stopped ordering. No engagement / email / session data.
- Customer-level product preferences ("what does Customer #1247 buy?") — point at WP Admin's per-customer order view.
- First-product / first-coupon / first-channel cohort splits. Not exposed on this skill today; point the merchant at get_attribution for channel-level new-customer mix as the closest available cut.
- Guest checkout lifetime value — no persistent guest identity. If the merchant leans heavily on guest checkout, their active_customers will undercount real shoppers.

When a merchant asks for any of these, say plainly what we can and can't see and point at the WP Admin workflow, a setting, or the connector that would answer it. Do NOT suggest that a new Skill, feature, or endpoint be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only ones we can deliver today):
- "How did my store do overall this period?" → get_revenue_summary
- "Who's buying right now (period-scoped, new vs returning)?" → get_customer_overview
- "What channels acquired these customers?" → get_attribution (already splits new-vs-returning per channel)
- "Compare to a different period" → re-run with custom date_start / date_end or different period
- "Email my top customers" → only if privacy_mode === "full" and a CRM/email MCP (Klaviyo, Mailchimp) is connected. Narrate by id, pass name/email to the other MCP.

DO NOT SUGGEST:
- Unmasking a specific Customer #N — point at WP Admin instead
- Predicting future LTV / churn — we do actuals only
- Listing every customer who meets a criterion — tool returns top-N by design
- Building a new Skill, feature, or endpoint — the merchant can't do that
- Per-customer product history — not exposed here; point at WP Admin

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE — this is an absolute rule, not a "mention with caveat" rule:
- Do not reference `ANALYTICS-SKILLS-MAP.md`, `CAPABILITY-BOUNDARIES.md`, `MERCHANT-QUESTIONS.md`, or any internal planning doc.
- Do not name any unshipped or planned internal tool/skill identifier. Including any `get_*` name that isn't on the tool list you can see, or variants like "the planned X skill", "the X skill would answer this", "when X ships". These leak developer-mode framing into merchant-facing chat. If you catch yourself writing a tool-name-shaped phrase, stop and rewrite without it.
- Do not offer to help spec future skills, suggest endpoints be built, or treat the reader as the developer of this plugin.

HOW TO DESCRIBE GAPS WITHOUT LEAKING:
Bad (leaks internal naming): "A get_churn_analysis skill would answer this."
Bad (still leaks): "You'd need the planned churn skill to surface it properly."
Good (describes the capability in plain language, points at a today-action): "That question is about churn — predicting which customers are about to stop ordering. We can show historic retention curves (how earlier cohorts tailed off), but forward-looking churn prediction isn't in the current tools. For a manual version, look at the cohort retention block and flag customers in cohorts that have dropped heavily by month 3-6."

The rule: describe the *shape of the missing capability* in merchant-facing language (churn prediction, LTV forecasting, per-customer product history, etc.), and point at a today-action they can take (WP Admin, a connector, a manual workflow). Never name the internal tool identifier that would fill the gap.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_attribution with Jan vs Feb date ranges — compare the traffic mix."
Bad (imperative tool-name): "Run get_customer_overview with interval=month to see this month over month."
Bad (parameter-shape framing): "Call get_attribution twice — one for January, one for February — then compare."
Bad (parameter-name in backticks): "I can break Paid Search down by keyword (`term`) or source (`google` / `bing`)."
Bad (developer-shape alias in parens): "Break it down by source (utm_source) to see where the spend is going."
Good (phrased as a merchant question): "Want me to compare where your January and February customers came from?"
Good (phrased as a prompt the merchant can send): "Ask me: 'how has this trended month over month?' and I'll pull it."
Good (answering why two cohorts differ without leaking the tool chain): "The next thing worth looking at is the channel mix that drove each month's new customers — different acquisition sources often explain retention gaps. Want me to pull that?"
Good (names platforms, not parameter values): "I can split Paid Search into the specific keywords — or into Google vs Bing if you want to see where the spend concentrates."

Rule: no backticks around parameter names or parameter values in the response to the merchant. Platform names (Google, Bing, Stripe, PayPal) are merchant vocabulary and are fine in plain text. Internal identifiers (`term`, `utm_source`, `group_by`, `channel_source`) are developer vocabulary and never belong in merchant-facing output.

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess lifetime figures. If the tool returns an error or empty response, tell the merchant you couldn't retrieve the data — do not fabricate numbers. Never mix active-base and acquisition-cohort frames in a single number claim.
DESCRIPTION,
					'hey-woo'
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
			'properties' => array(
				'period'          => array(
					'type'        => 'string',
					'enum'        => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ),
					'default'     => 'last_30_days',
					'description' => 'Time window. Filters which customers are summarised (active-base) and which cohorts appear in the retention matrix (acquisition-cohort).',
				),
				'date_start'      => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom start date (YYYY-MM-DD). Overrides period when provided with date_end.',
				),
				'date_end'        => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom end date (YYYY-MM-DD).',
				),
				'compare'         => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include comparison to the previous period (pre-computed percent deltas).',
				),
				'limit'           => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Number of top customers to return.',
				),
				'include_cohorts' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include the cohort retention matrix. Set false on very large stores where the matrix is not needed.',
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
				'period'              => array( 'type' => 'object' ),
				'currency'            => array( 'type' => 'string' ),
				'privacy_mode'        => array(
					'type' => 'string',
					'enum' => array( 'pseudonymised', 'full' ),
				),
				'metrics'             => array( 'type' => 'object' ),
				'segments'            => array( 'type' => 'object' ),
				'opportunities'       => array( 'type' => 'object' ),
				'top_customers'       => array( 'type' => 'array' ),
				'items_over_lifetime' => array( 'type' => 'object' ),
				'cohorts'             => array(
					'oneOf' => array(
						array( 'type' => 'array' ),
						array( 'type' => 'null' ),
					),
				),
				'time_between_orders' => array( 'type' => 'object' ),
				'comparison'          => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
				'note'                => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
				'privacy_note'        => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $input Validated input matching input_schema.
	 * @return array|\WP_Error Response payload or WP_Error.
	 */
	public static function execute( $input ) {
		$input           = is_array( $input ) ? $input : array();
		$period          = $input['period'] ?? 'last_30_days';
		$date_start      = $input['date_start'] ?? null;
		$date_end        = $input['date_end'] ?? null;
		$compare         = array_key_exists( 'compare', $input ) ? (bool) $input['compare'] : true;
		$limit           = (int) ( $input['limit'] ?? 10 );
		$include_cohorts = array_key_exists( 'include_cohorts', $input ) ? (bool) $input['include_cohorts'] : true;
		$telemetry_start = microtime( true );

		/**
		 * This action is documented in class-analytics-controller.php::fetch_revenue_summary().
		 *
		 * @since 0.1.0
		 */
		do_action(
			'hey_woo_skill_called',
			'get_customer_value',
			array(
				'period'          => $period,
				'date_start'      => $date_start,
				'date_end'        => $date_end,
				'compare'         => $compare,
				'limit'           => $limit,
				'include_cohorts' => $include_cohorts,
			)
		);

		$dates = AnalyticsController::resolve_dates( $period, $date_start, $date_end );
		$pii   = AbilitiesBootstrap::is_pii_allowed();

		$cache_key = 'hey_woo_customer_value_' . md5(
			$dates['start'] . '_' . $dates['end']
			. '_' . ( $compare ? '1' : '0' )
			. '_' . $limit
			. '_' . ( $include_cohorts ? '1' : '0' )
			. '_' . ( $pii ? '1' : '0' )
			. '_' . AnalyticsController::get_date_column()
			. '_' . implode( ',', AnalyticsController::get_paid_statuses() )
		);
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			/**
			 * This action is documented in class-analytics-controller.php::fetch_revenue_summary().
			 *
			 * @since 0.1.0
			 */
			do_action(
				'hey_woo_skill_executed',
				'get_customer_value',
				array(
					'duration_ms'   => (int) round( ( microtime( true ) - $telemetry_start ) * 1000 ),
					'cache_hit'     => true,
					'rows_returned' => count( $cached['top_customers'] ?? array() ),
					'date_start'    => $dates['start'],
					'date_end'      => $dates['end'],
					'interval'      => null,
					'bucket_count'  => null,
				)
			);
			return $cached;
		}

		$rows = self::query_lifetime_aggregates( $dates['start'], $dates['end'] );

		$metrics       = self::compute_metrics( $rows );
		$segments      = self::compute_segments( $rows );
		$opportunities = self::compute_opportunities( $segments );
		$items         = self::compute_items_histogram( $rows );

		$customer_ids  = array_map(
			function ( $row ) {
				return (int) $row['customer_id'];
			},
			$rows
		);
		$top_customers = self::build_top_customers( $rows, $limit, $pii );
		$time_between  = self::query_time_between_orders( $customer_ids );

		$cohorts = null;
		if ( $include_cohorts ) {
			$cohorts = self::query_cohort_retention( $dates['start'], $dates['end'] );
		}

		$result = array(
			'period'              => array(
				'start' => $dates['start'],
				'end'   => $dates['end'],
				'label' => $dates['label'],
			),
			'currency'            => get_woocommerce_currency(),
			'privacy_mode'        => $pii ? 'full' : 'pseudonymised',
			'metrics'             => $metrics,
			'segments'            => $segments,
			'opportunities'       => $opportunities,
			'top_customers'       => $top_customers,
			'items_over_lifetime' => $items,
			'cohorts'             => $cohorts,
			'time_between_orders' => $time_between,
			'comparison'          => null,
			'note'                => null,
			'privacy_note'        => $pii
				? 'Top customers include name and email because the merchant has enabled customer PII in AI responses. Use these only when piping the list to an email/CRM MCP (e.g. Klaviyo) the merchant is driving. Always refer to customers in narration by the pseudonymised id (Customer #N).'
				: 'Top customers are pseudonymised (Customer #N). Switch on "Allow customer-level data in AI responses" in plugin settings to include real names and emails — needed when chaining to email or CRM MCPs that must action the list.',
		);

		if ( 0 === $metrics['active_customers'] ) {
			$result['note'] = 'No paid orders found for this date range — no active-customer base to summarise.';
		}

		if ( $compare ) {
			$prev_dates    = AnalyticsController::get_previous_period( $dates['start'], $dates['end'] );
			$prev_rows     = self::query_lifetime_aggregates( $prev_dates['start'], $prev_dates['end'] );
			$prev_metrics  = self::compute_metrics( $prev_rows );
			$prev_segments = self::compute_segments( $prev_rows );
			$changes       = self::calculate_changes( $metrics, $prev_metrics );

			$result['comparison'] = array(
				'period'   => array(
					'start' => $prev_dates['start'],
					'end'   => $prev_dates['end'],
				),
				'metrics'  => $prev_metrics,
				'segments' => $prev_segments,
				'changes'  => $changes,
			);
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );

		/**
		 * This action is documented in class-analytics-controller.php::fetch_revenue_summary().
		 *
		 * @since 0.1.0
		 */
		do_action(
			'hey_woo_skill_executed',
			'get_customer_value',
			array(
				'duration_ms'   => (int) round( ( microtime( true ) - $telemetry_start ) * 1000 ),
				'cache_hit'     => false,
				'rows_returned' => count( $result['top_customers'] ?? array() ),
				'date_start'    => $dates['start'],
				'date_end'      => $dates['end'],
				'interval'      => null,
				'bucket_count'  => null,
			)
		);

		return $result;
	}

	/**
	 * Query per-customer lifetime aggregates for customers active in the
	 * period. Each row = one customer × their full lifetime history of
	 * paid orders.
	 *
	 * @param string $date_start YYYY-MM-DD.
	 * @param string $date_end   YYYY-MM-DD.
	 * @return array<int,array<string,mixed>>
	 */
	private static function query_lifetime_aggregates( $date_start, $date_end ) {
		global $wpdb;

		$table       = $wpdb->prefix . 'wc_order_stats';
		$statuses    = AnalyticsController::get_paid_statuses();
		$status_ph   = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$date_column = AnalyticsController::get_date_column();

		// Inner subquery identifies customers active in the period.
		// Outer query sums lifetime stats for those customers across
		// their entire paid order history (not filtered to the period).
		$sql = $wpdb->prepare(
			"SELECT
				os.customer_id,
				SUM(os.net_total) AS lifetime_spend,
				COUNT(DISTINCT os.order_id) AS lifetime_orders,
				SUM(os.num_items_sold) AS lifetime_items,
				MIN(os.{$date_column}) AS first_order,
				MAX(os.{$date_column}) AS last_order
			FROM {$table} os
			WHERE os.parent_id = 0
				AND os.status IN ({$status_ph})
				AND os.customer_id > 0
				AND os.customer_id IN (
					SELECT DISTINCT customer_id
					FROM {$table}
					WHERE {$date_column} >= %s
						AND {$date_column} <= %s
						AND parent_id = 0
						AND status IN ({$status_ph})
						AND customer_id > 0
				)
			GROUP BY os.customer_id",
			array_merge(
				$statuses, // outer IN.
				array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ),
				$statuses  // inner IN.
			)
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Roll up metrics from per-customer lifetime rows.
	 *
	 * @param array $rows Per-customer lifetime rows from the active-base query.
	 * @return array
	 */
	private static function compute_metrics( $rows ) {
		$count = count( $rows );

		if ( 0 === $count ) {
			return array(
				'active_customers'        => 0,
				'avg_lifetime_spend'      => 0.00,
				'median_lifetime_spend'   => 0.00,
				'max_lifetime_spend'      => 0.00,
				'avg_lifetime_orders'     => 0.0,
				'avg_lifetime_items'      => 0.0,
				'avg_days_between_orders' => null,
				'definition'              => 'Lifetime metrics for customers with at least one paid order in this period. Lifetime spend = SUM(net_total) across every paid order that customer has ever placed (completed + processing only; refund sub-orders excluded). Based on wc_get_is_paid_statuses() — matches the headline paid view in other skills.',
			);
		}

		$spends = array();
		$orders = array();
		$items  = array();

		foreach ( $rows as $row ) {
			$spends[] = (float) $row['lifetime_spend'];
			$orders[] = (int) $row['lifetime_orders'];
			$items[]  = (int) $row['lifetime_items'];
		}

		sort( $spends );
		$mid    = (int) floor( $count / 2 );
		$median = ( 0 === $count % 2 )
			? ( ( $spends[ $mid - 1 ] + $spends[ $mid ] ) / 2 )
			: $spends[ $mid ];

		$avg_orders = array_sum( $orders ) / $count;
		$avg_items  = array_sum( $items ) / $count;

		// avg_days_between_orders is computed from the time-between-orders
		// query and pasted into metrics there — here we default to null so
		// the block stays self-consistent when the query fails.
		return array(
			'active_customers'        => $count,
			'avg_lifetime_spend'      => round( array_sum( $spends ) / $count, 2 ),
			'median_lifetime_spend'   => round( $median, 2 ),
			'max_lifetime_spend'      => round( max( $spends ), 2 ),
			'avg_lifetime_orders'     => round( $avg_orders, 2 ),
			'avg_lifetime_items'      => round( $avg_items, 2 ),
			'avg_days_between_orders' => null,
			'definition'              => 'Lifetime metrics for customers with at least one paid order in this period. Lifetime spend = SUM(net_total) across every paid order that customer has ever placed (completed + processing only; refund sub-orders excluded). Based on wc_get_is_paid_statuses() — matches the headline paid view in other skills.',
		);
	}

	/**
	 * Split active-base into one-time vs repeat lifetime segments.
	 *
	 * @param array $rows Per-customer lifetime rows from the active-base query.
	 * @return array
	 */
	private static function compute_segments( $rows ) {
		$one_time = array(
			'customers'            => 0,
			'share_percent'        => 0.0,
			'total_lifetime_spend' => 0.00,
			'avg_lifetime_spend'   => 0.00,
			'avg_order_value'      => 0.00,
			'avg_lifetime_orders'  => 1.0,
			'definition'           => 'Customers whose full lifetime history consists of a single paid order (across all time, not just this period).',
		);
		$repeat   = array(
			'customers'            => 0,
			'share_percent'        => 0.0,
			'total_lifetime_spend' => 0.00,
			'avg_lifetime_spend'   => 0.00,
			'avg_order_value'      => 0.00,
			'avg_lifetime_orders'  => 0.0,
			'definition'           => 'Customers with two or more paid orders over their full lifetime. AOV here = total lifetime spend / total lifetime orders (per-order average within the segment).',
		);

		$one_time_spend  = 0.0;
		$repeat_spend    = 0.0;
		$repeat_orders   = 0;
		$total_customers = count( $rows );

		foreach ( $rows as $row ) {
			if ( 1 === (int) $row['lifetime_orders'] ) {
				++$one_time['customers'];
				$one_time_spend += (float) $row['lifetime_spend'];
			} else {
				++$repeat['customers'];
				$repeat_spend  += (float) $row['lifetime_spend'];
				$repeat_orders += (int) $row['lifetime_orders'];
			}
		}

		if ( $one_time['customers'] > 0 ) {
			$one_time['total_lifetime_spend'] = round( $one_time_spend, 2 );
			$one_time['avg_lifetime_spend']   = round( $one_time_spend / $one_time['customers'], 2 );
			$one_time['avg_order_value']      = $one_time['avg_lifetime_spend'];
		}
		if ( $repeat['customers'] > 0 ) {
			$repeat['total_lifetime_spend'] = round( $repeat_spend, 2 );
			$repeat['avg_lifetime_spend']   = round( $repeat_spend / $repeat['customers'], 2 );
			$repeat['avg_lifetime_orders']  = round( $repeat_orders / $repeat['customers'], 2 );
			$repeat['avg_order_value']      = $repeat_orders > 0 ? round( $repeat_spend / $repeat_orders, 2 ) : 0.00;
		}
		if ( $total_customers > 0 ) {
			$one_time['share_percent'] = round( $one_time['customers'] * 100 / $total_customers, 1 );
			$repeat['share_percent']   = round( $repeat['customers'] * 100 / $total_customers, 1 );
		}

		return array(
			'one_time' => $one_time,
			'repeat'   => $repeat,
		);
	}

	/**
	 * Pre-computed lever table so Claude reports uplift scenarios rather
	 * than deriving them from `segments.one_time` / `segments.repeat` by
	 * hand. Single arithmetic step per scenario; zero free-hand maths.
	 *
	 * `uplift_per_conversion` can be negative on stores where the
	 * currently-active one-time base happens to have spent more on their
	 * single order than the repeat segment has on each of theirs. Return
	 * the block anyway — Claude can read the sign and frame it as
	 * "no lever here" rather than guessing.
	 *
	 * @param array $segments Output of compute_segments().
	 * @return array
	 */
	private static function compute_opportunities( $segments ) {
		$stranded        = (int) ( $segments['one_time']['customers'] ?? 0 );
		$stranded_avg    = (float) ( $segments['one_time']['avg_lifetime_spend'] ?? 0.0 );
		$repeat_avg      = (float) ( $segments['repeat']['avg_lifetime_spend'] ?? 0.0 );
		$uplift_per_conv = round( $repeat_avg - $stranded_avg, 2 );
		$rates           = array( 10, 25, 50 );
		$scenarios       = array();

		foreach ( $rates as $rate ) {
			$conversions = (int) round( $stranded * ( $rate / 100 ) );
			$scenarios[] = array(
				'conversion_rate_percent' => $rate,
				'conversions'             => $conversions,
				'estimated_uplift'        => round( $conversions * $uplift_per_conv, 2 ),
			);
		}

		return array(
			'one_to_repeat_conversion' => array(
				'stranded_customers'    => $stranded,
				'stranded_avg_lifetime' => round( $stranded_avg, 2 ),
				'repeat_avg_lifetime'   => round( $repeat_avg, 2 ),
				'uplift_per_conversion' => $uplift_per_conv,
				'scenarios'             => $scenarios,
				'definition'            => 'Pre-computed scenario table for converting one-time buyers to repeaters. conversions = round(stranded_customers × conversion_rate_percent / 100); estimated_uplift = conversions × uplift_per_conversion. Uplift can be negative when the active one-time base has out-spent the repeat segment on average — report the sign honestly; do not recompute.',
			),
		);
	}

	/**
	 * Build the top_customers list. Sorted by lifetime_spend desc. Always
	 * includes pseudonymised id; hydrates name/email when the merchant has
	 * opted in.
	 *
	 * @param array $rows        Per-customer lifetime rows from the active-base query.
	 * @param int   $limit       Number of rows to return.
	 * @param bool  $include_pii Whether the merchant has opted into PII (real names/emails).
	 * @return array
	 */
	private static function build_top_customers( $rows, $limit, $include_pii ) {
		if ( empty( $rows ) ) {
			return array();
		}

		// Sort by lifetime_spend desc.
		usort(
			$rows,
			function ( $a, $b ) {
				return (float) $b['lifetime_spend'] <=> (float) $a['lifetime_spend'];
			}
		);

		$top = array_slice( $rows, 0, $limit );

		// Hydrate country (always safe — not PII) + optionally name/email.
		$customer_ids = array_map(
			function ( $row ) {
				return (int) $row['customer_id'];
			},
			$top
		);
		$lookups      = self::fetch_customer_lookup( $customer_ids, $include_pii );

		$result = array();
		foreach ( $top as $row ) {
			$cid      = (int) $row['customer_id'];
			$lifetime = (float) $row['lifetime_spend'];
			$orders   = (int) $row['lifetime_orders'];
			$aov      = $orders > 0 ? round( $lifetime / $orders, 2 ) : 0.00;
			$lookup   = $lookups[ $cid ] ?? array();

			$entry = array(
				'id'              => 'Customer #' . $cid,
				'lifetime_orders' => $orders,
				'lifetime_spend'  => round( $lifetime, 2 ),
				'avg_order_value' => $aov,
				'first_order'     => self::format_date( $row['first_order'] ),
				'last_order'      => self::format_date( $row['last_order'] ),
				'country'         => $lookup['country'] ?? null,
			);

			if ( $include_pii ) {
				$name           = trim( ( $lookup['first_name'] ?? '' ) . ' ' . ( $lookup['last_name'] ?? '' ) );
				$entry['name']  = '' !== $name ? $name : null;
				$entry['email'] = $lookup['email'] ?? null;
			}

			$result[] = $entry;
		}

		return $result;
	}

	/**
	 * Fetch country (and optionally name/email) from wc_customer_lookup
	 * for a set of customer ids. Returns a map keyed by customer_id.
	 *
	 * @param int[] $customer_ids Customer IDs to hydrate.
	 * @param bool  $include_pii  Whether the merchant has opted into PII (real names/emails).
	 * @return array
	 */
	private static function fetch_customer_lookup( $customer_ids, $include_pii ) {
		if ( empty( $customer_ids ) ) {
			return array();
		}

		global $wpdb;
		$table       = $wpdb->prefix . 'wc_customer_lookup';
		$ids_ph      = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$pii_columns = $include_pii ? ', first_name, last_name, email' : '';

		$sql = $wpdb->prepare(
			"SELECT customer_id, country{$pii_columns}
			FROM {$table}
			WHERE customer_id IN ({$ids_ph})",
			$customer_ids
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row['customer_id'] ] = $row;
		}
		return $map;
	}

	/**
	 * Items-over-lifetime histogram. Buckets per-customer total items sold.
	 *
	 * @param array $rows Per-customer lifetime rows from the active-base query.
	 * @return array
	 */
	private static function compute_items_histogram( $rows ) {
		$buckets = array(
			'1 item'      => array(
				'min' => 0,
				'max' => 1,
			),
			'2-3 items'   => array(
				'min' => 2,
				'max' => 3,
			),
			'4-5 items'   => array(
				'min' => 4,
				'max' => 5,
			),
			'6-10 items'  => array(
				'min' => 6,
				'max' => 10,
			),
			'11-20 items' => array(
				'min' => 11,
				'max' => 20,
			),
			'20+ items'   => array(
				'min' => 21,
				'max' => PHP_INT_MAX,
			),
		);

		$result = array();
		foreach ( array_keys( $buckets ) as $label ) {
			$result[] = array(
				'bucket'          => $label,
				'customers_count' => 0,
				'avg_items'       => 0.0,
				'avg_spend'       => 0.00,
				'share_percent'   => 0.0,
			);
		}

		$total    = count( $rows );
		$by_label = array();
		foreach ( $buckets as $label => $_ ) {
			$by_label[ $label ] = array(
				'items' => array(),
				'spend' => array(),
			);
		}

		foreach ( $rows as $row ) {
			$items = (int) $row['lifetime_items'];
			foreach ( $buckets as $label => $range ) {
				if ( $items >= $range['min'] && $items <= $range['max'] ) {
					$by_label[ $label ]['items'][] = $items;
					$by_label[ $label ]['spend'][] = (float) $row['lifetime_spend'];
					break;
				}
			}
		}

		$i = 0;
		foreach ( $buckets as $label => $_ ) {
			$bucket_rows                     = $by_label[ $label ];
			$count                           = count( $bucket_rows['items'] );
			$result[ $i ]['customers_count'] = $count;
			$result[ $i ]['avg_items']       = $count > 0 ? round( array_sum( $bucket_rows['items'] ) / $count, 1 ) : 0.0;
			$result[ $i ]['avg_spend']       = $count > 0 ? round( array_sum( $bucket_rows['spend'] ) / $count, 2 ) : 0.00;
			$result[ $i ]['share_percent']   = $total > 0 ? round( $count * 100 / $total, 1 ) : 0.0;
			++$i;
		}

		return array(
			'buckets'    => $result,
			'definition' => 'Distribution of customers in the active base by total items purchased across their full lifetime (sum of num_items_sold across every paid order).',
		);
	}

	/**
	 * Time between consecutive paid orders for active-base repeaters.
	 * Uses LAG() window function (MySQL 8+ / MariaDB 10.2+).
	 *
	 * @param int[] $customer_ids Active-base customer IDs to compute gaps for.
	 * @return array
	 */
	private static function query_time_between_orders( $customer_ids ) {
		$empty = array(
			'avg_days'    => null,
			'repeat_gaps' => 0,
			'buckets'     => array(),
			'definition'  => 'Gaps between consecutive paid orders, per customer, for repeaters in the active base. Bucketed. Uses the LAG window function — requires MySQL 8+ or MariaDB 10.2+.',
		);

		if ( empty( $customer_ids ) ) {
			return $empty;
		}

		global $wpdb;
		$table       = $wpdb->prefix . 'wc_order_stats';
		$statuses    = AnalyticsController::get_paid_statuses();
		$status_ph   = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$ids_ph      = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$date_column = AnalyticsController::get_date_column();

		$sql = $wpdb->prepare(
			"SELECT days_between
			FROM (
				SELECT DATEDIFF(
					{$date_column},
					LAG({$date_column}) OVER (PARTITION BY customer_id ORDER BY {$date_column})
				) AS days_between
				FROM {$table}
				WHERE parent_id = 0
					AND status IN ({$status_ph})
					AND customer_id > 0
					AND customer_id IN ({$ids_ph})
			) gaps
			WHERE days_between IS NOT NULL",
			array_merge( $statuses, $customer_ids )
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return $empty;
		}

		$bucket_defs = array(
			'Under 1 week' => array( 'max' => 6 ),
			'1-4 weeks'    => array( 'max' => 29 ),
			'1-3 months'   => array( 'max' => 89 ),
			'3-6 months'   => array( 'max' => 179 ),
			'6-12 months'  => array( 'max' => 364 ),
			'12+ months'   => array( 'max' => PHP_INT_MAX ),
		);

		$counts     = array_fill_keys( array_keys( $bucket_defs ), 0 );
		$sum_days   = array_fill_keys( array_keys( $bucket_defs ), 0 );
		$total_gaps = 0;
		$total_days = 0;

		foreach ( $rows as $row ) {
			$days = (int) $row['days_between'];
			++$total_gaps;
			$total_days += $days;

			foreach ( $bucket_defs as $label => $def ) {
				if ( $days <= $def['max'] ) {
					++$counts[ $label ];
					$sum_days[ $label ] += $days;
					break;
				}
			}
		}

		$buckets = array();
		foreach ( $bucket_defs as $label => $_ ) {
			$c         = $counts[ $label ];
			$buckets[] = array(
				'bucket'        => $label,
				'repeat_gaps'   => $c,
				'avg_days'      => $c > 0 ? round( $sum_days[ $label ] / $c, 1 ) : 0.0,
				'share_percent' => $total_gaps > 0 ? round( $c * 100 / $total_gaps, 1 ) : 0.0,
			);
		}

		return array(
			'avg_days'    => $total_gaps > 0 ? round( $total_days / $total_gaps, 1 ) : null,
			'repeat_gaps' => $total_gaps,
			'buckets'     => $buckets,
			'definition'  => 'Gaps between consecutive paid orders, per customer, for repeaters in the active base. avg_days is the mean across all gaps. Each gap is one customer moving from order N to order N+1. A customer with 3 orders contributes 2 gaps.',
		);
	}

	/**
	 * Cohort retention matrix — customers whose first paid order lands in
	 * the period, tracked forward through time.
	 *
	 * @param string $date_start YYYY-MM-DD start date.
	 * @param string $date_end   YYYY-MM-DD end date.
	 * @return array
	 */
	private static function query_cohort_retention( $date_start, $date_end ) {
		global $wpdb;

		$table       = $wpdb->prefix . 'wc_order_stats';
		$statuses    = AnalyticsController::get_paid_statuses();
		$status_ph   = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$date_column = AnalyticsController::get_date_column();

		// Cohort sizes: customers whose first paid order falls in the period,
		// grouped by YYYY-MM of first order. This is the denominator for
		// retention percentages at each offset. `lifetime_repeaters` counts
		// cohort members who have ever returned (≥ 2 paid orders in their
		// full lifetime) — feeds the per-cohort `lifetime_retention_percent`
		// ("ever returned") without an additional query pass.
		$sizes_sql = $wpdb->prepare(
			"SELECT DATE_FORMAT(first_order, '%%Y-%%m') AS cohort_month,
				COUNT(*) AS cohort_size,
				MIN(first_order) AS cohort_first_order,
				SUM(CASE WHEN lifetime_orders > 1 THEN 1 ELSE 0 END) AS lifetime_repeaters
			FROM (
				SELECT customer_id,
					MIN({$date_column}) AS first_order,
					COUNT(*) AS lifetime_orders
				FROM {$table}
				WHERE parent_id = 0 AND status IN ({$status_ph}) AND customer_id > 0
				GROUP BY customer_id
				HAVING MIN({$date_column}) >= %s AND MIN({$date_column}) <= %s
			) first_orders
			GROUP BY cohort_month
			ORDER BY cohort_month",
			array_merge( $statuses, array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ) )
		);

		$sizes = $wpdb->get_results( $sizes_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( empty( $sizes ) ) {
			return array();
		}

		// Retention matrix: for customers in each cohort, count orders at
		// each month offset from their first order. month=0 will always
		// equal the cohort size (the first order itself).
		$matrix_sql = $wpdb->prepare(
			"SELECT
				DATE_FORMAT(cohorts.first_order, '%%Y-%%m') AS cohort_month,
				TIMESTAMPDIFF(MONTH, cohorts.first_order, os.{$date_column}) AS months_since,
				COUNT(DISTINCT os.customer_id) AS customers_retained,
				SUM(os.net_total) AS revenue
			FROM {$table} os
			JOIN (
				SELECT customer_id, MIN({$date_column}) AS first_order
				FROM {$table}
				WHERE parent_id = 0 AND status IN ({$status_ph}) AND customer_id > 0
				GROUP BY customer_id
				HAVING MIN({$date_column}) >= %s AND MIN({$date_column}) <= %s
			) cohorts ON cohorts.customer_id = os.customer_id
			WHERE os.parent_id = 0 AND os.status IN ({$status_ph})
			GROUP BY cohort_month, months_since
			ORDER BY cohort_month, months_since",
			array_merge( $statuses, array( $date_start . ' 00:00:00', $date_end . ' 23:59:59' ), $statuses )
		);

		$matrix = $wpdb->get_results( $matrix_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $matrix ) ) {
			$matrix = array();
		}

		/*
		 * Assemble the nested shape. Each cohort carries:
		 * - `months_since_acquisition` — months elapsed between the cohort's
		 *   month anchor (first-of-month) and today. Stable across customers
		 *   in the same cohort regardless of their exact first-order day.
		 * - `maturity` — "mature" once month-1 retention has had time to
		 *   fully land (≥ 2 months since anchor), otherwise "ongoing".
		 *   Teaches Claude to call out incomplete cohorts without guessing
		 *   the cutoff.
		 * - `lifetime_retention_percent` — share of the cohort that has
		 *   ever returned (≥ 2 lifetime paid orders), cumulative across the
		 *   entire customer lifetime. Cleanest longitudinal retention number,
		 *   not affected by month-N slicing.
		 */
		$by_cohort = array();
		$now       = new \DateTime( 'now' );
		foreach ( $sizes as $row ) {
			$cohort                   = $row['cohort_month'];
			$size                     = (int) $row['cohort_size'];
			$repeaters                = (int) $row['lifetime_repeaters'];
			$months_since_acquisition = self::months_between( $cohort . '-01', $now );
			$maturity                 = $months_since_acquisition >= self::MATURITY_THRESHOLD_MONTHS
				? 'mature'
				: 'ongoing';

			// `flips_to_mature_on` pre-computes the exact date an ongoing
			// cohort reaches the maturity threshold. Kills the off-by-a-month
			// narration that surfaces when Claude derives flip timing by
			// hand ("end of April" / "mid-June" rather than the structural
			// YYYY-MM-01 anchor).
			$flips_on = null;
			if ( 'ongoing' === $maturity ) {
				$flip_dt = \DateTime::createFromFormat( 'Y-m-d', $cohort . '-01' );
				if ( $flip_dt ) {
					$flip_dt->modify( '+' . self::MATURITY_THRESHOLD_MONTHS . ' months' );
					$flips_on = $flip_dt->format( 'Y-m-d' );
				}
			}

			$by_cohort[ $cohort ] = array(
				'cohort'                     => $cohort,
				'size'                       => $size,
				'months_since_acquisition'   => $months_since_acquisition,
				'maturity'                   => $maturity,
				'maturity_threshold_months'  => self::MATURITY_THRESHOLD_MONTHS,
				'flips_to_mature_on'         => $flips_on,
				'lifetime_retention_percent' => $size > 0 ? round( $repeaters * 100 / $size, 1 ) : 0.0,
				'offsets'                    => array(),
			);
		}

		$cum_revenue = array();
		foreach ( $matrix as $row ) {
			$cohort = $row['cohort_month'];
			if ( ! isset( $by_cohort[ $cohort ] ) ) {
				continue;
			}
			$month    = (int) $row['months_since'];
			$retained = (int) $row['customers_retained'];
			$revenue  = (float) $row['revenue'];
			$size     = $by_cohort[ $cohort ]['size'];

			$cum_revenue[ $cohort ] = ( $cum_revenue[ $cohort ] ?? 0 ) + $revenue;
			$retention_percent      = $size > 0 ? round( $retained * 100 / $size, 1 ) : 0.0;
			$cumulative_avg_ltv     = $size > 0 ? round( $cum_revenue[ $cohort ] / $size, 2 ) : 0.00;

			$by_cohort[ $cohort ]['offsets'][] = array(
				'month'              => $month,
				'customers'          => $retained,
				'retention_percent'  => $retention_percent,
				'revenue'            => round( $revenue, 2 ),
				'cumulative_revenue' => round( $cum_revenue[ $cohort ], 2 ),
				'cumulative_avg_ltv' => $cumulative_avg_ltv,
			);
		}

		return array_values( $by_cohort );
	}

	/**
	 * Calculate pre-computed deltas on the skill's headline metrics.
	 *
	 * @param array $current  Current-period response payload.
	 * @param array $previous Previous-period response payload (same shape).
	 * @return array
	 */
	private static function calculate_changes( $current, $previous ) {
		$keys = array(
			'active_customers',
			'avg_lifetime_spend',
			'median_lifetime_spend',
			'max_lifetime_spend',
			'avg_lifetime_orders',
			'avg_lifetime_items',
		);

		$changes = array();
		foreach ( $keys as $key ) {
			$curr = (float) ( $current[ $key ] ?? 0 );
			$prev = (float) ( $previous[ $key ] ?? 0 );
			$diff = $curr - $prev;

			if ( 0.0 === $prev ) {
				$percent = ( $curr > 0 ) ? 100.0 : 0.0;
			} else {
				$percent = round( ( $diff / $prev ) * 100, 1 );
			}

			if ( $diff > 0 ) {
				$direction = 'up';
			} elseif ( $diff < 0 ) {
				$direction = 'down';
			} else {
				$direction = 'flat';
			}

			$changes[ $key ] = array(
				'amount'    => round( $diff, 2 ),
				'percent'   => $percent,
				'direction' => $direction,
			);
		}

		return $changes;
	}

	/**
	 * Whole months elapsed between a `YYYY-MM-DD` anchor and a DateTime.
	 * Both operands are normalised to their first-of-month at midnight
	 * before diffing so a cohort's "age" is stable regardless of which
	 * day in the month its earliest first_order landed on.
	 *
	 * @param string    $anchor YYYY-MM-DD (typically cohort-month + '-01').
	 * @param \DateTime $now    Reference "now" for the comparison.
	 * @return int
	 */
	private static function months_between( $anchor, $now ) {
		$anchor_dt = \DateTime::createFromFormat( 'Y-m-d', $anchor );
		if ( ! $anchor_dt ) {
			return 0;
		}
		$anchor_dt->setTime( 0, 0, 0 );
		$anchor_dt->modify( 'first day of this month' );

		$now_dt = clone $now;
		$now_dt->setTime( 0, 0, 0 );
		$now_dt->modify( 'first day of this month' );

		if ( $now_dt < $anchor_dt ) {
			return 0;
		}

		$diff = $anchor_dt->diff( $now_dt );
		return (int) ( $diff->y * 12 + $diff->m );
	}

	/**
	 * Normalise a wc_order_stats DATETIME to YYYY-MM-DD or null.
	 *
	 * @param string|null $value Raw DATETIME string from wc_order_stats.
	 * @return string|null
	 */
	private static function format_date( $value ) {
		if ( empty( $value ) ) {
			return null;
		}
		// wc_order_stats stores DATETIME. Return the date portion for
		// stability — time of day is meaningless at this analytical level.
		$dt = \DateTime::createFromFormat( 'Y-m-d H:i:s', $value );
		if ( ! $dt ) {
			return $value;
		}
		return $dt->format( 'Y-m-d' );
	}
}
