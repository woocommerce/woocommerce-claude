# Hey Woo

The intelligence layer on top of WooCommerce core's MCP server: analytics skills, knowledge resources, prompts, and an AI-readiness scoring engine, registered as WordPress Abilities and exposed through the single MCP endpoint at `/wp-json/woocommerce/mcp`.

WordPress plugin, PHP 7.4+, GPL-3.0-or-later. Public repo — never commit secrets, internal-only URLs, or anything not intended for public distribution.

## At the start of each session

**Read [CONTRIBUTING.md](./CONTRIBUTING.md) first.** It is the authoritative reference for:

- Architecture (`Plugin (PHP) → Abilities API → WC core MCP server`)
- The **privacy rule** — analytics responses are aggregated only; no PII flows to AI by default
- The **merchant-scope rule** — tools never suggest "build a new skill / endpoint"; the merchant can't action that
- WooCommerce tables and known edge cases (refund sub-orders, returning-customer flag, refunds formula)
- Caching (don't use WC core's DataStore cache; use transients with stable cache keys)
- The full how-to for adding a new analytics Skill (questions-first, ability registration, mandatory PHPUnit test, coverage guard)
- Design patterns worth knowing — silence-isn't-signal, guardrail bad/good phrasing pairs, narrative-layer pre-compute, small-N honesty, two-frame responses, mode-switching, tool-vs-resource affordances, custom-header vs OAuth auth, regulatory thresholds

**Re-stating the two highest-stakes rules** because they're load-bearing for every ability description and a single violation ships to a public release:

- **Privacy.** Analytics responses are aggregated counts/sums/averages only. No customer names, emails, or addresses unless the `hey_woo_allow_customer_pii` option is opt-in **and** the response shape clearly justifies it. Default off.
- **Merchant scope.** The AI is talking to a merchant. They can't add a tool, register a REST endpoint, or edit plugin code. Tool descriptions and merchant-facing text MUST NOT suggest "a future X skill would answer this" — substitute with something the merchant can action (a setting, a manual workflow, a connector, or an honest "this isn't something we can answer"). The static guardrail-sweep test (`tests/integration/test-ability-description-guardrails.php`) catches the obvious violations on every `./bin/check`.

## Layout

```
hey-woo/
├── hey-woo.php               # Plugin bootstrap (HPOS declaration, requirements, options migration)
├── includes/
│   ├── class-plugin.php      # Singleton — wires hooks, MCP filter, mcp_adapter_init injection
│   ├── abilities/            # One file per skill. wc-analytics/* (analytics tools),
│   │                         # hey-woo/* (store/readiness/search tools), wc-knowledge/*
│   │                         # (resources), wc-prompts/* (prompts), hey-woo-integrations/*
│   │                         # (dev-only prototype scaffolds — gated by wp_get_environment_type)
│   ├── api/                  # REST controllers + AnalyticsController (shared SQL helper —
│   │                         # no REST routes; abilities call into it)
│   ├── knowledge/            # Provider pattern (store profile / catalog / product / policy)
│   ├── scoring/              # Engine + 4 factors (product, schema, content, policy)
│   ├── settings/             # WC > Settings > Hey Woo tab
│   └── telemetry/            # SkillTelemetry + handlers (log, Tracks-gated by opt-in toggle)
├── tests/integration/        # PHPUnit; runs inside wp-env tests-cli container
├── tools/
│   ├── seed-demo-store.php   # 24-month, 5k-order seeded demo store (mt_srand(42))
│   └── mu-plugins/           # dev-only mu-plugins (allow-insecure-transport for HTTP wp-env)
├── skills/                   # Reference Claude Code / Codex skills (catalog-audit,
│                             # product-content-generator, store-health-monitor)
├── bin/
│   ├── check                 # Local CI mirror — PHPCS + composer audit + PHPUnit + DCC
│   └── check-dcc             # Data Consistency Checker (gated; auto-skips if not installed)
├── docs/performance-and-hosting.md
├── .wp-env.json              # wp-env (port 8888, mounts plugin + tools/mu-plugins)
└── .github/workflows/        # ci.yml (PHPCS + PHPUnit) · release.yml (tag → plugin zip)
```

## Local dev

```bash
npx @wordpress/env start          # boots WP 6.9 + WC + this plugin on http://localhost:8888
                                   # afterStart enables the MCP feature flag, activates WC + Hey Woo,
                                   # installs WC pages, sets a UK store address (London / GBP)
./bin/check                        # full pre-push gate (PHPCS, composer audit, PHPUnit, DCC)
```

To seed a realistic demo store (deterministic — `mt_srand(42)`):

```bash
npx @wordpress/env run cli -- bash -c "cat > /tmp/seed.php" < tools/seed-demo-store.php
npx @wordpress/env run cli -- wp eval-file /tmp/seed.php
```

## Architecture decisions baked in

These are validated decisions. **MUST NOT** relitigate without strong new signal.

