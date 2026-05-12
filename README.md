# WooCommerce for Claude

**Make any WooCommerce store AI-operable.**

WooCommerce core already has [native MCP support](https://developer.woocommerce.com/docs/features/mcp/) (in developer preview) — AI tools can connect and perform basic product and order operations. That's the plumbing.

WooCommerce for Claude adds the **intelligence layer** on top: structured store knowledge, AI readiness scoring, analytics insights, and a library of Skills that make a Woo store genuinely useful to AI.

Core MCP: "Claude can talk to your store."
WooCommerce for Claude: "Claude can understand your store and help you run it better."

---

## Performance

For a typical WooCommerce store, the plugin's load is lighter than loading the WC Analytics dashboard a few times an hour. It queries WooCommerce's pre-aggregated analytics lookup tables, caches every result for an hour, and runs nothing in the background — no cron, no polling, no sync jobs.

---

## Connect your Woo store to an AI client

### Prerequisites

- A self-hosted WooCommerce store (version 8.0+)
- Node.js 18+ on the machine running your MCP client
- An MCP-capable client (Claude Desktop, Claude Code, or any other MCP client)

### Claude Desktop — one click

1. Install and activate WooCommerce for Claude. For local dev: `pnpm exec wp-env start`.
2. Open **WooCommerce → Settings → WooCommerce for Claude** in WP admin (or click **Set up Claude** in the post-activation notice).
3. If WooCommerce MCP integration isn't on yet, click **Enable WooCommerce MCP integration**.
4. Click **Download WooCommerce for Claude**, then double-click the downloaded `.mcpb` file. Claude Desktop registers WooCommerce for Claude automatically — no copy-paste, no JSON, no API key wrangling.

The setup page auto-creates a Read-only WooCommerce REST API key (named "WooCommerce for Claude — Claude Desktop") and embeds it in the bundle. Switch to Read + Write on the same page if you want Claude to be able to create or edit products and orders. The bundle contains a credential — if it leaks, click **Regenerate** on the same page to revoke it instantly.

### Other MCP clients (Claude Code, Cursor, …)

Same setup page, **Manual Setup** card. Pick your client from the dropdown — WooCommerce for Claude renders a copy-pasteable JSON snippet (or a `claude mcp add …` one-liner for Claude Code) with this store's URL and the auto-generated API key already filled in.

### Manual / scripted setup

If you'd rather configure everything yourself — for example to drop the admin page from your workflow, ship via WP-CLI, or wire a CI deploy — the manual flow is:

#### 1. Create an API key

In **WooCommerce > Settings > Advanced > REST API**, create a new key with Read or Read/Write permissions. Save the consumer key (`ck_...`) and consumer secret (`cs_...`). The MCP endpoint authenticates with the standard WooCommerce REST API key flow — `ck_...` as the username and `cs_...` as the password over HTTP Basic auth. HTTPS is required for production; HTTP works for local dev.

#### 2. Point your MCP client at the endpoint

The recommended path is to connect through [`@automattic/mcp-wordpress-remote`](https://github.com/Automattic/mcp-wordpress-remote) — a lightweight local proxy that translates stdio-based MCP (what most clients speak) into HTTP requests to WordPress. This works across Claude Desktop, Claude Code, and any other MCP client.

**Claude Code** — one command:

```bash
claude mcp add woocommerce-claude \
  --env WP_API_URL=https://yourstore.com/wp-json/woocommerce-claude/mcp \
  --env WP_API_USERNAME=ck_xxx \
  --env WP_API_PASSWORD=cs_xxx \
  -- npx -y @automattic/mcp-wordpress-remote@0.3.0
```

**Claude Desktop / Cursor / generic** — add to your MCP config manually:

```json
{
  "mcpServers": {
    "woocommerce-claude": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@0.3.0"],
      "env": {
        "WP_API_URL": "https://yourstore.com/wp-json/woocommerce-claude/mcp",
        "WP_API_USERNAME": "ck_xxx",
        "WP_API_PASSWORD": "cs_xxx"
      }
    }
  }
}
```

Having trouble? See the [mcp-wordpress-remote troubleshooting guide](https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md).

If your MCP client supports HTTP transport natively (some do, many don't), you can point it straight at the endpoint without the proxy. Use HTTP Basic auth with the consumer key as the username and consumer secret as the password.

### Restart your client and talk to your store

You now have these tools available (plus the nine built-in `woocommerce-*` CRUD tools from WC core):

| Tool                                       | What it does                                                                                              |
| ------------------------------------------ | --------------------------------------------------------------------------------------------------------- |
| `woocommerce-claude-search-products`       | Search the catalog with enriched metadata                                                                 |
| `woocommerce-claude-get-product-details`   | Full product data with completeness scores                                                                |
| `woocommerce-claude-get-readiness-score`   | AI readiness score (0-100) with factor breakdown                                                          |
| `woocommerce-claude-get-recommendations`   | Prioritised improvements for AI readiness                                                                 |
| `woocommerce-claude-suggest-improvements`  | Specific improvements for a product or the whole store                                                    |
| `wc-analytics-totals`                      | Headline aggregates by subject: revenue, orders, customers, customer_value, tax, refunds                  |
| `wc-analytics-breakdown`                   | Grouped aggregates by subject + dimension: revenue, attribution, products, refunds, tax, coupons          |
| `wc-analytics-series`                      | Time-series by subject + interval (day / week / month / auto): customers, products                        |
| `wc-analytics-rows`                        | Flexible filter engine across orders / products / customers; aggregate-or-rows mode; pseudonymised rows   |
| `wc-analytics-confirm-large-range`         | Approve a >365-day range query after presenting the cost estimate (the gate-handshake helper)             |

Plus three resources (`store://profile`, `store://catalog-schema`, `store://policies`) and seven prompts (`wc-prompts-catalog-audit`, `wc-prompts-product-improve`, `wc-prompts-weekly-store-review`, `wc-prompts-revenue-drop-triage`, `wc-prompts-failed-order-triage`, `wc-prompts-refund-triage`, `wc-prompts-coupon-performance-triage`).

The repository also includes reference agent Skills in [`skills/`](./skills/). These are workflow wrappers around the MCP tools — for example, `weekly-store-review` combines revenue, orders, customers, products, attribution, and refunds into one merchant-friendly weekly review, `revenue-drop-triage` separates order volume, basket size, customer mix, refunds, products, channels, and pipeline into a practical diagnosis, `failed-order-triage` turns order status and payment-pipeline diagnostics into an action queue, `refund-triage` turns refund size, timing, and product/country drivers into practical checks, `coupon-performance-triage` separates coupon usage, discount cost, refunds, new-customer signal, and pipeline into a practical discount review, `customer-value-review` turns active-base LTV, repeat purchasing, cohort retention, and pseudonymised top customers into retention actions, and `tax-reconciliation` separates collected paid tax, shipping tax, refunded tax, on-hold tax, top rates, and WooCommerce admin reconciliation views. The MCP tools stay as stable data primitives; Skills carry the opinionated workflow guidance.

For best results in clients that support agent plugins or skills, install the companion agent plugin metadata from this repository as well as connecting the store MCP server. See [docs/agent-plugin.md](./docs/agent-plugin.md). The WordPress plugin provides live store data; the agent plugin teaches the AI client how to use it reliably.

In Claude Code, add the repository as a marketplace with `/plugin marketplace add woocommerce/woocommerce-claude`, then install it with `/plugin install woocommerce-claude@woocommerce-claude-ai-toolkit`. After `/reload-plugins`, try `/woocommerce-claude:weekly-store-review`, `/woocommerce-claude:revenue-drop-triage`, `/woocommerce-claude:failed-order-triage`, `/woocommerce-claude:refund-triage`, `/woocommerce-claude:coupon-performance-triage`, `/woocommerce-claude:customer-value-review`, or `/woocommerce-claude:tax-reconciliation`. Some Claude Code builds also expose the shorter aliases `/weekly-store-review`, `/revenue-drop-triage`, `/failed-order-triage`, `/refund-triage`, `/coupon-performance-triage`, `/customer-value-review`, and `/tax-reconciliation`.

### What can you ask?

A range of questions covering the store's catalogue, performance, customers, and AI readiness:

**Store performance**

- "How did my store do this week?"
- "Give me my weekly store review"
- "What's my AOV right now?"
- "How does Q4 last year compare to this year?"
- "What's my collected revenue vs my pending revenue?"
- "Triage my failed and on-hold orders from the last 30 days."
- "Triage refunds from the last 30 days and show what's driving them."
- "Revenue is down this month. Triage what changed."
- "Are my coupons working? Triage the last 30 days."
- "Review my customer value and repeat purchasing."

**Products**

- "Which products are selling best this month?"
- "Which products have the weakest descriptions?"
- "Run a full catalog audit"
- "Write a better description for the Garden Trowel"

**Customers and attribution**

- "Who are my best customers over time?"
- "Is my repeat customer rate improving?"
- "Which one-time buyers should I try to convert into repeat customers?"
- "Which channels are bringing in new customers?"
- "What's driving my sales — channel, source, campaign?"

**Refunds, coupons, tax**

- "What's being refunded, and why?"
- "Are my coupons working?"
- "Which discount codes are costing the most, and are they bringing useful orders?"
- "What did I collect in tax this quarter?"

**AI readiness**

- "What's my store's AI readiness score?"
- "What are my top 5 recommendations for improving AI discoverability?"

### What it can't do

The plugin describes the store from order data and structured catalogue knowledge. It deliberately doesn't try to do these:

- **Visitor / session data** — no page views, time on site, or conversion rate (orders only). For that, use Jetpack Stats, Google Analytics, or Parse.ly.
- **Ad spend / real ROAS** — we have revenue by channel but not ad cost. Combine with a Google Ads or Meta Ads MCP for true ROAS.
- **Cart abandonment** — orders only exist once placed. Use a cart-recovery plugin (Klaviyo, Omnisend, CartBounty) for abandonment data.
- **Customer PII in responses** — analytics responses are aggregated by design (counts, sums, averages). Individual names / emails / addresses are not surfaced. Look up specific customers in WP Admin.
- **Predictive analytics** — actuals only, no forecasts or churn prediction.
- **Competitor / market data** — your own store data only.

---

## How it works

### The plugin exposes structured knowledge

Standard WooCommerce REST API gives you raw product data. The plugin adds a **knowledge layer**:

- **Store profile** — identity, locale, payment methods, shipping zones, features
- **Catalog schema** — category tree, attribute definitions, product type distribution
- **Enriched products** — standard fields plus completeness scores, relationship maps
- **Policies** — shipping, returns, privacy policies as structured text

### The scoring engine measures AI readiness

Four factors, weighted by importance:

| Factor               | Weight | What it measures                                             |
| -------------------- | ------ | ------------------------------------------------------------ |
| Product Completeness | 35%    | Descriptions, images, categories, attributes, pricing, stock |
| Schema Coverage      | 25%    | Attribute usage, category depth, product relationships       |
| Content Quality      | 25%    | Description structure, SEO metadata, image alt text          |
| Policy Completeness  | 15%    | Shipping, returns, privacy, T&C page content                 |

### Builds on WooCommerce core MCP

WooCommerce core already exposes basic product and order CRUD via MCP. This project doesn't rebuild that — it extends it by registering additional WordPress Abilities that the core MCP server picks up alongside its own tools.

| Layer                    | What it provides                                                  | Who built it          |
| ------------------------ | ----------------------------------------------------------------- | --------------------- |
| **WooCommerce core MCP** | HTTP transport, auth, product/order CRUD tools                    | WooCommerce core team |
| **WooCommerce for Claude plugin**       | Analytics skills, knowledge resources, prompts, readiness scoring | This project          |

Everything ships through the single endpoint at `/wp-json/woocommerce-claude/mcp`. There is no separate MCP server process to run — the plugin registers its abilities and stands up its own MCP server on `mcp_adapter_init` using the WordPress MCP adapter (vendored inside WooCommerce). The server bundles tools, resources, and prompts directly, and authenticates via standard HTTP Basic auth — `ck_xxx` as the username, `cs_xxx` as the password, sourced from a WooCommerce REST API key with `read` or `read_write` scope.

---

## For developers: extending the knowledge layer

The plugin uses a provider pattern. Register your own knowledge provider:

```php
add_action( 'woocommerce_claude_register_providers', function( $registry ) {
    $registry->register( new My_Custom_Provider() );
});
```

Your provider implements `WooCommerce\Claude\Knowledge\KnowledgeProvider`:

```php
interface KnowledgeProvider {
    public function get_id();        // e.g. 'subscriptions'
    public function get_label();     // e.g. 'Subscription Data'
    public function is_available();  // Check if your data source exists
    public function get_data( $args = array() );  // Return structured knowledge
}
```

Add custom scoring factors:

```php
add_filter( 'woocommerce_claude_scoring_factors', function( $factors ) {
    $factors[] = new My_Custom_Scoring_Factor();
    return $factors;
});
```

Filter enriched product data:

```php
add_filter( 'woocommerce_claude_enriched_product', function( $data, $product ) {
    $data['subscription_status'] = get_post_meta( $product->get_id(), '_subscription_status', true );
    return $data;
}, 10, 2 );
```

---

## Local development

```bash
# Install Node dependencies:
pnpm install

# Start the WordPress + WooCommerce environment:
pnpm exec wp-env start

# Test the plugin's REST endpoints (still available for direct access):
curl -u ck_xxx:cs_xxx http://localhost:8888/wp-json/woocommerce-claude/v1/store/profile
curl -u ck_xxx:cs_xxx http://localhost:8888/wp-json/woocommerce-claude/v1/readiness/score
curl -u ck_xxx:cs_xxx http://localhost:8888/wp-json/woocommerce-claude/v1/products

# Test the MCP endpoint (HTTP works for local dev — HTTPS only matters for production):
curl -X POST -u ck_xxx:cs_xxx http://localhost:8888/wp-json/woocommerce-claude/mcp \
  -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
```

### Pre-push checks

Run the full lint/test suite locally before pushing:

```bash
./bin/check
```

Runs PHPCS (WordPress + Docs), composer audit, and a PHPUnit smoke test inside the wp-env tests-cli container. First run installs composer and pnpm deps; subsequent runs skip that.

`./bin/check` mirrors `.github/workflows/ci.yml` line-for-line, so the same checks run in CI on every push.

### Demo store ("WooCommerce for Claude!")

For testing analytics Skills, seed a full demo store with 2 years of realistic data:

```bash
# Copy the seed script into the container and run it (~10 minutes):
pnpm exec wp-env run cli -- bash -c "cat > /tmp/seed.php" < tools/seed-demo-store.php
pnpm exec wp-env run cli -- wp eval-file /tmp/seed.php
```

This generates:

| **Products** | 71 (50 simple + 21 variations across 22 categories) |
| **Customers** | 500 (power law — 20% generate 60% of orders) |
| **Orders** | 5,000 over 24 months |
| **Coupons** | 12 (always-on, seasonal, loyalty) |
| **Countries** | 10 (60% US, 15% UK, 10% CA, 5% DE, 5% AU, 5% other) |
| **Attribution** | Full channel/source/campaign/device with trends over time |

The script uses a fixed random seed (`mt_srand(42)`) so every run produces identical data — both partners testing against the same store get the same expected values.

Includes realistic patterns: growth trends, seasonal spikes (Black Friday, Christmas), weekend dips, time-of-day patterns, partial refunds, guest checkouts, and edge cases for the analytics queries.

To reset and re-seed:

```bash
pnpm exec wp-env run cli -- wp db reset --yes
pnpm exec wp-env run cli -- wp core install --url=localhost:8888 --title="WooCommerce for Claude!" \
  --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email
pnpm exec wp-env run cli -- wp plugin activate woocommerce.latest-stable plugin
pnpm exec wp-env run cli -- wp wc tool run install_pages --user=admin
pnpm exec wp-env run cli -- bash -c "cat > /tmp/seed.php" < tools/seed-demo-store.php
pnpm exec wp-env run cli -- wp eval-file /tmp/seed.php
```

---

## Contributing

Contributions welcome — file an issue or open a pull request.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
