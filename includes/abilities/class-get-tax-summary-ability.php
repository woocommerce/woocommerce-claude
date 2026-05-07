<?php
/**
 * `wc-analytics/get-tax-summary` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the SQL and
 * response assembly live in `AnalyticsController::fetch_tax_summary()`.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-tax-summary ability.
 */
class GetTaxSummaryAbility {

	const ABILITY_NAME = 'wc-analytics/get-tax-summary';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get tax summary', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
Get tax collected for a period — total tax, order tax vs shipping tax, refunded tax, the net-tax figure that maps to a VAT / sales-tax return, and a per-rate breakdown. Three-view pattern (paid / pipeline / admin_equivalent) so collected tax, on-hold tax (collected at checkout but not yet paid), and dashboard reconciliation totals stay distinct. Aggregated only — no per-order tax detail (privacy boundary).

THREE VIEWS — UNDERSTAND BEFORE QUOTING NUMBERS:
You read three sets of numbers. Use them like this:
- COLLECTED TAX — paid orders only (completed + processing). The default headline; lead with this for any "how much tax did I collect" question. (API field path for your reference: totals.total_tax / totals.order_tax / totals.shipping_tax)
- PENDING TAX — tax on on-hold orders. Collected at checkout but not yet paid through, so not legally collected revenue until the order clears. Surface separately when material (≥5% of collected total_tax) or when the merchant explicitly asks about on-hold tax. Don't lump into the collected figure. (API field path: pipeline.total_tax)
- DASHBOARD-MATCHING TAX — paid + on-hold + refunded summed straight. For reconciling against WC Admin > WooCommerce > Reports > Tax ONLY. Quote this figure when the merchant asks why our numbers differ from the dashboard; never lead the response with it. (API field path: admin_equivalent.total_tax)

Default narrative: lead with collected tax. Mention pending only when it's non-trivial or the merchant asks about it. The dashboard-matching figure is reconciliation-only — quote it when asked, but the headline is always collected tax.

RECONCILIATION QUESTIONS — ALWAYS CALL THE TOOL FIRST:
When the merchant asks why our numbers differ from WC Admin's report (or any "why doesn't tax X match Y" question), CALL THIS TOOL FIRST before narrating. The dashboard-matching figure is the bridge between the merchant's two figures — quoting it without grounding in the actual response leads to invented WC internals (option names, table names, indexing causes) pulled from training data instead of the real explanation.

The right shape: call the tool → read totals.total_tax (collected) AND admin_equivalent.total_tax (dashboard-matching) AND pipeline.total_tax (pending) → narrate the gap as "your collected figure is £X; WC Admin shows £Y because it adds the £Z sitting in on-hold orders plus the £W in refunds." Never reason about the gap generically without those numbers in hand.

Bad (reasoning about WC drift causes without calling the tool):
"WC Admin reports read from `wc_order_tax_lookup` / `wc_order_stats`, not the live order tables. If a recent order didn't get indexed (failed cron, plugin conflict), totals diverge. Try Settings > Tools > Regenerate order stats."

Good (tool first, then narrate the gap with real numbers):
"Pulled both figures. Your collected tax is £X. WC Admin shows £Y because it lumps in £Z from on-hold orders that are still awaiting payment. Once those clear, the two figures will align — there's nothing to fix here."

NEVER QUOTE THE API FIELD PATHS IN MERCHANT-FACING TEXT — this is an absolute rule, not a "with caveat" rule:
- Field paths like totals.total_tax, pipeline.total_tax, admin_equivalent.total_tax, totals.net_tax, totals.effective_tax_rate_percent, top_rates[].share_of_tax_percent are for YOUR orientation when picking which number to read. They are NOT names the merchant should see.
- Use the plain-English names ("collected tax", "pending tax", "the dashboard-matching figure", "net tax position", "effective tax rate", "this rate's share of total tax") in responses.
- Same rule applies to formula breakdowns. If the merchant asks how a metric is computed, explain in English ("collected tax as a share of net revenue") — never paste the field-name arithmetic ("total_tax ÷ paid net revenue").

Bad (verbatim field-path dump as merchant explanation):
"totals.total_tax — PAID tax only (completed + processing).
pipeline.total_tax — tax on on-hold orders.
admin_equivalent.total_tax — paid + on-hold + refunded, summed straight."

Good (plain-English narration):
"Your collected tax (paid orders) is £X. Pending tax sitting in on-hold orders is £Y. The figure WC Admin shows is £Z because it lumps those together with refunded orders."

Bad (field-name + value in backticks): "The API returns this directly as `effective_tax_rate_percent: 0` (total_tax ÷ paid net revenue)."
Bad (formula in parens with field names): "Effective tax rate is 0% (total_tax ÷ paid net revenue)."
Good (plain-English with no internal labels): "Effective tax rate is 0% — collected tax as a share of net revenue. Same answer regardless of period because no tax is being collected on any orders."

KEY FIELDS — READ THEM, DON'T DERIVE THEM:
- totals.net_tax: total_tax − refunded_tax. The figure that goes on a VAT / sales-tax return. Always read this directly when the merchant asks "what do I owe HMRC / IRS?" — never compute total − refunded yourself.
- totals.effective_tax_rate_percent: total_tax ÷ paid net_revenue × 100. Pre-computed. Use directly when the merchant asks "what's my effective tax rate?" — don't divide manually.
- totals.taxable_share_percent: taxable_orders ÷ total_paid_orders × 100. Pre-computed. Tells the merchant what fraction of their orders are taxable (rest are zero-rated, exempt, or to non-taxable jurisdictions).
- top_rates[].share_of_tax_percent: row.total_tax ÷ totals.total_tax × 100, pre-computed. Read it; never recompute from row + totals.

REFUNDED TAX:
- totals.refunded_tax is the absolute (positive) amount of tax in refund sub-orders for the period — clawed back from the original collection.
- For VAT-return reasoning, use net_tax (= total_tax − refunded_tax). Don't reach into refund_analysis for this — refund_analysis returns total refunded amount (net + tax + shipping), not the tax-only slice.

PER-RATE NARRATIVE:
- top_rates rows carry the rate's name (e.g. "UK VAT"), country (ISO-2 code), state (often empty for country-wide rates), and the rate percentage as it's configured in WP Admin.
- A rate with `tax_rate_country='GB'` and `tax_rate_state=''` is a country-wide UK rate. A rate with `tax_rate_country='US'` and `tax_rate_state='CA'` is a California sales-tax row. Multi-state US merchants will see one row per state.
- Rates with `tax_rate_id=0` represent tax that wasn't tied to a configured rate (legacy data, manually-entered tax). Surface honestly — they're real collected tax, just not attributable to a current setting.

SMALL-N HONESTY (per the design pattern in CLAUDE.md):
- When a top_rates row has orders_count ≤ 5, the row's share / percentages are noisy — a single high-tax order can spike a row to look like a major contributor. Frame as signal-to-watch, not conclusion. State the caveat explicitly when the merchant could otherwise act on the noise.
- A row with orders_count = 0 but positive amounts is a structural artefact (e.g. shipping_tax-only without any line tax) — narrate honestly rather than reporting "0 orders".

EMPTY-PERIOD HANDLING:
- If totals.total_tax = 0 AND pipeline.total_tax = 0, the store either doesn't charge tax in the period or has no taxable orders. Say so honestly — don't fabricate a figure. The note field will explain.
- If top_rates is empty but totals.total_tax > 0, the tax was collected without a configured rate (legacy / manual entry). Surface as "tax collected but rates aren't matched to current settings" rather than missing data.

NARRATIVE GUIDANCE — COMPARISON: comparison.changes carries pre-computed percent / amount / direction for every total. Per-rate change blocks exist on top_rates rows that appeared in both periods. Rates that dropped out land in comparison.dropped_out. Use those numbers as-is — never recompute deltas yourself.

NARRATIVE GUIDANCE — REGULATORY THRESHOLDS: when a merchant's figures cross or sit near a tax-registration threshold, surface it proactively — but frame it as jurisdiction context, never as tax advice.

Common thresholds worth flagging:
- UK VAT: £90k annualised turnover triggers mandatory registration (2024 update).
- EU OSS: €10k/year in cross-border B2C sales across EU destinations triggers OSS registration (or per-country VAT).
- Canada GST/HST: CAD 30k small-supplier threshold.
- Australia GST: AUD 75k/year.
- US sales tax: state-by-state economic nexus — varies widely (e.g. South Dakota USD 100k / 200 transactions, California USD 500k). No single number — flag "check your state's nexus rules" rather than naming a figure.

Fire when:
- Collected tax is £0 or implausibly low AND annualised revenue (YTD × 12/months-elapsed, read from get_revenue_summary if needed) crosses a threshold. This is the strongest fire-signal on this skill.
- The merchant asks an open "should I be worried" question and tax configuration is a plausible concern.
- The merchant explicitly asks about a jurisdiction's tax and their data suggests they may be above the registration threshold.

Don't fire when:
- The merchant asked a specific arithmetic question ("what was March VAT?"). Answer the literal question first; threshold context is a follow-up only if relevant.
- The store is clearly below all thresholds (low annualised revenue, no international orders).

Always hedge: "if you're VAT-registered and trading above £90k, this is worth checking with your accountant" — never state a compliance conclusion. Annualising mid-year is back-of-envelope; the merchant's accountant knows the exact tax year and the exact rules.

WHAT THIS CAN'T ANSWER (critical — do NOT suggest drill-downs into these):
- Per-order tax detail. Aggregated-only by deliberate design (privacy + load). Point at WP Admin > WooCommerce > Reports > Tax for line-item-level drill-in, or WP Admin > WooCommerce > Orders with a tax filter for individual orders.
- Tax owed by jurisdiction (UK VAT bucket vs EU VAT bucket vs US sales tax bucket). Only per-rate, not jurisdiction roll-up. If the merchant asks for an "EU VAT" total, scan the top_rates rows for EU country codes and sum honestly in-chat — flag the manual roll-up so they know it's read-and-add, not pre-computed.
- Tax-class breakdowns (digital vs reduced-rate VAT items). The lookup table groups by rate-id, not class. If asked, point at the per-rate breakdown as the closest existing answer.
- MTD VAT submission to HMRC. Out of scope — that's an HMRC API integration, not analytics.
- Whether you should be charging tax on a specific order. Compliance question — point at WP Admin > WooCommerce > Settings > Tax for the merchant's current configuration.
- US sales-tax nexus thresholds. Out of scope — that's a tax-compliance problem (TaxJar / Avalara). We surface what was collected; not whether it should have been.
- Future tax liability or projection. We report actuals only — no forecasting.
- Tax avoidance advice. Plainly out of scope.

When a merchant asks for any of these, say plainly what we can and can't see, and point at the WP Admin workflow, the merchant's accountant, or the connector that would answer it. Do NOT suggest that a new Skill, feature, or endpoint be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only suggest these — only suggest drill-downs we can deliver *today* with an existing tool, never an unshipped one):
- "Want a country breakdown of revenue?" → get_revenue_breakdown with group_by=country
- "Compare to last quarter?" → compare=true or recall with different dates
- "What got refunded?" → get_refund_analysis for full refund context
- "Where's the rest of revenue coming from?" → get_revenue_summary

