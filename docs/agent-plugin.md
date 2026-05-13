# WooCommerce for Claude Agent Plugin

WooCommerce for Claude has two installable pieces:

- The WordPress plugin, installed on the WooCommerce store. It exposes the live MCP endpoint, tools, resources, prompts, auth flow, and setup UI.
- The agent plugin/skills pack, installed into an AI client. It teaches the agent how to use those MCP tools for merchant workflows such as weekly reviews, revenue-drop triage, coupon-performance triage, customer value reviews, customer acquisition reviews, inventory risk reviews, catalogue merchandising reviews, product performance reviews, channel performance reviews, geography reviews, shipping-method reviews, payment-method reviews, failed-order triage, refund triage, tax reconciliation, catalogue audits, and product content improvements.

This repository now includes the first-stage agent plugin manifests:

- `.claude-plugin/marketplace.json`
- `agent-plugin/.claude-plugin/plugin.json`
- `agent-plugin/skills/`
- `plugin.json`
- `.codex-plugin/plugin.json`
- `.claude-plugin/plugin.json`
- `.cursor-plugin/plugin.json`
- `skills/`

The agent plugin does not replace the WordPress plugin. The skills assume the store-side WooCommerce for Claude MCP server is already connected.

## Slash commands

Installing the agent plugin may make client-level plugin commands available, such as `/plugin install ...` in Claude Code. In Claude Code, use the namespaced WooCommerce commands `/woocommerce-claude:weekly-store-review`, `/woocommerce-claude:revenue-drop-triage`, `/woocommerce-claude:coupon-performance-triage`, `/woocommerce-claude:customer-value-review`, `/woocommerce-claude:customer-acquisition-review`, `/woocommerce-claude:inventory-risk-review`, `/woocommerce-claude:catalogue-merchandising-review`, `/woocommerce-claude:product-performance-review`, `/woocommerce-claude:channel-performance-review`, `/woocommerce-claude:geography-performance-review`, `/woocommerce-claude:shipping-method-review`, `/woocommerce-claude:payment-method-review`, `/woocommerce-claude:failed-order-triage`, `/woocommerce-claude:refund-triage`, and `/woocommerce-claude:tax-reconciliation` after the plugin is installed and plugins are reloaded. Some Claude Code builds also expose the shorter aliases `/weekly-store-review`, `/revenue-drop-triage`, `/coupon-performance-triage`, `/customer-value-review`, `/customer-acquisition-review`, `/inventory-risk-review`, `/catalogue-merchandising-review`, `/product-performance-review`, `/channel-performance-review`, `/geography-performance-review`, `/shipping-method-review`, `/payment-method-review`, `/failed-order-triage`, `/refund-triage`, and `/tax-reconciliation`. Other clients may trigger skills from their descriptions, default prompts, or a skill/prompt picker.

## Test path

