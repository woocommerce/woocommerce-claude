<?php
/**
 * REST controller for Hey Woo DIFM (Do It For Me) endpoints.
 *
 * Routes:
 *   POST /hey-woo/v1/difm/chat        — Send a chat message; returns Claude's reply.
 *   POST /hey-woo/v1/difm/key/validate — Validate an Anthropic API key.
 *
 * @package HeyWoo\Difm
 */

namespace HeyWoo\Difm;

use HeyWoo\Abilities\GetRevenueSummaryAbility;
use HeyWoo\Abilities\GetOrdersSummaryAbility;
use HeyWoo\Abilities\GetProductPerformanceAbility;
use HeyWoo\Abilities\GetCustomerOverviewAbility;
use HeyWoo\Abilities\GetRefundAnalysisAbility;
use HeyWoo\Abilities\GetAttributionAbility;
use HeyWoo\Abilities\GetCouponPerformanceAbility;
use HeyWoo\Abilities\GetRevenueBreakdownAbility;
use HeyWoo\Abilities\GetTaxSummaryAbility;
use HeyWoo\Abilities\GetCustomerValueAbility;
use HeyWoo\Abilities\QueryAnalyticsAbility;
use HeyWoo\Abilities\GetProductDetailsAbility;
use HeyWoo\Abilities\SearchProductsAbility;
use HeyWoo\Abilities\GetStoreProfileAbility;
use HeyWoo\Abilities\GetReadinessScoreAbility;
use HeyWoo\Abilities\GetRecommendationsAbility;
use HeyWoo\Abilities\SuggestImprovementsAbility;
use HeyWoo\Abilities\ConfirmLargeRangeAbility;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the DIFM REST routes.
 *
 * PHP 7.4 compatible — no union types, no match, no enums.
 */
class DifmRestController {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'hey-woo/v1';

	/**
	 * Chat route.
	 */
	const CHAT_ROUTE = '/difm/chat';

	/**
	 * Key validation route.
	 */
	const VALIDATE_ROUTE = '/difm/key/validate';

	/**
	 * Maximum number of history turns accepted per request (user + assistant pairs).
	 */
	const MAX_HISTORY_TURNS = 20;

