# Hey Woo

**Make any WooCommerce store AI-operable.**

WooCommerce core already has [native MCP support](https://developer.woocommerce.com/docs/features/mcp/) (in developer preview) — AI tools can connect and perform basic product and order operations. That's the plumbing.

Hey Woo adds the **intelligence layer** on top: structured store knowledge, AI readiness scoring, analytics insights, and a library of Skills that make a Woo store genuinely useful to AI.

Core MCP: "Claude can talk to your store."
Hey Woo: "Claude can understand your store and help you run it better."

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

1. Install and activate Hey Woo. For local dev: `npx wp-env start`.
2. Open **WooCommerce → Settings → Hey Woo** in WP admin (or click **Set up Claude** in the post-activation notice).
3. If WooCommerce MCP integration isn't on yet, click **Enable WooCommerce MCP integration**.
4. Click **Download Hey Woo for Claude Desktop**, then double-click the downloaded `.mcpb` file. Claude Desktop registers Hey Woo automatically — no copy-paste, no JSON, no API key wrangling.

The setup page auto-creates a Read-only WooCommerce REST API key (named "Hey Woo MCP — Claude Desktop") and embeds it in the bundle. Switch to Read + Write on the same page if you want Claude to be able to create or edit products and orders. The bundle contains a credential — if it leaks, click **Regenerate** on the same page to revoke it instantly.

### Other MCP clients (Claude Code, Cursor, …)

Same setup page, **Manual Setup** card. Pick your client from the dropdown — Hey Woo renders a copy-pasteable JSON snippet (or a `claude mcp add …` one-liner for Claude Code) with this store's URL and the auto-generated API key already filled in.

### Manual / scripted setup

If you'd rather configure everything yourself — for example to drop the admin page from your workflow, ship via WP-CLI, or wire a CI deploy — the manual flow is unchanged:

#### 1. Enable WooCommerce core MCP

```bash
wp option update woocommerce_feature_mcp_integration_enabled yes
```

(Or via the admin: **WooCommerce > Settings > Advanced > Features > MCP integration**.)

#### 2. Create an API key

In **WooCommerce > Settings > Advanced > REST API**, create a new key with Read or Read/Write permissions. Save the consumer key (`ck_...`) and consumer secret (`cs_...`). The MCP endpoint authenticates via an `X-MCP-API-Key: ck_...:cs_...` header; HTTPS is required by default. For local HTTP dev see the mu-plugin snippet in the [Local development](#local-development) section.

#### 3. Point your MCP client at the endpoint

The WooCommerce MCP docs recommend connecting through [`@automattic/mcp-wordpress-remote`](https://github.com/Automattic/mcp-wordpress-remote) — a lightweight local proxy that translates stdio-based MCP (what most clients speak) into HTTP requests to WordPress. This works across Claude Desktop, Claude Code, and any other MCP client.

**Claude Code** — one command:

```bash
claude mcp add hey-woo \
  --env WP_API_URL=https://yourstore.com/wp-json/woocommerce/mcp \
  --env CUSTOM_HEADERS='{"X-MCP-API-Key": "ck_xxx:cs_xxx"}' \
  -- npx -y @automattic/mcp-wordpress-remote@latest
```

**Claude Desktop / Cursor / generic** — add to your MCP config manually:

```json
{
  "mcpServers": {
    "hey-woo": {
      "command": "npx",
      "args": ["-y", "@automattic/mcp-wordpress-remote@latest"],
      "env": {
        "WP_API_URL": "https://yourstore.com/wp-json/woocommerce/mcp",
        "CUSTOM_HEADERS": "{\"X-MCP-API-Key\": \"ck_xxx:cs_xxx\"}"
      }
    }
  }
}
```

Having trouble? See the [mcp-wordpress-remote troubleshooting guide](https://github.com/Automattic/mcp-wordpress-remote/blob/trunk/Docs/troubleshooting.md).

If your MCP client supports HTTP transport natively (some do, many don't), you can point it straight at the endpoint without the proxy:

```json
{
  "mcpServers": {
    "hey-woo": {
      "type": "http",
      "url": "https://yourstore.com/wp-json/woocommerce/mcp",
      "headers": {
        "X-MCP-API-Key": "ck_xxx:cs_xxx"
      }
    }
  }
}
```

### Restart your client and talk to your store

You now have these tools available (plus the nine built-in `woocommerce-*` CRUD tools from WC core):

| Tool                                   | What it does                                           |
| -------------------------------------- | ------------------------------------------------------ |
| `hey-woo-search-products`              | Search the catalog with enriched metadata              |
| `hey-woo-get-product-details`          | Full product data with completeness scores             |
| `hey-woo-get-readiness-score`          | AI readiness score (0-100) with factor breakdown       |
| `hey-woo-get-recommendations`          | Prioritised improvements for AI readiness              |
| `hey-woo-suggest-improvements`         | Specific improvements for a product or the whole store |
| `wc-analytics-get-revenue-summary`     | Revenue summary with three-view reconciliation         |
| `wc-analytics-get-orders-summary`      | Order counts, AOV, status breakdown, heatmap           |
| `wc-analytics-get-product-performance` | Top products with catalogue coverage + time series     |
| `wc-analytics-get-customer-overview`   | New vs returning split, repeat rate, pipeline          |
| `wc-analytics-get-customer-value`      | Lifetime value, cohort retention, top customers        |
| `wc-analytics-get-attribution`         | Revenue by channel, source, medium, campaign, device   |

Plus three resources (`store://profile`, `store://catalog-schema`, `store://policies`) and two prompts (`wc-prompts-catalog-audit`, `wc-prompts-product-improve`).

### What can you ask?

A range of questions covering the store's catalogue, performance, customers, and AI readiness:

**Store performance**

- "How did my store do this week?"
- "What's my AOV right now?"
- "How does Q4 last year compare to this year?"
- "What's my collected revenue vs my pending revenue?"

**Products**

- "Which products are selling best this month?"
- "Which products have the weakest descriptions?"
- "Run a full catalog audit"
- "Write a better description for the Garden Trowel"

**Customers and attribution**

- "Who are my best customers over time?"
- "Is my repeat customer rate improving?"
- "Which channels are bringing in new customers?"
- "What's driving my sales — channel, source, campaign?"

**Refunds, coupons, tax**

- "What's being refunded, and why?"
- "Are my coupons working?"
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
| **Hey Woo plugin**       | Analytics skills, knowledge resources, prompts, readiness scoring | This project          |

Everything ships through the single endpoint at `/wp-json/woocommerce/mcp`. There is no separate MCP server process to run — the plugin registers its abilities, a filter widens the core server's inclusion rules to cover the `wc-analytics/*` and `hey-woo/*` namespaces, and resources/prompts are injected via the `mcp_adapter_init` action.

---

## For developers: extending the knowledge layer

The plugin uses a provider pattern. Register your own knowledge provider:

```php
add_action( 'hey_woo_register_providers', function( $registry ) {
    $registry->register( new My_Custom_Provider() );
});
```

Your provider implements `HeyWoo\Knowledge\KnowledgeProvider`:

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
add_filter( 'hey_woo_scoring_factors', function( $factors ) {
    $factors[] = new My_Custom_Scoring_Factor();
    return $factors;
});
```

Filter enriched product data:

```php
add_filter( 'hey_woo_enriched_product', function( $data, $product ) {
    $data['subscription_status'] = get_post_meta( $product->get_id(), '_subscription_status', true );
    return $data;
}, 10, 2 );
```

---

## Local development

```bash
# Start the WordPress + WooCommerce environment:
npx @wordpress/env start

# Enable Woo core MCP:
npx @wordpress/env run cli -- wp option update woocommerce_feature_mcp_integration_enabled yes

# Test the plugin's REST endpoints (still available for direct access):
curl -u ck_xxx:cs_xxx http://localhost:8888/wp-json/hey-woo/v1/store/profile
curl -u ck_xxx:cs_xxx http://localhost:8888/wp-json/hey-woo/v1/readiness/score
curl -u ck_xxx:cs_xxx http://localhost:8888/wp-json/hey-woo/v1/products

# MCP requires HTTPS by default. For local HTTP, add a mu-plugin:
echo '<?php add_filter( "woocommerce_mcp_allow_insecure_transport", "__return_true" );' \
  | npx @wordpress/env run cli -- bash -c "cat > /var/www/html/wp-content/mu-plugins/allow-http-mcp.php"

# Then test the endpoint:
curl -X POST http://localhost:8888/wp-json/woocommerce/mcp \
  -H 'Content-Type: application/json' \
  -H 'X-MCP-API-Key: ck_xxx:cs_xxx' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}'
```

### Pre-push checks

Run the full lint/test suite locally before pushing:

```bash
./bin/check
```

Runs PHPCS (WordPress + Docs), composer audit, and a PHPUnit smoke test inside the wp-env tests-cli container. First run installs composer deps; subsequent runs skip that.

`./bin/check` mirrors `.github/workflows/ci.yml` line-for-line, so the same checks run in CI on every push.

### Demo store ("Hey Woo!")

For testing analytics Skills, seed a full demo store with 2 years of realistic data:

```bash
# Copy the seed script into the container and run it (~10 minutes):
npx @wordpress/env run cli -- bash -c "cat > /tmp/seed.php" < tools/seed-demo-store.php
npx @wordpress/env run cli -- wp eval-file /tmp/seed.php
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
npx @wordpress/env run cli -- wp db reset --yes
npx @wordpress/env run cli -- wp core install --url=localhost:8888 --title="Hey Woo!" \
  --admin_user=admin --admin_password=password --admin_email=admin@example.com --skip-email
npx @wordpress/env run cli -- wp plugin activate woocommerce.latest-stable plugin
npx @wordpress/env run cli -- wp wc tool run install_pages --user=admin
npx @wordpress/env run cli -- bash -c "cat > /tmp/seed.php" < tools/seed-demo-store.php
npx @wordpress/env run cli -- wp eval-file /tmp/seed.php
```

---

## Contributing

Contributions welcome — file an issue or open a pull request.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
