# Contributing to WooCommerce for Claude

Thanks for taking an interest. This document covers how the codebase is organised, the engineering norms we follow, and the workflow for adding a new analytics skill.

If you're just looking to install / use the plugin, the [README](./README.md) is the right starting point.

## Quick links

- [Architecture](#architecture)
- [Privacy rule](#privacy-rule)
- [Merchant-scope rule](#merchant-scope-rule)
- [WooCommerce tables we use](#woocommerce-tables-we-use)
- [Known edge cases](#known-edge-cases)
- [Caching](#caching)
- [Pre-push checks](#pre-push-checks-bincheck)
- [Adding a new analytics Skill](#adding-a-new-analytics-skill)
- [Design patterns worth knowing](#design-patterns-worth-knowing)

## Architecture

```
Plugin (PHP)                           WooCommerce for Claude MCP server
─────────────────────                  ──────────────────────
Abilities (wp-abilities/v1)     ─────▶ /wp-json/woocommerce-claude/mcp
  - wc-analytics/* (tools)             tools     (curated via Plugin::mcp_tool_ability_ids())
  - woocommerce-claude/*       (tools)            resources (passed in to create_server())
  - wc-knowledge/* (resources)         prompts   (passed in to create_server())
  - wc-prompts/*    (prompts)
Knowledge providers
Scoring engine
```

There is **no separate MCP server process** — WooCommerce for Claude registers its own MCP server via the WordPress MCP adapter (vendored inside WooCommerce as `vendor/wordpress/mcp-adapter`) and owns the single endpoint at `/wp-json/woocommerce-claude/mcp`. The plugin boots the adapter on `plugins_loaded` so the endpoint works regardless of WC's `mcp_integration` feature flag, then calls `$adapter->create_server('woocommerce-claude', 'woocommerce-claude', 'mcp', ...)` on `mcp_adapter_init` with a curated list of tools, resources, and prompts.

**Analytics Skills** all use the WordPress Abilities API (`wp_register_ability()`, auto-exposed under `wp-abilities/v1/abilities/wc-analytics/{skill}/run`). This is the standard path for anything that exposes actions or tools to AI systems.

### Key directories

| Path | What |
|---|---|
| `includes/abilities/` | Ability classes — one file per skill. `wc-analytics/*` for analytics tools, `woocommerce-claude/*` for store/readiness tools, `wc-knowledge/*` for resources, `wc-prompts/*` for prompts. Bootstrap in `class-abilities-bootstrap.php`. |
| `includes/api/class-analytics-controller.php` | Shared analytics data-access helper. Holds the SQL + response assembly for every skill; no REST routes of its own. |
| `includes/api/` | REST controllers for store, catalog, products, readiness. The non-analytics tool abilities delegate into these. |
| `includes/knowledge/providers/` | Knowledge providers (store profile, catalog, products, policies) |
| `includes/scoring/` | Scoring engine + 4 factors (product completeness, schema coverage, content quality, policy completeness) |
| `includes/class-plugin.php` | Singleton. Boots the WP MCP adapter on `plugins_loaded` and registers the WooCommerce for Claude MCP server (with its tools, resources, prompts, and a Basic-auth callback that authenticates `ck_xxx:cs_xxx` against `wp_woocommerce_api_keys`) on `mcp_adapter_init`. |
| `skills/` | Reference Claude Code / Codex skills (catalog-audit, product-content-generator, store-health-monitor) |

## Privacy rule

All analytics Skills return **aggregated data only** — counts, sums, averages. No individual customer names, emails, addresses, or PII flows to the AI. This is a deliberate design decision, not a limitation.

## Merchant-scope rule

The AI is talking to a **merchant**, not to the plugin's developer. A merchant using the plugin can't add a REST endpoint, register an MCP tool, or edit the plugin's code. So tools must **never suggest that a new Skill, feature, or endpoint be built** in merchant-facing chat.

When a gap is hit, steer the merchant to something they can action: a setting, a connector, a manual workflow, or an honest "this isn't something we can answer."

Every MCP tool description in `includes/abilities/class-*-ability.php` carries this rule in its `WHAT THIS CAN'T ANSWER` block.

## WooCommerce tables we use

| Table | What it stores |
|---|---|
| `wc_order_stats` | Order-level metrics (revenue, tax, shipping, status, returning_customer flag) |
| `wc_order_product_lookup` | Product-level sales data (quantity, revenue per product per order) |
| `wc_customer_lookup` | Customer summary data (total spend, order count, dates) |
| `wc_order_coupon_lookup` | Coupon usage per order |
| `wc_order_tax_lookup` | Tax collected per rate per order |
| `wc_order_addresses` | Billing/shipping addresses (HPOS) |
| `wp_wc_orders_meta` (HPOS) **or** `wp_postmeta` (classic) | Order meta including attribution data (origin, utm_source, utm_medium, utm_campaign, utm_term, utm_content, device_type). Detect at query time by table existence — see the `get_order_meta_source()` helper in `class-analytics-controller.php`. |

## Known edge cases

- **Refund sub-orders**: Must filter with `parent_id = 0` or refund orders inflate customer counts.
- **Refunds formula**: Must include tax and shipping components (`net_total + tax_total + shipping_total`), not just `net_total`.
- **Returning customer flag**: Set at order creation time — a customer can appear in both new and returning buckets within the same date range.

## Caching

Don't use WC core's DataStore cache. It has a known bug — `TimeInterval::default_before()` includes microseconds in the cache key, so the MD5 changes every request and the cache never hits.

**Our approach:** Write direct SQL with our own caching. Use WordPress transients with stable cache keys — normalise date ranges to `Y-m-d H:i:s` strings before hashing, never pass live DateTime objects into cache key generation.

```php
// Good: stable cache key
$cache_key = 'woocommerce_claude_revenue_' . md5( $date_start . '_' . $date_end . '_' . $status_filter );
$cached = get_transient( $cache_key );
if ( false !== $cached ) {
    return $cached;
}
// ... run SQL, set_transient with 1 hour TTL
```

## Pre-push checks: `./bin/check`

Runs the full lint/test suite locally — PHPCS (WordPress + Docs), composer audit, and PHPUnit smoke test inside the wp-env tests-cli container. First run installs composer and pnpm deps; subsequent runs skip that.

```bash
pnpm install              # first run only
pnpm exec wp-env start    # if not already running
./bin/check
```

The script mirrors `.github/workflows/ci.yml` line-for-line, so the same checks run in CI on every push.

## Extending the analytics surface

Before adding a new MCP ability or analytics subject, ask whether the current tools already return the data. If the missing piece is an opinionated workflow over existing data — for example a weekly store review, refund triage, or catalogue cleanup plan — add or update an agent-side Skill under `skills/` instead. Abilities are data primitives; Skills are workflows.

The MCP surface is four verb-shaped tools: `wc-analytics-totals`, `wc-analytics-breakdown`, `wc-analytics-series`, `wc-analytics-rows`. Most new analytics work means adding a `subject` to one of those tools (and the corresponding `fetch_*` helper on `AnalyticsController`), not minting a new top-level tool.

The high-level shape:

1. **Questions first** — write 5-10 questions a merchant might ask before planning the SQL. The questions drive the response shape, not the other way around. If a planned response doesn't answer the questions, the response shape is wrong.

2. **Pick the verb tool by question shape**:
   - "How much / how many" → `wc-analytics-totals`
   - "Broken down by X" → `wc-analytics-breakdown`
   - "Over time" → `wc-analytics-series`
   - "Show me the actual records" → `wc-analytics-rows`

3. **Add the data layer to `AnalyticsController`** — a `public static fetch_<subject>()` helper with SQL + response assembly. Mirror the parameter shape of an existing sibling (e.g. `fetch_revenue_summary` for new totals subjects).

4. **Wire it into the verb tool** — extend the `subject` enum in the matching `class-analytics-<verb>-ability.php` schema, add a dispatch arm in `dispatch()`, extend the consolidated describe heredoc with a new `SUBJECT = <name>` section. For breakdown, also extend `validate_dimension()` and `default_dimension_for()`.

5. **Tool-prefix the gate type** — verb tools pass `'<verb>:<subject>'` (e.g. `'totals:revenue'`) to `LargeRangeGate::check_run()` so approvals don't collide across tools sharing a subject. Mirror the existing pattern.

6. **Pre-compute anything the AI would otherwise derive by hand.** Comparisons, deltas, percentages — and *ratios between any two returned fields* too. If a demo shows the AI computing `field_a / field_b` from the response to answer a question, that division should live in the endpoint. Every arithmetic step the AI does is a hallucination risk.

7. **Mandatory PHPUnit integration tests** — `tests/integration/test-<verb>.php` already exists; extend its data provider with the new subject and add per-subject assertions for the data layer. The coverage guard in `tests/integration/test-ability-registration.php` is per-verb-tool, not per-subject.

### What every test extension looks like

For data-layer assertions, look at the existing per-subject integration tests in `tests/integration/` — they call `AnalyticsController::fetch_*` directly and pin response invariants against fixture data. For verb-tool wiring (schema validation, dispatch routing, telemetry payload), extend the matching `test-<verb>.php` file's subject-routing data provider and add per-subject assertions on the result.

Shared shape every per-subject data-layer test follows:

1. **File-level docblock** pinning the invariants this test class guards.
2. **`use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;`** — provides `seed_customer()`, `seed_paid_order()`, `seed_refund_order()`, etc.
3. **`set_up()`** seeds the deterministic fixture for all tests in the class.
4. **Direct `AnalyticsController::fetch_<subject>(...)` calls** — verb-tool wiring is exercised separately in `test-<verb>.php`.
5. **One `test_*` method per invariant** — one assertion cluster per question the merchant will ask.

Also update two static-sweep constants when adding any new MCP tool:

- Add the new ability ID to `Test_Ability_Registration::EXPECTED_ABILITY_IDS`.
- Add the new tool's name to `Test_Ability_Description_Guardrails::REGISTERED_TOOL_REFERENCES` if any existing describe references it via a `→` follow-up arrow.

Run `./bin/check` before the second commit to confirm the full PHPUnit suite passes.

## Design patterns worth knowing

Reusable lessons that sit above any single skill. Reach for them when making design calls on new skills, enhancements, or tool descriptions.

### "Silence isn't signal"

When a design call defaults to *"ship the simpler/safer thing, wait for signal"*, stop and ask whether the signal is structurally hard to hear. For WooCommerce for Claude, signal is hard to hear for:

- B2B / wholesale / invoice merchants — most demo stores are consumer-skewed, so on-hold / pipeline patterns barely register in test data
- Merchants on less-common payment methods (BACS, cheque, purchase order)
- Merchants in regions you don't stress-test (tax edge cases, currency formats, language)
- MCP-ecosystem workflows where the capability is consumed by a *chained* MCP — the broken handoff never shows up in your tool's own telemetry
- Any segment you have no telemetry on

In those cases, default *toward* the richer shape — or ship the capability behind a cheap opt-in rather than deferring it. The engineering cost of an extra field, view, or off-by-default setting is usually tiny. The cost of leaving it out is a merchant or integration partner who silently gets a worse experience and never tells you.

### Guardrail shape: bad/good phrasing pairs beat abstract rules

When a tool description needs to prevent a specific merchant-scope violation, don't just say "don't X." Show a bad example and a paired good example for the exact gap shape the AI keeps hitting. Abstract rules get interpreted as "with caveats is fine." Concrete phrasing pairs force substitution.

Bad (got interpreted as "mention with caveat is fine"):
> "Don't reference internal planning docs or offer to help spec future skills."

Good (forces substitution):
> Bad: "A get_customer_value skill would answer this."
> Bad: "You'd need the planned get_customer_value skill to surface it properly."
> Good: "That question is about cohort retention — following specific customer groups over time to see when they come back. That longitudinal view isn't in the current tools. For a manual version, export the customer list from WP Admin > WooCommerce > Customers with a date filter and pivot in a spreadsheet."

**Corollary: static sweeps as behavioural-test proxies.** When a guardrail is about the AI's runtime behaviour — something you can only truly test with a paid, flaky API call against a real model — look instead for a static property the tool description itself should satisfy. A regex sweep of the ability description strings (`includes/abilities/class-*-ability.php`) catches the class of regression where the bad/good phrasing pair is absent from the description in the first place. Lives at `tests/integration/test-ability-description-guardrails.php` and runs on every `./bin/check`.

### Narrative-layer drift is a pre-compute trigger too

The canonical pre-compute rule is about *headline numbers* — if the AI would otherwise derive a figure by hand, pre-compute it on the server. The rule extends one layer further: any arithmetic or calendar inference the AI narrates *in its reasoning* is the same hallucination class, even when no headline number was asked for.

Two concrete shapes:

- **Fabricated rationale over a real number.** The AI explains *why* a threshold fires by inferring a stat that contradicts the actual returned field. Fix: emit the threshold value (e.g. `maturity_threshold_months: 2`) on every relevant row so the AI reads it rather than inferring it.
- **Calendar arithmetic by hand.** The AI computes flip dates from "first of month + threshold" arithmetic and gets the dates wrong. Fix: pre-compute `flips_to_mature_on: "YYYY-MM-DD"` per row.

The rule: if the AI would otherwise *reason in a way that produces a number or a date*, pre-compute it.

**Corollary: tighten the mechanism, not the proximate phrasing.** When tightening a rule, target the failure-shape's mechanism, not the surface it happens to travel on. *"The AI's arithmetic disagreeing with a tool-returned field"* is the failure shape — *"any visible multiplication"* is the surface, and rules written against the surface over-apply.

### "Small-N honesty as default" — flag the sample size before the percentage

Per-row percentages on `top_groups` (refund_rate_percent, share_of_x_percent, new_customer_share_percent, etc.) are honest when the underlying counts are large and misleading when the counts are small. A single £135 refund on a product that sold £358 in the period spikes that row's `refund_rate_percent` to 37.7% — true arithmetically, useless operationally.

The rule: when a per-row count is small (≤5 typically, judged in context) AND the row's percentage is interpretation-driving, frame the percentage as **signal-to-watch, not conclusion**. State the sample size explicitly. Call out that one event would change the percentage materially. Suggest the merchant gather more data before acting on the rate alone.

Concrete shape:
> "Each of these had only 1 refund on modest revenue, so the rates are noisy — a single return on a low-volume SKU spikes the percentage. Cast Iron Skillet sold ~£358; one £135 refund swings it to 37.7%."

### "Two-frame responses beat picking a frame"

Sibling to the three-view pattern (`metrics` / `pipeline` / `admin_equivalent`) but operating on a different axis. When a response can serve two genuinely different merchant questions with non-overlapping inclusion rules, don't force a choice — ship both frames in one response, each with a `definition` string that teaches the model which question it answers.

Concrete example (`get_customer_value`):

- **Active-base frame** (`metrics`, `segments`, `top_customers`, etc.) — customers with at least one paid order in the period, summarised by their full lifetime. Answers "who's buying from me right now?"
- **Acquisition-cohort frame** (`cohorts`) — customers whose *first* paid order falls in the period, tracked forward through time. Answers "are recent acquisitions as valuable as older ones?"

Same period param, same skill, non-overlapping customer sets per frame. `definition` strings do the bridging — the AI reads them and narrates correctly.

### "When the task changes, change the session"

Context compounds positively within a mode and negatively across modes. The same session that helps compound *build* context actively leaks planning framing into merchant-facing text when you pivot to *testing* it — classic signatures: "a new X tool just became available", "want me to flag this as an enhancement?", or claims about what "the tool could surface" (even when it already does).

The modes that need physically separate sessions:

- **Build** → **demo**.
- **Feature-ship** → **migration**. Blank-slate pattern-setting ≠ preserve-exactly pattern-applying — mixing them risks applying the fresh pattern too creatively to code that shouldn't change.
- **Planning** → **verification**.

**Corollary: switch the workspace, not just the chat.** A new chat in the same project workspace still loads project memory, project-scoped MCPs, and the codebase via the file system. Project context contaminates demos even when the chat is fresh. The load-bearing rule is the workspace, not the chat — `cd` to a scratch dir before starting a fresh AI session.

### Tool affordances travel; resource affordances don't (yet)

When the same data can be exposed through MCP as both a tool and a resource, ship both. Tool support is mature and universal across MCP clients (`tools/call` is the oldest, most-tested primitive). Resource support is newer and spottier — especially in prompt-execution agent loops.

This matters most for **prompt bodies**. A prompt body that says "Call get_store_profile" relies on universal client behaviour. A prompt body that says "Read the `store://profile` resource" relies on the client's agent loop handling resources in prompt execution — which isn't guaranteed.

The cost of duplication is small (thin wrapper; both affordances can delegate to the same controller/provider). The benefit is ecosystem portability.

### Custom-header auth travels to desktop/CLI; OAuth travels to browsers

The mature/universal MCP auth dialect depends on the client family:

- **Desktop / CLI clients** let the user configure arbitrary request headers. Custom-header auth is universal here.
- **Browser-based clients** per the MCP spec only implement OAuth 2.1 with Protected Resource Metadata discovery. No UI for arbitrary headers.

A 401 from a browser-based client needs to include `WWW-Authenticate: Bearer resource_metadata="…"` pointing to a `/.well-known/oauth-protected-resource` document. Without that, the client reports a generic "couldn't reach the server" — accurate-but-misleading.

Practical: developer-controlled deployments can stay custom-header (the developer configures their own client); merchant-facing surfaces (which have to assume browser clients) must default to OAuth.

### Proactive regulatory-context flagging

When a merchant's figures cross or sit near a tax-registration threshold, surface it proactively — but frame it as jurisdiction context, not as tax advice. The pattern: read-the-numbers → recognise a threshold is in play → flag the implication → hedge with "worth checking with your accountant."

Common thresholds worth flagging:
- **UK VAT:** £90k annualised turnover triggers mandatory registration.
- **EU OSS:** €10k/year in cross-border B2C sales across EU destinations.
- **Canada GST/HST:** CAD 30k small-supplier threshold.
- **Australia GST:** AUD 75k/year.
- **US sales tax:** state-by-state economic nexus — varies widely.

When NOT to surface: the merchant asked a specific arithmetic question ("what was March VAT?"); answer the literal question first. Always hedge: "if you're VAT-registered and trading above £90k, this is worth checking with your accountant" — never state a compliance conclusion.

## Style

British English throughout (consistency beats inconsistency).

## Pull requests

- One feature / fix per branch
- `./bin/check` clean before pushing
- Conventional commit messages (`feat:`, `fix:`, `chore:`, `docs:`)
- New analytics skill = code + test in the same PR (the coverage guard fails CI otherwise)
- Note any new design pattern in this file's "Design patterns worth knowing" section if it's worth keeping
