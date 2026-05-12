# WooCommerce for Claude Agent Plugin

WooCommerce for Claude has two installable pieces:

- The WordPress plugin, installed on the WooCommerce store. It exposes the live MCP endpoint, tools, resources, prompts, auth flow, and setup UI.
- The agent plugin/skills pack, installed into an AI client. It teaches the agent how to use those MCP tools for merchant workflows such as weekly reviews, refund triage, catalog audits, and product content improvements.

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

Installing the agent plugin may make client-level plugin commands available, such as `/plugin install ...` in Claude Code. In Claude Code, use namespaced WooCommerce commands such as `/woocommerce-claude:weekly-store-review` and `/woocommerce-claude:refund-triage` after the plugin is installed and plugins are reloaded. Some Claude Code builds also expose shorter aliases such as `/weekly-store-review` and `/refund-triage`. Other clients may trigger skills from their descriptions, default prompts, or a skill/prompt picker.

## Test path

1. Install and connect the WordPress plugin to the AI client.
2. Install this repository as an agent plugin or skills pack in the same client.
3. Ask: "Give me my weekly store review."
4. Confirm the `weekly-store-review` skill triggers and the answer includes revenue, orders, customers, refunds, products, channels, watch list, and next actions.
5. Ask: "Triage refunds from the last 30 days."
6. Confirm the `refund-triage` skill triggers and the answer covers refund size, rate, timing, top refunded products/countries, likely checks, and next actions without customer PII.

## Automated smoke test

For local demo-store regression testing, run the agent workflow through Claude Code in print mode:

```bash
./bin/check-agent-workflows
```

The script loads `agent-plugin/` directly with `--plugin-dir`, so it tests the current branch without uninstalling, reinstalling, or reloading the marketplace plugin. It captures transcripts under `.agent-workflow-runs/`, checks the expected refund triage sections, and fails on known bad phrasing patterns.

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

Then try:

```text
/woocommerce-claude:weekly-store-review
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
Triage refunds from the last 30 days.
```

If your client shows shorter aliases, these should also work:

```text
/weekly-store-review
```

or:

```text
/refund-triage
```
