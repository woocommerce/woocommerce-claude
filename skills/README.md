# WooCommerce for Claude Skills

These are reference agent skills for WooCommerce for Claude. They are workflow wrappers around the plugin's MCP tools, resources, and prompts.

The MCP tools are the data primitives: analytics totals, breakdowns, series, rows, product details, readiness scores, and recommendations. Skills teach an AI agent how to combine those primitives for a specific merchant task.

## Merchant workflow skills

| Skill | Use it for |
| --- | --- |
| `weekly-store-review` | A weekly performance review covering revenue, orders, customers, top products, channels, refunds, and next actions. Also exposed as the `wc-prompts-weekly-store-review` MCP prompt. |
| `revenue-drop-triage` | A revenue-drop diagnostic separating order volume, AOV, customer mix, refunds, products, channels, pipeline, likely checks, and next actions. Also exposed as the `wc-prompts-revenue-drop-triage` MCP prompt. |
| `failed-order-triage` | A payment-risk triage for failed orders, on-hold orders, stuck payments, gateway signals, and order follow-up queues. Also exposed as the `wc-prompts-failed-order-triage` MCP prompt. |
| `refund-triage` | A refund diagnostic covering refund size, rate, timing, top refunded products/countries, likely checks, and next actions. Also exposed as the `wc-prompts-refund-triage` MCP prompt. |
| `catalog-audit` | A broad AI-readiness audit of the product catalogue. |
| `store-health-monitor` | Operational catalogue checks for missing images, weak descriptions, stock gaps, and policy issues. |
| `product-content-generator` | Better descriptions, FAQs, attributes, SEO metadata, and alt text for a product. |

## Adding skills

Prefer a new skill when the work is an opinionated workflow over existing data, such as "review this week", "triage refunds", or "prepare a catalogue cleanup plan".

Prefer a new MCP ability or analytics subject only when the current tools cannot return the data needed to answer the merchant's question.

Skills should keep merchant-facing output in plain English: no internal tool names, no parameter names, no database details, no customer PII, and no suggestions that a merchant should build a new endpoint or plugin feature.