1. Install and connect the WordPress plugin to the AI client.
2. Install this repository as an agent plugin or skills pack in the same client.
3. Ask: "Give me my weekly store review."
4. Confirm the `weekly-store-review` skill triggers and the answer includes revenue, orders, customers, refunds, products, channels, watch list, and next actions.
5. Ask: "Revenue is down this month. Triage what changed."
6. Confirm the `revenue-drop-triage` skill triggers and the answer separates revenue movement, order volume, basket size, customers, refunds, products, channels, likely checks, and next actions without customer PII.
7. Ask: "Are my coupons working? Triage the last 30 days."
8. Confirm the `coupon-performance-triage` skill triggers and the answer separates coupon usage, discount cost, AOV with/without coupons, refunds, new-customer signal, pipeline, likely checks, and next actions without customer PII.
9. Ask: "Review my customer value and repeat purchasing."
10. Confirm the `customer-value-review` skill triggers and the answer separates active-base lifetime value, one-time versus repeat segments, cohort retention, pseudonymised top customers, opportunities, and next actions without customer PII.
11. Ask: "Review customer acquisition from the last 30 days."
12. Confirm the `customer-acquisition-review` skill triggers and the answer separates new versus returning customers, acquisition channels, first-time customer quality signals, repeat/customer-value context, tracking coverage, and next actions without customer PII or unsupported forecasts.
13. Ask: "Review my inventory risk from the last 30 days."
14. Confirm the `inventory-risk-review` skill triggers and the answer separates current stock state, period product sales, priority stock risks, sale-priced stock issues, slow movers, and next actions without customer PII or unsupported forecasts.
15. Ask: "Review catalogue merchandising from the last 30 days."
16. Confirm the `catalogue-merchandising-review` skill triggers and the answer separates top products, category/SKU coverage, current stock and sale pricing, slow movers, product-page actions, and next actions without customer PII or unsupported forecasts, margin, conversion, or new-SKU claims.
17. Ask: "Review product performance from the last 30 days."
18. Confirm the `product-performance-review` skill triggers and the answer separates top products, product mix changes, category/SKU coverage, refund signals, products to review, and next actions without customer PII or unsupported forecasts.
19. Ask: "Review channel performance from the last 30 days."
20. Confirm the `channel-performance-review` skill triggers and the answer separates paid revenue, customer mix, channel movement, pipeline skew, tracking coverage, source/campaign checks, and next actions without customer PII or unsupported ad-performance claims.
21. Ask: "Review geography performance from the last 30 days."
22. Confirm the `geography-performance-review` skill triggers and the answer separates billing-country revenue, orders, customer context, refund signals, coverage, tax context, and next actions without customer PII, unsupported causation, or tax advice.
23. Ask: "Review shipping methods from the last 30 days."
24. Confirm the `shipping-method-review` skill triggers and the answer separates paid order value by shipping method, shipping charges, pipeline, coverage, refund signals, operational checks, and next actions without customer PII or unsupported carrier-timing claims.
25. Ask: "Review payment methods from the last 30 days."
26. Confirm the `payment-method-review` skill triggers and the answer separates paid revenue by method, on-hold pipeline, failed orders, payment-label coverage, gateway/admin checks, and next actions without customer PII or unsupported processor-reversal or conversion claims.
27. Ask: "Triage my failed and on-hold orders."
28. Confirm the `failed-order-triage` skill triggers and the answer separates on-hold payment pipeline from failed checkout risk, lists actionable order links without customer PII, and gives next actions.
29. Ask: "Triage refunds from the last 30 days."
30. Confirm the `refund-triage` skill triggers and the answer covers refund size, rate, timing, top refunded products/countries, likely checks, and next actions without customer PII.
31. Ask: "Reconcile my tax collected in the last 30 days."
32. Confirm the `tax-reconciliation` skill triggers and the answer separates collected paid tax, shipping tax, refunded tax, on-hold tax, the admin reconciliation view, top tax rates, and next actions without customer PII.

## Automated smoke test

For local demo-store regression testing, run the agent workflow through Claude Code in print mode:

```bash
./bin/check-agent-workflows
```

The script loads `agent-plugin/` directly with `--plugin-dir`, so it tests the current branch without uninstalling, reinstalling, or reloading the marketplace plugin. It runs Claude Code in `auto` permission mode, captures the transcript under `.agent-workflow-runs/`, checks the expected workflow sections, and fails on the bad phrasing patterns we do not want to regress. This validates the agent-side workflow skill; it does not prove a remote demo store has the newest WordPress plugin zip installed.

Before running it, confirm Claude Code has a WooCommerce for Claude MCP server configured:

```bash
claude mcp list
```

If the store is not listed, use the `claude mcp add ...` command from the store setup page, or pass a dedicated MCP config into the smoke test:

```bash
./bin/check-agent-workflows --mcp-config /path/to/demo-store.mcp.json
```

The default `iris-demo` profile expects the deterministic seeded demo store. For a different store, run:

```bash
./bin/check-agent-workflows --profile generic
```

This is also available as an opt-in final step in the regular local gate:

```bash
RUN_AGENT_WORKFLOW_SMOKE=1 ./bin/check
```

It is skipped by default because it calls the live Claude model and the configured store MCP connection, so it can spend tokens and may vary slightly run to run.

