# WooCommerce for Claude

The intelligence layer for an AI-ready WooCommerce store: analytics skills, knowledge resources, prompts, and an AI-readiness scoring engine, registered as WordPress Abilities and exposed through the plugin's own MCP endpoint at `/wp-json/woocommerce-claude/mcp`.

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

- **Privacy.** Analytics responses are aggregated counts/sums/averages only. No customer names, emails, or addresses unless the `woocommerce_claude_allow_customer_pii` option is opt-in **and** the response shape clearly justifies it. Default off.
- **Merchant scope.** The AI is talking to a merchant. They can't add a tool, register a REST endpoint, or edit plugin code. Tool descriptions and merchant-facing text MUST NOT suggest "a future X skill would answer this" — substitute with something the merchant can action (a setting, a manual workflow, a connector, or an honest "this isn't something we can answer"). The static guardrail-sweep test (`plugins/woocommerce-for-claude/tests/integration/test-ability-description-guardrails.php`) catches the obvious violations on every `./bin/check`.

## Layout

```
hey-woo/
├── plugins/
│   ├── woocommerce-for-claude/
│   │   ├── woocommerce-claude.php        # Plugin bootstrap (HPOS declaration, requirements, options migration)
│   │   ├── includes/
│   │   │   ├── class-plugin.php          # Singleton — wires hooks, boots WP MCP adapter, registers own MCP server
│   │   │   ├── abilities/                # Claude-specific tools/resources/prompts plus compatibility aliases
│   │   │   │                             # for shared wc-analytics classes
│   │   │   ├── api/                      # REST controllers + AnalyticsController compatibility alias
│   │   │   ├── knowledge/                # Provider pattern (store profile / catalog / product / policy)
│   │   │   ├── scoring/                  # Engine + 4 factors (product, schema, content, policy)
│   │   │   ├── settings/                 # WC > Settings > WooCommerce for Claude tab
│   │   │   └── telemetry/                # SkillTelemetry + handlers (log, Tracks-gated by opt-in toggle)
│   │   ├── tests/integration/            # PHPUnit; runs inside wp-env tests-cli container
│   │   └── skills/                       # Reference Claude Code / Codex workflow skills
│   └── hey-woo/                          # Canonical Hey Woo BYOK admin chat plugin package
├── php-packages/
│   └── commerce-abilities/               # Composer path package; owns shared wc-analytics abilities,
│                                         # AnalyticsService, and LargeRangeGate
├── tools/
│   ├── seed-demo-store.php   # 24-month, 5k-order seeded demo store (mt_srand(42))
│   └── mu-plugins/           # dev-only mu-plugins (allow-insecure-transport for HTTP wp-env)
├── bin/
│   ├── check                 # Local CI mirror — PHPCS + Hey Woo checks + audit + PHPUnit + DCC
│   └── check-dcc             # Data Consistency Checker (gated; auto-skips if not installed)
├── docs/performance-and-hosting.md
├── .wp-env.json              # wp-env (port 8888, mounts plugins + tools/mu-plugins)
└── .github/workflows/        # ci.yml · release.yml · release-hey-woo.yml
```

## Local dev

```bash
pnpm install                      # first run only
pnpm exec wp-env start            # boots WP 6.9 + WC + this plugin on http://localhost:8888
                                   # afterStart activates WC + WooCommerce for Claude, installs WC pages,
                                   # sets a UK store address (London / GBP)
./bin/check                        # full pre-push gate (PHPCS, composer audit, PHPUnit, DCC)
RUN_AGENT_WORKFLOW_SMOKE=1 ./bin/check
                                   # full gate + live Claude Code agent workflow smoke test
```

To seed a realistic demo store (deterministic — `mt_srand(42)`):

```bash
pnpm exec wp-env run cli -- bash -c "cat > /tmp/seed.php" < tools/seed-demo-store.php
pnpm exec wp-env run cli -- wp eval-file /tmp/seed.php
```

## Architecture decisions baked in

These are validated decisions. **MUST NOT** relitigate without strong new signal.

