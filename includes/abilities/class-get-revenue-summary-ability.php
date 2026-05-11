<?php
/**
 * `wc-analytics/get-revenue-summary` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the SQL and
 * response assembly live in `AnalyticsController::fetch_revenue_summary()`
 * (shared with the four sibling skills migrated in E5).
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-revenue-summary ability.
 */
class GetRevenueSummaryAbility {

	const ABILITY_NAME = 'wc-analytics/get-revenue-summary';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get revenue summary', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
TRANSITIONAL — PREFER wc-analytics-totals subject=revenue. This per-type narrative remains as the source of the legacy describe text returned by wc-analytics-describe; for any new call route to the verb tool, which carries the consolidated per-subject describe inline.

Get revenue summary for a time period — net/total sales, orders, AOV, items sold, refunds, taxes, shipping. Includes comparison to previous period with pre-computed percentage changes.

THREE VIEWS — UNDERSTAND BEFORE QUOTING NUMBERS:
You read three sets of numbers. Use them like this:
- COLLECTED REVENUE — paid orders only (completed + processing), net of refunds. The default headline; lead with this for any "how much did I sell" question. (API field path for your reference: metrics.net_sales / metrics.gross_sales / metrics.orders_count)
- PENDING REVENUE — revenue sitting in on-hold orders awaiting payment (bank transfer, BACS, invoice, cheque). Not yet collected. Surface separately when material (≥5% of collected net sales) or when the merchant explicitly asks about on-hold revenue. Don't lump into the collected figure. (API field path: pipeline.revenue)
- DASHBOARD-MATCHING REVENUE — paid + on-hold + refunded statuses summed straight. For reconciling against WC Admin > WooCommerce > Analytics > Revenue ONLY. Quote this figure when the merchant asks why our numbers differ from the dashboard; never lead the response with it. (API field path: admin_equivalent.net_sales)

Default narrative: lead with collected revenue. Mention pending only when it's non-trivial or the merchant asks about it. The dashboard-matching figure is reconciliation-only — quote it when asked, but the headline is always collected revenue.

NEVER QUOTE THE API FIELD PATHS IN MERCHANT-FACING TEXT — this is an absolute rule, not a "with caveat" rule:
- Field paths like metrics.net_sales, pipeline.revenue, admin_equivalent.net_sales are for YOUR orientation when picking which number to read. They are NOT names the merchant should see.
- Use the plain-English names ("collected revenue", "pending revenue", "the dashboard-matching figure") in responses.
- Same rule applies to formula breakdowns. If the merchant asks how a metric is computed, explain in English ("net sales are gross sales minus refunds") — never paste the field-name arithmetic ("gross_sales − refunds").

Bad (verbatim field-path dump as merchant explanation):
"metrics: PAID revenue from completed + processing orders, net of refunds.
pipeline: ON-HOLD orders awaiting payment.
admin_equivalent: paid + on-hold + refunded, lumped together."

Good (plain-English narration):
"Your collected revenue (paid orders, net of refunds) is £X. Pending revenue sitting in on-hold orders is £Y. The figure WC Admin shows is £Z because it lumps those together with refunded orders."

NARRATIVE GUIDANCE — REGULATORY THRESHOLDS: when the merchant's annualised revenue crosses or sits near a tax-registration threshold, surface it proactively — but frame it as jurisdiction context, never as tax advice. Revenue is the earliest signal for this; if the annualised total is above a threshold, offer to pull the tax summary to see what's actually been collected.

Common thresholds worth flagging:
- UK VAT: £90k annualised turnover triggers mandatory registration (2024 update).
- EU OSS: €10k/year in cross-border B2C sales across EU destinations triggers OSS registration (or per-country VAT).
- Canada GST/HST: CAD 30k small-supplier threshold.
- Australia GST: AUD 75k/year.
- US sales tax: state-by-state economic nexus — varies widely (e.g. South Dakota USD 100k / 200 transactions, California USD 500k). No single number — flag "check your state's nexus rules" rather than naming a figure.

Fire when:
- Annualised revenue (YTD × 12/months-elapsed) crosses a registration threshold for the merchant's home country. Offer to pull the tax summary to confirm collection is in place.
- The merchant asks an open "how's the business doing" question and the annualised figure is at or above a threshold where tax registration becomes a requirement.

Don't fire when:
- The merchant asked a specific arithmetic question ("what was revenue last month?"). Answer the literal question first; threshold context is a follow-up only if relevant.
- Annualised revenue is clearly below all thresholds.

Always hedge: "if you're trading above £90k annualised, UK VAT registration becomes mandatory — worth checking with your accountant" — never state a compliance conclusion. Annualising mid-year is back-of-envelope; the merchant's accountant knows the exact tax year and the exact rules. If threshold context is relevant, offer to pull the tax summary — that's where collected-tax figures live.

WHAT THIS CAN'T ANSWER:
- Revenue broken down by channel, product, or customer — those are separate tools (wc-analytics-breakdown subject=attribution / subject=products, wc-analytics-totals subject=customers). Suggest them as drill-downs.
- Revenue forecasts or projections. Report actuals only.
- Net profit or margin. No COGS data available.
If the merchant asks for any of these, say what you can and can't see and suggest the right tool. Do NOT suggest that a new Skill, endpoint, or feature be built — the merchant can't action that.

GOOD FOLLOW-UP SUGGESTIONS (only suggest these — only suggest drill-downs we can deliver *today* with an existing tool, never an unshipped one):
- "Break this down by product" → wc-analytics-breakdown subject=products
- "Break it down by category / country / payment method" → wc-analytics-breakdown subject=revenue, dimension=category|country|payment_method
- "What channels drove this?" → wc-analytics-breakdown subject=attribution, dimension=channel
- "Who's buying?" → wc-analytics-totals subject=customers
- "Show me the orders behind this" → wc-analytics-rows entity=orders
- "Compare to a different period" → re-run with custom dates

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE:
- Do not reference internal planning docs by filename (e.g. ANALYTICS-SKILLS-MAP.md) or offer to help spec future skills. The reader is a merchant, not a developer. Describe gaps in plain language and point at WP Admin or a connector.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_product_performance to see what's behind this revenue."
Bad (imperative tool-name): "Run get_revenue_summary with compare=true to see last quarter."
Bad (parameter-shape framing): "Call get_attribution with period=last_month to see the channel mix."
Bad (parameter-name in backticks): "Want to split this by `period` or by `compare`?"
Bad (developer-shape alias in parens): "We could filter by status (wc-on-hold) to see pipeline only."
Good (phrased as a merchant question): "Want me to break this down by product?"
Good (phrased as a prompt the merchant can send): "Ask me 'compare this to last quarter' and I'll pull it."
Good (answering a follow-up without leaking the tool chain): "Worth checking which channels drove this — acquisition mix often explains revenue swings. Want me to pull the split?"
Good (names dimensions, not parameter values): "I can split this by category, country, or payment method — which cut is most useful?"

Rule: no backticks around parameter names or parameter values in the response to the merchant. Payment-method names (BACS, cheque, Stripe, PayPal) and status names merchants recognise (on-hold, processing, completed) are merchant vocabulary and are fine in plain text. Internal identifiers (`period`, `compare`, `wc-on-hold`, `status_breakdown`) are developer vocabulary and never belong in merchant-facing output.

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess analytics figures. Never sum values across the three views — they overlap. If the tool returns an error or empty data, tell the merchant you couldn't retrieve the data — do not fabricate numbers.
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
					'description' => 'Include comparison to the previous period (pre-computed percent deltas).',
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
				'metrics'          => array( 'type' => 'object' ),
				'pipeline'         => array( 'type' => 'object' ),
				'admin_equivalent' => array( 'type' => 'object' ),
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
	 * Run the ability — delegates to AnalyticsController::fetch_revenue_summary().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_revenue_summary(
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true
		);
	}
}
