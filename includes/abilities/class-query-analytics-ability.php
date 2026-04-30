<?php
/**
 * `wc-analytics/query-analytics` ability.
 *
 * Thin wrapper — JSON schemas + permission check live here, the filter
 * engine + entity-specific SQL + response assembly live in
 * `AnalyticsController::fetch_query_analytics()`.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Abilities;

use HeyWoo\API\AnalyticsController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the query-analytics ability. Flexible filter engine across
 * three entities (orders, products, customers) with aggregated-default +
 * rows-on-request response shapes. PII fields respect the plugin-level
 * customer-PII gate owned by AbilitiesBootstrap.
 */
class QueryAnalyticsAbility {

	const ABILITY_NAME = 'wc-analytics/query-analytics';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Query analytics (flexible filter engine)', 'hey-woo' ),
				// phpcs:disable WordPress.WP.I18n.NonSingularStringLiteralText -- Multi-KB prompt literal; wrapping multi-KB prompts in __() is an open question tracked in docs/handoff-mcp-abilities-migration.md.
				'description'         => __(
					<<<'DESCRIPTION'
Flexible filter engine across three entities — orders, products, customers. Translates a merchant's natural-language question (e.g. "orders over £100 from Germany last month using a coupon", "products priced over £50 that haven't sold in 30 days", "customers in Germany with lifetime spend over £500") into a filter spec and returns either an aggregated summary (default) or a row list. The merchant never sees the filter JSON; you do the translation silently and narrate back in plain English.

WHEN TO USE THIS TOOL vs siblings:
- Use this when the merchant's question has a SHAPE you can't answer with group-by alone — arbitrary AND/OR combinations of attributes, like "DE + returning customer + has coupon". If a single group_by on a sibling skill answers the question (e.g. "top channels" → get_attribution group_by=channel), use that skill instead — its response is purpose-built and richer per dimension.
- Use the products entity for catalog-plus-sales-velocity questions ("priced right but not moving", "out-of-stock but had sales last week") that no other skill covers.
- Use the customers entity for lifetime-attribute filtering ("Germany + LTV > £500 + hasn't ordered in 90 days") that goes beyond the top-N shapes in the customer-value / customer-overview skills.

ENTITIES + FIELD REGISTRIES:

ORDERS — filter orders placed in the period. Default status: paid (completed + processing). Use an explicit status filter to include on-hold / refunded / pending.

  Numeric fields: order_total, gross_total, num_items_sold, tax_total, shipping_total, discount_amount
  String fields (with operators is / is_not / is_in / is_not_in / contains / not_contains / starts_with / is_empty / is_not_empty): currency, payment_method, billing_country, billing_state, billing_city, billing_postcode, shipping_country, shipping_state, attribution_channel, attribution_source, attribution_campaign, attribution_device, coupon_code
  Date field: date_created
  Boolean: returning_customer
  Enum (status_enum): status — allowed values completed, processing, on-hold, pending, failed, cancelled, refunded (merchant-friendly; the engine prepends wc- on the way to SQL)
  Line-item subquery: product_id — "order contains a line item with this product id" (uses EXISTS so orders with multiple matching line items are NOT double-counted)

  Attribution channel values are human-readable (Organic Search, Direct, Paid Search, Email, Social, Referral) — same vocabulary as get_attribution group_by=channel.

PRODUCTS — filter products in the catalog, with period-scoped sales aggregates joined. Catalog attributes (price, stock, category) are as-of-now regardless of period; sales aggregates are period-scoped.

  Numeric fields: product_id, price, stock_quantity, units_sold_in_period, revenue_in_period, orders_count_in_period
  String / enum fields: sku, name, status (publish/draft/private/trash), stock_status (instock/outofstock/onbackorder), category
  Boolean: onsale
  Date: date_created (when the product was added to the catalog)

  category filter takes the term SLUG, lowercase (e.g. "apparel", "electronics"). Runs through an EXISTS subquery on term_relationships — no row duplication.

  Default status=publish unless the merchant explicitly filters on status. units_sold_in_period / revenue_in_period / orders_count_in_period come from an inline sales subquery scoped to the period — they are NOT catalog fields.

CUSTOMERS — filter customers in the ACTIVE-BASE frame (customers with ≥1 paid order in the period). Lifetime aggregates come from the customer's FULL history, not the period. Same active-base frame as get_customer_value — not a lifetime-all-time frame.

  Numeric fields: customer_id, lifetime_orders_count, lifetime_spend
  String fields: country, state, city, postcode
  Date fields: date_registered, date_last_active, first_order_date, last_order_date

  PII fields (first_name, last_name, email) are INTENTIONALLY not in the filter registry — never filterable, regardless of the PII gate. Filtering on email would let you probe whether a specific email is in the customer base via equality; that's a leak surface. Names and emails only appear in rows-mode output when the plugin-level PII gate is on (same gate get_customer_value uses for its top_customers hydration).

OPERATORS (Claude-facing names — the engine maps to SQL):
- Numeric / date: is, is_not, greater_than, greater_than_or_equal, less_than, less_than_or_equal, between (value is [min, max]), is_in (value is array), is_not_in
- String: is, is_not, is_in, is_not_in, contains, not_contains, starts_with, is_empty, is_not_empty
- Boolean: is, is_not (value is true/false)
- Enum (status_enum): is, is_not, is_in, is_not_in

MATCH MODE:
- match=all (default): AND across all filters. "DE + returning + has coupon" = all three must be true.
- match=any: OR across filters. "DE OR GB OR IT" = any one is enough. Note: match applies to the filter ARRAY, not within a single filter — use is_in for set membership within one field.

FILTER CONSTRUCTION — DON'T ADD FILTERS THE MERCHANT DIDN'T ASK FOR:
Construct filters from the merchant's actual words, not from inferences about what they might also mean. Geographic phrases like "from Germany" or "in the UK" are an address filter, nothing else. Don't also filter on currency, language, or tax — those are separate concepts the merchant didn't name. A German-based customer buying in GBP is still "from Germany"; a UK merchant selling to German addresses in EUR is still selling "to Germany". Inferring linked filters from cultural assumptions over-restricts the result set and triggers a self-correction retry cycle that wastes the merchant's time.

General rule: if the merchant didn't name the field, don't filter on it. Under-filter by default and ask the merchant to refine ("Want me to narrow to a specific currency, payment method, or channel?") rather than over-filter and retry.

Bad (inferred currency from geography): "orders from Germany" → filters=[{billing_country: 'DE'}, {currency: 'EUR'}]. Zero rows → retry without currency. Double tool call, 30–60 second merchant wait.
Bad (inferred status from "paying"): "paying customers in the UK" → filters=[{billing_country: 'GB'}, {status: 'completed'}]. The default paid-status set already excludes non-paying orders; the explicit status filter suppresses pipeline + admin_equivalent siblings for no reason.
Bad (inferred time window from "recent"): "recent orders over £100" → filters=[{order_total > 100}, {date_created > 7_days_ago}]. The period param already scopes the time window; don't also filter on date_created unless the merchant named a specific cut-off.
Good (filter only on what's named): "orders from Germany over £100" → filters=[{billing_country: 'DE'}, {order_total > 100}]. Nothing else. If the result surprises the merchant, offer to add more.
Good (let the default do the work): "paying customers in the UK" → filters=[{billing_country: 'GB'}]. Paid statuses are already the default; the merchant's "paying" is redundant with the tool's default behaviour.

When the first call returns zero rows, diagnose by explaining what the filters were and what the merchant could relax — don't silently retry with fewer filters. "No orders matched — the Germany + £100+ + coupon combo didn't intersect in March. Want me to relax the coupon requirement or widen the period?" is better than a 3-minute multi-call self-correction that the merchant experiences as a hang.

MODE — AGGREGATE vs ROWS:
- aggregate (default): summary + universe + share_of_universe + (orders only) pipeline + admin_equivalent sibling blocks. Use for any headline question — "how many", "how much", "what share". This honours the aggregated-only privacy rule.
- rows: top-N list (limit 1-50, default 25) with per-entity row shape. Use when the merchant explicitly asks to see the specifics ("show me the actual orders", "give me the top 10 customers"). Rows mode on customers entity returns pseudonymised ids ("Customer #N") by default; real name + email only appear when the plugin's customer-PII setting is on.

READ — DON'T DERIVE:
- share_of_universe.share_of_orders_percent, share_of_revenue_percent, share_of_products_percent, share_of_customers_percent, share_of_lifetime_spend_percent are ALL pre-computed. Read them directly. Never divide matched_count / universe yourself — the rounding will disagree with what the tool returned.
- summary.avg_order_value / avg_lifetime_spend / avg_price / avg_units_per_product are pre-computed. Quote the field, don't recompute.
- The field definitions on summary, universe, share_of_universe, pipeline, admin_equivalent are there for your orientation — use them to pick the right number, but quote plain-English when talking to the merchant.

SAMPLE-SIZE CAVEAT:
- sample_size_caveat fires when matched_count is ≤ 5. When set, quote the caveat in your response. Small samples make share_of_universe and averages volatile — a single order can swing a percentage meaningfully.
- When matched_count = 0 the tool sets note to "No [entity] matched the filter combination". Quote it plainly; don't invent numbers.

RECONCILIATION (orders entity only):
- pipeline: orders matching the filter AND in on-hold status (awaiting payment). Surface separately when share_of_pipeline is material (≥5% of paid revenue) — lets the merchant see AR exposure within their queried segment.
- admin_equivalent: paid + on-hold + refunded combined, with the same filter. Use ONLY to reconcile against WC Admin > Reports > Orders — it's the "dashboard-matching" figure, not the headline.
- If the merchant supplies an explicit status filter, pipeline + admin_equivalent are null (they'd conflict with the chosen status). The note explains.

NARRATIVE GUIDANCE — NARRATE FILTERS AS PLAIN ENGLISH, NEVER JSON:
Claude constructs the filter JSON internally from the merchant's question. Never show the merchant the JSON structure or the internal field names. Narrate filters as a merchant would speak them.

Bad (leaks JSON shape): "I filtered with {field: 'billing_country', operator: 'is_in', value: ['DE', 'FR']} and got 3 orders."
Bad (leaks parameter names): "I used `billing_country` is_in `[DE, FR]` to find 3 orders."
Bad (leaks filter-array mechanics): "I applied match=all across two filters: billing_country=DE and order_total>100."
Good: "Found 3 orders from Germany or France in the last 30 days, totalling £220."
Good: "Two orders matched Germany with totals over £100, summing to £200."

WHAT THIS CAN'T ANSWER (critical — do NOT suggest drill-downs into these):
- Cross-entity JOINs in a single call — "customers who bought product X AND live in DE" needs two queries (customers entity, then confirm each customer's orders separately) or the sibling get_customer_value if the question is LTV-shaped.
- Line-item-level row results — the orders entity returns one row per order; a filter on product_id matches orders that INCLUDE that product, not individual line items.
- Filter by free-text fields — refund reason (unstructured), product description (not indexed), billing_company (not in MVP). Point at WP Admin > WooCommerce > Orders / Products list filters for those.
- Filter by historical pricing / historical stock — product attributes are as-of-now. "What was the price of Premium Speaker in October?" isn't answerable; direct the merchant to product revision history in WP Admin.
- Real-name / email filtering — intentionally off even when the PII gate is on. Ask the merchant to confirm the customer via WP Admin > WooCommerce > Customers first.
- Creating or mutating records — this is a read-only analytics tool.
- Arbitrary SQL — the filter engine is field-registry-bound. Unknown fields return an error listing what IS available.

GOOD FOLLOW-UP SUGGESTIONS (only suggest drill-downs we can deliver today with an existing tool, never an unshipped one):
- "Want to see the actual [orders / products / customers] that matched?" → recall with mode=rows
- "What's the LTV of these customers?" → route to get_customer_value if the shape shifts to cohort / LTV analysis
- "Where's the revenue coming from by channel / country / category?" → route to get_attribution / get_revenue_breakdown for group-by shapes
- "Compare this to the previous period?" → recall with different dates (this tool doesn't pre-compute comparison)
- "What products did these customers buy?" → for orders-entity results, route to get_product_performance

DO NOT SUGGEST:
- Cross-entity JOINs in one query — not supported
- Line-item-level row results
- Filter by free-text / PII fields
- SQL or tool-internal syntax
- Creating / modifying records via this tool
- Building a new Skill, feature, or endpoint — see NEVER NAME INTERNAL FILES / UNSHIPPED TOOL NAMES below

NEVER NAME INTERNAL FILES OR UNSHIPPED TOOL NAMES IN THE RESPONSE — this is an absolute rule:
- Do not reference internal planning docs, field registries, or the filter-engine implementation.
- Do not name any unshipped or planned internal tool/skill identifier. No "the planned X skill would answer this" or "when Y ships" framing.
- Do not offer to help spec future skills, suggest endpoints be built, or treat the reader as the developer of this plugin. The reader is a merchant running a shop.

HOW TO DESCRIBE GAPS WITHOUT LEAKING:
Bad: "A cross-entity skill would answer that."
Bad: "You'd need the planned line-item-level skill."
Good: "That question chains customer and product filters together, which needs two separate queries — I can pull the customer list first, then check each customer's order history. Want to start there?"

HOW TO OFFER FOLLOW-UPS — phrase drill-downs as questions, never as tool invocations. The merchant can't invoke a tool directly; they invoke it by asking a question. Offer the question; don't name the call.

Bad (names the invocation): "The next call is query_analytics with entity=customers and a country filter."
Bad (imperative tool-name): "Run query_analytics with filters=[{field:'lifetime_spend', operator:'greater_than', value:500}]."
Bad (parameter-shape framing): "Call this again with mode=rows to see the specifics."
Bad (parameter-name in backticks): "I can also filter by `lifetime_spend` or `country`."
Bad (developer-shape alias in parens): "Sort by lifetime_spend (the default orderby) to see top customers first."
Good: "Want me to pull the actual list of customers matching that filter?"
Good: "Want to see how that breaks down by country?"
Good: "I can narrow that further — lifetime value, purchase recency, or both?"

Rule: no backticks around parameter names or parameter values in the response to the merchant. Country / coupon / channel names are merchant vocabulary and are fine in plain text. Internal identifiers (`entity`, `filters`, `orderby`, `match`, `mode`) are developer vocabulary and never belong in merchant-facing output.

STORAGE VOCABULARY IS NEVER MERCHANT-FACING — the merchant's view of their payment methods, gateways, and shipping options is the display name in WP Admin > WooCommerce > Settings (e.g. "Direct Bank Transfer", "PayPal", "Credit Card (Stripe)"). They do not see underlying slugs (bacs, bank_transfer, ppec_paypal) or gateway IDs, so surfacing those can't help them troubleshoot. If your first call returns zero results and you want to offer a re-run with a different value, route them to the WP Admin display name they can actually look up.

Bad (backticked storage slugs, developer framing): "BACS is sometimes stored as `bacs`, but some stores use `bank_transfer` or a custom gateway ID — want me to try those?"
Bad (stored as + backticked slug): "Your payment method slug might be stored as `ppec_paypal` rather than `paypal`."
Bad (meta_key / table name leak): "The attribution channel is stored in `_wc_order_attribution_origin` meta — check there if this returns empty."
Good (merchant vocabulary + actionable WP Admin workflow): "The exact label depends on what's configured in WP Admin > WooCommerce > Settings > Payments — check there and tell me what name shows next to BACS, and I'll retry with that."
Good (steer to display name, not storage): "Different stores name their bank-transfer gateway differently — 'Direct Bank Transfer', 'Bank Transfer', or a custom label. Which one shows up in your checkout?"

Rule: no backticked slugs, gateway IDs, meta keys, or table names in merchant-facing text. These are storage vocabulary the merchant can't observe from their admin UI.

IMPORTANT: Only report numbers returned by this tool. Never estimate, extrapolate, or guess analytics figures. If the tool returns an error or empty data, tell the merchant you couldn't retrieve the data — do not fabricate numbers.
DESCRIPTION,
					'hey-woo'
				),
				// phpcs:enable WordPress.WP.I18n.NonSingularStringLiteralText
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => self::input_schema(),
				'output_schema'       => self::output_schema(),
				'execute_callback'    => array( __CLASS__, 'execute' ),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'show_in_rest' => true,
				),
			)
		);
	}