- **Plugin-owned MCP server.** WooCommerce for Claude registers its own MCP server at `/wp-json/woocommerce-claude/mcp` via the WordPress MCP adapter (vendored inside WooCommerce as `vendor/wordpress/mcp-adapter`). The plugin boots the adapter on `plugins_loaded` so the endpoint works regardless of WC's `mcp_integration` feature flag, then calls `$adapter->create_server('woocommerce-claude', 'woocommerce-claude', 'mcp', ...)` on `mcp_adapter_init` with a curated list of tools, resources, and prompts. Auth uses an `X-MCP-API-Key: ck:cs` header backed by a standard WC REST API key. The earlier "ride on WC's `woocommerce-mcp` server via `woocommerce_mcp_include_ability`" approach is gone — that endpoint is being deprecated upstream.
- **Single Abilities API namespace.** Every analytics skill is at `wp-abilities/v1/abilities/wc-analytics/{skill}/run`. The shared `woocommerce/commerce-abilities` package registers those `wc-analytics/*` abilities; WooCommerce for Claude keeps MCP curation/auth and backwards-compatible PHP aliases.
- **Three plugin-owned ability prefixes**, declared in `Plugin::OWNED_ABILITY_NAMESPACES`: `wc-analytics/`, `woocommerce-claude/`, `woocommerce-claude-integrations/`. The WC auth scope filter trusts only routes under these prefixes — a WooCommerce for Claude consumer key cannot be replayed against abilities registered by other plugins. Adding a fourth prefix is a single-edit operation; the `Plugin::mcp_tool_ability_ids()` curated list must be updated in lockstep.
- **Aggregated-only privacy by default.** PII gate (`woocommerce_claude_allow_customer_pii`) is off; merchants opt in only when chaining with email/CRM MCPs that need real addresses. `wc_string_to_bool` reads the option (not `(bool)` — `'no'` would otherwise be truthy).
- **HPOS-compatible.** Declared via `FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`. SQL must read from HPOS tables (`wp_wc_orders_meta` for attribution) when available, falling back to `wp_postmeta` only when HPOS isn't enabled. The runtime branch lives in `AnalyticsService::get_order_meta_source()` (also reachable through the plugin's `AnalyticsController` compatibility alias).
- **No background work.** No cron, no polling, no sync jobs. The plugin runs only when an MCP/REST request arrives. This is the contract that lets the perf doc say "lighter than loading WC Analytics a few times an hour."
- **Transient cache with stable keys.** Never pass live `DateTime` objects into cache-key generation; normalise to `Y-m-d H:i:s` strings before hashing. WC core's DataStore cache has a known microseconds-in-key bug — don't reach for it.

## Stack

- **PHP 7.4+** (the plugin Composer platform is pinned to 7.4 to match CI; PHPCompatibilityWP enforces the floor)
- **WordPress 6.9+** (Abilities API requires it)
- **WooCommerce 10.6+** (tested up to 10.7)
- **PHPCS:** `WordPress-Extra` + `WordPress-Docs` + `WooCommerce` rulesets via `dealerdirect/phpcodesniffer-composer-installer`
- **PHPUnit 9.6** + `yoast/phpunit-polyfills` — runs *inside* the wp-env `tests-cli` container, not on host PHP
- **pnpm 10.33.0** for Node tooling (`packageManager` is pinned in `package.json`)
- **`@wordpress/scripts plugin-zip`** for release builds; the root `pnpm run plugin-zip` script builds `woocommerce-for-claude.zip` from `plugins/woocommerce-for-claude/`, and `pnpm run hey-woo-plugin-zip` builds `hey-woo.zip` from `plugins/hey-woo/`

## Common pitfalls

### `./bin/check` requires the wp-env tests container

Step 5 of the script (PHPUnit) shells into `pnpm exec wp-env run tests-cli`. If wp-env isn't running, the script aborts with an explicit message before running PHPUnit. Start it once per session:

```bash
pnpm exec wp-env start
```

The DCC step (`bin/check-dcc`) is gated — it auto-skips when `vendor-plugins/wca-data-consistency/` isn't present, or when the dev store has no orders. Don't try to "fix" the skip; the upstream plugin is privately distributed and there's no public install path yet.

