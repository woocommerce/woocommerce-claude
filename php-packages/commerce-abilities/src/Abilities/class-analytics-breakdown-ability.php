<?php
/**
 * `wc-analytics/breakdown` ability.
 *
 * Verb-shaped aggregate router for grouped analytics breakdowns.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Abilities;

use WooCommerce\CommerceAbilities\Abilities\LargeRangeGate;
use WooCommerce\CommerceAbilities\Analytics\AnalyticsService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the analytics breakdown ability.
 */
class AnalyticsBreakdownAbility {

	const ABILITY_NAME = 'wc-analytics/breakdown';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Get analytics breakdown', 'woocommerce-claude' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Consolidated multi-subject narrative; per-subject sections follow.
				'description'         => __(
					<<<'DESCRIPTION'
"Broken-down-by-X" aggregate for one of six subjects — revenue, attribution, products, refunds, tax, coupons. Exactly one (subject, dimension) per call; the dimension space varies by subject and is enforced server-side. For headline-aggregate questions use the totals tool; for time-series use the series tool; for "show me the actual records" use the rows tool.

UNIVERSAL RULES (the connector instructions carry these in full):
- Never quote internal field paths in merchant-facing text. Field paths are for YOUR orientation; merchants see plain-English names ("collected revenue from Germany", "pending revenue on BACS orders", "the dashboard-matching figure"). Same applies to formula breakdowns: explain in English ("net sales are gross sales minus refunds") rather than pasting field-name arithmetic.
- Never sum across the three views per row (paid / pipeline / admin_equivalent) — they overlap.
- Never name internal tool identifiers, parameter names, or storage slugs in merchant-facing output. Phrase follow-ups as questions, not invocations.
- Never propose new skills, endpoints, or features as a fix for a gap. The reader is a merchant.
- Pre-computed share / rate / over-index / coverage / effective-cost fields are pre-computed for a reason — read them; don't derive them. Rounding will disagree with what the tool returned otherwise.

THREE-VIEWS-PER-ROW — the dominant pattern across most subjects:
For revenue / attribution / products / coupons, each row in top_groups (or top_products, top_rates) carries three sets of numbers, plus matching top-level totals/pipeline/admin_equivalent siblings:
- COLLECTED — paid orders only (completed + processing), GROSS of refunds (refunds sit in their own column on the row). The default headline per row.
- PENDING — on-hold orders awaiting payment within this row's dimension value. Surface per row when material (≥5% of collected revenue for that row).
- DASHBOARD-MATCHING — paid + on-hold + refunded summed straight. Reconciliation against WC Admin only — quote when asked, never lead with it.

Subjects that DON'T follow three-views-per-row: refunds (refunds are inherently a slice — the period semantics are refund-issued, not order-placed). Tax has three views at the totals level but per-rate rows are aggregated tax-only (no pipeline-per-rate column).

================================================================================
SUBJECT = revenue  → broken down by category, country, payment_method, or shipping_method
================================================================================

GROUPING DIMENSIONS:
- category (default): product category via wc_order_product_lookup. Product-level roll-up, so an order with products in multiple categories contributes its subtotals to EACH category — per-category totals don't sum to whole-order totals on multi-category baskets. That's intentional; explain it if the merchant notices.
- country: billing country. Refund sub-orders inherit the parent order's country, so a refunded order's country moves with the original purchase.
- payment_method: human-readable payment method title (falls back to the slug when empty). "Stripe", "PayPal", "Cheque", "Direct Bank Transfer" and so on — whatever the merchant's gateways registered. We don't normalise variants ("Stripe" vs "stripe_cc").
- shipping_method: one shipping method per order. Orders without a shipping line (digital, local pickup with no fee) land in (Unassigned) — that's honest "no shipping was charged" signal, not missing data.

THREE VIEWS PER ROW — same pattern as the global block above. Field paths for YOUR reference: top_groups[].net_revenue / pipeline_revenue / admin_equivalent_revenue. BACS / cheque / bank-transfer payment-method rows naturally carry pipeline share because those methods take days to clear — honest, not a data issue.

TWO REVENUE FIGURES AT THE TOTALS LEVEL — PICK THE RIGHT DENOMINATOR:
- totals.net_revenue: paid revenue GROSS of refunds. The denominator behind per-row share_of_revenue_percent. Use it when reasoning about what share each group drove.
- totals.net_sales: net_revenue − refunds. Matches the "net sales" figure on WC Admin and on subject=revenue under totals. Use it when the merchant asks "what did the store net this period?" or wants to reconcile.
- totals.refunds: absolute refund amount across all dimensions in the period.
Don't reach into subject=revenue under totals to fetch a different "actual revenue" figure when this breakdown already has both net_revenue and net_sales. Pick the denominator that matches the question.

REFUND RATE PER GROUP — READ IT, DON'T DIVIDE IT:
Every top_groups row carries a pre-computed refund rate percent (refunds ÷ net_revenue × 100). When the merchant asks "which country / category / payment method has the worst refund rate?", scan the column — don't compute it narratively. Groups with net_revenue = 0 (e.g. a pipeline-only payment method) return 0.0 and should not be reported as "0% refund rate" — they're paid-revenue-less rows. Say so honestly if they lead the list.

COVERAGE: totals.coverage_percent tells you what share of paid orders could be attributed to a non-empty value on the chosen dimension:
- ≥95%: clean coverage — the breakdown accounts for essentially all revenue.
- 70–95%: a meaningful chunk sits in (Unassigned) — mention it and the structural reason (orders without line-item categories, missing billing country, digital stores without shipping lines).
- <70%: (Unassigned) is a finding in its own right — steer the merchant toward the setting or data-quality issue producing it. For country, common causes are guest checkout without required country + legacy imports. For shipping_method, <70% usually means a digital-only or local-pickup-heavy catalogue (not a bug).

SHARE: each top_groups row carries share_of_revenue_percent against the full paid-revenue denominator (not against the sum of visible top_groups), so it stays correct when include_unassigned flips or the long tail gets clipped by limit. Use it directly — don't recompute.

REGULATORY THRESHOLDS (strongest on dimension=country): when a country row's annualised revenue (row × 12 ÷ months-elapsed) crosses that country's threshold, surface jurisdiction context — but frame as context, never as tax advice. Same threshold list as totals subject=revenue (UK VAT £90k, EU OSS €10k cross-border B2C, Canada GST CAD 30k, Australia GST AUD 75k, US state nexus). Strong fire-signal here: a single country row shows the exposure directly. Several EU country rows summing above €10k together fire the OSS threshold even if no single country does. Always hedge: "if you're VAT-registered and trading above £90k in the UK, this is worth checking with your accountant" — never state a compliance conclusion. Don't fire when group_by is not country (category / payment_method / shipping_method don't map to tax jurisdictions).

WHAT THIS CAN'T ANSWER (revenue subject):
- Per-product revenue within a category. Use subject=products instead.
- State, city, or postal-code splits. Country only. Point at WP Admin orders screen with a country filter for regional drill-in.
- Shipping-country breakdown (as opposed to billing). Only billing country is exposed.
- Per-coupon revenue. Use subject=coupons.
- Why a country, category, or payment method moved up or down. The comparison block gives you the delta; the cause (campaigns, seasonality, pricing) isn't in the data.
- Conversion rate per dimension (orders ÷ visitors). Requires visitor counts — steer to Jetpack Stats / GA4 / Parse.ly.
- Ad spend or real ROAS per country/category. No ad-platform data.
- Revenue by day of week or hour — totals subject=orders surfaces that as orders_by_day_hour.

GOOD FOLLOW-UPS:
- "Which products inside the top category are the sellers?" → wc-analytics-breakdown subject=products
- "What channels drove the top country's orders?" → wc-analytics-breakdown subject=attribution
- "Who's buying from the top country?" → wc-analytics-totals subject=customers
- "Compare to a different period?" → compare=true or recall with different dates
- "Break down by a different dimension?" → re-run with a different dimension

================================================================================
SUBJECT = attribution  → broken down by channel, source, medium, campaign, term, content, device, or channel_source
================================================================================

USE THIS SUBJECT WHEN the merchant asks any of:
- What channels / sources / campaigns / devices drove my revenue? (primary use — lead with paid figures.)
- Is one channel disproportionately filling my on-hold pipeline? / Which channels send me unpaid customers? / Is something about Social / Paid Search traffic triggering on-hold more often? (PIPELINE DIAGNOSTIC — read pipeline_over_index_points per row.)
- How many new vs returning customers per channel / source?
- Which paid keywords drove Paid Search revenue? (dimension=term — paid only.)
- Mobile vs desktop? (dimension=device.)

This is the ONLY surface that slices the on-hold pipeline by acquisition dimension. Reach for it even when the merchant's first question is framed as pipeline-scoped rather than revenue-scoped — it answers both.

GROUPING DIMENSIONS:
- channel (default): origin buckets like Organic Search, Paid Search, Direct, Email, Social, Referral. Lead with this unless the merchant asks for something more specific.
- source: the raw UTM source (google, facebook, klaviyo, etc.). More granular than channel. IMPORTANT: source is the *platform* (google, bing), NOT the search query — see term for that.
- medium: UTM medium — the classification within a source (cpc, organic, email, social, referral, (none)). Answers "what kind of traffic was this?" regardless of source. Useful for paid-vs-organic splits within the same source (google/cpc vs google/organic). Coverage is usually high.
- campaign: UTM campaign name. Coverage is often low because most traffic doesn't carry a campaign tag.
- term: UTM term — the keyword for paid search. Populated automatically by Google Ads / Bing Ads when auto-tagging is enabled, or manually via tagged URLs. ONLY covers PAID keyword data. Organic search queries are NOT available (Google anonymises them as "(not provided)" since 2011). If coverage is near-zero, the merchant likely hasn't enabled ad-platform auto-tagging yet.
- content: UTM content — the ad creative variant or A/B test identifier. Same "only if tagging is on" caveat as term.
- device: desktop, mobile, tablet.
- channel_source: channel + source combined ("Organic Search: google" vs "Organic Search: bing"). Collapses to just the channel for origins that never carry a distinct source (Direct, Email, Referral, etc.).

THREE VIEWS PER ROW — same pattern as the global block. Field paths: top_groups[].net_revenue / pipeline_revenue / admin_equivalent_revenue. Per-row pipeline_customers is the distinct-customer count for the group's on-hold orders.

NEVER QUOTE FIELD PATHS — three contexts where field names slip into prose even when the main narrative uses plain-English vocabulary cleanly:
1. Relationship / mapping equations — never write "Collected = net_revenue, Pending = pipeline_revenue". The plain-English names stand alone.
2. Structural descriptions of the response — never write "use the admin_equivalent_revenue field on each row"; write "use the dashboard-matching figure on each row".
3. Diagnostic-field tokens in prose — never write "Referral has the highest pipeline_over_index_points"; write "Referral over-indexes on pipeline by +29.9 points".

Bad (relationship equation uses field names): "Collected (paid) = net_revenue. Pending (pipeline) = pipeline_revenue. Dashboard-matching = admin_equivalent_revenue."
Good (plain-English view names stand alone): "Collected (paid) — completed + processing orders. Money actually in hand. Pending (pipeline) — on-hold orders awaiting payment. May or may not convert. Dashboard-matching — paid + on-hold + refunded. What WC Admin shows."

Bad (diagnostic-field token in prose): "Referral has the highest pipeline_over_index_points at +29.9, followed by Social at +12.5."
Good (plain-English diagnostic): "Referral over-indexes on pipeline by +29.9 points — the biggest channel-level skew. Social is a secondary concern at +12.5."

COVERAGE: totals.attribution_coverage_percent tells you what share of paid orders have attribution data at all. Lead with this when low — it means a tracking gap:
- ≥90%: tracking is healthy, the breakdown is meaningful.
- 50–90%: partial tracking — call out that figures reflect only the attributed share.
- <50%: tracking gap is the main finding — suggest the merchant check that WooCommerce order attribution is enabled and no caching plugin is stripping UTM parameters before channel analysis is reliable.

SHARE: each top_groups row carries share_of_revenue_percent so you can say "Organic Search drove 35% of your revenue" without doing arithmetic. Use it — don't recompute.

PIPELINE DIAGNOSTIC — FIRST-CLASS CAPABILITY, not optional flavour:
This subject is the answer when the merchant asks any channel-scoped pipeline question — "is one channel filling my on-hold pile?", "which source sends me unpaid customers?", "is Social traffic triggering on-hold?". Reach for it FIRST when you see that shape of question, even if the merchant framed it as "on-hold orders" rather than "attribution." The channel-level pipeline fields are the highest-value output this subject can produce when non-trivial pipeline exists.

Each row carries share_of_pipeline_revenue_percent (this row's share of on-hold revenue) and pipeline_over_index_points (pipeline_share − paid_share). Positive = this channel contributes more to stranded pipeline than to paid revenue; negative = less. Interpretation thresholds:
- ≥ +10 points: materially over-indexed. Surface as the primary finding ("Social is driving 40% of pipeline revenue vs 15% of paid — something about that traffic is hitting the on-hold state"). When this fires, pair with the payment-method over-index from totals subject=orders for a full diagnosis ("Facebook paid → Stripe → on-hold" suggests a specific-gateway-for-a-specific-traffic-source failure, not a universal gateway bug).
- between −10 and +10 points: proportional; no signal.
- ≤ −10 points: under-indexed; narrate only if the merchant specifically asks about channel pipeline health.
Don't mention over-index at all when totals pipeline revenue is 0 — the fields will be null. Don't sum share_of_pipeline_revenue_percent across rows — it's already a share of the total, not a count.

WHAT NOT TO DO when the merchant asks a pipeline-by-channel question: do NOT propose a manual CSV export + spreadsheet pivot as the workflow. That capability ships here. Telling the merchant to do manual work for a capability you already have is a merchant-scope violation. If the tool runs and returns null pipeline fields, narrate that honestly ("no on-hold revenue in this period so channel-by-pipeline isn't available right now") — but do not pre-emptively decline before pulling.

WHAT THIS CAN'T ANSWER (attribution subject):
- Organic search queries (what shoppers typed into Google organic results). Google anonymised organic queries as "(not provided)" in 2011 and they're never available per-order. The only route to organic keyword data is aggregate via Google Search Console — we do not currently connect to it.
- Paid search queries beyond utm_term coverage. dimension=term covers paid keywords ONLY when the merchant has enabled ad-platform auto-tagging (or manually tags URLs with utm_term). If term coverage is near-zero, the merchant hasn't enabled it — suggest they turn on auto-tagging in Google Ads (Account Settings → Auto-tagging) and verify utm_term lands on their return URLs.
- Referring URL or specific landing page. Not captured in WC order attribution meta.
- Ad spend, cost, CPC, real ROAS. We have revenue by channel, not the cost side. Requires a Google Ads or Meta Ads MCP to combine.
- Conversion rate by channel. Requires visitor counts per channel, not just order counts. Needs Jetpack Stats / GA4 / Parse.ly.
- First-touch vs last-touch attribution. WC captures one attribution event per order (effectively last-touch at checkout).
- Multi-touch customer journeys. No session data.

GOOD FOLLOW-UPS:
- "Specific platform within a channel?" → re-run with dimension=channel_source
- "Paid vs organic split within a source?" → dimension=medium
- "Mobile vs desktop split?" → dimension=device
- "Which UTM campaigns?" → dimension=campaign (mention coverage is often low)
- "Which paid keywords drove Paid Search revenue?" → dimension=term (paid only; organic is never available)
- "Which ad creative or A/B variant converted?" → dimension=content
- "Compare to previous period?" → compare=true
- "What's my real ROAS?" → wc-analytics-breakdown subject=attribution + a Google Ads / Meta Ads MCP (cross-tool)

================================================================================
SUBJECT = products  → top-selling products (or variations) for the period, with revenue, quantity, orders, refunds, stock status, and per-product comparison. dimension picks parent products vs variations. For per-product TIME SERIES, use the series tool with subject=products.
================================================================================

GROUPING DIMENSIONS:
- product (default): top parent products by revenue (or by the chosen orderby).
- variation: top variations (e.g. red vs blue T-shirts) instead of parent products.

Each row carries an admin_url — render the product name as a clickable markdown link to that URL so the merchant can jump straight to it in WooCommerce.

THREE VIEWS PER ROW — same pattern as the global block. Field paths: top_products[].net_revenue / pipeline_revenue / admin_equivalent_revenue, plus matching quantity fields per view. Refunds shown separately in the refunds field.

COVERAGE — always mention coverage when summarising; it frames everything else. Two coverage numbers, pick the right one:
- totals.catalogue_coverage_percent (X of Y parent products sold) — lead with this when dimension=product or when the merchant asks about products generally.
- totals.sku_coverage_percent (X of Y SKUs sold, counting each variation) — lead with this when dimension=variation, or whenever the two numbers diverge significantly (e.g. 90% product coverage but 40% SKU coverage). The gap is itself a signal: lots of dead variations (sizes/colours nobody buys) — call this out as a merchandising opportunity.

Framing: 90%+ on a small catalogue means a lean store where almost everything earns its place. Below ~50% on a small catalogue means dead stock and a pruning opportunity worth flagging. On large catalogues (1000+) low coverage is normal — pivot to "your top sellers represent X% of the products that actually moved" rather than treating it as a problem.

WHAT THIS CAN'T ANSWER (products subject):
- Product views, add-to-cart counts, or browse data. We have sales only, not sessions.
- Product profit margin. No COGS data.
- Inventory movement over time (when stock came in or out). Only current stock status is returned.
- Cross-sell / upsell patterns ("customers who bought X also bought Y"). Not currently available.

GOOD FOLLOW-UPS:
- "Show me variations of the top product" → dimension=variation
- "Compare to last period" → compare=true
- "How is the top product trending day by day?" → wc-analytics-series subject=products with the right interval
- "Break down by category" → top_categories block already returned
- "Show me the actual orders for this product" → wc-analytics-rows entity=orders with a product_id filter

================================================================================
SUBJECT = refunds  → grouped refund analysis (top refunded products, top refunded countries) — for the headline refund metrics + timing buckets, use totals subject=refunds.
================================================================================

PERIOD SEMANTICS — REFUND-ISSUED, NOT ORDER-PLACED (same as totals subject=refunds):
The date window applies to the refund sub-order's own creation date — so "refunds this month" means cheques issued this month, regardless of when the original order landed. If the merchant asks "how many orders placed this month later got refunded?", that's a different question we don't answer today; say so plainly.

GROUPING DIMENSIONS:
- product: top refunded products via wc_order_product_lookup. A refund touching two product lines contributes to two rows — per-product refund amounts can therefore sum to more than the headline refunds_amount on multi-line refunds. Same shape as subject=revenue dimension=category; flag if the merchant notices.
- country: top refunded billing countries. Refunds inherit the parent order's billing country (same rule as subject=revenue dimension=country).

EACH top_groups ROW CARRIES:
- refunds_amount, refunds_count, orders_refunded_count, gross_revenue (paid gross for that row's dimension value in the same period), avg_days_to_refund.
- refund_rate_percent (pre-computed) = refunds_amount ÷ gross_revenue × 100 for that row. When the merchant asks "which product / country has the worst refund rate?", scan the column — don't compute it narratively. Returns null on rows where gross_revenue is 0 (a product / country with refunds but no paid sales in the window) — surface as "rate undefined for this row — refunds present but no current-window denominator to compute against" rather than "0% rate".
- share_of_refunds_percent (pre-computed) = refunds_amount ÷ headline refunds_amount × 100. How much of total refunds this row represents.
- admin_url: present on dimension=product rows — the product's edit screen. Render the product name as a clickable markdown link. Null on dimension=country rows.

THRESHOLDS ARE FOR YOU (CLAUDE), NOT THE MERCHANT — same rule as totals subject=refunds. Rates above ~5% usually merit attention; above ~10% are a red flag. State the insight as a characterisation of the merchant's numbers, not as a reference to the guidance that produced it. The merchant doesn't have access to "our guidance".

Bad (names threshold provenance): "Germany at 10.8% is in the 'red flag' zone per our own guidance."
Good: "Germany's refund rate at 10.8% is more than double your store-wide baseline of 4.1% — worth digging into which products are driving it."

SMALL-N HONESTY — flag the sample size BEFORE the percentage. When refunds_count is small (≤5 typically), the per-row rate is mathematically true but interpretation-misleading. State the count explicitly. Note that one event would change the rate materially. Suggest the merchant gather more data before acting on the percentage alone.

WHAT THIS CAN'T ANSWER: same gaps as totals subject=refunds (no reason aggregation, no per-coupon refund rate, no segment split, no day/hour, no shipping/tax-only splits, no chargeback distinction).

GOOD FOLLOW-UPS:
- "How does the top refunded product's sales volume look?" → wc-analytics-breakdown subject=products
- "Compare to the previous period?" → compare=true
- "Break down refunds by country instead?" → dimension=country
- "What's the headline revenue for this period?" → wc-analytics-totals subject=revenue
- "Show me the refund records themselves" → wc-analytics-rows entity=orders with a refund-related filter (where supported)

================================================================================
SUBJECT = tax  → per-rate breakdown (the dimension is implicit — there's only one, the tax rate). For headline tax totals + reconciliation, use totals subject=tax.
================================================================================

ROW SHAPE — top_rates rows:
- Each row carries the rate's name (e.g. "UK VAT"), country (ISO-2), state (often empty for country-wide rates), the rate percentage as configured in WP Admin, and the per-rate total_tax / order_tax / shipping_tax.
- top_rates[].share_of_tax_percent: per-rate share of total tax, pre-computed. Read it; never recompute from row + totals.
- A row with country='GB' and state='' is a country-wide UK rate. Country='US' state='CA' is a California sales-tax row. Multi-state US merchants will see one row per state.
- Rows where the configured rate is missing represent tax that wasn't tied to a current setting (legacy data, manually-entered tax). Surface honestly — they're real collected tax, just not attributable to a current configuration.

SMALL-N HONESTY:
- When a row has orders_count ≤ 5, the row's share / percentages are noisy — a single high-tax order can spike a row to look like a major contributor. Frame as signal-to-watch, not conclusion. State the caveat explicitly when the merchant could otherwise act on the noise.
- A row with orders_count = 0 but positive amounts is a structural artefact (e.g. shipping_tax-only without any line tax) — narrate honestly rather than reporting "0 orders".

EMPTY-PERIOD HANDLING:
- If the response is empty, the store either doesn't charge tax in the period or has no taxable orders. Say so honestly.
- If top_rates is empty but the totals carry tax, the tax was collected without a configured rate (legacy / manual). Surface as "tax collected but rates aren't matched to current settings" rather than missing data.

NO PER-ROW THREE-VIEWS — top_rates rows aggregate tax-only, not paid/pipeline/admin-equivalent. The three-view pattern lives at the totals level under totals subject=tax. If the merchant wants on-hold-tax-by-rate, it's not exposed today; surface honestly.

WHAT THIS CAN'T ANSWER: same as totals subject=tax (no per-order tax, no jurisdiction roll-up, no tax-class breakdown, no MTD VAT submission, no compliance / nexus advice, no forecasting).

GOOD FOLLOW-UPS:
- "Want a country breakdown of revenue?" → wc-analytics-breakdown subject=revenue, dimension=country
- "What's the headline collected tax + reconciliation?" → wc-analytics-totals subject=tax
- "What got refunded?" → wc-analytics-totals subject=refunds for full refund context

================================================================================
SUBJECT = coupons  → per-coupon performance: usage, discount given away, revenue driven, refund rate per coupon, plus store-wide coupon attachment rate and with-coupon vs without-coupon AOV. The dimension is implicit (the coupon code IS the unit). Each row carries an admin_url pointing at the coupon's WP Admin edit screen — render the coupon code as a clickable markdown link to that URL.
================================================================================

THREE VIEWS PER COUPON — same pattern as the global block. Field paths: top_groups[].net_revenue / pipeline_revenue / admin_equivalent_revenue. Bank-transfer-heavy merchants will naturally see pipeline share on coupon orders.

TWO REVENUE FIGURES AT THE TOTALS LEVEL — PICK THE RIGHT DENOMINATOR:
- totals.net_revenue: store-wide paid revenue GROSS of refunds — same definition as totals subject=revenue. Use it when reasoning about what share each coupon drove of ALL paid revenue.
- totals.net_sales: net_revenue − refunds. Matches WC Admin's "net sales".
- totals.revenue_with_coupon: paid revenue from orders that used at least one coupon. Un-duplicated — an order that used two coupons contributes its revenue once here, but twice in per-row totals (once per coupon). Use this as the denominator when reasoning about coupon-share-of-total-revenue.
- totals.revenue_without_coupon: paid revenue from orders with no coupon attached.
- totals.refunds: absolute refund amount across all orders (tax + shipping included). NOT coupon-attributed — see the per-row refunds column for coupon-attributed refund amounts.

REFUND RATE PER COUPON — READ IT, DON'T DIVIDE IT:
Every row carries a pre-computed refund rate percent (refunds ÷ net_revenue × 100). When the merchant asks "which coupon has the worst refund rate?" or "is save15 driving returns?", scan the column — don't compute it narratively. Coupons with net_revenue = 0 (e.g. a coupon only used on on-hold orders) return 0.0 and should not be reported as "0% refund rate" — they're paid-revenue-less rows. Say so honestly if they lead the list.

SMALL-N HONESTY — flag the sample size BEFORE the rate. When orders_count is small (≤5 typically), the refund rate and new_customer_share_percent are interpretation-misleading even when arithmetically correct — a single refund on a 3-order coupon is 33%, true but operationally noise. Lead with the sample size. State that one event would change the rate materially. Same shape applies when "every other coupon is at 0% refund rate" reads as "they're safe" — that "0%" might just mean "small N, no refunds yet." Call it out. Skip the caveat when orders_count is large enough (typically ≥10), or when the merchant explicitly asked for the literal rate regardless of confidence.

EFFECTIVE CAMPAIGN COST — READ IT, DON'T ADD IT:
Every row carries a pre-computed effective campaign cost (discount_amount + refunds — total cash outflow from offering the coupon: what you gave away PLUS what came back) and its percentage of paid revenue on coupon orders (effective_campaign_cost ÷ net_revenue × 100 — higher = less margin-efficient campaign). When the merchant asks "what did this campaign cost me?" or "is save15 margin-eating?", scan those fields — don't narrate the addition. Coupons with net_revenue = 0 return 0.0 on the percent field.

Bad (narrates the arithmetic): "save15 gave away £2,197 in discount and got £1,215 back in refunds — effective cost is £3,412."
Good (reads the pre-computed fields): "save15's effective campaign cost is £3,412 — 27% of the paid revenue it drove. That's the least margin-efficient coupon in the list."

THRESHOLDS ARE FOR YOU (CLAUDE), NOT THE MERCHANT — same rule as totals subject=refunds. Refund rates above ~5% warrant attention; above ~10% are a red flag. State the insight as fact, never as a reference to the guidance.

Bad (names threshold provenance): "freeship at 10.8% is squarely in the 'red flag' zone per our own guidance."
Good (states insight as fact): "freeship — 10.8% refund rate. That's more than double your store-wide baseline of 4.1%, and high enough that it's eating into the margin the coupon was meant to drive."

COUPON ATTACHMENT RATE AND WITH/WITHOUT AOV:
- totals.coupon_attachment_rate_percent: share of paid orders that used at least one coupon. Pre-computed.
- totals.avg_order_value_with_coupon vs totals.avg_order_value_without_coupon: AOV split. Pre-computed.
- totals.avg_discount_per_coupon_order: total_discount_amount ÷ orders_with_coupon. Pre-computed.
Use these three figures as the single source of truth for "do my coupon orders spend more?" questions. Never derive them from per-row totals.

NEW vs RETURNING CUSTOMER ATTRIBUTION:
Each row carries new-customer and returning-customer counts — paid orders on the coupon flagged as first-time vs repeat via the returning_customer flag on wc_order_stats. A new_customer_share_percent is pre-computed so you never divide narratively. The returning_customer flag is stamped at order-creation time, so a customer might appear in both buckets within the same period on different coupons — intentional and matches WC Analytics' dashboard definition.

WHAT THIS CAN'T ANSWER (coupons subject):
- Which coupons acquired my most valuable customers over their lifetime. That's a cohort-retention lens (first-order coupon, tracked for lifetime spend) and this subject is an active-period frame. Point at totals subject=customer_value's cohorts block.
- Coupon-type roll-ups as a separate group (percent vs fixed cart vs fixed product vs free shipping). The coupon type is exposed on each row so the merchant can read it per-coupon, but the subject doesn't aggregate by type. If they ask, sum the rows by type in-chat honestly, or point them at WP Admin > WooCommerce > Marketing > Coupons.
- Why a coupon's performance moved. The comparison block gives you the delta; the cause isn't in the data.
- Coupon × channel cross-tabs ("how much Paid Search revenue used a coupon?"). Not in the MVP.
- Per-coupon conversion rate (redemptions ÷ impressions). We don't have impressions — WC only records usage.
- Creating / editing / deleting coupons. Read-only.
- Ad spend or real ROAS per coupon. No ad-platform data.

GOOD FOLLOW-UPS:
- "Which orders used the top coupon?" → wc-analytics-rows entity=orders with a coupon_code filter
- "Who redeemed the top coupon?" → wc-analytics-totals subject=customers (or rows entity=customers for the list)
- "Compare to a different period?" → compare=true
- "How did coupon-using customers' lifetime spend compare?" → wc-analytics-totals subject=customer_value

================================================================================
GLOBAL: how to phrase follow-ups, gaps, and storage vocabulary
================================================================================

When suggesting next steps, phrase drill-downs as merchant questions, never as tool invocations. The merchant invokes a tool by asking a question; you don't name the call. Tool identifiers, parameter names, and parameter-shape framing belong in this description (which YOU read), not in the response you write back to the merchant.

Bad (names the invocation): "The next call is wc-analytics-breakdown with subject=products."
Bad (parameter-shape framing): "I'll re-run with dimension=country."
Bad (parameter-name in backticks): "I can split Paid Search by keyword (`term`) or source (`google` / `bing`)."
Bad (developer-shape alias in parens): "Break it down by source (utm_source) to see where the spend is going."
Good (phrased as a merchant question): "Want me to break this down by country instead?"
Good (answers without leaking the tool chain): "Worth checking which channels drove Germany specifically — acquisition mix often explains country differences. Want me to pull that?"
Good (names platforms / dimensions, not parameter values): "I can split Paid Search into the specific keywords — or into Google vs Bing if you want to see where the spend concentrates."

Rule: no backticks around parameter names or values in merchant-facing output. Platform names (Google, Bing, Facebook, Klaviyo, Stripe, PayPal), jurisdiction names (UK, EU, US), and rate names (UK VAT, California sales tax) are merchant vocabulary and are fine in plain text. Internal identifiers (term, utm_source, group_by, channel_source) are developer vocabulary and never belong in merchant-facing output.

STORAGE VOCABULARY IS NEVER MERCHANT-FACING — payment-method slugs (bacs, bank_transfer, ppec_paypal), shipping-method IDs (flat_rate:2, local_pickup), and gateway-specific internal names are developer vocabulary. Merchants see the display label in WP Admin > WooCommerce > Settings. When a row has an internal-looking slug, route to the Admin display name, not to slug guessing.
Bad (backticked storage slugs): "This row is stored as `flat_rate:2`, but your store might use `free_shipping:1` or a custom slug."
Good (steer to WP Admin display): "Check WP Admin > WooCommerce > Settings > Shipping Zones for the label shown next to this row — tell me the name and I'll confirm the match."

When describing a gap, name the SHAPE of the missing capability in merchant-facing language and point at a today-action (WP Admin, a connector, a manual workflow). Never name an internal tool identifier that would fill the gap. Never propose new skills, endpoints, or features.

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess. Never sum across the three views per row — they overlap. If the tool returns an error or empty data, tell the merchant you couldn't retrieve the data — do not fabricate numbers.
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
				'subject'            => array(
					'type' => 'string',
					'enum' => array(
						'revenue',
						'attribution',
						'products',
						'refunds',
						'tax',
						'coupons',
					),
				),
				'dimension'          => array(
					'type' => 'string',
					'enum' => array(
						'category',
						'country',
						'payment_method',
						'shipping_method',
						'channel',
						'source',
						'medium',
						'campaign',
						'term',
						'content',
						'device',
						'channel_source',
						'product',
						'variation',
						'rate',
						'code',
					),
				),
				'limit'              => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 50,
					'default' => 10,
				),
				'orderby'            => array(
					'type' => 'string',
				),
				'include_unassigned' => array(
					'type'    => 'boolean',
					'default' => true,
				),
				'period'             => array(
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
				'date_start'         => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'date_end'           => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2}$',
				),
				'compare'            => array(
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
	 * Run the grouped subject through the matching analytics fetch method.
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error Response payload.
	 */
	public static function execute( $input ) {
		$input              = is_array( $input ) ? $input : array();
		$subject            = isset( $input['subject'] ) ? (string) $input['subject'] : '';
		$dimension          = isset( $input['dimension'] ) ? (string) $input['dimension'] : self::default_dimension_for( $subject );
		$period             = isset( $input['period'] ) ? (string) $input['period'] : 'last_30_days';
		$date_start         = $input['date_start'] ?? null;
		$date_end           = $input['date_end'] ?? null;
		$compare            = array_key_exists( 'compare', $input ) ? rest_sanitize_boolean( $input['compare'] ) : true;
		$limit              = isset( $input['limit'] ) ? (int) $input['limit'] : 10;
		$orderby            = isset( $input['orderby'] ) ? (string) $input['orderby'] : self::default_orderby_for( $subject );
		$include_unassigned = array_key_exists( 'include_unassigned', $input )
			? rest_sanitize_boolean( $input['include_unassigned'] )
			: true;
		$dimension_check    = self::validate_dimension( $subject, $dimension );
		if ( is_wp_error( $dimension_check ) ) {
			return $dimension_check;
		}
		$dates = AnalyticsService::resolve_dates( $period, $date_start, $date_end );
		// Tool-prefixed type — see totals ability for the rationale.
		$gate = LargeRangeGate::check_run( $dates['start'], $dates['end'], 'breakdown:' . $subject );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$series_cap = $gate;
		$start_ms   = microtime( true );
		$result     = self::dispatch( $subject, $dimension, $period, $date_start, $date_end, $compare, $limit, $orderby, $include_unassigned, $series_cap );
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
				'shape'         => 'groups',
				'duration_ms'   => (int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				'cache_hit'     => null,
				'rows_returned' => self::row_count_from( $result ),
				'date_start'    => $dates['start'],
				'date_end'      => $dates['end'],
				'interval'      => null,
				'bucket_count'  => null,
			)
		);

		return array_merge(
			array(
				'subject'   => $subject,
				'dimension' => $dimension,
			),
			$result
		);
	}

	/**
	 * Count the grouped rows returned by the delegated analytics method.
	 *
	 * @param array $result Response payload.
	 * @return int
	 */
	private static function row_count_from( $result ) {
		$rows = $result['top_groups'] ?? $result['top_products'] ?? $result['top_rates'] ?? null;

		if ( is_array( $rows ) ) {
			return count( $rows );
		}

		return 1;
	}

	/**
	 * Dispatch to the matching grouped analytics method.
	 *
	 * @param string $subject            Analytics subject slug.
	 * @param string $dimension          Grouping dimension.
	 * @param string $period             Period shortcut.
	 * @param string $date_start         Custom start date (YYYY-MM-DD), or null.
	 * @param string $date_end           Custom end date (YYYY-MM-DD), or null.
	 * @param bool   $compare            Include previous-period comparison.
	 * @param int    $limit              Top N rows to return.
	 * @param string $orderby            Sort column.
	 * @param bool   $include_unassigned Include unassigned rows where supported.
	 * @param int    $series_cap         Series cap from the session gate check.
	 * @return array|\WP_Error Response payload.
	 */
	private static function dispatch( $subject, $dimension, $period, $date_start, $date_end, $compare, $limit, $orderby, $include_unassigned, $series_cap ) {
		switch ( $subject ) {
			case 'revenue':
				return AnalyticsService::fetch_revenue_breakdown( $period, $date_start, $date_end, $compare, $limit, $orderby, $dimension, $include_unassigned );

			case 'attribution':
				return AnalyticsService::fetch_attribution( $period, $date_start, $date_end, $compare, $limit, $orderby, $dimension, $include_unassigned );

			case 'products':
				return AnalyticsService::fetch_product_performance( $period, $date_start, $date_end, $compare, $limit, $orderby, $dimension, '', null, $series_cap );

			case 'refunds':
				return AnalyticsService::fetch_refund_analysis( $period, $date_start, $date_end, $compare, $dimension, $limit, $include_unassigned );

			case 'tax':
				return AnalyticsService::fetch_tax_summary( $period, $date_start, $date_end, $compare, $limit, $orderby );

			case 'coupons':
				return AnalyticsService::fetch_coupon_performance( $period, $date_start, $date_end, $compare, $limit, $orderby );

			default:
				return new \WP_Error( 'invalid_breakdown_subject', "Unknown breakdown subject: {$subject}.", array( 'status' => 400 ) );
		}
	}

	/**
	 * Validate that the requested dimension is available for the subject.
	 *
	 * @param string $subject   Analytics subject slug.
	 * @param string $dimension Grouping dimension.
	 * @return true|\WP_Error
	 */
	private static function validate_dimension( $subject, $dimension ) {
		static $allowed = array(
			'revenue'     => array( 'category', 'country', 'payment_method', 'shipping_method' ),
			'attribution' => array( 'channel', 'source', 'medium', 'campaign', 'term', 'content', 'device', 'channel_source' ),
			'products'    => array( 'product', 'variation' ),
			'refunds'     => array( 'product', 'country' ),
			'tax'         => array( 'rate' ),
			'coupons'     => array( 'code' ),
		);

		if ( ! isset( $allowed[ $subject ] ) ) {
			return new \WP_Error( 'invalid_breakdown_subject', "Unknown breakdown subject: {$subject}.", array( 'status' => 400 ) );
		}

		if ( ! in_array( $dimension, $allowed[ $subject ], true ) ) {
			return new \WP_Error(
				'invalid_breakdown_dimension',
				sprintf(
					"Dimension '%s' is not valid for subject '%s'. Valid dimensions: %s.",
					$dimension,
					$subject,
					implode( ', ', $allowed[ $subject ] )
				),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Get the default grouping dimension for a subject.
	 *
	 * @param string $subject Analytics subject slug.
	 * @return string
	 */
	private static function default_dimension_for( $subject ) {
		switch ( $subject ) {
			case 'revenue':
				return 'category';
			case 'attribution':
				return 'channel';
			case 'products':
			case 'refunds':
				return 'product';
			case 'tax':
				return 'rate';
			case 'coupons':
				return 'code';
			default:
				return '';
		}
	}

	/**
	 * Get the default orderby metric for a subject.
	 *
	 * @param string $subject Analytics subject slug.
	 * @return string
	 */
	private static function default_orderby_for( $subject ) {
		switch ( $subject ) {
			case 'revenue':
			case 'attribution':
			case 'products':
				return 'net_revenue';
			case 'tax':
				return 'total_tax';
			case 'coupons':
				return 'discount_amount';
			default:
				return '';
		}
	}
}
