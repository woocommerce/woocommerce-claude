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
 * `Customer #{customer_id}` handle. Real names and emails are never
 * surfaced — the merchant looks up the real identity in WP Admin >
 * WooCommerce > Customers when they need it.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-customer-value ability.
 */
class GetCustomerValueAbility {

	const ABILITY_NAME = 'wc-analytics/get-customer-value';
	const CACHE_TTL    = HOUR_IN_SECONDS;

	/**
	 * Register the ability. Called from AbilitiesBootstrap on
	 * wp_abilities_api_init.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get customer value', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
TRANSITIONAL — PREFER wc-analytics-totals subject=customer_value. This per-type narrative remains as the source of the legacy describe text returned by wc-analytics-describe; for any new call route to the verb tool, which carries the consolidated per-subject describe inline.

Get LIFETIME customer value — LTV stats, one-time vs repeat segmentation, top customers, cohort retention, items-over-lifetime histogram, and time between orders. The period parameter filters which customers are summarised; the metrics are lifetime aggregates.

TWO INCLUSION FRAMES IN ONE RESPONSE — teach the merchant which block answers which question:
- ACTIVE-BASE VIEW (metrics, segments, top_customers, items_over_lifetime, time_between_orders): customers who placed at least one paid order in this period, summarised by their FULL lifetime history. Answers "who's buying from me right now, and what have they been worth across their whole relationship?" Use for top-customer narratives, one-time vs repeat splits, current-value narratives.
- ACQUISITION-COHORT VIEW (cohorts): customers whose FIRST paid order falls in this period, tracked forward through time. Answers "of the customers I acquired this period, who came back and when?" Use for retention-curve narratives, comparing acquisition vintages, and questions like "are recent customers as valuable as old ones?"

Different questions map to different blocks. Lead with whichever block directly answers the merchant. Don't sum across frames — they describe different customer sets.

NO THREE-VIEW PATTERN (unlike revenue/orders/products/attribution/customer_overview): lifetime spend is inherently longitudinal — on-hold orders shouldn't count as "value delivered" and refund netting happens over years. Pipeline-scoped questions belong to get_customer_overview. If a merchant asks "why doesn't this match my admin Customers report?", explain that this skill is lifetime-shaped and the admin report is period-shaped; they answer different questions.

CRITICAL PRIVACY RULES — read before every response:
- top_customers always carries a pseudonymised `id` field ("Customer #1247") and never includes real names or emails. ALWAYS refer to customers by this id in narration. Each row also carries an admin_url pointing at the merchant's WC Admin Customers report for that pseudonymised id — render the id as a clickable markdown link to that URL (e.g. `[Customer #1247](https://example.com/wp-admin/...)`) so the merchant can jump to the real identity in one click. The skill itself stays pseudonymised; the link just shortcuts the WP Admin lookup the privacy rule already directs them to.
- DO NOT invent names or emails. If you don't see them in the payload, they're not available — full stop.
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
- Individual customer names or emails. The skill is pseudonymised by design — point at WP Admin > WooCommerce > Customers when the merchant needs the real identity behind a `Customer #N`.
- Churn prediction or at-risk customer identification. We show past behaviour, not forecasts. For churn, cohort retention curves (cohorts block) show historic drop-off — the merchant interprets.
- LTV forecasting ("what will Customer #1247 be worth next year?"). We only report actuals.
- Why a specific customer stopped ordering. No engagement / email / session data.
- Customer-level product preferences ("what does Customer #1247 buy?") — point at WP Admin's per-customer order view.
- First-product / first-coupon / first-channel cohort splits. Not exposed on this skill today; point the merchant at get_attribution for channel-level new-customer mix as the closest available cut.
- Guest checkout lifetime value — no persistent guest identity. If the merchant leans heavily on guest checkout, their active_customers will undercount real shoppers.

When a merchant asks for any of these, say plainly what we can and can't see and point at the WP Admin workflow, a setting, or the connector that would answer it. Do NOT suggest that a new Skill, feature, or endpoint be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only ones we can deliver today):
- "How did my store do overall this period?" → wc-analytics-totals subject=revenue
- "Who's buying right now (period-scoped, new vs returning)?" → wc-analytics-totals subject=customers
- "What channels acquired these customers?" → wc-analytics-breakdown subject=attribution, dimension=channel (already splits new-vs-returning per channel)
- "Compare to a different period" → re-run with custom date_start / date_end or different period

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
					'enum' => array( 'pseudonymised' ),
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

		return AnalyticsController::fetch_customer_value(
			$period,
			$date_start,
			$date_end,
			$compare,
			$limit,
			$include_cohorts
		);
	}
}