### MCP requires HTTPS by default

Local wp-env runs on plain HTTP. The WooCommerce for Claude MCP transport (`WP\MCP\Transport\HttpTransport`) does not enforce HTTPS, so curl-style local testing against `/wp-json/woocommerce-claude/mcp` works without a TLS cert. Production stores should still front the endpoint with HTTPS.

### Two-step skill addition

A new analytics skill needs **code + PHPUnit test + two static-sweep constants** in the same PR. The coverage guard at `plugins/woocommerce-for-claude/tests/integration/test-ability-registration.php` fails CI when:

1. The new ability ID isn't in `Test_Ability_Registration::EXPECTED_ABILITY_IDS`, **or**
2. There's no `plugins/woocommerce-for-claude/tests/integration/test-<slug>.php` file with at least one `test_*` method.

The full how-to is in CONTRIBUTING.md (`Adding a new analytics Skill`). Don't shortcut the test — the coverage guard is the substitute for "did anyone actually verify this against real data?"

This section is about registered analytics Abilities under `php-packages/commerce-abilities/src/Abilities/`, not agent-side workflow skills under `plugins/woocommerce-for-claude/skills/`. If the current MCP tools already return the needed data and the change is just an opinionated workflow ("weekly review", "refund triage", "catalogue cleanup plan"), add or update a `plugins/woocommerce-for-claude/skills/<name>/SKILL.md` file instead of adding a new MCP ability.

### Composer path package refresh

`woocommerce/commerce-abilities` is installed with `"symlink": false` so release zips vendor a real copy of the shared package. After editing files under `php-packages/commerce-abilities/`, refresh each consuming plugin's vendor mirror and lock metadata:

```bash
composer update --working-dir=plugins/woocommerce-for-claude woocommerce/commerce-abilities --no-progress --prefer-dist
composer update --working-dir=plugins/hey-woo woocommerce/commerce-abilities --no-progress --prefer-dist
```

### The `woocommerce-claude-tests` mapping is the integration-tests mount

`.wp-env.json` mounts `plugins/woocommerce-for-claude` into the dev environment as `woocommerce-claude` and into the **tests** environment as `woocommerce-claude-tests`. The PHPUnit container's working dir is `wp-content/plugins/woocommerce-claude-tests` — that's why `bootstrap.php` loads the production-side `woocommerce-claude/woocommerce-claude.php` mount when loading WC. Don't rename either mount; the bootstrap and the CI workflow both rely on the slug.

### British English

The plugin is published as a UK-Automattic-shaped product (default seed store is London / GBP). British English everywhere — text strings, comments, docblocks, error messages, README/CONTRIBUTING. WP-Docs sniffs don't enforce this; rely on review.

## Workflow

- **Branch per change.** One feature / fix per branch. PR back to `trunk`.
- **Conventional commits.** `feat:`, `fix:`, `chore:`, `docs:`. The release workflows expect this for auto-generated release notes (`generate_release_notes: true`).
- **One canonical repo.** WooCommerce for Claude and Hey Woo both live in this monorepo. Hey Woo source changes belong under `plugins/hey-woo/`; the Hey Woo release workflow builds `hey-woo.zip` from that directory with its own version/tag.
- **Definition of done for a PR:**
  1. `./bin/check` exits green locally (PHPCS + composer audit + PHPUnit + DCC).
  2. New analytics skill = code + PHPUnit test + the two static-sweep constants in the same PR.
  3. Tool/ability descriptions don't violate the merchant-scope rule (the description-guardrail sweep enforces the obvious cases; review catches the rest).
  4. CONTRIBUTING.md "Design patterns worth knowing" section updated when a new reusable pattern is established.
  5. AGENTS.md (this file) updated when a new gotcha, command, or convention is introduced.
- **Don't commit release zips.** `*.zip` is in `.gitignore`; the release workflow rebuilds `woocommerce-for-claude.zip`, `hey-woo.zip`, and `woocommerce-claude-agent-plugin.zip` from the tag. Don't update zip artefacts in regular commits.
