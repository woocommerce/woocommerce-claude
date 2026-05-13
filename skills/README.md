# WooCommerce for Claude Skills

These are reference agent skills for WooCommerce for Claude. They are workflow wrappers around the plugin's MCP tools, resources, and prompts.

The MCP tools are the data primitives: analytics totals, breakdowns, series, rows, product details, readiness scores, and recommendations. Skills teach an AI agent how to combine those primitives for a specific merchant task.

## Merchant workflow skills

| Skill | Use it for |
| --- | --- |
| `weekly-store-review` | A weekly performance review covering revenue, orders, customers, top products, channels, refunds, and next actions. Also exposed as the `wc-prompts-weekly-store-review` MCP prompt. |
| `revenue-drop-triage` | A revenue-drop diagnostic separating order volume, AOV, customer mix, refunds, products, channels, pipeline, likely checks, and next actions. Also exposed as the `wc-prompts-revenue-drop-triage` MCP prompt. |
| `coupon-performance-triage` | A coupon-performance diagnostic covering coupon usage, discount cost, with/without-coupon AOV, refunds, new-customer signal, pipeline, likely checks, and next actions. Also exposed as the `wc-prompts-coupon-performance-triage` MCP prompt. |
| `customer-value-review` | A customer value and retention review covering active-base LTV, one-time versus repeat segments, cohort retention, reorder cadence, pseudonymised top customers, opportunities, and next actions. |
| `inventory-risk-review` | A stock and inventory risk review covering current stock state, recent product sales, restock/watch priorities, sale-priced stock issues, slow movers, and next actions. |
| `failed-order-triage` | A payment-risk triage for failed orders, on-hold orders, stuck payments, gateway signals, and order follow-up queues. Also exposed as the `wc-prompts-failed-order-triage` MCP prompt. |
| `refund-triage` | A refund diagnostic covering refund size, rate, timing, top refunded products/countries, likely checks, and next actions. Also exposed as the `wc-prompts-refund-triage` MCP prompt. |
| `tax-reconciliation` | A tax collection and reconciliation readout covering collected paid tax, shipping tax, refunded tax, pending/on-hold tax, top rates, admin reconciliation views, and next actions. |
| `catalog-audit` | A broad AI-readiness audit of the product catalogue. |
| `store-health-monitor` | Operational catalogue checks for missing images, weak descriptions, stock gaps, and policy issues. |
| `product-content-generator` | Better descriptions, FAQs, attributes, SEO metadata, and alt text for a product. |

## Candidate workflow skills

These are candidates for future agent-side workflow skills. Treat this as a working backlog, not a merchant-facing roadmap. Each candidate should still pass the "existing tools first" test before implementation; add a new MCP ability or analytics subject only when the current tools cannot return the data safely.

| Candidate | Use it for | Likely existing tools |
| --- | --- | --- |
| `product-performance-review` | A product-level trading review covering top sellers, product mix shifts, dropped-out products, category/SKU coverage, refunds, and merchandising actions. | `wc-analytics-breakdown` products/refunds, `wc-analytics-series` products, `wc-analytics-rows` products |
| `channel-performance-review` | A channel/source review covering revenue, customers, pipeline skew, attribution coverage, and tracking checks without ROAS or ad-spend claims. | `wc-analytics-breakdown` attribution, `wc-analytics-totals` revenue/customers/orders |
| `shipping-method-review` | A shipping-method review covering collected revenue, pipeline, refunds, unassigned coverage, and practical shipping-setting checks. | `wc-analytics-breakdown` revenue by shipping method, `wc-analytics-totals` orders/refunds |
| `geography-performance-review` | A country-level review covering revenue concentration, refunds, customer mix, tax-threshold context, and operational checks. | `wc-analytics-breakdown` revenue/refunds by country, `wc-analytics-totals` revenue/customers/tax |
| `payment-method-review` | A payment-method review covering paid revenue, on-hold pipeline, failed orders, payment-method concentration, and gateway checks. | `wc-analytics-breakdown` revenue by payment method, `wc-analytics-totals` orders/revenue |
| `customer-acquisition-review` | A current-period acquisition review covering new versus returning customers, acquisition channels, customer quality signals, and next actions without forecasting LTV. | `wc-analytics-totals` customers/customer_value, `wc-analytics-breakdown` attribution |
| `catalogue-merchandising-review` | A merchandising review covering top products, slow movers, sale pricing, current stock state, categories, and product-page actions. | `wc-analytics-breakdown` products, `wc-analytics-rows` products, product details |

## Adding skills

Prefer a new skill when the work is an opinionated workflow over existing data, such as "review this week", "triage refunds", or "prepare a catalogue cleanup plan".

Prefer a new MCP ability or analytics subject only when the current tools cannot return the data needed to answer the merchant's question.

Skills should keep merchant-facing output in plain English: no internal tool names, no parameter names, no database details, no customer PII, and no suggestions that a merchant should build a new endpoint or plugin feature.