DO NOT SUGGEST:
- Per-order tax detail via this skill — aggregated only, point at WP Admin Reports > Tax
- Jurisdiction-bucket totals as a parameter — read the rows and sum honestly
- Tax-class breakdowns via a group_by param — not exposed
- MTD / VAT-submission workflows — out of scope
- Building a new Skill, feature, or endpoint — the merchant can't do that

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE — this is an absolute rule, not a "mention with caveat" rule:
- Do not reference internal planning docs by filename or offer to help spec future skills.
- Do not name any unshipped or planned internal tool/skill identifier. Including any `get_*` name that isn't on the tool list you can see, or variants like "the planned X skill", "the X skill would answer this", "when X ships". These leak developer-mode framing into merchant-facing chat.
- Do not offer to help spec future skills, suggest endpoints be built, or treat the reader as the developer of this plugin.

HOW TO DESCRIBE GAPS WITHOUT LEAKING:
Bad (leaks internal naming): "A jurisdiction-rollup skill would answer this."
Bad (still leaks): "You'd need the planned tax-class breakdown to surface it properly."
Good (plain language + today-action): "That question groups rates into VAT buckets — UK, EU, rest of world. The per-rate breakdown gives you the raw rows; sum the GB and EU rows yourself for the buckets, or check WP Admin > WooCommerce > Reports > Tax which groups them visually."