	/**
	 * Permission gate. Aggregated reads only — same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * JSON Schema for the ability input.
	 *
	 * @return array
	 */
	private static function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'entity'     => array(
					'type'        => 'string',
					'enum'        => array( 'orders', 'products', 'customers' ),
					'default'     => 'orders',
					'description' => 'Which entity to filter. Each has its own field registry — see the full list in the tool description.',
				),
				'filters'    => array(
					'type'        => 'array',
					'default'     => array(),
					'description' => 'Filter specs. Each is {field, operator, value}. Field must exist in the entity registry; operator must be allowed for the field type. Value shape varies by operator — scalar for is/greater_than/etc., array for is_in/between. Full validation happens inside the ability; the schema is intentionally loose so mixed value types land as-is.',
				),
				'match'      => array(
					'type'        => 'string',
					'enum'        => array( 'all', 'any' ),
					'default'     => 'all',
					'description' => 'all = AND across filters, any = OR. Default all.',
				),
				'period'     => array(
					'type'        => 'string',
					'enum'        => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ),
					'default'     => 'last_30_days',
					'description' => 'Time window. Custom date_start/date_end overrides this.',
				),
				'date_start' => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom start date (YYYY-MM-DD).',
				),
				'date_end'   => array(
					'type'        => 'string',
					'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
					'description' => 'Custom end date (YYYY-MM-DD).',
				),
				'mode'       => array(
					'type'        => 'string',
					'enum'        => array( 'aggregate', 'rows' ),
					'default'     => 'aggregate',
					'description' => 'aggregate = summary counts / sums (default); rows = top-N row list (pseudonymised identities unless the PII gate is on).',
				),
				'limit'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 25,
					'description' => 'Row cap for rows mode (ignored in aggregate mode).',
				),
				'orderby'    => array(
					'type'        => 'string',
					'description' => 'Column to sort rows by. Must be a filterable field on the chosen entity; each entity has its own default. Ignored in aggregate mode.',
				),
				'order'      => array(
					'type'        => 'string',
					'enum'        => array( 'ASC', 'DESC' ),
					'default'     => 'DESC',
					'description' => 'Sort direction for rows mode.',
				),
			),
		);
	}

	/**
	 * JSON Schema for the ability output.
	 *
	 * Response shape varies by entity + mode; the envelope is stable.
	 *
	 * @return array
	 */
	private static function output_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period'             => array( 'type' => 'object' ),
				'entity'             => array( 'type' => 'string' ),
				'mode'               => array( 'type' => 'string' ),
				'match'              => array( 'type' => 'string' ),
				'filters_applied'    => array( 'type' => 'array' ),
				'currency'           => array( 'type' => 'string' ),
				'summary'            => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
				'rows'               => array(
					'oneOf' => array(
						array( 'type' => 'array' ),
						array( 'type' => 'null' ),
					),
				),
				'universe'           => array( 'type' => 'object' ),
				'share_of_universe'  => array( 'type' => 'object' ),
				'pipeline'           => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
				'admin_equivalent'   => array(
					'oneOf' => array(
						array( 'type' => 'object' ),
						array( 'type' => 'null' ),
					),
				),
				'sample_size_caveat' => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
				'privacy_mode'       => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
				'note'               => array(
					'oneOf' => array(
						array( 'type' => 'string' ),
						array( 'type' => 'null' ),
					),
				),
			),
		);
	}

	/**
	 * Run the ability — delegates to AnalyticsController::fetch_query_analytics().
	 *
	 * @param array $input Validated ability input.
	 * @return array|\WP_Error Response payload, or WP_Error on invalid filters.
	 */
	public static function execute( $input ) {
		$input = is_array( $input ) ? $input : array();

		return AnalyticsController::fetch_query_analytics(
			$input['entity'] ?? 'orders',
			is_array( $input['filters'] ?? null ) ? $input['filters'] : array(),
			$input['match'] ?? 'all',
			$input['period'] ?? 'last_30_days',
			$input['date_start'] ?? null,
			$input['date_end'] ?? null,
			$input['mode'] ?? 'aggregate',
			(int) ( $input['limit'] ?? 25 ),
			$input['orderby'] ?? null,
			$input['order'] ?? 'DESC'
		);
	}
}
