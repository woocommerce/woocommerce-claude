<?php
/**
 * `wc-analytics/totals` ability.
 *
 * Verb-shaped aggregate router for headline analytics totals.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities;

use WooCommerce\CommerceAbilities\Analytics\AnalyticsService;

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
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Consolidated multi-subject narrative; per-subject sections follow.
				'description'         => __(
					<<<'DESCRIPTION'
Headline aggregate for one of six subjects — revenue, orders, customers, customer_value, tax, refunds. Pick the subject by the question shape; the response envelope is stable, the inside changes by subject. For "broken down by X" questions use the breakdown tool; for time-series use the series tool; for "show me the actual records" use the rows tool.

UNIVERSAL RULES (the connector instructions carry these in full — repeated here so each subject section can reference them):
- Never quote internal field paths in merchant-facing text. Field paths are for YOUR orientation when picking which number to read; merchants see plain-English names ("collected revenue", "pending revenue", "the dashboard-matching figure"). Same applies to formula breakdowns: explain in English ("net sales are gross sales minus refunds") rather than pasting field-name arithmetic.
- Never sum across the three views (paid / pipeline / admin_equivalent) — they overlap.
- Never name internal tool identifiers, parameter names, or storage slugs in merchant-facing output. Phrase follow-ups as questions ("Want me to break this down by product?"), not invocations.
- Never propose new skills, endpoints, or features as a fix for a gap. The reader is a merchant, not the plugin developer.
- The 365-day extended-range gate fires on every totals call (all six subjects), at this ability's level — before dispatch to the underlying fetch. When the gate fires, present the cost estimate to the merchant, wait for approval, then re-run with the same params. The error data's cost_estimate.type carries the literal value to pass to wc-analytics-confirm-large-range (totals-prefixed for verb-tool calls — e.g. totals:revenue — so approvals do not collide with breakdown or series calls sharing the same subject). See the connector instructions for the full handshake.

================================================================================
SUBJECT = revenue  → headline net/total sales, orders, AOV, items sold, refunds, taxes, shipping, plus three-view reconciliation. Period filters paid orders; comparison block carries pre-computed deltas.
================================================================================

THREE VIEWS — paid / pipeline / admin_equivalent:
- COLLECTED REVENUE — paid orders only (completed + processing), net of refunds. The default headline; lead with this. (Field path for YOUR reference: metrics.net_sales / metrics.gross_sales / metrics.orders_count.)
- PENDING REVENUE — on-hold orders awaiting payment (bank transfer, BACS, invoice, cheque). Surface separately when material (≥5% of collected net sales) or when the merchant asks. (Field path: pipeline.revenue.)
- DASHBOARD-MATCHING REVENUE — paid + on-hold + refunded summed straight. Reconciliation only — quote when the merchant asks why our numbers differ from WC Admin > Analytics > Revenue; never lead with it. (Field path: admin_equivalent.net_sales.)

Bad (verbatim field-path dump): "metrics: PAID revenue from completed + processing orders, net of refunds. pipeline: ON-HOLD orders awaiting payment. admin_equivalent: paid + on-hold + refunded, lumped together."
Good (plain-English): "Your collected revenue (paid orders, net of refunds) is £X. Pending revenue sitting in on-hold orders is £Y. The figure WC Admin shows is £Z because it lumps those together with refunded orders."

KEY METRICS:
- metrics.average_order_value uses net_total > 0 as the denominator filter, so 100%-coupon paid orders don't drag the average down.
- metrics.refunds is the absolute (positive) sum of refund sub-orders' total_sales — netting already happened in metrics.net_sales.
- metrics.total_customers = COUNT(DISTINCT customer_id) across paid parent orders only.

REGULATORY THRESHOLDS — surface jurisdiction context proactively, never as tax advice:
Common thresholds worth flagging when the merchant's annualised revenue (YTD × 12 ÷ months-elapsed) sits at or above one:
- UK VAT: £90k annualised turnover triggers mandatory registration (2024 update).
- EU OSS: €10k/year cross-border B2C across EU destinations triggers OSS (or per-country VAT).
- Canada GST/HST: CAD 30k small-supplier threshold.
- Australia GST: AUD 75k/year.
- US sales tax: state-by-state economic nexus, varies widely (e.g. South Dakota USD 100k / 200 transactions, California USD 500k). No single number — flag "check your state's nexus rules" rather than naming a figure.

Fire when annualised revenue crosses a threshold for the merchant's home country, OR when the merchant asks an open "how's the business doing" question and the figure sits above a threshold. Don't fire when they asked a specific arithmetic question — answer literal first; thresholds are follow-up only. Always hedge: "if you're trading above £90k annualised, UK VAT registration becomes mandatory — worth checking with your accountant" — never state a compliance conclusion. Annualising mid-year is back-of-envelope; the accountant knows the exact tax year and rules. Offer to pull the tax subject if threshold context is relevant.

WHAT THIS CAN'T ANSWER (revenue subject):
- Revenue broken down by channel, product, country, payment method, or customer — those are the breakdown tool's territory.
- Revenue forecasts or projections. Actuals only.
- Net profit or margin. No COGS data available.

GOOD FOLLOW-UPS:
- "Break this down by product / category / country / payment method" → wc-analytics-breakdown
- "What channels drove this?" → wc-analytics-breakdown with subject=attribution
- "Who's buying?" → totals subject=customers (or customer_value for lifetime view)
- "Show me the actual orders" → wc-analytics-rows with entity=orders
- "Compare to a different period" → re-run with custom dates

================================================================================
SUBJECT = orders  → order count, AOV, items per order, status breakdown across all statuses, value distribution, day-and-hour heatmap, multi-currency detection. Includes the richest pipeline diagnostic block in the surface.
================================================================================

THREE VIEWS — paid / pipeline / admin_equivalent:
- PAID ORDERS — completed + processing only. Default headline. (Field path: metrics.orders_count / metrics.revenue / metrics.avg_order_value.)
- PENDING ORDERS — on-hold orders awaiting payment. Surface when material (≥5% of paid orders_count or revenue), or when the merchant asks about on-hold. The pending block also carries diagnostic fields — see PIPELINE DIAGNOSTIC. (Field path: pipeline.orders_count / pipeline.revenue.)
- DASHBOARD-MATCHING ORDERS — paid + on-hold + refunded summed straight. Reconciliation only. (Field path: admin_equivalent.orders_count.)

A separate status_breakdown block shows EVERY status including pending/failed — use that for "how many orders are stuck on hold / failing" questions, not the three views above.

PIPELINE DIAGNOSTIC — "is this a real backlog?":
The pipeline block carries three diagnostic fields on top of orders_count + revenue. Read them together:
- pipeline.oldest_order_days: age of the oldest on-hold order placed in the period. Zero-pipeline → null.
- pipeline.age_buckets: distribution across "0-7d" / "8-30d" / "31-60d" / "60d+", each value a count of on-hold orders in that bucket.
- pipeline.payment_methods: per-method rows sorted by orders_count desc, each carrying orders_count, revenue, share_of_pipeline_revenue_percent, share_of_paid_revenue_percent, and pipeline_over_index_points.

Diagnostic interpretation:
- Freshness: if age_buckets["60d+"] > 0 OR oldest_order_days ≥ 30, call it out as an OPERATIONAL BACKLOG, not a fresh blip. Orders 60+ days old that nobody has chased means a process gap.
- Payment-method signal: card gateways (Stripe, PayPal, Square, Braintree, Adyen, Apple Pay) should NEVER sit on-hold — they either succeed or fail at checkout. A card gateway appearing in payment_methods is a failing-gateway signal. BACS / cheque / bank transfer / direct-debit legitimately take days to clear; on-hold on those is expected.
- Over-index: pipeline_over_index_points = share_of_pipeline − share_of_paid. Positive = this method over-contributes to stranded orders relative to paid revenue. Card gateways with ANY positive value are a signal — they shouldn't have ANY pipeline. Manual-payment methods are expected to run very positive; not a red flag on its own.

WHAT THIS CAN'T ANSWER (orders subject):
- Individual order details (customer names, contents, addresses). Use the rows tool with entity=orders, or the WC Admin orders screen.
- Order fulfilment time or shipping delays. We don't track time between status transitions.
- Why specifically an on-hold order hasn't been paid. Pipeline diagnostic surfaces age + method, not the customer-level reason.
- Abandoned carts or checkout drop-off. WooCommerce doesn't capture cart/session data.

GOOD FOLLOW-UPS:
- "What products were in those orders?" → wc-analytics-breakdown with subject=products
- "What channels drove them?" → wc-analytics-breakdown with subject=attribution
- "Is one channel driving disproportionate pipeline?" → wc-analytics-breakdown with subject=attribution, dimension=channel — the per-row pipeline_over_index_points pairs with the payment-method diagnostic here for a full "which traffic source hitting which gateway" diagnosis.
- "Who placed them?" → wc-analytics-totals subject=customers
- "Show me the specific on-hold orders" → wc-analytics-rows with entity=orders + status filter

STORAGE VOCABULARY IS NEVER MERCHANT-FACING — payment-method slugs (bacs, bank_transfer, ppec_paypal) are developer vocabulary. Merchants see the display label in WP Admin > WooCommerce > Settings > Payments ("Direct Bank Transfer", "PayPal", "Credit Card (Stripe)"). When a row's slug looks internal, route to the Admin display name, not to slug guessing.
Bad (backticked storage slugs): "BACS is sometimes stored as `bacs`, but some stores use `bank_transfer` or a custom gateway ID."
Good (Admin display): "Different stores name their bank-transfer gateway differently — 'Direct Bank Transfer', 'Bank Transfer', or a custom label. Which one shows up in your checkout?"

================================================================================
SUBJECT = customers  → period-scoped view of new vs returning customer counts, orders, revenue, AOV per segment, and repeat rate. Three-view reconciliation. Comparison carries pre-computed deltas.
================================================================================

When called via this tool there is NO time series — set interval on the series tool instead. The customers subject under totals is intentionally aggregate-only; series questions belong to the series tool.

THREE VIEWS:
- metrics: PAID orders only (processing + completed). total_customers = COUNT(DISTINCT customer_id) so no double-counting. new_customers and returning_customers are distinct counts by the returning_customer flag. Headline.
- pipeline: customers with ON-HOLD orders awaiting payment.
- admin_equivalent: paid + on-hold + refunded; total_customers = new + returning, which CAN double-count. Reconciliation against WC Admin > Customers.

CRITICAL EDGE CASE — the returning_customer flag is set at order creation and never updated:
- A customer's first-ever order has returning_customer=0 (new).
- Every subsequent order has returning_customer=1 (returning).
- If a customer places their first AND second order in the same period, they count once as new AND once as returning — appearing in both buckets.
- metrics.total_customers uses distinct counting so it's NOT the sum of new + returning when overlap exists.
- metrics.overlap_customers = (new + returning) − total_customers; non-zero means some customers flipped buckets within this period. If asked why "new + returning > total", that's the explanation.

KEY METRICS:
- metrics.new_customers and returning_customers with new_customer_percent / returning_customer_percent for context.
- metrics.repeat_rate_percent = returning ÷ total. The "how sticky are my customers this period" number.
- Per-segment spend (new_customer_net_sales, returning_customer_net_sales) and AOV. Returning customers typically have higher AOV — call out the gap if meaningful.
- Per-customer ratios: new_customer_orders_per_customer, returning_customer_orders_per_customer, new_customer_spend_per_customer, returning_customer_spend_per_customer. These differ from AOV — AOV is per-order, these are per-customer for the period (bake in order frequency). Use these to answer "do returning customers spend more than new?" directly.

WHAT THIS CAN'T ANSWER (customers subject):
- Individual customer names, emails, addresses. Privacy rule — point at the rows tool with entity=customers, mode=rows for pseudonymised lifetime-aggregate row lists, or WP Admin > WooCommerce > Customers for individual lookup.
- Lifetime customer value, cohort retention, time-between-orders, lifetime order count per customer. Those belong to subject=customer_value.
- Customers broken down by country, role, or first-order coupon. Not currently exposed.
- Churn rate or customer reactivation. Belongs to customer_value's cohort retention block.

DO NOT SUGGEST AS A FOLLOW-UP — even if adjacent in merchant intent:
- "Top customers by spend" / "customer LTV" / "best customers over time" — these are subject=customer_value's territory, not this subject. If the merchant asks directly, route them; don't volunteer it as a drill-down from this subject.
- Listing customer names, emails, or IDs — privacy rule.
- Building a new skill, feature, or endpoint — merchants can't action that.

GOOD FOLLOW-UPS:
- "Compare to the previous period" → compare=true (on by default)
- "How has this changed month over month?" → wc-analytics-series subject=customers with the right interval
- "What channels brought new customers?" → wc-analytics-breakdown subject=attribution (already splits new vs returning per channel)
- "How valuable are they over their lifetime?" → wc-analytics-totals subject=customer_value
- "Show me specific orders from new customers" → wc-analytics-rows entity=orders

================================================================================
SUBJECT = customer_value  → LIFETIME aggregates: LTV stats, one-time vs repeat segmentation, top customers (pseudonymised), cohort retention, items-over-lifetime histogram, time-between-orders. The period parameter filters which customers are summarised; the metrics are lifetime aggregates.
================================================================================

TWO INCLUSION FRAMES IN ONE RESPONSE — teach the merchant which block answers which question:
- ACTIVE-BASE VIEW (metrics, segments, top_customers, items_over_lifetime, time_between_orders): customers who placed at least one paid order in this period, summarised by their FULL lifetime history. Answers "who's buying from me right now, and what have they been worth across their whole relationship?" Use for top-customer narratives, one-time vs repeat splits, current-value narratives.
- ACQUISITION-COHORT VIEW (cohorts): customers whose FIRST paid order falls in this period, tracked forward through time. Answers "of the customers I acquired this period, who came back and when?" Use for retention-curve narratives, comparing acquisition vintages, "are recent customers as valuable as old ones?"

Different questions map to different blocks. Lead with whichever block directly answers the merchant. Don't sum across frames — they describe different customer sets.

NO THREE-VIEW PATTERN: lifetime spend is inherently longitudinal — on-hold orders shouldn't count as "value delivered" and refund netting happens over years. Pipeline-scoped questions belong to subject=customers. If a merchant asks "why doesn't this match my admin Customers report?", explain that this subject is lifetime-shaped and the admin report is period-shaped; they answer different questions.

CRITICAL PRIVACY RULES:
- top_customers always carries a pseudonymised id ("Customer #1247") and never includes real names or emails. ALWAYS refer to customers by this id in narration. Each row also carries an admin_url pointing at the merchant's WC Admin Customers report — render the id as a clickable markdown link to that URL (e.g. `[Customer #1247](https://example.com/wp-admin/...)`) so the merchant can jump to the real identity in one click. The skill itself stays pseudonymised; the link shortcuts the WP Admin lookup.
- DO NOT invent names or emails. If you don't see them in the payload, they're not available — full stop.
- Never list all customers (the tool returns top-N by design) — "show me everyone who spent over £500" needs the rows tool with entity=customers and a lifetime_spend filter.
- Guest-checkout customers (customer_id = 0) are not tracked in lifetime stats — WooCommerce can't attach orders to a persistent guest identity. Mention this honestly if the merchant's store leans heavily on guest checkout.

KEY METRICS to lead with:
- metrics.active_customers: headcount the response is summarising.
- metrics.avg_lifetime_spend / median_lifetime_spend / max_lifetime_spend: the "how valuable is my active base?" headline. Median vs avg gap tells you whether a few whales are distorting the average.
- segments.one_time.share_percent vs segments.repeat.share_percent: conversion-to-repeat opportunity if one_time dominates. Pair with avg_lifetime_spend per segment — repeats typically spend 3-10× lifetime.
- opportunities.one_to_repeat_conversion.scenarios: pre-computed lever table showing estimated_uplift at 10% / 25% / 50% conversion rates. READ THESE NUMBERS DIRECTLY. Do not multiply stranded_customers × rate × uplift_per_conversion yourself — the scenarios array already did it. Do NOT show the intermediate multiplication ("£2,264 per conversion × 18 = £40,756") — the scenario row already presents both figures side by side; the merchant doesn't need the working. Report the estimated_uplift as the answer; reach for the other fields only if the merchant asks "where does that number come from?" If uplift_per_conversion is negative, report that honestly ("no lever here — your active one-time base has actually out-spent repeat customers per head") instead of presenting a negative uplift as opportunity.
- cohorts[N].offsets[month=1].retention_percent: classic 30-day retention curve. Use for "what % of 3-month-ago new customers came back?"
- cohorts[N].lifetime_retention_percent: "ever returned" rate — share of the cohort with ≥ 2 lifetime paid orders. The cleanest longitudinal retention number. Prefer this over summing month-by-month retention when the merchant asks "what % of my January cohort came back (at all)?" — the per-month view looks artificially thin for early months.
- cohorts[N].maturity + months_since_acquisition + maturity_threshold_months + flips_to_mature_on: "ongoing" cohorts are still maturing (below the threshold months since their month anchor), so their later-month retention is incomplete by definition. The threshold is a FIXED STRUCTURAL cutoff carried on every cohort row (currently 2 months). DO NOT attribute the threshold to the store's average time between orders, a "typical repurchase window", or any other store metric — it's a hard-coded classification, not data-driven. For ongoing cohorts, the flip-to-mature date is the exact YYYY-MM-DD on which the cohort reaches the threshold — report that date verbatim ("flips to mature on 2026-05-01"), not an approximation ("end of April" / "mid-June"). Mature cohorts have a null flip-to-mature date. Don't compare an ongoing cohort's retention to a mature one head-to-head.
- cohorts[N].offsets[month=N].cumulative_avg_ltv: cohort maturation curve — directly answers "are recent customers as valuable as older ones at the same age?"
- time_between_orders.avg_days + time_between_orders.buckets: re-order cadence — drives email timing, replenishment reminders.
- items_over_lifetime.buckets: basket-depth distribution — informs bundle / cross-sell opportunity.

OPPORTUNITY FRAMING: when the one_to_repeat_conversion block is present and uplift_per_conversion is positive, frame scenarios as "if you converted N% of your one-time buyers to repeaters, you'd unlock £X over their lifetimes." Always read conversions and estimated_uplift from the scenarios array — never derive them narratively.

COMPARISON: comparison.changes carries pre-computed percent / amount / direction for headline metrics. Use those numbers directly; do not recompute. Lifetime metrics shift slowly — but active_customers shifting tells you your base is growing or shrinking.

WHAT THIS CAN'T ANSWER (customer_value subject):
- Individual customer names or emails. Pseudonymised by design — point at WP Admin > WooCommerce > Customers when the merchant needs the real identity behind a Customer #N.
- Churn prediction or at-risk customer identification. We show past behaviour, not forecasts. For churn, cohort retention curves show historic drop-off — the merchant interprets.
- LTV forecasting ("what will Customer #1247 be worth next year?"). Actuals only.
- Why a specific customer stopped ordering. No engagement / email / session data.
- Customer-level product preferences ("what does Customer #1247 buy?") — point at WP Admin's per-customer order view.
- First-product / first-coupon / first-channel cohort splits. Not exposed; the closest available cut is wc-analytics-breakdown subject=attribution for channel-level new-customer mix.
- Guest checkout lifetime value — no persistent guest identity. If the merchant leans heavily on guest checkout, active_customers will undercount real shoppers.

DO NOT SUGGEST as follow-ups: unmasking a specific Customer #N (point at WP Admin), predicting future LTV / churn, listing every customer who meets a criterion (use the rows tool), or building new features.

GOOD FOLLOW-UPS:
- "How did my store do overall?" → wc-analytics-totals subject=revenue
- "Who's buying right now (period-scoped, new vs returning)?" → wc-analytics-totals subject=customers
- "What channels acquired these customers?" → wc-analytics-breakdown subject=attribution
- "Show me the customers behind this number" → wc-analytics-rows entity=customers (pseudonymised, lifetime-aggregate rows)
- "Compare to a different period" → re-run with different dates

================================================================================
SUBJECT = tax  → tax collected for a period — total tax, order tax vs shipping tax, refunded tax, the net-tax figure that maps to a VAT / sales-tax return, plus a per-rate breakdown sibling. Three-view reconciliation. Aggregated only — no per-order tax detail (privacy boundary).
================================================================================

THREE VIEWS:
- COLLECTED TAX — paid orders only (completed + processing). Default headline. (Field path: totals.total_tax / totals.order_tax / totals.shipping_tax.)
- PENDING TAX — tax on on-hold orders. Collected at checkout but not yet paid through, so not legally collected revenue until the order clears. Surface when material. (Field path: pipeline.total_tax.)
- DASHBOARD-MATCHING TAX — paid + on-hold + refunded summed straight. Reconciliation against WC Admin > Reports > Tax. (Field path: admin_equivalent.total_tax.)

RECONCILIATION QUESTIONS — ALWAYS PULL THE DATA FIRST:
When the merchant asks why our numbers differ from WC Admin's report (or any "why doesn't tax X match Y" question), pull this subject FIRST before narrating. The dashboard-matching figure is the bridge between the merchant's two figures — quoting it without grounding in the actual response leads to invented WC internals (option names, table names, indexing causes) pulled from training data instead of the real explanation.

Bad (reasoning about WC drift causes without pulling data): "WC Admin reports read from `wc_order_tax_lookup` / `wc_order_stats`, not the live order tables. If a recent order didn't get indexed (failed cron, plugin conflict), totals diverge. Try Settings > Tools > Regenerate order stats."
Good (data first, then narrate the gap with real numbers): "Pulled both figures. Your collected tax is £X. WC Admin shows £Y because it lumps in £Z from on-hold orders that are still awaiting payment. Once those clear, the two figures will align — there's nothing to fix here."

KEY FIELDS — READ THEM, DON'T DERIVE THEM:
- totals.net_tax: total_tax − refunded_tax. The figure that goes on a VAT / sales-tax return. Always read this directly when the merchant asks "what do I owe HMRC / IRS?" — never compute total − refunded yourself.
- totals.effective_tax_rate_percent: total_tax ÷ paid net_revenue × 100. Pre-computed. Use directly when the merchant asks "what's my effective tax rate?" — don't divide manually.
- totals.taxable_share_percent: taxable_orders ÷ total_paid_orders × 100. Pre-computed. Tells the merchant what fraction of their orders are taxable (rest are zero-rated, exempt, or to non-taxable jurisdictions).
- top_rates[].share_of_tax_percent: per-rate share of total tax, pre-computed. Read it; never recompute from row + totals.

REFUNDED TAX:
- totals.refunded_tax is the absolute (positive) amount of tax in refund sub-orders for the period — clawed back from the original collection.
- For VAT-return reasoning, use net_tax (= total_tax − refunded_tax). Don't reach into subject=refunds for this — refund_analysis returns total refunded amount (net + tax + shipping), not the tax-only slice.

PER-RATE NARRATIVE:
- top_rates rows carry the rate's name (e.g. "UK VAT"), country (ISO-2), state (often empty for country-wide rates), and the rate percentage as it's configured in WP Admin.
- A row with country='GB' and state='' is a country-wide UK rate. Country='US' state='CA' is a California sales-tax row. Multi-state US merchants will see one row per state.
- Rows where the configured rate is missing represent tax that wasn't tied to a current setting (legacy data, manually-entered tax). Surface honestly — they're real collected tax, just not attributable to a current configuration.

SMALL-N HONESTY:
- When a top_rates row has orders_count ≤ 5, the row's share / percentages are noisy — a single high-tax order can spike a row to look like a major contributor. Frame as signal-to-watch, not conclusion. State the caveat explicitly when the merchant could otherwise act on the noise.
- A row with orders_count = 0 but positive amounts is a structural artefact (e.g. shipping_tax-only without any line tax) — narrate honestly rather than reporting "0 orders".

EMPTY-PERIOD HANDLING:
- If collected tax = 0 AND pending tax = 0, the store either doesn't charge tax in the period or has no taxable orders. Say so honestly — don't fabricate. The note field will explain.
- If top_rates is empty but total tax > 0, the tax was collected without a configured rate (legacy / manual). Surface as "tax collected but rates aren't matched to current settings" rather than missing data.

REGULATORY THRESHOLDS — same list as subject=revenue (UK VAT £90k, EU OSS €10k, Canada GST CAD 30k, Australia AUD 75k, US state-by-state nexus). Strongest fire-signal on this subject: collected tax is £0 or implausibly low AND annualised revenue crosses a threshold. Always hedge: "if you're VAT-registered and trading above £90k, this is worth checking with your accountant" — never state a compliance conclusion.

COMPARISON: comparison.changes carries pre-computed percent / amount / direction for every total. Per-rate change blocks exist on top_rates rows that appeared in both periods. Rates that dropped out land in comparison.dropped_out. Use those numbers as-is — never recompute deltas yourself.

WHAT THIS CAN'T ANSWER (tax subject):
- Per-order tax detail. Aggregated-only by deliberate design (privacy + load). Point at WP Admin > WooCommerce > Reports > Tax for line-item-level drill-in, or WP Admin > WooCommerce > Orders with a tax filter for individual orders.
- Tax owed by jurisdiction (UK VAT bucket vs EU VAT bucket vs US sales tax bucket). Only per-rate, not jurisdiction roll-up. If the merchant asks for an "EU VAT" total, scan the per-rate rows for EU country codes and sum honestly in-chat — flag the manual roll-up so they know it's read-and-add.
- Tax-class breakdowns (digital vs reduced-rate VAT items). The lookup table groups by rate-id, not class.
- MTD VAT submission to HMRC. Out of scope — that's an HMRC API integration, not analytics.
- Whether you should be charging tax on a specific order. Compliance question — point at WP Admin > WooCommerce > Settings > Tax for the merchant's current configuration.
- US sales-tax nexus thresholds. Out of scope — that's a tax-compliance problem (TaxJar / Avalara). We surface what was collected; not whether it should have been.
- Future tax liability or projection. Actuals only.
- Tax avoidance advice. Plainly out of scope.

GOOD FOLLOW-UPS:
- "Want a country breakdown of revenue?" → wc-analytics-breakdown subject=revenue, dimension=country
- "Compare to last quarter?" → compare=true or recall with different dates
- "What got refunded?" → wc-analytics-totals subject=refunds for full refund context
- "Where's the rest of revenue coming from?" → wc-analytics-totals subject=revenue

================================================================================
SUBJECT = refunds  → headline refund metrics: refunds_amount, refunds_count, orders_refunded_count, refund_rate_percent against paid gross revenue, days between order and refund (avg / median), partial vs full split, plus a fixed-bucket timing distribution. NO three-view pattern (refunds are inherently a slice). For grouped refund analysis (by product, by country) use wc-analytics-breakdown subject=refunds.
================================================================================

PERIOD SEMANTICS — REFUND-ISSUED, NOT ORDER-PLACED:
The date window applies to the refund sub-order's own creation date — so "refunds this month" means cheques issued this month, regardless of when the original order landed. A refund issued in April against an order placed in January lands in April. The timing buckets + avg_days_to_refund + median_days_to_refund tell you how far back the refunded parents actually are. If the merchant asks "how many orders placed this month later got refunded?" — that's a different question we don't answer today; say so plainly.

HEADLINE METRICS:
- refunds_amount: total refunded in the period. Uses the canonical formula (net_total + tax_total + shipping_total on the refund sub-orders) so it matches subject=revenue's refunds figure and breakdown subject=revenue.
- refunds_count: number of refund sub-orders issued. Multiple partials against the same parent each count separately.
- orders_refunded_count: DISTINCT parent orders touched by any refund. A parent with three partial refunds counts as 1 here even though refunds_count for that parent is 3.
- refund_rate_percent: refunds_amount ÷ paid_gross_revenue × 100, pre-computed. Read it — don't divide. Returns null when paid_gross_revenue is 0 (a refund-only window where the original sales sit outside the period) — narrate that as "rate undefined for this window — refunds are visible but the original sales fall outside it" rather than "0% refund rate".
- paid_gross_revenue: the denominator behind refund_rate_percent — paid gross revenue in the same window, matching subject=revenue's paid-orders figure.
- avg_days_to_refund / median_days_to_refund: mean and median of DATEDIFF(refund_date, parent_order_date). Use median when talking about "typical" — mean is pulled by long-tail 60+ day refunds.
- partial_refunds_count / partial_refunds_amount / full_refunds_count: full = parent order's status flipped to wc-refunded (WC does this automatically when a refund covers the whole order). Everything else is partial.

TIMING BUCKETS — FIXED ORDER:
timing.buckets is always returned in this order: Same day, 1–7 days, 8–30 days, 31+ days. Each row carries count + share_percent (pre-computed). Narrative shorthand:
- Heavy on Same day: often wrong-item / payment-confusion / accidental duplicate orders. Worth drilling into the top refunded products.
- Heavy on 1–7 days: shipping / initial-impression issues.
- Heavy on 8–30 days: delivery delays, defects surfacing after use, or dissatisfaction.
- Heavy on 31+ days: delayed-dispute / chargeback adjacent / long-tail quality issues.

THRESHOLDS ARE FOR YOU (CLAUDE), NOT THE MERCHANT:
Rates above ~5% usually merit attention; above ~10% are a red flag. These thresholds are guidance for YOU when shaping the response. When surfacing the interpretation to the merchant, state the insight as a characterisation of their numbers — not as a reference to the guidance that produced it. The merchant doesn't have access to "our guidance" and doesn't need to know it exists.

Bad (names the provenance of a threshold): "10.8% is squarely in the 'red flag' zone per our own guidance."
Bad (same shape): "This rate merits attention per our thresholds."
Good (states the insight as fact): "10.8% is red-flag territory — well above the 5% mark where refund rates start to warrant attention, and over the 10% line where they become a real margin concern."
Good (skips the threshold reference entirely when not the headline): "Germany's refund rate at 10.8% is more than double your store-wide baseline of 4.1% — worth digging into which products are driving it."

SMALL-N HONESTY — flag the sample size BEFORE the percentage:
When refunds_count is small (≤5 typically), the refund_rate_percent is mathematically true but interpretation-misleading — a single £100 refund on a product that sold £300 spikes the rate to 33%, true arithmetically, useless operationally. Frame as signal-to-watch, not conclusion. State the count explicitly. Skip the caveat when refunds_count is large enough (typically ≥10) for the rate to carry, or when the merchant explicitly asked for the literal rate regardless of confidence.

WHAT THIS CAN'T ANSWER (refunds subject):
- Why the refund happened. WooCommerce stores a free-text reason note on each refund but it's unstructured (often blank, or one-word like "damaged") — trying to aggregate free text produces hallucinogenic categorisation. If the merchant asks for refund reasons, point them at WP Admin > WooCommerce > Orders with status filter "Refunded" to read individual notes.
- Per-coupon refund rate. Coupons aren't grouped here — point the merchant at the coupon subject of wc-analytics-breakdown for per-code stats.
- Refund rate by customer segment (new vs returning). The returning_customer flag is on the parent order but we don't split the refunds pivot by it in the MVP.
- Refunds by day of week / hour. Not yet surfaced as a dimension.
- Separate shipping-refund / tax-refund splits. The headline uses the canonical formula (net + tax + shipping) to match revenue.
- Refund-to-chargeback distinction. Chargebacks issued through the payment processor don't automatically create a refund sub-order — they show up on the processor side (Stripe dashboard, PayPal reports). Point the merchant at their payment processor's dashboard.
- Time from refund request (customer email) to refund issued. We only see the refund record itself, not the customer-support timeline.

GOOD FOLLOW-UPS:
- "Which products are driving refunds?" → wc-analytics-breakdown subject=refunds, dimension=product
- "Which countries?" → wc-analytics-breakdown subject=refunds, dimension=country
- "How does the top refunded product's sales volume look?" → wc-analytics-breakdown subject=products
- "Compare to the previous period?" → compare=true or recall with different dates
- "What's the headline revenue for this period?" → wc-analytics-totals subject=revenue

================================================================================
GLOBAL: how to phrase follow-ups and gaps
================================================================================

When suggesting next steps, phrase drill-downs as merchant questions, never as tool invocations. The merchant invokes a tool by asking a question; you don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in this description (which YOU read), not in the response you write back to the merchant.

Bad (names the invocation): "The next call is wc-analytics-breakdown with subject=products."
Bad (parameter-shape framing): "I'll re-run with compare=true."
Bad (parameter-name in backticks): "Want to split this by `period` or by `compare`?"
Bad (developer-shape alias in parens): "We could filter by status (wc-on-hold) to see pipeline only."
Good (phrased as a merchant question): "Want me to break this down by product?"
Good (answers without leaking the tool chain): "Worth checking which channels drove this — acquisition mix often explains revenue swings. Want me to pull the split?"
Good (names dimensions, not parameter values): "I can split this by category, country, or payment method — which cut is most useful?"

Rule: no backticks around parameter names or values in merchant-facing output. Payment-method names (BACS, Stripe, PayPal), platform names (Google, Bing), jurisdiction names (UK, EU, US), and status names merchants recognise (on-hold, processing, completed) are merchant vocabulary and are fine in plain text. Internal identifiers (period, compare, status_breakdown) are developer vocabulary and never belong in merchant-facing output.

When describing a gap, name the SHAPE of the missing capability in merchant-facing language (cohort retention, LTV ranking, churn analysis), and point at a today-action they can take (WP Admin, a connector, a manual workflow). Never name an internal tool identifier that would fill the gap. Never propose new skills, endpoints, or features.

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess. Never sum across the three views — they overlap. If the tool returns an error or empty data, tell the merchant you couldn't retrieve the data — do not fabricate numbers.
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

		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );

		// Prefix the subject with the verb-tool slug so approvals minted by one
		// verb tool don't get consumed by another tool sharing the same subject
		// (e.g. totals subject=revenue vs breakdown subject=revenue). The legacy
		// router stays unprefixed because its per-type slugs are already unique.
		$gate_result = LargeRangeGate::check_run( $dates['start'], $dates['end'], 'totals:' . $subject );
		if ( is_wp_error( $gate_result ) ) {
			return $gate_result;
		}
		$series_cap = $gate_result;

		$start_ms = microtime( true );
		$result   = self::dispatch( $subject, $period, $date_start, $date_end, $compare, $series_cap );

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
				return AnalyticsService::fetch_revenue_summary( $period, $date_start, $date_end, $compare );

			case 'orders':
				return AnalyticsService::fetch_orders_summary( $period, $date_start, $date_end, $compare );

			case 'customers':
				return AnalyticsService::fetch_customer_overview(
					$period,
					$date_start,
					$date_end,
					$compare,
					'',
					null,
					$series_cap
				);

			case 'customer_value':
				return AnalyticsService::fetch_customer_value( $period, $date_start, $date_end, $compare, 10, true );

			case 'tax':
				return AnalyticsService::fetch_tax_summary( $period, $date_start, $date_end, $compare, 10, 'total_tax' );

			case 'refunds':
				return AnalyticsService::fetch_refund_analysis( $period, $date_start, $date_end, $compare, 'none', 10, true );

			default:
				return new \WP_Error(
					'invalid_totals_subject',
					"Unknown analytics totals subject: {$subject}.",
					array( 'status' => 400 )
				);
		}
	}
}
