# Hey Woo Skills

These are reference agent skills for Hey Woo. They are workflow wrappers around the plugin's MCP tools, resources, and prompts.

The MCP tools are the data primitives: analytics totals, breakdowns, series, rows, product details, readiness scores, and recommendations. Skills teach an AI agent how to combine those primitives for a specific merchant task.

## Merchant workflow skills

| Skill | Use it for |
| --- | --- |
| `weekly-store-review` | A weekly performance review covering revenue, orders, customers, top products, channels, refunds, and next actions. Also exposed as the `wc-prompts-weekly-store-review` MCP prompt. |
| `revenue-drop-triage` | A revenue-drop diagnostic separating order volume, AOV, customer mix, refunds, products, channels, pipeline, likely checks, and next actions. Also exposed as the `wc-prompts-revenue-drop-triage` MCP prompt. |
| `coupon-performance-triage` | A coupon-performance diagnostic covering coupon usage, discount cost, with/without-coupon AOV, refunds, new-customer signal, pipeline, likely checks, and next actions. Also exposed as the `wc-prompts-coupon-performance-triage` MCP prompt. |
| `customer-value-review` | A customer value and retention review covering active-base LTV, one-time versus repeat segments, cohort retention, reorder cadence, pseudonymised top customers, opportunities, and next actions. |
| `customer-acquisition-review` | A current-period acquisition review covering new versus returning customers, acquisition channels, customer quality signals, tracking coverage, and next actions without forecasting LTV. |
| `inventory-risk-review` | A stock and inventory risk review covering current stock state, recent product sales, restock/watch priorities, sale-priced stock issues, slow movers, and next actions. |
| `catalogue-merchandising-review` | A catalogue merchandising review covering top products, slow movers, sale pricing, current stock state, categories, and product-page actions. |
| `product-performance-review` | A product trading review covering top products, product mix shifts, newly entered or dropped-out top results, category/SKU coverage, product refund signals, and merchandising actions. |
| `channel-performance-review` | A channel/source review covering paid revenue, customer mix, attribution coverage, on-hold pipeline skew, tracking checks, and merchant actions without ROAS or ad-spend claims. |
| `geography-performance-review` | A billing-country review covering revenue concentration, orders, customer context, refunds, coverage, tax-threshold context, and operational checks without tax advice. |
| `shipping-method-review` | A shipping-method review covering paid order value, shipping charges, pipeline, refunds, unassigned coverage, and practical shipping-setting checks without carrier-timing claims. |
| `payment-method-review` | A payment-method review covering paid revenue by method, on-hold pipeline, failed orders, payment-label coverage, and gateway/admin checks without processor-reversal or conversion claims. |
| `failed-order-triage` | A payment-risk triage for failed orders, on-hold orders, stuck payments, gateway signals, and order follow-up queues. Also exposed as the `wc-prompts-failed-order-triage` MCP prompt. |
| `refund-triage` | A refund diagnostic covering refund size, rate, timing, top refunded products/countries, likely checks, and next actions. Also exposed as the `wc-prompts-refund-triage` MCP prompt. |
| `tax-reconciliation` | A tax collection and reconciliation readout covering collected paid tax, shipping tax, refunded tax, pending/on-hold tax, top rates, admin reconciliation views, and next actions. |
| `catalog-audit` | A broad AI-readiness audit of the product catalogue. |
| `store-health-monitor` | Operational catalogue checks for missing images, weak descriptions, stock gaps, and policy issues. |
| `product-content-generator` | Better descriptions, FAQs, attributes, SEO metadata, and alt text for a product. |

## Candidate workflow skills

There are no current candidates in this batch. Keep this section as a working backlog, not a merchant-facing roadmap. Each future candidate should still pass the "existing tools first" test before implementation; add a new MCP ability or analytics subject only when the current tools cannot return the data safely.

## Adding skills

Prefer a new skill when the work is an opinionated workflow over existing data, such as "review this week", "triage refunds", or "prepare a catalogue cleanup plan".

Prefer a new MCP ability or analytics subject only when the current tools cannot return the data needed to answer the merchant's question.

Skills should keep merchant-facing output in plain English: no internal tool names, no parameter names, no database details, no customer PII, and no suggestions that a merchant should build a new endpoint or plugin feature.
