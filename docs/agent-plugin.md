# WooCommerce for Claude Agent Plugin

WooCommerce for Claude has two installable pieces:

- The WordPress plugin, installed on the WooCommerce store. It exposes the live MCP endpoint, tools, resources, prompts, auth flow, and setup UI.
- The agent plugin/skills pack, installed into an AI client. It teaches the agent how to use those MCP tools for merchant workflows such as weekly reviews, revenue-drop triage, coupon-performance triage, failed-order triage, refund triage, catalog audits, and product content improvements.

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

Installing the agent plugin may make client-level plugin commands available, such as `/plugin install ...` in Claude Code. In Claude Code, use the namespaced WooCommerce commands `/woocommerce-claude:weekly-store-review`, `/woocommerce-claude:revenue-drop-triage`, `/woocommerce-claude:coupon-performance-triage`, `/woocommerce-claude:failed-order-triage`, and `/woocommerce-claude:refund-triage` after the plugin is installed and plugins are reloaded. Some Claude Code builds also expose the shorter aliases `/weekly-store-review`, `/revenue-drop-triage`, `/coupon-performance-triage`, `/failed-order-triage`, and `/refund-triage`. Other clients may trigger skills from their descriptions, default prompts, or a skill/prompt picker.

## Test path

1. Install and connect the WordPress plugin to the AI client.
2. Install this repository as an agent plugin or skills pack in the same client.
3. Ask: "Give me my weekly store review."
4. Confirm the `weekly-store-review` skill triggers and the answer includes revenue, orders, customers, refunds, products, channels, watch list, and next actions.
5. Ask: "Revenue is down this month. Triage what changed."
6. Confirm the `revenue-drop-triage` skill triggers and the answer separates revenue movement, order volume, basket size, customers, refunds, products, channels, likely checks, and next actions without customer PII.
7. Ask: "Are my coupons working? Triage the last 30 days."
8. Confirm the `coupon-performance-triage` skill triggers and the answer separates coupon usage, discount cost, AOV with/without coupons, refunds, new-customer signal, pipeline, likely checks, and next actions without customer PII.
9. Ask: "Triage my failed and on-hold orders."
10. Confirm the `failed-order-triage` skill triggers and the answer separates on-hold payment pipeline from failed checkout risk, lists actionable order links without customer PII, and gives next actions.
11. Ask: "Triage refunds from the last 30 days."
12. Confirm the `refund-triage` skill triggers and the answer covers refund size, rate, timing, top refunded products/countries, likely checks, and next actions without customer PII.

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
/woocommerce-claude:failed-order-triage
```

or:

```text
/woocommerce-claude:refund-triage
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
Triage my failed and on-hold orders.
```

or:

```text
Triage refunds from the last 30 days.
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
/failed-order-triage
```

or:

```text
/refund-triage
```