## Claude Code marketplace install

From Claude Code chat, add this repository as a marketplace:

```text
/plugin marketplace add woocommerce/woocommerce-claude
```

Then install the plugin:

```text
/plugin install woocommerce-claude@woocommerce-claude-ai-toolkit
```

If you previously tested an older branch copy, update the marketplace first:

```text
/plugin marketplace update woocommerce-claude-ai-toolkit
```

If Claude reports that the plugin is already installed, uninstall and reinstall it:

```text
/plugin uninstall woocommerce-claude@woocommerce-claude-ai-toolkit
/plugin install woocommerce-claude@woocommerce-claude-ai-toolkit
```

Restart Claude Code, or run:

```text
/reload-plugins
```

## Local branch testing

When testing an unmerged branch, point Claude Code at the local checkout instead of the GitHub marketplace. This avoids reinstalling the default branch:

```text
/plugin marketplace add /path/to/woocommerce-claude
/plugin update woocommerce-claude@woocommerce-claude-ai-toolkit
/reload-plugins
```

If a new skill does not appear after update, check the installed inventory:

```bash
claude plugin details woocommerce-claude@woocommerce-claude-ai-toolkit
```

Claude Code caches installed plugin versions. When adding a skill to an existing plugin, bump the agent-plugin manifest version so the update creates a fresh cache entry instead of reusing an older same-version package.

Then try the namespaced commands:

```text
/woocommerce-claude:weekly-store-review
```

or:

```text
/woocommerce-claude:revenue-drop-triage
```

or:

```text
/woocommerce-claude:coupon-performance-triage
```

or:

```text
/woocommerce-claude:customer-value-review
```

or:

```text
/woocommerce-claude:customer-acquisition-review
```

or:

```text
/woocommerce-claude:inventory-risk-review
```

or:

```text
/woocommerce-claude:catalogue-merchandising-review
```

or:

```text
/woocommerce-claude:product-performance-review
```

or:

```text
/woocommerce-claude:channel-performance-review
```

or:

```text
/woocommerce-claude:geography-performance-review
```

or:

```text
/woocommerce-claude:shipping-method-review
```

or:

```text
/woocommerce-claude:payment-method-review
```

or:

```text
/woocommerce-claude:failed-order-triage
```

or:

```text
/woocommerce-claude:refund-triage
```

or:

```text
/woocommerce-claude:tax-reconciliation
```

or:

```text
Give me my weekly store review.
```

or:

```text
Revenue is down this month. Triage what changed.
```

or:

```text
Are my coupons working? Triage the last 30 days.
```

or:

```text
Review my customer value and repeat purchasing.
```

or:

```text
Review customer acquisition from the last 30 days.
```

or:

```text
Review my inventory risk from the last 30 days.
```

or:

```text
Review catalogue merchandising from the last 30 days.
```

or:

```text
Review product performance from the last 30 days.
```

or:

```text
Review channel performance from the last 30 days.
```

or:

```text
Review geography performance from the last 30 days.
```

or:

```text
Review shipping methods from the last 30 days.
```

or:

```text
Review payment methods from the last 30 days.
```

or:

```text
Triage my failed and on-hold orders.
```

or:

```text
Triage refunds from the last 30 days.
```

or:

```text
Reconcile my tax collected in the last 30 days.
```

If your client shows shorter aliases, these should also work:

```text
/weekly-store-review
```

or:

```text
/revenue-drop-triage
```

or:

```text
/coupon-performance-triage
```

or:

```text
/customer-value-review
```

or:

```text
/customer-acquisition-review
```

or:

```text
/inventory-risk-review
```

or:

```text
/catalogue-merchandising-review
```

or:

```text
/product-performance-review
```

or:

```text
/channel-performance-review
```

or:

```text
/geography-performance-review
```

or:

```text
/shipping-method-review
```

or:

```text
/payment-method-review
```

or:

```text
/failed-order-triage
```

or:

```text
/refund-triage
```

or:

```text
/tax-reconciliation
```
