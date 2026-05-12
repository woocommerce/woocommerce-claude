# WooCommerce for Claude Agent Plugin

WooCommerce for Claude has two installable pieces:

- The WordPress plugin, installed on the WooCommerce store. It exposes the live MCP endpoint, tools, resources, prompts, auth flow, and setup UI.
- The agent plugin/skills pack, installed into an AI client. It teaches the agent how to use those MCP tools for merchant workflows such as weekly reviews, catalog audits, and product content improvements.

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

Installing the agent plugin may make client-level plugin commands available, such as `/plugin install ...` in Claude Code. In Claude Code, the weekly review skill is exposed as `/weekly-store-review` after the plugin is installed and plugins are reloaded. Other clients may trigger skills from their descriptions, default prompts, a skill/prompt picker, or a namespaced command such as `/woocommerce-claude:weekly-store-review`.

## Test path

1. Install and connect the WordPress plugin to the AI client.
2. Install this repository as an agent plugin or skills pack in the same client.
3. Ask: "Give me my weekly store review."
4. Confirm the `weekly-store-review` skill triggers and the answer includes revenue, orders, customers, refunds, products, channels, watch list, and next actions.

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
/weekly-store-review
```

or:

```text
Give me my weekly store review.
```

If your client shows namespaced plugin commands, try:

```text
/woocommerce-claude:weekly-store-review
```