The rule: describe the *shape of the missing capability* in merchant-facing language, and point at a today-action they can take (WP Admin, a connector, a manual workflow). Never name the internal tool identifier that would fill the gap.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_revenue_breakdown with group_by=country."
Bad (imperative tool-name): "Run get_refund_analysis with period=last_month to see what got refunded."
Bad (parameter-shape framing): "Call get_tax_summary with compare=true to see the change vs last period."
Bad (parameter-name in backticks): "I can break that down by `tax_rate_id` or filter by `tax_rate_country`."
Bad (developer-shape alias in parens): "Sort by total_tax (the default orderby) to see top rates first."
Good (phrased as a merchant question): "Want me to compare this against last quarter?"
Good (phrased as a prompt the merchant can send): "Ask me: 'how does this compare to last quarter?' and I'll pull it."
Good (answering a follow-up without leaking the tool chain): "Worth checking which countries are driving revenue — that often explains where the tax is coming from. Want me to pull that?"
Good (names jurisdictions, not parameter values): "I can group by tax rate, or you can ask about the UK VAT row specifically."

Rule: no backticks around parameter names or parameter values in the response to the merchant. Jurisdiction names (UK, EU, US) and rate names ("UK VAT", "California sales tax") are merchant vocabulary and are fine in plain text. Internal identifiers (`tax_rate_id`, `tax_rate_country`, `group_by`, `orderby`) are developer vocabulary and never belong in merchant-facing output.

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess analytics figures. If the tool returns an error or empty data, tell the merchant you couldn't retrieve the data — do not fabricate numbers.
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
				'period'     => array(
					'type'        => 'string',
					'enum'        => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ),
					'default'     => 'last_30_days',
					'description' => 'Time window. Custom date_start/date_end overrides this.',
				),
				'date_start' => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom start date (YYYY-MM-DD).',
				),
				'date_end'   => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom end date (YYYY-MM-DD).',
				),
				'compare'    => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include comparison to the previous period (totals + per-rate deltas + dropped_out).',
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Number of top tax rates to return.',
				),
				'orderby'    => array(
					'type'        => 'string',
					'enum'        => array( 'total_tax', 'order_tax', 'shipping_tax', 'orders_count' ),
					'default'     => 'total_tax',
					'description' => 'Sort column for top_rates.',
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
				'period'           => array( 'type' => 'object' ),
				'currency'         => array( 'type' => 'string' ),
				'orderby'          => array( 'type' => 'string' ),
				'limit'            => array( 'type' => 'integer' ),
				'totals'           => array( 'type' => 'object' ),
				'pipeline'         => array( 'type' => 'object' ),
				'admin_equivalent' => array( 'type' => 'object' ),
				'top_rates'        => array( 'type' => 'array' ),
				'comparison'       => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
				'note'             => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
			),
		);
	}

	/**
	 * Run the ability — delegates to AnalyticsController::fetch_tax_summary().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_tax_summary(
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true,
			$input['limit'] ?? 10,
			$input['orderby'] ?? 'total_tax'
		);
	}
}
