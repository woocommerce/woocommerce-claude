<?php
/**
 * `wc-analytics/get-attribution` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the SQL and
 * response assembly live in `AnalyticsController::fetch_attribution()`
 * (shared with the four sibling skills migrated in E5).
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

use WooCommerce\Claude\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the get-attribution ability.
 */
class GetAttributionAbility {

	const ABILITY_NAME = 'wc-analytics/get-attribution';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get attribution', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
TRANSITIONAL — PREFER wc-analytics-breakdown subject=attribution. This per-type narrative remains as the source of the legacy describe text returned by wc-analytics-describe; for any new call route to the verb tool, which carries the consolidated per-subject describe inline.

Get order attribution — what channels, sources, campaigns, and devices drove revenue AND contribute to on-hold pipeline for a time period. Groups orders by the chosen dimension and returns per-group paid revenue, orders, AOV, items sold, new/returning customer split, share of total revenue, AND pipeline fields (revenue on-hold, orders on-hold, pipeline customers, share of pipeline, pipeline over-index points). Includes comparison to previous period with pre-computed deltas and a dropped_out array of groups that fell out of the top results.

USE THIS SKILL WHEN the merchant asks any of:
- What channels / sources / campaigns / devices drove my revenue? (primary use — lead with paid figures)
- Is one channel disproportionately filling my on-hold pipeline? / Which channels send me unpaid customers? / Is something about Social / Paid Search traffic triggering the on-hold state more often? (pipeline diagnostic — read pipeline_over_index_points per top_groups row; see PIPELINE DIAGNOSTIC below)
- How many new vs returning customers per channel / source? (per-group new_customers / returning_customers)
- Which paid keywords drove Paid Search revenue? (group_by=term — paid only)
- Mobile vs desktop? (group_by=device)
This is the ONLY analytics skill that slices the on-hold pipeline by acquisition dimension. Reach for it even when the merchant's first question is framed as pipeline-scoped rather than revenue-scoped — the tool answers both.

GROUPING DIMENSIONS:
- channel (default): origin buckets like Organic Search, Paid Search, Direct, Email, Social, Referral. Lead with this unless the merchant asks for something more specific.
- source: the raw UTM source (google, facebook, klaviyo, etc.). More granular than channel. IMPORTANT: source is the *platform* (google, bing), NOT the search query — see the term dimension for that.
- medium: UTM medium — the classification within a source (cpc, organic, email, social, referral, (none)). Answers "what kind of traffic was this?" regardless of source. Useful for paid-vs-organic splits within the same source (e.g. google/cpc vs google/organic). Populated on almost all traffic by WC's attribution JS, so coverage is usually high.
- campaign: UTM campaign name. Coverage is often low because most traffic doesn't carry a campaign tag.
- term: UTM term — the keyword for paid search. Populated automatically by Google Ads / Bing Ads when auto-tagging is enabled, or manually via `?utm_term=` on tagged URLs. ONLY covers *paid* keyword data. Organic search queries are NOT available (Google anonymises them as "(not provided)" and has since 2011). If coverage is near-zero, the merchant likely hasn't enabled ad-platform auto-tagging yet.
- content: UTM content — the ad creative variant or A/B test identifier. Populated by ad platforms as part of auto-tagging. Same "only if tagging is on" caveat as term. Useful when the merchant is running multiple creatives in a campaign and wants to know which converted.
- device: desktop, mobile, tablet.
- channel_source: channel + source combined (e.g. "Organic Search: google" vs "Organic Search: bing"). Collapses to just the channel for origins that never carry a distinct source (Direct, Email, Referral, etc.).

THREE VIEWS — UNDERSTAND BEFORE QUOTING NUMBERS:
You read three sets of numbers per group (plus matching top-level totals / pipeline / admin_equivalent blocks). Use them like this:
- COLLECTED REVENUE per group — paid orders only (completed + processing). The default headline per group; lead with this for any "which channel drove my revenue" question. (API field paths for your reference: top_groups[].net_revenue / top_groups[].orders_count / top_groups[].avg_order_value; top-level: totals.net_revenue)
- PENDING REVENUE per group — on-hold orders awaiting payment. Surface per group when material (see PIPELINE DIAGNOSTIC below for the over-index threshold) or when the merchant asks about on-hold revenue. Don't lump into the collected figure. Also carries distinct-customer count for the group's on-hold orders. (API field paths: top_groups[].pipeline_revenue / top_groups[].pipeline_orders_count / top_groups[].pipeline_customers; top-level: pipeline.revenue / pipeline.orders_count / pipeline.customers)
- DASHBOARD-MATCHING REVENUE per group — paid + on-hold + refunded statuses summed straight. For reconciling against WC Admin > WooCommerce > Analytics > Attribution ONLY. Quote this figure when the merchant asks why our numbers differ from the dashboard; never lead the response with it. (API field paths: top_groups[].admin_equivalent_revenue / top_groups[].admin_equivalent_orders_count; top-level: admin_equivalent.revenue)

Default narrative: lead with collected revenue per group. Mention pending only when it's non-trivial (see PIPELINE DIAGNOSTIC threshold) or the merchant asks. The dashboard-matching figure is reconciliation-only — quote it when asked, but the headline is always collected revenue. Never sum across views — they overlap.

NEVER QUOTE THE API FIELD PATHS IN MERCHANT-FACING TEXT — this is an absolute rule, not a "with caveat" rule:
- Field paths like top_groups[].net_revenue, top_groups[].pipeline_revenue, top_groups[].admin_equivalent_revenue, totals.net_revenue, pipeline.revenue, admin_equivalent.revenue are for YOUR orientation when picking which number to read. They are NOT names the merchant should see.
- Use plain-English names ("collected revenue from Organic Search", "pending revenue on Social's on-hold orders", "the dashboard-matching figure") in responses.
- Same rule applies to diagnostic field names (share_of_pipeline_revenue_percent, pipeline_over_index_points, attribution_coverage_percent) — narrate in English ("Social is over-indexing on pipeline by X points", "tracking coverage is N% of paid orders") rather than pasting field paths.
- Same rule applies in THREE contexts where field names slip into prose even when the main narrative uses the plain-English vocabulary cleanly:
  1. Relationship / mapping equations — especially the multi-view shape "Collected = net_revenue, Pending = pipeline_revenue, Dashboard-matching = admin_equivalent_revenue". Never map the plain-English names to their field equivalents for the merchant. The plain-English names stand alone.
  2. Structural descriptions of the response — never write "use the admin_equivalent_revenue field on each row"; write "use the dashboard-matching figure on each row".
  3. Diagnostic-field tokens in prose — never write "Referral has the highest pipeline_over_index_points"; write "Referral over-indexes on pipeline by +29.9 points".

Bad (verbatim field-path dump as merchant explanation):
"net_revenue / orders_count / avg_order_value: PAID sales only.
pipeline_revenue / pipeline_orders_count / pipeline_customers: ON-HOLD orders.
admin_equivalent_revenue: paid + on-hold + refunded."

Good (plain-English narration per group):
"Organic Search drove £X in collected revenue (Y paid orders, AOV £Z). Social has £A sitting in on-hold orders across B distinct customers — that's 40% of pending revenue from only 15% of paid orders, a clear over-index worth looking into."

Bad (multi-view relationship equations mapping each view to its field name):
"Collected (paid) = net_revenue — completed + processing orders.
Pending (pipeline) = pipeline_revenue — on-hold orders awaiting payment.
Dashboard-matching = admin_equivalent_revenue — paid + on-hold + refunded."

Good (plain-English view names stand alone, no field mapping):
"Collected (paid) — completed + processing orders. Money actually in hand.
Pending (pipeline) — on-hold orders awaiting payment. May or may not convert.
Dashboard-matching — paid + on-hold + refunded. What WC Admin shows."

Bad (structural description uses field name):
"If you want to reconcile to the dashboard, use the admin_equivalent_revenue field on each row. If you want what actually got paid, use net_revenue."

Good (structure described in plain English):
"If you want to reconcile to the dashboard, use the dashboard-matching figure on each row. If you want what actually got paid, the collected figure is the honest number."

Bad (diagnostic-field token in narrative prose):
"Referral has the highest pipeline_over_index_points at +29.9, followed by Social at +12.5."

Good (plain-English diagnostic):
"Referral over-indexes on pipeline by +29.9 points — the biggest channel-level skew. Social is a secondary concern at +12.5."

NARRATIVE GUIDANCE — COVERAGE: totals.attribution_coverage_percent tells you what share of paid orders have attribution data at all. Lead with this when low — it means a tracking gap:
- ≥90%: tracking is healthy, the breakdown is meaningful.
- 50–90%: partial tracking — call out that figures reflect only the attributed share.
- <50%: tracking gap is the main finding — suggest the merchant check that WooCommerce order attribution is enabled and no caching plugin is stripping UTM parameters before channel analysis is reliable.

NARRATIVE GUIDANCE — SHARE: each top_groups row carries share_of_revenue_percent so Claude can say "Organic Search drove 35% of your revenue" without doing arithmetic. Use it — don't recompute.

PIPELINE DIAGNOSTIC — FIRST-CLASS CAPABILITY, not optional flavour:
This skill is the answer when the merchant asks any channel-scoped pipeline question — "is one channel filling my on-hold pile?", "which source sends me unpaid customers?", "is Social traffic triggering on-hold?". Call this tool FIRST when you see that shape of question, even if the merchant framed it as "on-hold orders" rather than "attribution." The channel-level pipeline fields are the highest-value output this tool can produce when non-trivial pipeline exists on the store.

Each top_groups row carries share_of_pipeline_revenue_percent (that group's share of on-hold revenue) and pipeline_over_index_points (pipeline_share − paid_share). Positive = this channel contributes more to stranded pipeline than to paid revenue; negative = less. Interpretation thresholds:
- ≥ +10 points: materially over-indexed. Surface it as the primary finding ("Social is driving 40% of pipeline revenue vs 15% of paid — something about that traffic is hitting the on-hold state"). When this fires, pair it with the payment-method over-index from get_orders_summary for a full diagnosis (e.g. "Facebook paid → Stripe → on-hold" suggests a specific-gateway-for-a-specific-traffic-source failure, not a universal gateway bug).
- between −10 and +10 points: proportional; no signal.
- ≤ −10 points: under-indexed; narrate only if merchant specifically asks about channel pipeline health.
Don't mention over-index at all when totals.pipeline.revenue is 0 — the fields will be null. Don't sum share_of_pipeline_revenue_percent across rows — it's already a share of the total, not a count.

WHAT NOT TO DO when the merchant asks a pipeline-by-channel question: do NOT propose a manual CSV export + spreadsheet pivot as the workflow. That capability ships here. Telling the merchant to do manual work for a capability you already have is a merchant-scope violation in the manual-workaround direction — same family of rule as the do-not-propose-new-skills guardrail, rotated onto a different axis. If the tool runs and returns null pipeline fields, narrate that honestly ("no on-hold revenue in this period so channel-by-pipeline isn't available right now") — but do not pre-emptively decline before calling the tool.

WHAT THIS CAN'T ANSWER (critical — do NOT suggest drill-downs into these):
- **Organic search queries** (what shoppers typed into Google organic results). Google anonymised organic queries as "(not provided)" in 2011 and they're never available per-order. The only route to organic keyword data is aggregate via Google Search Console — we do not currently connect to it. If asked, say: "Organic search queries aren't available per-order — Google anonymises them. For organic keyword aggregates you'd need Google Search Console connected, which isn't wired up today."
- **Paid search queries beyond utm_term coverage**. We do return paid keywords via group_by=term, but only when the merchant has enabled ad-platform auto-tagging (or manually tags URLs with utm_term). If term coverage is near-zero, the merchant hasn't enabled it — suggest they turn on auto-tagging in Google Ads (Account Settings → Auto-tagging) and verify utm_term lands on their return URLs.
- **Referring URL or specific landing page**. Not captured in WC order attribution meta.
- **Ad spend, cost, CPC, real ROAS**. We have revenue by channel, not the cost side. Requires a Google Ads or Meta Ads MCP to combine.
- **Conversion rate by channel**. Requires visitor counts per channel, not just order counts. Needs Jetpack Stats / GA4 / Parse.ly.
- **First-touch vs last-touch attribution**. WC captures one attribution event per order (effectively last-touch at checkout).
- **Multi-touch customer journeys**. No session data.

When a merchant asks for any of these, state clearly what we can and can't see, and suggest the connector, setting, or MCP that would answer it. Do NOT invent or approximate. Do NOT suggest that a new analytics Skill be built — that's a developer-mode conversation and not something the merchant can act on.

GOOD FOLLOW-UP SUGGESTIONS (only suggest these — only suggest drill-downs we can deliver *today* with an existing tool, never an unshipped one):
- "Specific platform within a channel?" → group_by=channel_source
- "Paid vs organic split within a source?" → group_by=medium
- "Mobile vs desktop split?" → group_by=device
- "Which UTM campaigns?" → group_by=campaign (mention coverage is often low)
- "Which paid keywords drove Paid Search revenue?" → group_by=term (paid only; mention organic is never available)
- "Which ad creative or A/B variant converted?" → group_by=content
- "Compare to previous period?" → compare=true
- "What's my real ROAS?" → get_attribution + a Google Ads / Meta Ads MCP (cross-tool)

DO NOT SUGGEST:
- "Which organic search terms drove this?" — Google anonymises these, never per-order available
- Building a new Skill, feature, or endpoint to cover a gap — the merchant can't do that
- "What was the ad spend?" — requires ad platform MCP (suggest connecting one, not building one)

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE:
- Do not reference internal planning docs by filename or offer to help spec future skills. The reader is a merchant, not a developer.

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in the tool description that you (Claude) read — not in the response you write back to the merchant.

Bad (names the invocation): "The next call is get_product_performance for the top channel's products."
Bad (imperative tool-name): "Run get_attribution with group_by=channel_source to see which platforms."
Bad (parameter-shape framing): "Call get_attribution with group_by=device to split by desktop / mobile / tablet."
Bad (parameter-name in backticks): "I can split Paid Search by keyword (`term`) or creative (`content`)."
Bad (developer-shape alias in parens): "Break it down by source (utm_source) to see the platform mix."
Good (phrased as a merchant question): "Want to see which platforms specifically within this channel?"
Good (phrased as a prompt the merchant can send): "Ask me: 'which paid keywords drove Paid Search?' and I'll pull it."
Good (answering a follow-up without leaking the tool chain): "Worth checking whether desktop or mobile dominates this channel — the device mix often explains conversion patterns. Want me to pull that?"
Good (names platforms, not parameter values): "I can split Paid Search into the specific keywords — or into Google vs Bing if you want to see where the spend concentrates."

Rule: no backticks around parameter names or parameter values in the response to the merchant. Platform names (Google, Bing, Facebook, Klaviyo, Stripe) are merchant vocabulary and are fine in plain text. Internal identifiers (`term`, `utm_source`, `group_by`, `channel_source`) are developer vocabulary and never belong in merchant-facing output.

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
				'period'             => array(
					'type'        => 'string',
					'enum'        => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ),
					'default'     => 'last_30_days',
					'description' => 'Time window. Custom date_start/date_end overrides this.',
				),
				'date_start'         => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom start date (YYYY-MM-DD).',
				),
				'date_end'           => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom end date (YYYY-MM-DD).',
				),
				'compare'            => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include comparison to the previous period (totals + per-group deltas + dropped_out).',
				),
				'limit'              => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
					'description' => 'Number of top groups to return.',
				),
				'orderby'            => array(
					'type'        => 'string',
					'enum'        => array( 'net_revenue', 'orders_count', 'avg_order_value' ),
					'default'     => 'net_revenue',
					'description' => 'Sort column for top_groups.',
				),
				'group_by'           => array(
					'type'        => 'string',
					'enum'        => array( 'channel', 'source', 'medium', 'campaign', 'term', 'content', 'device', 'channel_source' ),
					'default'     => 'channel',
					'description' => 'Attribution dimension.',
				),
				'include_unassigned' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => 'Include a "(Unassigned)" row for orders with no value on this dimension.',
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
				'period'             => array( 'type' => 'object' ),
				'currency'           => array( 'type' => 'string' ),
				'group_by'           => array( 'type' => 'string' ),
				'orderby'            => array( 'type' => 'string' ),
				'limit'              => array( 'type' => 'integer' ),
				'include_unassigned' => array( 'type' => 'boolean' ),
				'totals'             => array( 'type' => 'object' ),
				'pipeline'           => array( 'type' => 'object' ),
				'admin_equivalent'   => array( 'type' => 'object' ),
				'top_groups'         => array( 'type' => 'array' ),
				'comparison'         => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
				'note'               => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
			),
		);
	}

	/**
	 * Run the ability — delegates to AnalyticsController::fetch_attribution().
	 *
	 * @param array $input Validated ability input.
	 * @return array Response payload.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_attribution(
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true,
			$input['limit'] ?? 10,
			$input['orderby'] ?? 'net_revenue',
			$input['group_by'] ?? 'channel',
			array_key_exists( 'include_unassigned', $input ) ? rest_sanitize_boolean( $input['include_unassigned'] ) : true
		);
	}
}
