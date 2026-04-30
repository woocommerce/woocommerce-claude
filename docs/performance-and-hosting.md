# Performance and hosting considerations

This doc covers what happens on a merchant's server when Claude asks questions through Hey Woo, which query shapes are expensive, and what merchants can do to stay comfortably inside their hosting plan.

If you're running a typical WooCommerce store on decent shared hosting, you can skip to [Where it could bite](#where-it-could-bite) and leave the rest for later.

## TL;DR

For most merchants, the plugin's load is **lighter than loading the WC Analytics dashboard a few times an hour**. It queries the same pre-aggregated lookup tables Analytics uses, caches every result for an hour, and runs nothing in the background — no cron, no polling, no sync jobs.

Where it can get noisier: very large catalogues (10k+ SKUs), very large order histories (100k+ orders), unbounded date ranges, and shared hosting without an object cache. Details below.

## How load is generated

Each question to Claude can fire one or more tool calls against `/wp-json/woocommerce/mcp`. Per uncached call:

1. The plugin runs a direct SQL query against WooCommerce's analytics lookup tables (`wc_order_stats`, `wc_order_product_lookup`, `wc_customer_lookup`, etc.).
2. The result is stored in a WordPress transient with a 1-hour TTL.
3. The response is returned to Claude.

Repeat calls for the same skill + params within the hour hit the transient and skip the SQL entirely. Nothing runs when the merchant isn't asking.

This is the same query pattern that powers the WC Analytics dashboard — merchants who already use Analytics without issue will see no meaningful change.

## Where it could bite

Six query shapes account for almost all the risk. Each row below: what makes it expensive, who's most exposed, and what to do.

### 1. Unbounded or multi-year date ranges

**Why it's expensive:** aggregation scans every row in the window, cache or no cache. A 5-year window on a 5-year-old store with 500k orders scans 500k rows.

**Who's most at risk:** stores with 100k+ orders on shared hosting.

**What to do:** prefer the built-in period values (`last_30_days`, `last_90_days`, `this_year`) or keep custom date ranges under 12 months. Chunk longer windows year-by-year if needed.

### 2. High-cardinality `group_by`

**Why it's expensive:** even though every skill caps returned rows at 50, the underlying `GROUP BY` still aggregates across every distinct value before the top-N slice. `get_attribution group_by=term` on a store with thousands of organic search terms is the classic case.

**Who's most at risk:** stores with heavy organic / paid search traffic, wide geographic spread (100+ countries), or high-cardinality UTM content dimensions.

**What to do:** start at low-cardinality dimensions (`channel`, `source`, `device`, `country`, `payment_method`) and drill into high-cardinality ones (`term`, `content`, `product`) only for narrower date ranges.

### 3. Product-level grouping on large catalogues

**Why it's expensive:** `get_product_performance` and `get_refund_analysis group_by=product` scan the product lookup table, which is joined to order lines. A 50k-SKU store with 500k+ orders is a big join.

**Who's most at risk:** large catalogues on shared hosting without an object cache.

**What to do:** keep product-level questions to shorter windows (30–90 days). Use the top-N limit (max 50) to cap returned rows — the plugin already does this by default.

### 4. Classic (non-HPOS) order storage

**Why it's expensive:** attribution queries read UTM data from order meta. On HPOS, that's a dedicated `wp_wc_orders_meta` table. On classic storage, it's `wp_postmeta` — a wide table shared with every post type on the site, and often the biggest table in the database.

**Who's most at risk:** stores still on classic storage with large order histories and heavy post/page/CPT content.

**What to do:** enable HPOS (**WooCommerce > Settings > Advanced > Features > High-Performance Order Storage**). This is a one-time migration that benefits the whole store, not just this plugin.

### 5. Cold cache without an object cache

**Why it's expensive:** WordPress transients go in `wp_options` by default. On cheap shared hosting with no object cache (Redis/Memcached), every first-of-the-hour tool call writes a row to `wp_options`. Not catastrophic, but it adds up across chatty sessions.

**Who's most at risk:** shared hosting with no object cache, especially agency setups where multiple merchants hit the same database server.

**What to do:** install an object cache (Redis, Memcached, or a managed equivalent). Most modern WooCommerce hosts include one — if yours doesn't, it's worth asking them to enable it, or installing a drop-in like [Redis Object Cache](https://wordpress.org/plugins/redis-cache/).

### 6. Rapid-fire AI chains

**Why it's expensive:** Claude sometimes fires several tools in sequence to answer one question — e.g. "how did I do this quarter?" might trigger `get_revenue_summary`, `get_orders_summary`, and `get_attribution` back-to-back. First call of each hits SQL; subsequent identical calls are cached.

**Who's most at risk:** no one structurally — this is bounded by the tool count per skill. Worth being aware of if server response times already feel tight.

**What to do:** nothing merchant-side. Future versions may add a concurrency cap.

## Recommended hosting profile

These are guidelines, not hard requirements. Most stores will be fine on less.

| Store size | Shared hosting OK? | Object cache recommended? | HPOS recommended? |
|---|---|---|---|
| < 10k orders | Yes | Helpful | Yes (general WC recommendation) |
| 10k–100k orders | Yes, if the host is reasonable | Yes | Yes |
| 100k+ orders | Managed WooCommerce hosting recommended | Strongly recommended | Yes |
| 500k+ orders | Managed hosting + object cache | Required in practice | Yes — and consider data-warehouse tier when available |

## What merchants can do today

- **Enable HPOS** if you haven't already — general WC best practice, and directly helps attribution queries.
- **Install an object cache** — helps WooCommerce broadly, helps this plugin specifically.
- **Keep custom date ranges bounded** — prefer preset periods; chunk long windows.
- **Start wide, then drill down** — ask "what's my revenue by channel?" before "what's my revenue by UTM content?".

## What future versions will add

Not promises — a map of the levers we have if feedback shows load is actually an issue in the wild:

- **Hard date-range caps per skill** — reject windows over, say, 3 years unless explicitly overridden.
- **Concurrency cap** — soft limit on simultaneous tool calls per store.
- **Data-warehouse tier (Managed)** — for very large stores, queries can run against Automattic-hosted ClickHouse instead of the merchant's WP database. This removes the load entirely at the cost of sync lag (minutes-to-hours) and a Managed-tier subscription.

## If you're investigating a suspected issue

1. Check Query Monitor or a similar tool for slow queries tagged with transient keys starting `hey_woo_`.
2. Confirm the affected skill — the transient key includes the skill name.
3. Compare response times against the WC Analytics dashboard for the same date range. If Analytics is also slow, the bottleneck is upstream of this plugin.

Report anything unusual via the [project issues tracker](https://github.com/Automattic/hey-woo/issues) with the store size, hosting profile, and the question/skill that triggered it.
