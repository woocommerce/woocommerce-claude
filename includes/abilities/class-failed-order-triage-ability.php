<?php
/**
 * `wc-prompts/failed-order-triage` ability - exposed as an MCP prompt via the
 * WooCommerce for Claude MCP server's component registry.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the failed-order-triage prompt ability.
 */
class FailedOrderTriageAbility {

	const ABILITY_NAME = 'wc-prompts/failed-order-triage';

	/**
	 * Register the ability with the WordPress Abilities API.
	 */
	public static function register() {
		wp_register_ability(
			self::ABILITY_NAME,
			array(
				'label'               => __( 'Failed order triage', 'woocommerce-claude' ),
				'description'         => __( 'Triage failed and on-hold WooCommerce orders, payment pipeline, checkout risk, and next actions.', 'woocommerce-claude' ),
				'category'            => AbilitiesBootstrap::CATEGORY,
				'input_schema'        => self::input_schema(),
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
	 * Permission gate - same capability as WC Admin.
	 *
	 * @param array $input Ability input (unused).
	 * @return bool
	 */
	public static function permission_check( $input ) {
		unset( $input );
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * JSON Schema for the prompt input.
	 *
	 * @return array
	 */
	private static function input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'period' => array(
					'type'        => 'string',
					'default'     => 'last_30_days',
					'description' => 'Analytics period to triage. Use last_30_days by default unless the merchant explicitly asks for a different range.',
					'enum'        => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ),
				),
			),
		);
	}