	/**
	 * Maximum number of Anthropic API calls per chat request (including tool-use rounds).
	 *
	 * Each tool-use round costs one API call. Five iterations allows for four rounds
	 * of tool calls followed by a final answer, which comfortably covers complex queries.
	 */
	const MAX_TOOL_ITERATIONS = 5;

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all DIFM REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::CHAT_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'send_chat_message' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'message' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'minLength'         => 1,
						),
						'history' => array(
							'type'     => 'array',
							'required' => false,
							'default'  => array(),
							'items'    => array(
								'type'       => 'object',
								'properties' => array(
									'role'    => array(
										'type' => 'string',
										'enum' => array( 'user', 'assistant' ),
									),
									'content' => array(
										'type' => 'string',
									),
								),
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::VALIDATE_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'validate_key' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'key' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check: only users able to manage the store.
	 *
	 * @return true|\WP_Error
	 */
	public function check_permission() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Permission denied.', 'hey-woo' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * POST /hey-woo/v1/difm/chat — send a message and return Claude's reply.
	 *
	 * Accepts { message, history } and calls the Anthropic API, exposing all
	 * available store analytics abilities as tools so Claude can fetch live data
	 * for any time period the merchant asks about.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function send_chat_message( \WP_REST_Request $request ) {
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		if ( ! AnthropicClient::has_api_key() ) {
			return rest_ensure_response( array( 'status' => 'no_key' ) );
		}

		$user_message = (string) $request->get_param( 'message' );
		$raw_history  = (array) $request->get_param( 'history' );

		// Build conversation history, capped to MAX_HISTORY_TURNS.
		$messages = array();
		$turns    = array_slice( $raw_history, - ( self::MAX_HISTORY_TURNS * 2 ) );
		foreach ( $turns as $turn ) {
			$role    = isset( $turn['role'] ) ? $turn['role'] : '';
			$content = isset( $turn['content'] ) ? $turn['content'] : '';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) && '' !== $content ) {
				$messages[] = array(
					'role'    => $role,
					'content' => sanitize_text_field( $content ),
				);
			}
		}

		// Append the new user message.
		$messages[] = array(
			'role'    => 'user',
			'content' => $user_message,
		);

		$client        = new AnthropicClient();
		$system_prompt = $this->build_system_prompt();
		$tools         = $this->build_tool_definitions();
		$iterations    = 0;

		while ( $iterations < self::MAX_TOOL_ITERATIONS ) {
			$result = $client->messages( $messages, $system_prompt, $tools );

			if ( is_wp_error( $result ) ) {
				return rest_ensure_response(
					array(
						'status'  => 'error',
						'message' => $result->get_error_message(),
					)
				);
			}

			$stop_reason = isset( $result['stop_reason'] ) ? $result['stop_reason'] : 'end_turn';
			$content     = isset( $result['content'] ) && is_array( $result['content'] ) ? $result['content'] : array();

			if ( 'tool_use' !== $stop_reason ) {
				// Extract the first text block and return it as the reply.
				$reply = '';
				foreach ( $content as $block ) {
					if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
						$reply = $block['text'] ?? '';
						break;
					}
				}
				return rest_ensure_response(
					array(
						'status' => 'ok',
						'reply'  => $reply,
					)
				);
			}

			// Execute all requested tool calls.
			$tool_results = array();
			foreach ( $content as $block ) {
				if ( ! isset( $block['type'] ) || 'tool_use' !== $block['type'] ) {
					continue;
				}
				$tool_output    = $this->execute_tool( $block['name'], (array) ( $block['input'] ?? array() ) );
				$tool_results[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $block['id'],
					'content'     => wp_json_encode( $tool_output ),
				);
			}

			// If there were no tool_use blocks despite stop_reason, bail out.
			if ( empty( $tool_results ) ) {
				break;
			}

			// Append the assistant turn (with tool_use blocks) and the tool results.
			$messages[] = array(
				'role'    => 'assistant',
				'content' => $content,
			);
			$messages[] = array(
				'role'    => 'user',
				'content' => $tool_results,
			);

			++$iterations;
		}

		return rest_ensure_response(
			array(
				'status'  => 'error',
				'message' => __( 'The assistant took too many steps — please try again.', 'hey-woo' ),
			)
		);
	}

	/**
	 * POST /hey-woo/v1/difm/key/validate — validate an Anthropic API key.
	 *
	 * @param \WP_REST_Request $request Incoming request (body: {"key":"sk-ant-..."}).
	 * @return \WP_REST_Response
	 */
	public function validate_key( \WP_REST_Request $request ) {
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		$key    = (string) $request->get_param( 'key' );
		$result = AnthropicClient::validate_key( $key );

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'valid'   => false,
					'message' => $result->get_error_message(),
				)
			);
		}

		return rest_ensure_response( array( 'valid' => true ) );
	}

	/**
	 * Build the system prompt for the conversational assistant.
	 *
	 * Provides store context so Claude can give relevant answers. All store
	 * data is fetched on demand via the tools defined in build_tool_definitions().
	 *
	 * @return string
	 */
	private function build_system_prompt() {
		$store_name = get_bloginfo( 'name' );
		$store_url  = get_bloginfo( 'url' );
		$currency   = get_woocommerce_currency();
		$date       = gmdate( 'l, j F Y' );

		return sprintf(
			'You are an AI assistant for the WooCommerce store "%1$s" (%2$s). '
			. 'Today is %3$s. The store uses %4$s as its currency. '
			. 'Use the available tools to fetch live store data — always call the relevant '
			. 'tool before answering data questions rather than guessing. '
			. 'Be concise, direct, and focused on actionable insights. '
			. 'Do not suggest building new features, plugins, or API endpoints — the merchant cannot action that. '
			. 'Never expose internal field names (e.g. metrics.net_sales) in your responses — use plain English only. '
			. 'If a tool returns an extended_range_required error, call confirm_large_range first, '
			. 'then retry the original tool with the confirmation_token.',
			esc_html( $store_name ),
			esc_url( $store_url ),
			esc_html( $date ),
			esc_html( $currency )
		);
	}

	/**
	 * Execute a single tool call by dispatching to the matching ability.
	 *
	 * Returns an array — either the ability result or {'error': '...'} on failure
	 * so Claude can handle tool errors gracefully.
	 *
	 * @param string $name  Tool name as sent by Claude (snake_case).
	 * @param array  $input Tool input parameters from Claude.
	 * @return array
	 */
	private function execute_tool( $name, array $input ) {
		$map = array(
			'get_revenue_summary'     => array( GetRevenueSummaryAbility::class, 'execute' ),
			'get_orders_summary'      => array( GetOrdersSummaryAbility::class, 'execute' ),
			'get_refund_analysis'     => array( GetRefundAnalysisAbility::class, 'execute' ),
			'get_customer_overview'   => array( GetCustomerOverviewAbility::class, 'execute' ),
			'get_product_performance' => array( GetProductPerformanceAbility::class, 'execute' ),
			'get_attribution'         => array( GetAttributionAbility::class, 'execute' ),
			'get_coupon_performance'  => array( GetCouponPerformanceAbility::class, 'execute' ),
			'get_revenue_breakdown'   => array( GetRevenueBreakdownAbility::class, 'execute' ),
			'get_tax_summary'         => array( GetTaxSummaryAbility::class, 'execute' ),
			'get_customer_value'      => array( GetCustomerValueAbility::class, 'execute' ),
			'query_analytics'         => array( QueryAnalyticsAbility::class, 'execute' ),
			'get_product_details'     => array( GetProductDetailsAbility::class, 'execute' ),
			'search_products'         => array( SearchProductsAbility::class, 'execute' ),
			'get_store_profile'       => array( GetStoreProfileAbility::class, 'execute' ),
			'get_readiness_score'     => array( GetReadinessScoreAbility::class, 'execute' ),
			'get_recommendations'     => array( GetRecommendationsAbility::class, 'execute' ),
			'suggest_improvements'    => array( SuggestImprovementsAbility::class, 'execute' ),
			'confirm_large_range'     => array( ConfirmLargeRangeAbility::class, 'execute' ),
		);

		if ( ! isset( $map[ $name ] ) ) {
			return array( 'error' => "Unknown tool: {$name}" );
		}

		try {
			$result = call_user_func( $map[ $name ], $input );
			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}
			return is_array( $result ) ? $result : array( 'result' => $result );
		} catch ( \Throwable $e ) {
			return array( 'error' => 'Tool execution failed.' );
		}
	}

	/**
	 * Build the Anthropic tool definitions for all exposed store abilities.
	 *
	 * @return array Anthropic-format tool definition array.
	 */
	private function build_tool_definitions() {
		$period_prop = array(
			'type'        => 'string',
			'enum'        => array( 'today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'last_month', 'this_quarter', 'this_year' ),
			'default'     => 'last_30_days',
			'description' => 'Time window for the report.',
		);

		$date_start_prop = array(
			'type'        => 'string',
			'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
			'description' => 'Custom start date (YYYY-MM-DD). Overrides period.',
		);

		$date_end_prop = array(
			'type'        => 'string',
			'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
			'description' => 'Custom end date (YYYY-MM-DD). Overrides period.',
		);

		$compare_prop = array(
			'type'        => 'boolean',
			'default'     => true,
			'description' => 'Include comparison to previous period.',
		);

		$limit_prop = array(
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => 50,
			'default'     => 10,
			'description' => 'Maximum number of rows to return.',
		);

		$standard_props = array(
			'period'     => $period_prop,
			'date_start' => $date_start_prop,
			'date_end'   => $date_end_prop,
			'compare'    => $compare_prop,
		);

		return array(

			array(
				'name'         => 'get_revenue_summary',
				'description'  => 'Get revenue summary for a time period — net sales, gross sales, orders, AOV, refunds, taxes, and shipping.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => $standard_props,
				),
			),

			array(
				'name'         => 'get_orders_summary',
				'description'  => 'Get orders summary — order count, AOV, status breakdown, value distribution, and day/hour heatmap.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => $standard_props,
				),
			),

			array(
				'name'         => 'get_refund_analysis',
				'description'  => 'Get refund metrics — total refunded, refund rate, timing buckets, and optional breakdown by product or country.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$standard_props,
						array(
							'group_by' => array(
								'type'        => 'string',
								'enum'        => array( 'none', 'product', 'country' ),
								'default'     => 'none',
								'description' => 'Optional drill-in dimension.',
							),
							'limit'    => $limit_prop,
						)
					),
				),
			),

			array(
				'name'         => 'get_customer_overview',
				'description'  => 'Get customer overview — new vs returning counts, repeat rate, revenue per segment, and optional time series.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$standard_props,
						array(
							'interval'           => array(
								'type'        => 'string',
								'enum'        => array( '', 'auto', 'day', 'week', 'month' ),
								'default'     => '',
								'description' => 'Time-series granularity. Leave empty for totals only.',
							),
							'confirmation_token' => array(
								'type'        => 'string',
								'description' => 'Token from a prior extended_range_required error.',
							),
						)
					),
				),
			),

			array(
				'name'         => 'get_product_performance',
				'description'  => 'Get top-selling products with revenue, quantity, orders, refunds, and stock status.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$standard_props,
						array(
							'limit'              => $limit_prop,
							'orderby'            => array(
								'type'        => 'string',
								'enum'        => array( 'net_revenue', 'gross_revenue', 'quantity', 'orders_count' ),
								'default'     => 'net_revenue',
								'description' => 'Sort column.',
							),
							'group_by'           => array(
								'type'        => 'string',
								'enum'        => array( 'product', 'variation' ),
								'default'     => 'product',
								'description' => 'Group by parent product or individual variation.',
							),
							'interval'           => array(
								'type'        => 'string',
								'enum'        => array( '', 'auto', 'day', 'week', 'month' ),
								'default'     => '',
								'description' => 'Time-series granularity. Leave empty for totals only.',
							),
							'confirmation_token' => array(
								'type'        => 'string',
								'description' => 'Token from a prior extended_range_required error.',
							),
						)
					),
				),
			),

			array(
				'name'         => 'get_attribution',
				'description'  => 'Get order attribution — which channels, sources, campaigns, and devices drove revenue.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$standard_props,
						array(
							'group_by'           => array(
								'type'        => 'string',
								'enum'        => array( 'channel', 'source', 'medium', 'campaign', 'term', 'content', 'device', 'channel_source' ),
								'default'     => 'channel',
								'description' => 'Attribution dimension.',
							),
							'limit'              => $limit_prop,
							'orderby'            => array(
								'type'        => 'string',
								'enum'        => array( 'net_revenue', 'orders_count', 'avg_order_value' ),
								'default'     => 'net_revenue',
								'description' => 'Sort column.',
							),
							'include_unassigned' => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => 'Include unassigned/direct row.',
							),
						)
					),
				),
			),

			array(
				'name'         => 'get_coupon_performance',
				'description'  => 'Get per-coupon performance — usage count, discount given, revenue driven, and refund rate per coupon.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$standard_props,
						array(
							'limit'   => $limit_prop,
							'orderby' => array(
								'type'        => 'string',
								'enum'        => array( 'discount_amount', 'net_revenue', 'orders_count', 'new_customers_count' ),
								'default'     => 'discount_amount',
								'description' => 'Sort column.',
							),
						)
					),
				),
			),

			array(
				'name'         => 'get_revenue_breakdown',
				'description'  => 'Get revenue broken down by category, billing country, payment method, or shipping method.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$standard_props,
						array(
							'group_by'           => array(
								'type'        => 'string',
								'enum'        => array( 'category', 'country', 'payment_method', 'shipping_method' ),
								'default'     => 'category',
								'description' => 'Revenue breakdown dimension.',
							),
							'limit'              => $limit_prop,
							'orderby'            => array(
								'type'        => 'string',
								'enum'        => array( 'net_revenue', 'orders_count', 'avg_order_value' ),
								'default'     => 'net_revenue',
								'description' => 'Sort column.',
							),
							'include_unassigned' => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => 'Include unassigned row.',
							),
						)
					),
				),
			),

			array(
				'name'         => 'get_tax_summary',
				'description'  => 'Get tax collected — total tax, order vs shipping tax, refunded tax, net tax, and per-rate breakdown.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$standard_props,
						array(
							'limit'   => $limit_prop,
							'orderby' => array(
								'type'        => 'string',
								'enum'        => array( 'total_tax', 'order_tax', 'shipping_tax', 'orders_count' ),
								'default'     => 'total_tax',
								'description' => 'Sort column.',
							),
						)
					),
				),
			),

			array(
				'name'         => 'get_customer_value',
				'description'  => 'Get lifetime customer value — LTV stats, one-time vs repeat segmentation, top customers, and cohort retention.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$standard_props,
						array(
							'limit'           => $limit_prop,
							'include_cohorts' => array(
								'type'        => 'boolean',
								'default'     => true,
								'description' => 'Include cohort retention matrix.',
							),
						)
					),
				),
			),

			array(
				'name'         => 'query_analytics',
				'description'  => 'Flexible filter engine across orders, products, or customers. Use for specific lookups such as high-value orders, low-stock products, or customers who bought a specific product.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'entity'     => array(
							'type'        => 'string',
							'enum'        => array( 'orders', 'products', 'customers' ),
							'default'     => 'orders',
							'description' => 'Which entity to filter.',
						),
						'filters'    => array(
							'type'        => 'array',
							'default'     => array(),
							'description' => 'Filter specs — each item is {field, operator, value}.',
						),
						'match'      => array(
							'type'        => 'string',
							'enum'        => array( 'all', 'any' ),
							'default'     => 'all',
							'description' => 'AND (all) or OR (any) across filters.',
						),
						'period'     => $period_prop,
						'date_start' => $date_start_prop,
						'date_end'   => $date_end_prop,
						'mode'       => array(
							'type'        => 'string',
							'enum'        => array( 'aggregate', 'rows' ),
							'default'     => 'aggregate',
							'description' => 'aggregate returns summary stats; rows returns individual records.',
						),
						'limit'      => $limit_prop,
						'orderby'    => array(
							'type'        => 'string',
							'description' => 'Column to sort by.',
						),
						'order'      => array(
							'type'        => 'string',
							'enum'        => array( 'ASC', 'DESC' ),
							'default'     => 'DESC',
							'description' => 'Sort direction.',
						),
					),
				),
			),

			array(
				'name'         => 'get_product_details',
				'description'  => 'Get full details for a specific product — description, attributes, images, relationships, and completeness score.',
				'input_schema' => array(
					'type'       => 'object',
					'required'   => array( 'product_id' ),
					'properties' => array(
						'product_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'WooCommerce product ID.',
						),
					),
				),
			),

			array(
				'name'         => 'search_products',
				'description'  => 'Search the product catalogue by name or description. Use this to find a product ID before calling get_product_details.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'query'    => array(
							'type'        => 'string',
							'description' => 'Search terms (name or description).',
						),
						'category' => array(
							'type'        => 'string',
							'description' => 'Filter by category slug.',
						),
						'page'     => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'default'     => 1,
							'description' => 'Page number.',
						),
						'per_page' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 100,
							'default'     => 20,
							'description' => 'Results per page.',
						),
					),
				),
			),

			array(
				'name'         => 'get_store_profile',
				'description'  => 'Get the store\'s identity, configuration, payment methods, shipping zones, and enabled features.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),

			array(
				'name'         => 'get_readiness_score',
				'description'  => 'Get the store\'s AI readiness score (0–100) with a breakdown by factor: product completeness, schema coverage, policy completeness, and content quality.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),

			array(
				'name'         => 'get_recommendations',
				'description'  => 'Get prioritised recommendations for improving the store\'s AI readiness, each with a priority rating and description.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(),
				),
			),

			array(
				'name'         => 'suggest_improvements',
				'description'  => 'Suggest specific improvements for a product or the whole store to increase AI readiness.',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Optional — limit suggestions to a specific product.',
						),
						'focus'      => array(
							'type'        => 'string',
							'enum'        => array( 'description', 'images', 'seo', 'attributes', 'all' ),
							'default'     => 'all',
							'description' => 'Area to focus improvements on.',
						),
					),
				),
			),

			array(
				'name'         => 'confirm_large_range',
				'description'  => 'Approve a pending large date-range query. Call this after an analytics tool returns an extended_range_required error, then retry the original tool with the returned confirmation_token.',
				'input_schema' => array(
					'type'       => 'object',
					'required'   => array( 'date_start', 'date_end', 'type', 'description' ),
					'properties' => array(
						'date_start'  => array(
							'type'        => 'string',
							'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
							'description' => 'Start date of the range to confirm (YYYY-MM-DD).',
						),
						'date_end'    => array(
							'type'        => 'string',
							'pattern'     => '^\\d{4}-\\d{2}-\\d{2}$',
							'description' => 'End date of the range to confirm (YYYY-MM-DD).',
						),
						'type'        => array(
							'type'        => 'string',
							'enum'        => array( 'revenue_summary', 'orders_summary', 'product_performance', 'customer_overview', 'attribution', 'customer_value', 'revenue_breakdown', 'coupon_performance', 'refund_analysis', 'tax_summary', 'query_analytics' ),
							'description' => 'The analytics type being approved.',
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Human-readable summary of the query being approved.',
						),
					),
				),
			),

		);
	}
}