- **No separate MCP server process.** Everything ships through WC core's MCP at `/wp-json/woocommerce/mcp`. This plugin registers WordPress Abilities (`wp_register_ability`) and opts them in via `woocommerce_mcp_include_ability` for tools and `mcp_adapter_init` (priority 20, after WC's 10) for resources/prompts. WC core's component registry exposes `register_resources()` / `register_prompts()` — that's the injection seam.
- **Single Abilities API namespace.** Every analytics skill is at `wp-abilities/v1/abilities/wc-analytics/{skill}/run`. Earlier custom `hey-woo/v1/analytics/*` REST routes were folded into Abilities so MCP and direct callers hit one surface.
- **Three plugin-owned ability prefixes**, declared in `Plugin::OWNED_ABILITY_NAMESPACES`: `wc-analytics/`, `hey-woo/`, `hey-woo-integrations/`. The WC auth scope filter trusts only routes under these prefixes — a Hey Woo consumer key cannot be replayed against abilities registered by other plugins. Adding a fourth prefix is a single-edit operation; the `include_wc_analytics_in_mcp` filter must be updated in lockstep.
- **Aggregated-only privacy by default.** PII gate (`hey_woo_allow_customer_pii`) is off; merchants opt in only when chaining with email/CRM MCPs that need real addresses. `wc_string_to_bool` reads the option (not `(bool)` — `'no'` would otherwise be truthy).
- **HPOS-compatible.** Declared via `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`. SQL must read from HPOS tables (`wp_wc_orders_meta` for attribution) when available, falling back to `wp_postmeta` only when HPOS isn't enabled. The runtime branch lives in `AnalyticsController::get_order_meta_source()`.
- **No background work.** No cron, no polling, no sync jobs. The plugin runs only when an MCP/REST request arrives. This is the contract that lets the perf doc say "lighter than loading WC Analytics a few times an hour."
- **Transient cache with stable keys.** Never pass live `DateTime` objects into cache-key generation; normalise to `Y-m-d H:i:s` strings before hashing. WC core's DataStore cache has a known microseconds-in-key bug — don't reach for it.

## Stack

- **PHP 7.4+** (Composer platform pinned to 7.4 to match CI; PHPCompatibilityWP enforces the floor)
- **WordPress 6.9+** (Abilities API requires it)
- **WooCommerce 10.6+** (tested up to 10.7)
- **PHPCS:** `WordPress-Extra` + `WordPress-Docs` + `WooCommerce` rulesets via `dealerdirect/phpcodesniffer-composer-installer`
- **PHPUnit 9.6** + `yoast/phpunit-polyfills` — runs *inside* the wp-env `tests-cli` container, not on host PHP
- **`@wordpress/scripts plugin-zip`** for release builds; CI tag (`v*`) triggers `.github/workflows/release.yml`

## Common pitfalls

### `./bin/check` requires the wp-env tests container

Step 4 of the script (PHPUnit) shells into `npx @wordpress/env run tests-cli`. If wp-env isn't running, the script aborts with an explicit message before running PHPUnit. Start it once per session:

```bash
npx @wordpress/env start
```

The DCC step (`bin/check-dcc`) is gated — it auto-skips when `vendor-plugins/wca-data-consistency/` isn't present, or when the dev store has no orders. Don't try to "fix" the skip; the upstream plugin is privately distributed and there's no public install path yet.

### MCP requires HTTPS by default

Local wp-env runs on plain HTTP. The mu-plugin at `tools/mu-plugins/dev-allow-insecure-transport.php` sets `woocommerce_mcp_allow_insecure_transport` for the dev environment only. Don't move that filter into the plugin proper — it's dev-only, deliberately.

### Two-step skill addition

A new analytics skill needs **code + PHPUnit test + two static-sweep constants** in the same PR. The coverage guard at `tests/integration/test-ability-registration.php` fails CI when:

1. The new ability ID isn't in `Test_Ability_Registration::EXPECTED_ABILITY_IDS`, **or**
2. There's no `tests/integration/test-<slug>.php` file with at least one `test_*` method.

The full how-to is in CONTRIBUTING.md (`Adding a new analytics Skill`). Don't shortcut the test — the coverage guard is the substitute for "did anyone actually verify this against real data?"

### The `hey-woo-tests` mapping is the integration-tests mount

`.wp-env.json` mounts the repo into the dev environment as `hey-woo` and into the **tests** environment as `hey-woo-tests`. The PHPUnit container's working dir is `wp-content/plugins/hey-woo-tests` — that's why `bootstrap.php` does `glob($plugin_dir . '/hey-woo/...')` to find the production-side mount when loading WC. Don't rename either mount; the bootstrap and the CI workflow both rely on the slug.

### British English

The plugin is published as a UK-Automattic-shaped product (default seed store is London / GBP). British English everywhere — text strings, comments, docblocks, error messages, README/CONTRIBUTING. WP-Docs sniffs don't enforce this; rely on review.

## Workflow

- **Branch per change.** One feature / fix per branch. PR back to `trunk`.
- **Conventional commits.** `feat:`, `fix:`, `chore:`, `docs:`. The release workflow expects this for auto-generated release notes (`generate_release_notes: true`).
- **Definition of done for a PR:**
  1. `./bin/check` exits green locally (PHPCS + composer audit + PHPUnit + DCC).
  2. New analytics skill = code + PHPUnit test + the two static-sweep constants in the same PR.
  3. Tool/ability descriptions don't violate the merchant-scope rule (the description-guardrail sweep enforces the obvious cases; review catches the rest).
  4. CONTRIBUTING.md "Design patterns worth knowing" section updated when a new reusable pattern is established.
  5. AGENTS.md (this file) updated when a new gotcha, command, or convention is introduced.
- **Don't commit `hey-woo.zip`.** It's checked into the repo as a one-off artefact, but `*.zip` is in `.gitignore` and the release workflow rebuilds it from the tag. Don't update it in regular commits.