	/**
	 * Assemble the MCP prompt message list.
	 *
	 * @param array $input Validated prompt arguments.
	 * @return array `{ messages: [...] }` shaped for MCP prompts/get.
	 */
	public static function execute( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$period = isset( $input['period'] ) && is_string( $input['period'] ) ? sanitize_key( $input['period'] ) : 'last_30_days';

		$text = <<<PROMPT
You are a WooCommerce store operations analyst. Triage failed and on-hold orders for the merchant.

Use period "{$period}" unless the merchant explicitly asked for exact dates. Use the headline order overview with comparison enabled only to get the comparison dates plus status/pipeline diagnostics; do not use its paid-order or paid-revenue comparison as a denominator in the final answer.

Use the tools quietly. If you need a progress sentence before the final answer, say only: "I'll check the last 30 days and separate on-hold payment pipeline from failed checkout risk." Do not tell the merchant you are loading schemas, loading tools, selecting tools, calling tools, using a connector, or constructing filters.

Required data:
1. Read store profile once for store name, currency, locale, and payment setup context.
2. Call wc-analytics-totals with subject=orders and compare=true. Read status_breakdown and the pipeline diagnostic. Treat paid-order counts as background only.
3. Call wc-analytics-rows with entity=orders, mode=aggregate, and a status filter for on-hold.
4. Call wc-analytics-rows with entity=orders, mode=aggregate, and a status filter for failed.
5. If the order overview returns a comparison range, call wc-analytics-rows twice more using the exact comparison dates: once with the on-hold status filter and once with the failed status filter. Use these only for current-vs-prior count/value wording; do not compute percentage changes, rates, or shares unless a tool returned them.
6. If on-hold orders exist, call wc-analytics-rows with entity=orders, mode=rows, status=on-hold, limit=10, orderby=date_created, order=ASC.
7. If failed orders exist, call wc-analytics-rows with entity=orders, mode=rows, status=failed, limit=10, orderby=date_created, order=DESC.
8. If on-hold value is material, a card gateway appears in the on-hold payment-method diagnostic, or the merchant asks where stuck orders came from, call wc-analytics-breakdown with subject=attribution, dimension=channel, limit=6, include_unassigned=true, compare=true.

Rules:
- Treat on-hold orders as payment pipeline, not failed revenue. Manual methods such as bank transfer, BACS, cheque, and invoice can legitimately sit on-hold while payment clears.
- Treat card-gateway on-hold orders as abnormal because card gateways should usually complete or fail at checkout.
- Treat failed orders as checkout risk, not confirmed lost revenue.
- Use returned comparison fields, age buckets, payment-method diagnostics, and pipeline over-index fields directly. For status-specific on-hold and failed comparisons, read the current and prior aggregate calls side by side and state count/value movement plainly. Do not hand-calculate percentage changes, rates, shares, averages, or deltas.
- When comparing current and prior status buckets, use neutral wording such as "larger", "smaller", or "similar" and quote both values. Avoid magnitude labels such as "slight", "modest", "meaningfully", "roughly flat", "broadly flat", "sharp", or "surging" unless a returned comparison field supplies that judgement.
- Do not turn failed/on-hold counts or values into a share of paid orders or paid revenue by combining separate calls. Say "43 failed orders versus 39 last period" rather than "the failed rate fell to 10%" unless that rate is returned directly.
- Do not compare failed/on-hold movement against paid-order growth. Avoid claims like "failed orders are a smaller share of the order book", "the failed-order share improved", "10% of paid orders versus 14.7%", or "paid orders grew sharply, so the issue is less severe". The triage is about the failed/on-hold buckets themselves.
- Do not compute differences such as "up $24.7k", "+4 orders", or "average basket is 33 items" from separate returned fields. Say "28 orders / $77,990 versus 22 orders / $53,300 last period" instead of doing the arithmetic in prose. Only quote averages that are explicitly returned as average fields.
- Do not sum or calculate shares from the Priority Queue rows. Avoid claims like "these four orders account for $17,200" or "22% of stuck value sits in four rows" unless the tool returned that exact aggregate. The queue is for action ordering, not extra arithmetic.
- Do not sum paid revenue, on-hold value, failed order value, and dashboard-matching figures.
- Keep customer details pseudonymised. Order refs and admin links are OK; customer names, emails, phone numbers, and addresses are not.
- If a finding rests on 5 or fewer orders, state the count before interpreting it.
- Failed order rows do not expose payment method by default. Do not claim a gateway caused failed orders unless the data shows it or the merchant supplied a gateway-specific filter.
- Do not call failed orders "routine", "normal", or "expected checkout attrition" unless a returned comparison or merchant-supplied baseline supports that wording. Prefer "no single cause is visible in the available data" when the signal is thin.
- Treat store-profile payment methods as setup context only. An empty configured-methods list does not prove no payment methods are configured and does not explain unassigned order payment methods by itself. Phrase it as "the profile did not expose configured payment methods, so confirm settings manually" rather than a cause.
- Treat analytics "unassigned payment method" as a visibility gap until the merchant checks actual order pages. Do not tell the merchant to "fix payment-method labelling", "ensure each order has a payment method recorded", or "label it correctly" as a conclusion. Say "verify the order page, then troubleshoot the gateway/manual method if it is blank there too."
- If every on-hold payment method is blank, do not treat "no card gateway is visible" as reassuring. Say the gateway/manual-method split cannot be read until at least one payment method is visible on the actual order page or in gateway logs.
- Do not assume a standard clearing window for manual payments. Phrase stale on-hold orders as "worth checking against your payment terms" rather than "past the point a bank transfer would normally clear" unless the merchant supplied that policy.
- Do not mention tool names, ability names, parameter names, database tables, status slugs, or internal field paths in the final answer.

Output exactly these sections:

### Failed and On-Hold Order Triage

**Store:** [store name]

**Period:** [date range]

**Compared with:** [comparison range, or "Not compared" if unavailable]

#### 1. Snapshot

Two or three sentences covering on-hold orders, failed orders, and whether those buckets are growing, shrinking, or flat. You may mention paid orders only as neutral background, never as a denominator, share, or reason to downgrade the issue.

#### 2. Severity

Low, Medium, or High with one sentence explaining why, based on counts, value, movement, stale age buckets, and card-gateway on-hold signals.

#### 3. On-Hold Pipeline

Summarise count/value, oldest age, age buckets, and payment-method signals. Separate expected manual-payment clearing from abnormal gateway behaviour.

#### 4. Failed Orders

Summarise failed order count/value and whether it changed from the comparison period. Keep cause language cautious unless data supports it.

#### 5. Priority Queue

List up to five orders to chase first. If both on-hold and failed rows exist, list up to four oldest on-hold rows first, preserving the row order returned by the oldest-on-hold rows call, then one most recent failed row. If fewer than four on-hold rows exist, fill the remaining slots with the most recent failed rows. Do not skip an older on-hold row to include a second failed row, and do not reshuffle same-day on-hold rows by value or item count. Use order links, dates, statuses, and values. Do not call failed rows "highest-value" unless you explicitly sorted them by value. Do not include customer names or addresses. Do not add a calculated total or percentage beneath the queue. The specific orders named later in Next Actions must come from this queue, unless you explicitly say you are expanding beyond the queue.

#### 6. Likely Checks

List two or three checks grounded in the data: payment gateway logs, bank-transfer instructions, webhook health, fraud rules, stock holds, abandoned customer follow-up, or channel-specific tracking.

#### 7. Next Actions

Give three concrete merchant-actionable steps. Do not replace this section with a follow-up question.
PROMPT;

		return array(
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}
}
