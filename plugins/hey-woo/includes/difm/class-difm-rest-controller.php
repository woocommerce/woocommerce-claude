<?php
/**
 * REST controller for Hey Woo endpoints.
 *
 * Routes:
 *   POST /hey-woo/v1/difm/chat — Send a chat message; returns the AI reply.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

use WooCommerce\CommerceAbilities\Abilities\ConfirmLargeRangeAbility;
use WooCommerce\HeyWoo\Telemetry\TelemetryHandler;

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
	 * Maximum number of history turns accepted per request (user + assistant pairs).
	 */
	const MAX_HISTORY_TURNS = 20;

	/**
	 * Pseudo-tool name for chart rendering declarations.
	 *
	 * Not in TOOL_ABILITY_MAP — captured client-side rather than executed.
	 */
	const RENDER_CHART_TOOL = 'render_chart';

	/**
	 * Maximum number of AI provider calls per chat request (including tool-use rounds).
	 *
	 * Each tool-use round costs one API call. Five iterations allows for four rounds
	 * of tool calls followed by a final answer, which comfortably covers complex queries.
	 */
	const MAX_TOOL_ITERATIONS = 5;

	/**
	 * Transient prefix for pending large-range confirmations.
	 */
	const PENDING_LARGE_RANGE_PREFIX = 'hey_woo_difm_large_range_';

	/**
	 * Workflow slug active for the current request.
	 *
	 * @var string
	 */
	private $active_workflow_slug = '';

	/**
	 * Provider-visible tool names mapped to registered WordPress abilities.
	 *
	 * DIFM exposes the verb-shaped analytics surface rather than the legacy
	 * one-ability-per-report tools. The four analytics tools carry the richer
	 * subject/dimension/series/rows descriptions from ability metadata.
	 *
	 * The confirm-large-range ability is intentionally absent. Large-range
	 * approval is a controller-level merchant consent flow, not a model tool.
	 */
	private const TOOL_ABILITY_MAP = array(
		'analytics_totals'     => 'wc-analytics/totals',
		'analytics_breakdown'  => 'wc-analytics/breakdown',
		'analytics_series'     => 'wc-analytics/series',
		'analytics_rows'       => 'wc-analytics/rows',
		'get_product_details'  => 'hey-woo/get-product-details',
		'search_products'      => 'hey-woo/search-products',
		'get_store_profile'    => 'hey-woo/get-store-profile',
		'get_readiness_score'  => 'hey-woo/get-readiness-score',
		'get_recommendations'  => 'hey-woo/get-recommendations',
		'suggest_improvements' => 'hey-woo/suggest-improvements',
	);

	/**
	 * Return the DIFM tool allowlist.
	 *
	 * Tests use this to detect drift between the allowlist and registered abilities.
	 *
	 * @return array
	 */
	public static function get_tool_ability_map() {
		return self::TOOL_ABILITY_MAP;
	}

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
	 * POST /hey-woo/v1/difm/chat — send a message and return the AI reply.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function send_chat_message( \WP_REST_Request $request ) {
		$resolver = new DifmProviderResolver();
		$client   = $resolver->resolve_client();
		if ( is_wp_error( $client ) ) {
			if ( in_array( $client->get_error_code(), array( 'no_ai_provider', 'no_api_key', 'wordpress_ai_unavailable' ), true ) ) {
				return rest_ensure_response( array( 'status' => 'no_key' ) );
			}

			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $client->get_error_message(),
				)
			);
		}

		if ( ! $client instanceof DifmAiClientInterface ) {
			return rest_ensure_response( array( 'status' => 'no_key' ) );
		}

		$user_message = (string) $request->get_param( 'message' );
		$raw_history  = (array) $request->get_param( 'history' );

		$pending_large_range = $this->get_pending_large_range_request();
		if ( is_array( $pending_large_range ) ) {
			if ( $this->is_large_range_refusal( $user_message ) ) {
				$this->clear_pending_large_range_request();
				return rest_ensure_response(
					array(
						'status' => 'ok',
						'reply'  => __( 'No problem — I will not load the larger range. Ask me again with a shorter date range whenever you are ready.', 'hey-woo' ),
					)
				);
			}

			if ( $this->is_large_range_affirmation( $user_message ) ) {
				$pending_workflow           = $this->workflow_from_pending_large_range( $pending_large_range );
				$this->active_workflow_slug = is_array( $pending_workflow ) ? (string) $pending_workflow['slug'] : '';
				$this->log_workflow_selected( $pending_workflow );
				$system_prompt = $this->build_system_prompt( $pending_workflow );
				return $this->answer_confirmed_large_range_request( $client, $system_prompt, $raw_history, $user_message, $pending_large_range );
			}

			return rest_ensure_response(
				array(
					'status' => 'ok',
					'reply'  => __( 'I still need an explicit confirmation before loading the larger range. Reply with "yes, proceed" to continue, or ask for a shorter date range.', 'hey-woo' ),
				)
			);
		}

		$workflow                   = WorkflowSkills::select_for_message( $user_message );
		$this->active_workflow_slug = is_array( $workflow ) ? (string) $workflow['slug'] : '';
		$this->log_workflow_selected( $workflow );

		if ( $this->should_use_precomputed_weekly_report( $workflow ) ) {
			return $this->answer_precomputed_weekly_report( $client, $user_message );
		}

		if ( $this->should_use_precomputed_acquisition_report( $workflow ) ) {
			return $this->answer_precomputed_acquisition_report( $client, $user_message );
		}

		$system_prompt = $this->build_system_prompt( $workflow );
		$messages      = $this->build_conversation_messages( $raw_history, $user_message );
		$tools         = $this->build_tool_definitions();

		if ( is_wp_error( $tools ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $tools->get_error_message(),
				)
			);
		}

		return $this->answer_with_tools( $client, $system_prompt, $messages, $tools, '', $this->merchant_requested_chart( $user_message ) );
	}

	/**
	 * Whether a selected workflow should use server-computed report inputs.
	 *
	 * @param array<string,string>|null $workflow Selected workflow, if any.
	 * @return bool
	 */
	private function should_use_precomputed_weekly_report( $workflow ) {
		return is_array( $workflow )
			&& isset( $workflow['slug'] )
			&& 'weekly-store-review' === (string) $workflow['slug'];
	}

	/**
	 * Whether a selected workflow should use server-computed acquisition inputs.
	 *
	 * @param array<string,string>|null $workflow Selected workflow, if any.
	 * @return bool
	 */
	private function should_use_precomputed_acquisition_report( $workflow ) {
		return is_array( $workflow )
			&& isset( $workflow['slug'] )
			&& 'customer-acquisition-review' === (string) $workflow['slug'];
	}

	/**
	 * Compose the weekly report from deterministic server-side aggregates.
	 *
	 * The model writes the merchant-facing narrative, but the numbers and
	 * visual candidates come from Hey Woo's own ability calls.
	 *
	 * @param DifmAiClientInterface $client       AI provider client.
	 * @param string                $user_message Merchant request.
	 * @return \WP_REST_Response
	 */
	private function answer_precomputed_weekly_report( DifmAiClientInterface $client, $user_message ) {
		$options       = $this->parse_weekly_report_options( $user_message );
		$source        = $this->build_weekly_report_source( $options );
		$user_prompt   = $this->build_weekly_report_user_prompt( $source, $options );
		$system_prompt = $this->build_weekly_report_system_prompt( ! empty( $options['include_actions'] ) );

		$result = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			$system_prompt,
			array(),
			3500,
			array(
				'surface'   => 'difm_precomputed_weekly_report',
				'iteration' => 1,
			)
		);

		if ( is_wp_error( $result ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $result->get_error_message(),
				)
			);
		}

		$content = isset( $result['content'] ) && is_array( $result['content'] ) ? $result['content'] : array();
		$reply   = $this->extract_text_reply( $content );
		$payload = $this->parse_weekly_report_payload( $reply );

		if ( ! is_array( $payload ) ) {
			$retry_result = $client->messages(
				array(
					array(
						'role'    => 'user',
						'content' => $user_prompt,
					),
					array(
						'role'    => 'assistant',
						'content' => $reply,
					),
					array(
						'role'    => 'user',
						'content' => 'Internal correction: your previous response was not valid report JSON. Return ONLY the JSON object matching the required shape. No intro sentence, no markdown, no fenced code block, no apology.',
					),
				),
				$system_prompt,
				array(),
				3500,
				array(
					'surface'   => 'difm_precomputed_weekly_report',
					'iteration' => 2,
				)
			);

			if ( is_wp_error( $retry_result ) ) {
				return rest_ensure_response(
					array(
						'status'  => 'error',
						'message' => $retry_result->get_error_message(),
					)
				);
			}

			$retry_content = isset( $retry_result['content'] ) && is_array( $retry_result['content'] ) ? $retry_result['content'] : array();
			$payload       = $this->parse_weekly_report_payload( $this->extract_text_reply( $retry_content ) );
		}

		if ( ! is_array( $payload ) ) {
			$payload = $this->build_weekly_report_fallback_payload(
				$source,
				$options,
				! empty( $options['include_actions'] )
			);
		}

		return rest_ensure_response( $this->build_chat_response( $this->build_weekly_report_reply( $payload ) ) );
	}

	/**
	 * Compose the acquisition report from deterministic server-side aggregates.
	 *
	 * @param DifmAiClientInterface $client       AI provider client.
	 * @param string                $user_message Merchant request.
	 * @return \WP_REST_Response
	 */
	private function answer_precomputed_acquisition_report( DifmAiClientInterface $client, $user_message ) {
		$options       = $this->parse_acquisition_report_options( $user_message );
		$source        = $this->build_acquisition_report_source( $options );
		$user_prompt   = $this->build_acquisition_report_user_prompt( $source, $options );
		$system_prompt = $this->build_acquisition_report_system_prompt( ! empty( $options['include_actions'] ) );

		$result = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => $user_prompt,
				),
			),
			$system_prompt,
			array(),
			3400,
			array(
				'surface'   => 'difm_precomputed_acquisition_report',
				'iteration' => 1,
			)
		);

		if ( is_wp_error( $result ) ) {
			$payload = $this->build_acquisition_report_fallback_payload(
				$source,
				$options,
				! empty( $options['include_actions'] )
			);

			return rest_ensure_response( $this->build_chat_response( $this->build_weekly_report_reply( $payload ) ) );
		}

		$content = isset( $result['content'] ) && is_array( $result['content'] ) ? $result['content'] : array();
		$reply   = $this->extract_text_reply( $content );
		$payload = $this->parse_weekly_report_payload( $reply );

		if ( ! is_array( $payload ) ) {
			$retry_result = $client->messages(
				array(
					array(
						'role'    => 'user',
						'content' => $user_prompt,
					),
					array(
						'role'    => 'assistant',
						'content' => $reply,
					),
					array(
						'role'    => 'user',
						'content' => 'Internal correction: your previous response was not valid report JSON. Return ONLY the JSON object matching the required shape. No intro sentence, no markdown, no fenced code block, no apology.',
					),
				),
				$system_prompt,
				array(),
				3400,
				array(
					'surface'   => 'difm_precomputed_acquisition_report',
					'iteration' => 2,
				)
			);

			if ( is_wp_error( $retry_result ) ) {
				$payload = null;
			} else {
				$retry_content = isset( $retry_result['content'] ) && is_array( $retry_result['content'] ) ? $retry_result['content'] : array();
				$payload       = $this->parse_weekly_report_payload( $this->extract_text_reply( $retry_content ) );
			}
		}

		if ( ! is_array( $payload ) ) {
			$payload = $this->build_acquisition_report_fallback_payload(
				$source,
				$options,
				! empty( $options['include_actions'] )
			);
		}

		return rest_ensure_response( $this->build_chat_response( $this->build_weekly_report_reply( $payload ) ) );
	}

	/**
	 * Parse the model's report JSON into a structured payload.
	 *
	 * @param string $reply Raw model text.
	 * @return array<string,mixed>|null
	 */
	private function parse_weekly_report_payload( $reply ) {
		$reply = trim( (string) $reply );
		$reply = preg_replace( '/^```(?:json|hey-woo-report)?\s*/i', '', $reply );
		$reply = preg_replace( '/```\s*$/', '', (string) $reply );
		$reply = trim( (string) $reply );

		$start = strpos( $reply, '{' );
		$end   = strrpos( $reply, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$reply = substr( $reply, $start, $end - $start + 1 );
		}

		$decoded = json_decode( $reply, true );
		if ( ! is_array( $decoded ) || empty( $decoded['title'] ) ) {
			return null;
		}

		foreach ( array( 'metric_tiles', 'insights', 'charts', 'tables', 'caveats', 'sources', 'actions' ) as $key ) {
			if ( ! isset( $decoded[ $key ] ) || ! is_array( $decoded[ $key ] ) ) {
				$decoded[ $key ] = array();
			}
		}

		return $decoded;
	}

	/**
	 * Wrap a parsed report payload in the UI's structured-report block.
	 *
	 * @param array<string,mixed> $payload Parsed report payload.
	 * @return string
	 */
	private function build_weekly_report_reply( array $payload ) {
		$json = wp_json_encode( $payload );
		$json = is_string( $json ) ? $json : '{}';

		return "```hey-woo-report\n" . $json . "\n```";
	}

	/**
	 * Build a deterministic briefing when the model fails the JSON contract.
	 *
	 * @param array<string,mixed> $source          Precomputed source packet.
	 * @param array<string,mixed> $options         Report options.
	 * @param bool                $include_actions Whether to include action cards.
	 * @return array<string,mixed>
	 */
	private function build_weekly_report_fallback_payload( array $source, array $options, $include_actions ) {
		$currency = $this->weekly_report_value( $source, array( 'totals_revenue', 'currency' ), get_woocommerce_currency() );
		$period   = $this->weekly_report_value( $source, array( 'totals_revenue', 'period', 'label' ), 'Selected period' );

		$net_sales        = (float) $this->weekly_report_value( $source, array( 'totals_revenue', 'metrics', 'net_sales' ), 0 );
		$orders_count     = (int) $this->weekly_report_value( $source, array( 'totals_orders', 'metrics', 'orders_count' ), 0 );
		$aov              = (float) $this->weekly_report_value( $source, array( 'totals_orders', 'metrics', 'avg_order_value' ), 0 );
		$total_customers  = (int) $this->weekly_report_value( $source, array( 'totals_customers', 'metrics', 'total_customers' ), 0 );
		$refund_count     = (int) $this->weekly_report_value( $source, array( 'totals_refunds', 'metrics', 'refunds_count' ), 0 );
		$refund_rate      = (float) $this->weekly_report_value( $source, array( 'totals_refunds', 'metrics', 'refund_rate_percent' ), 0 );
		$revenue_change   = $this->weekly_report_value( $source, array( 'totals_revenue', 'comparison', 'changes', 'net_sales' ), null );
		$orders_change    = $this->weekly_report_value( $source, array( 'totals_orders', 'comparison', 'changes', 'orders_count' ), null );
		$aov_change       = $this->weekly_report_value( $source, array( 'totals_orders', 'comparison', 'changes', 'avg_order_value' ), null );
		$customers_change = $this->weekly_report_value( $source, array( 'totals_customers', 'comparison', 'changes', 'total_customers' ), null );
		$refunds_change   = $this->weekly_report_value( $source, array( 'totals_refunds', 'comparison', 'changes', 'refund_rate_percent' ), null );
		$product_rows     = $this->weekly_report_product_rows( $source, $currency );
		$channel_rows     = $this->weekly_report_channel_rows( $source, $currency );
		$top_product      = $this->weekly_report_value( $source, array( 'products', 'top_products', 0 ), array() );
		$top_channel      = $this->weekly_report_value( $source, array( 'attribution_channels', 'top_groups', 0 ), array() );

		$insights = array(
			array(
				'title'    => $this->weekly_report_trading_headline( $revenue_change ),
				'summary'  => sprintf(
					'Net sales were %1$s across %2$s paid orders. AOV was %3$s, with %4$s customers active in the period.',
					$this->format_weekly_report_currency( $net_sales, $currency ),
					number_format_i18n( $orders_count ),
					$this->format_weekly_report_currency( $aov, $currency ),
					number_format_i18n( $total_customers )
				),
				'category' => 'Trading',
				'status'   => $this->weekly_report_status_label( $this->weekly_report_change_tone( $revenue_change ) ),
				'metric'   => $this->weekly_report_change_text( $revenue_change ),
				'tone'     => $this->weekly_report_change_tone( $revenue_change ),
			),
		);

		if ( is_array( $top_product ) && ! empty( $top_product['product_name'] ) ) {
			$product_change = isset( $top_product['change'] ) && is_array( $top_product['change'] ) ? $top_product['change'] : null;
			$insights[]     = array(
				'title'    => sprintf( '%s is the product to understand first', (string) $top_product['product_name'] ),
				'summary'  => sprintf(
					'It contributed %1$s from %2$s units. Treat that as evidence for the week, then check whether the movement is broad demand or one product carrying the read.',
					$this->format_weekly_report_currency( $this->weekly_report_numeric( $top_product, 'net_revenue' ), $currency ),
					number_format_i18n( (int) $this->weekly_report_numeric( $top_product, 'quantity' ) )
				),
				'category' => 'Products',
				'status'   => $this->weekly_report_status_label( $this->weekly_report_change_tone( $product_change ) ),
				'metric'   => $this->weekly_report_change_text( $product_change ),
				'tone'     => $this->weekly_report_change_tone( $product_change ),
			);
		}

		if ( is_array( $top_channel ) && ! empty( $top_channel['label'] ) ) {
			$share      = $this->weekly_report_numeric( $top_channel, 'share_of_revenue_percent' );
			$share_text = $share > 0 ? ' at ' . $this->format_weekly_report_percent( $share ) . ' of paid revenue' : '';
			$insights[] = array(
				'title'    => sprintf( '%s is the channel mix anchor', (string) $top_channel['label'] ),
				'summary'  => sprintf(
					'The channel accounted for %1$s%2$s across %3$s orders. If that share is unusually high, the next useful step is channel-specific evidence rather than another store-wide summary.',
					$this->format_weekly_report_currency( $this->weekly_report_numeric( $top_channel, 'net_revenue' ), $currency ),
					$share_text,
					number_format_i18n( (int) $this->weekly_report_numeric( $top_channel, 'orders_count' ) )
				),
				'category' => 'Channels',
				'status'   => $share >= 50 ? 'Heads-up' : 'Worth knowing',
				'metric'   => $share > 0 ? $this->format_weekly_report_percent( $share ) : '',
				'tone'     => $share >= 50 ? 'warning' : 'neutral',
			);
		}

		$insights[] = array(
			'title'    => $refund_count > 0 ? 'Refunds need a quick quality read' : 'Refunds were quiet',
			'summary'  => $refund_count > 0
				? sprintf( '%1$s refunds put refund rate at %2$s. Check whether those refunds cluster around the same product, promise, or fulfilment step before acting.', number_format_i18n( $refund_count ), $this->format_weekly_report_percent( $refund_rate ) )
				: 'No refund volume stood out in the precomputed totals for this period.',
			'category' => 'Refunds',
			'status'   => $refund_count > 0 ? 'Heads-up' : 'Looking good',
			'metric'   => $refund_count > 0 ? $this->format_weekly_report_percent( $refund_rate ) : '',
			'tone'     => $refund_count > 0 ? 'warning' : 'positive',
		);

		$actions = $include_actions ? $this->build_weekly_report_fallback_actions( $insights ) : array();

		return array(
			'title'        => 'Weekly store review',
			'subtitle'     => (string) $period,
			'summary'      => 'Here is the aggregate read for the selected period. This version stays conservative and only uses verified store totals, product movement, channel mix, and refunds.',
			'metric_tiles' => array(
				$this->weekly_report_metric_tile( 'Revenue', $this->format_weekly_report_currency( $net_sales, $currency ), $revenue_change, 'Paid net sales', false ),
				$this->weekly_report_metric_tile( 'Orders', number_format_i18n( $orders_count ), $orders_change, 'Paid orders', false ),
				$this->weekly_report_metric_tile( 'AOV', $this->format_weekly_report_currency( $aov, $currency ), $aov_change, 'Average order value', false ),
				$this->weekly_report_metric_tile( 'Customers', number_format_i18n( $total_customers ), $customers_change, 'Active buyers', false ),
				$this->weekly_report_metric_tile( 'Refunds', $refund_count > 0 ? $this->format_weekly_report_percent( $refund_rate ) : '0', $refunds_change, number_format_i18n( $refund_count ) . ' refunds', true ),
			),
			'insights'     => array_slice( $insights, 0, 5 ),
			'charts'       => $this->weekly_report_channel_chart( $source ),
			'tables'       => array_values(
				array_filter(
					array(
						! empty( $product_rows ) ? array(
							'title'   => 'Product evidence',
							'columns' => array( 'Product', 'Revenue', 'Units', 'Movement' ),
							'rows'    => $product_rows,
							'note'    => 'Top products are supporting evidence, not the whole report.',
						) : null,
						! empty( $channel_rows ) ? array(
							'title'   => 'Channel evidence',
							'columns' => array( 'Channel', 'Revenue', 'Orders', 'Share' ),
							'rows'    => $channel_rows,
							'note'    => 'Channel labels come from available order attribution.',
						) : null,
					)
				)
			),
			'caveats'      => array(
				array(
					'title'  => 'Conservative read',
					'detail' => 'This briefing sticks to verified aggregate surfaces and avoids unsupported causes when the generated document needs rebuilding.',
					'tone'   => 'warning',
				),
			),
			'sources'      => array(
				array(
					'label'  => 'Revenue totals',
					'detail' => 'Paid net sales, orders, AOV, customers, and comparison deltas.',
				),
				array(
					'label'  => 'Product breakdown',
					'detail' => 'Top product revenue and units.',
				),
				array(
					'label'  => 'Channel breakdown',
					'detail' => 'Order attribution grouped by channel where available.',
				),
			),
			'actions'      => $actions,
		);
	}

	/**
	 * Read a nested value from an array.
	 *
	 * @param array<string,mixed> $data    Source array.
	 * @param array<int,mixed>    $path    Path parts.
	 * @param mixed               $fallback Fallback value.
	 * @return mixed
	 */
	private function weekly_report_value( array $data, array $path, $fallback = null ) {
		$current = $data;
		foreach ( $path as $part ) {
			if ( is_array( $current ) && array_key_exists( $part, $current ) ) {
				$current = $current[ $part ];
				continue;
			}

			return $fallback;
		}

		return $current;
	}

	/**
	 * Numeric array field helper.
	 *
	 * @param array<string,mixed> $data Source data.
	 * @param string              $key  Field key.
	 * @return float
	 */
	private function weekly_report_numeric( array $data, $key ) {
		return isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? (float) $data[ $key ] : 0.0;
	}

	/**
	 * Build one metrics-tape tile.
	 *
	 * @param string            $label   Tile label.
	 * @param string            $value   Display value.
	 * @param array<mixed>|null $change  Comparison change.
	 * @param string            $caption Tile caption.
	 * @param bool              $invert  Whether lower is better.
	 * @return array<string,string>
	 */
	private function weekly_report_metric_tile( $label, $value, $change, $caption, $invert ) {
		return array(
			'label'   => $label,
			'value'   => $value,
			'trend'   => $this->weekly_report_change_text( $change ),
			'caption' => $caption,
			'tone'    => $this->weekly_report_change_tone( $change, $invert ),
		);
	}

	/**
	 * Format a currency value for the briefing.
	 *
	 * @param float|int $amount   Amount.
	 * @param string    $currency Currency code.
	 * @return string
	 */
	private function format_weekly_report_currency( $amount, $currency ) {
		$symbol   = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' ) : '';
		$symbol   = '' !== $symbol ? $symbol : ( $currency ? $currency . ' ' : '' );
		$decimals = abs( (float) $amount ) < 100 ? 2 : 0;

		return $symbol . number_format_i18n( (float) $amount, $decimals );
	}

	/**
	 * Format a percent value.
	 *
	 * @param float|int $value Value.
	 * @return string
	 */
	private function format_weekly_report_percent( $value ) {
		return number_format_i18n( (float) $value, 1 ) . '%';
	}

	/**
	 * Format a comparison change.
	 *
	 * @param array<mixed>|null $change Change payload.
	 * @return string
	 */
	private function weekly_report_change_text( $change ) {
		if ( ! is_array( $change ) || ! isset( $change['percent'] ) || ! is_numeric( $change['percent'] ) ) {
			return '';
		}

		$percent = (float) $change['percent'];
		if ( abs( $percent ) < 0.05 ) {
			return 'flat';
		}

		return ( $percent > 0 ? '+' : '' ) . number_format_i18n( $percent, 1 ) . '%';
	}

	/**
	 * Infer report tone from a comparison change.
	 *
	 * @param array<mixed>|null $change Change payload.
	 * @param bool              $invert Whether lower is better.
	 * @return string
	 */
	private function weekly_report_change_tone( $change, $invert = false ) {
		if ( ! is_array( $change ) || empty( $change['direction'] ) || 'flat' === $change['direction'] ) {
			return 'neutral';
		}

		$is_good = $invert ? 'down' === $change['direction'] : 'up' === $change['direction'];

		return $is_good ? 'positive' : 'negative';
	}

	/**
	 * Convert a tone to the report status label.
	 *
	 * @param string $tone Tone.
	 * @return string
	 */
	private function weekly_report_status_label( $tone ) {
		if ( 'positive' === $tone ) {
			return 'Looking good';
		}

		if ( 'negative' === $tone ) {
			return 'Concern';
		}

		return 'Worth knowing';
	}

	/**
	 * Trading headline from revenue movement.
	 *
	 * @param array<mixed>|null $change Revenue comparison.
	 * @return string
	 */
	private function weekly_report_trading_headline( $change ) {
		$tone = $this->weekly_report_change_tone( $change );
		if ( 'positive' === $tone ) {
			return 'Trading improved versus the comparison period';
		}

		if ( 'negative' === $tone ) {
			return 'Trading softened versus the comparison period';
		}

		return 'Trading was broadly steady';
	}

	/**
	 * Build compact product evidence rows.
	 *
	 * @param array<string,mixed> $source   Source packet.
	 * @param string              $currency Currency code.
	 * @return array<int,array<int,string>>
	 */
	private function weekly_report_product_rows( array $source, $currency ) {
		$products = $this->weekly_report_value( $source, array( 'products', 'top_products' ), array() );
		if ( ! is_array( $products ) ) {
			return array();
		}

		$rows = array();
		foreach ( array_slice( $products, 0, 5 ) as $product ) {
			if ( ! is_array( $product ) || empty( $product['product_name'] ) ) {
				continue;
			}

			$change = isset( $product['change'] ) && is_array( $product['change'] ) ? $product['change'] : null;
			$rows[] = array(
				(string) $product['product_name'],
				$this->format_weekly_report_currency( $this->weekly_report_numeric( $product, 'net_revenue' ), $currency ),
				number_format_i18n( (int) $this->weekly_report_numeric( $product, 'quantity' ) ),
				$this->weekly_report_change_text( $change ),
			);
		}

		return $rows;
	}

	/**
	 * Build compact channel evidence rows.
	 *
	 * @param array<string,mixed> $source   Source packet.
	 * @param string              $currency Currency code.
	 * @return array<int,array<int,string>>
	 */
	private function weekly_report_channel_rows( array $source, $currency ) {
		$channels = $this->weekly_report_value( $source, array( 'attribution_channels', 'top_groups' ), array() );
		if ( ! is_array( $channels ) ) {
			return array();
		}

		$rows = array();
		foreach ( array_slice( $channels, 0, 5 ) as $channel ) {
			if ( ! is_array( $channel ) || empty( $channel['label'] ) ) {
				continue;
			}

			$rows[] = array(
				(string) $channel['label'],
				$this->format_weekly_report_currency( $this->weekly_report_numeric( $channel, 'net_revenue' ), $currency ),
				number_format_i18n( (int) $this->weekly_report_numeric( $channel, 'orders_count' ) ),
				$this->format_weekly_report_percent( $this->weekly_report_numeric( $channel, 'share_of_revenue_percent' ) ),
			);
		}

		return $rows;
	}

	/**
	 * Build an optional channel mix chart.
	 *
	 * @param array<string,mixed> $source Source packet.
	 * @return array<int,array<string,mixed>>
	 */
	private function weekly_report_channel_chart( array $source ) {
		$channels = $this->weekly_report_value( $source, array( 'attribution_channels', 'top_groups' ), array() );
		if ( ! is_array( $channels ) || count( $channels ) < 2 ) {
			return array();
		}

		$points = array();
		foreach ( array_slice( $channels, 0, 5 ) as $channel ) {
			if ( ! is_array( $channel ) || empty( $channel['label'] ) ) {
				continue;
			}

			$points[] = array(
				'x' => (string) $channel['label'],
				'y' => $this->weekly_report_numeric( $channel, 'net_revenue' ),
			);
		}

		if ( count( $points ) < 2 ) {
			return array();
		}

		return array(
			array(
				'type'    => 'bar',
				'title'   => 'Channel mix',
				'x_label' => 'Channel',
				'y_label' => 'Revenue',
				'series'  => array(
					array(
						'name' => 'Revenue',
						'data' => $points,
					),
				),
			),
		);
	}

	/**
	 * Build deterministic action cards from fallback insights.
	 *
	 * @param array<int,array<string,mixed>> $insights Report insights.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_weekly_report_fallback_actions( array $insights ) {
		$actions = array();
		foreach ( array_slice( $insights, 0, 3 ) as $insight ) {
			$title    = isset( $insight['title'] ) ? (string) $insight['title'] : 'Investigate the signal';
			$summary  = isset( $insight['summary'] ) ? (string) $insight['summary'] : '';
			$metric   = isset( $insight['metric'] ) ? (string) $insight['metric'] : '';
			$priority = isset( $insight['tone'] ) && 'negative' === $insight['tone'] ? 'high' : 'medium';

			$actions[] = array(
				'title'            => $title,
				'priority'         => $priority,
				'summary'          => $summary,
				'key_metric'       => $metric,
				'impact'           => isset( $insight['category'] ) ? (string) $insight['category'] : 'Store performance',
				'evidence'         => $summary,
				'next_steps'       => array(
					'Open the named product, channel, or refund slice in WooCommerce.',
					'Check whether the movement is broad enough to act on or just a small sample.',
					'Make one bounded change and compare the same metric next period.',
				),
				'expected_outcome' => 'A clearer driver and one measurable follow-up for the next review.',
			);
		}

		return $actions;
	}

	/**
	 * Parse report setup choices from the generated workflow prompt.
	 *
	 * @param string $message Merchant request.
	 * @return array<string,mixed>
	 */
	private function parse_weekly_report_options( $message ) {
		$message = (string) $message;
		$period  = 'last_7_days';

		if ( false !== stripos( $message, 'Period: Last 30 days' ) ) {
			$period = 'last_30_days';
		} elseif ( false !== stripos( $message, 'Period: Month to date' ) ) {
			$period = 'month_to_date';
		} elseif ( false !== stripos( $message, 'Period: Quarter to date' ) ) {
			$period = 'quarter_to_date';
		}

		$compare         = false === stripos( $message, 'Do not include a comparison period' );
		$include_actions = false === stripos( $message, 'Keep actions empty' )
			&& false === stripos( $message, 'do not suggest separate action cards' );
		$schedule        = '';

		if ( preg_match( '/Schedule preference:\s*([^\n.]+(?:\.[^\n.]*)?)/i', $message, $matches ) ) {
			$schedule = trim( (string) $matches[1] );
		}

		return array(
			'period'          => $period,
			'compare'         => $compare,
			'include_actions' => $include_actions,
			'schedule'        => $schedule,
		);
	}

	/**
	 * Build the deterministic source packet for the weekly report composer.
	 *
	 * @param array<string,mixed> $options Report options.
	 * @return array<string,mixed>
	 */
	private function build_weekly_report_source( array $options ) {
		$period  = isset( $options['period'] ) ? (string) $options['period'] : 'last_7_days';
		$compare = ! empty( $options['compare'] );

		$period_input = array(
			'period'  => $period,
			'compare' => $compare,
		);

		return array(
			'options'              => $options,
			'store'                => $this->normalise_precomputed_tool_output( $this->execute_tool( 'get_store_profile', array() ) ),
			'totals_revenue'       => $this->normalise_precomputed_tool_output(
				$this->execute_tool(
					'analytics_totals',
					array_merge( $period_input, array( 'subject' => 'revenue' ) )
				)
			),
			'totals_orders'        => $this->normalise_precomputed_tool_output(
				$this->execute_tool(
					'analytics_totals',
					array_merge( $period_input, array( 'subject' => 'orders' ) )
				)
			),
			'totals_customers'     => $this->normalise_precomputed_tool_output(
				$this->execute_tool(
					'analytics_totals',
					array_merge( $period_input, array( 'subject' => 'customers' ) )
				)
			),
			'totals_refunds'       => $this->normalise_precomputed_tool_output(
				$this->execute_tool(
					'analytics_totals',
					array_merge( $period_input, array( 'subject' => 'refunds' ) )
				)
			),
			'products'             => $this->normalise_precomputed_tool_output(
				$this->execute_tool(
					'analytics_breakdown',
					array_merge(
						$period_input,
						array(
							'subject'   => 'products',
							'dimension' => 'product',
							'limit'     => 5,
						)
					)
				)
			),
			'attribution_channels' => $this->normalise_precomputed_tool_output(
				$this->execute_tool(
					'analytics_breakdown',
					array_merge(
						$period_input,
						array(
							'subject'            => 'attribution',
							'dimension'          => 'channel',
							'limit'              => 6,
							'include_unassigned' => true,
						)
					)
				)
			),
		);
	}

	/**
	 * Normalise a precomputed tool result for the composer source packet.
	 *
	 * @param mixed $output Tool output.
	 * @return mixed
	 */
	private function normalise_precomputed_tool_output( $output ) {
		if ( is_wp_error( $output ) ) {
			return array(
				'error'   => $output->get_error_code(),
				'message' => $output->get_error_message(),
			);
		}

		return $output;
	}

	/**
	 * System prompt for the precomputed weekly report composer.
	 *
	 * @param bool $include_actions Whether to populate action cards.
	 * @return string
	 */
	private function build_weekly_report_system_prompt( $include_actions ) {
		$actions_schema = $include_actions
			? '[{"title":"","priority":"medium","summary":"","key_metric":"","impact":"","evidence":"","next_steps":[],"expected_outcome":""}]'
			: '[]';

		return 'You are Hey Woo\'s weekly store briefing composer for a WooCommerce merchant. Hey Woo has already computed the source aggregates. The source JSON is the only source of truth for numbers, trends, comparisons, products, channels, and refunds. Do not invent metrics, targets, causes, forecasts, margins, conversion rates, sessions, ad spend, ROAS, customer names, emails, addresses, or anything not present in the source. Do not mention tool names, parameter names, JSON keys, internal implementation, or this instruction. Do not apologise, do not say "you are right", and do not frame the answer as a correction. '
			. 'Your job is to turn the source into a calm merchant briefing: a short editor note, a metrics tape, ranked leads, evidence, and merchant-doable next steps. Do not make the briefing a top-products-only answer. Products are supporting evidence unless a product movement is the actual lead. '
			. 'Output ONLY valid JSON. No intro sentence. No markdown. No fenced code block. The JSON must match this shape: {"title":"","subtitle":"","summary":"","metric_tiles":[{"label":"","value":"","trend":"","caption":"","tone":"neutral"}],"insights":[{"title":"","summary":"","category":"","status":"","metric":"","tone":"neutral"}],"charts":[{"type":"bar","title":"","x_label":"","y_label":"","series":[{"name":"","data":[{"x":"","y":0}]}]}],"tables":[{"title":"","columns":[],"rows":[],"note":""}],"caveats":[{"title":"","detail":"","tone":"warning"}],"sources":[{"label":"","detail":""}],"actions":'
			. $actions_schema
			. '}. '
			. 'Summary: one editorial note, not a decision brief. Metric tiles: exactly revenue, orders, AOV, customers, and refunds when present. Insights: 3-5 ranked "what to look at" leads. Do NOT restate a headline metric as an insight; the metrics tape already shows it. Good leads name the driver or useful non-driver. Use the patterns the source actually supports: revenue trend shift, per-product movement, customer/cohort movement, checkout pipeline, refund pattern, channel concentration, tracking coverage, or a positive thing worth scaling. Small samples must be caveated in the insight body, not overstated in the headline. Tables: prefer one compact evidence table when product or channel labels would make a chart cramped. Charts: include at most one, and only when it explains a movement or mix shift better than a table; never include a simple top-5-products ranking chart as the only visual. Actions: if actions are enabled, provide exactly three specific merchant-doable actions tied to specific insights with evidence and concrete next steps; never use vague actions like "review the report". Sources: name the aggregate surfaces used in merchant terms, such as "Revenue totals" or "Product breakdown".';
	}

	/**
	 * User prompt for the precomputed weekly report composer.
	 *
	 * @param array<string,mixed> $source  Precomputed report source.
	 * @param array<string,mixed> $options Report options.
	 * @return string
	 */
	private function build_weekly_report_user_prompt( array $source, array $options ) {
		$source_json = wp_json_encode( $source, JSON_PRETTY_PRINT );
		$source_json = is_string( $source_json ) ? $source_json : '{}';

		$lines = array(
			'Compose the weekly store review from this server-computed source packet.',
			'Period option: ' . ( isset( $options['period'] ) ? (string) $options['period'] : 'last_7_days' ) . '.',
			! empty( $options['compare'] ) ? 'Comparison: use the previous matching period values already present in the source.' : 'Comparison: do not describe previous-period movement.',
		);

		if ( ! empty( $options['schedule'] ) ) {
			$lines[] = 'Schedule setup note: ' . (string) $options['schedule'] . '. Mention it briefly in the intro only; do not claim an automatic schedule has been saved.';
		}

		$lines[] = 'Source JSON:';
		$lines[] = $source_json;
		$lines[] = 'Return ONLY the JSON object. No surrounding text, no markdown, no fenced code block.';

		return implode( "\n\n", $lines );
	}

	/**
	 * Parse customer-acquisition report setup choices.
	 *
	 * @param string $message Merchant request.
	 * @return array<string,mixed>
	 */
	private function parse_acquisition_report_options( $message ) {
		$message = (string) $message;
		$period  = 'last_30_days';

		if ( false !== stripos( $message, 'Period: Last 7 days' ) ) {
			$period = 'last_7_days';
		} elseif ( false !== stripos( $message, 'Period: Month to date' ) ) {
			$period = 'month_to_date';
		} elseif ( false !== stripos( $message, 'Period: Quarter to date' ) ) {
			$period = 'quarter_to_date';
		}

		$compare         = false === stripos( $message, 'Do not include a comparison period' );
		$include_actions = false === stripos( $message, 'Keep actions empty' )
			&& false === stripos( $message, 'do not suggest separate action cards' );
		$schedule        = '';

		if ( preg_match( '/Schedule preference:\s*([^\n.]+(?:\.[^\n.]*)?)/i', $message, $matches ) ) {
			$schedule = trim( (string) $matches[1] );
		}

		return array(
			'period'          => $period,
			'compare'         => $compare,
			'include_actions' => $include_actions,
			'schedule'        => $schedule,
		);
	}

	/**
	 * Build the deterministic source packet for the acquisition report composer.
	 *
	 * @param array<string,mixed> $options Report options.
	 * @return array<string,mixed>
	 */
	private function build_acquisition_report_source( array $options ) {
		$period  = isset( $options['period'] ) ? (string) $options['period'] : 'last_30_days';
		$compare = ! empty( $options['compare'] );

		$period_input = array(
			'period'  => $period,
			'compare' => $compare,
		);

		$customer_value = $this->normalise_precomputed_tool_output(
			$this->execute_tool(
				'analytics_totals',
				array_merge( $period_input, array( 'subject' => 'customer_value' ) )
			)
		);

		if ( is_array( $customer_value ) ) {
			unset( $customer_value['top_customers'] );
		}

		return array(
			'options'              => $options,
			'store'                => $this->normalise_precomputed_tool_output( $this->execute_tool( 'get_store_profile', array() ) ),
			'customer_mix'         => $this->normalise_precomputed_tool_output(
				$this->execute_tool(
					'analytics_totals',
					array_merge( $period_input, array( 'subject' => 'customers' ) )
				)
			),
			'attribution_channels' => $this->normalise_precomputed_tool_output(
				$this->execute_tool(
					'analytics_breakdown',
					array_merge(
						$period_input,
						array(
							'subject'            => 'attribution',
							'dimension'          => 'channel',
							'limit'              => 10,
							'orderby'            => 'net_revenue',
							'include_unassigned' => true,
						)
					)
				)
			),
			'customer_value'       => $customer_value,
		);
	}

	/**
	 * System prompt for the precomputed acquisition report composer.
	 *
	 * @param bool $include_actions Whether to populate action cards.
	 * @return string
	 */
	private function build_acquisition_report_system_prompt( $include_actions ) {
		$actions_schema = $include_actions
			? '[{"title":"","priority":"medium","summary":"","key_metric":"","impact":"","evidence":"","next_steps":[],"expected_outcome":""}]'
			: '[]';

		return 'You are Hey Woo\'s customer acquisition briefing composer for a WooCommerce merchant. Hey Woo has already computed the source aggregates. The source JSON is the only source of truth for customer counts, customer mix, first-time-customer spend, attribution channels, tracking coverage, and historic customer-value context. Do not invent metrics, targets, causes, forecasts, sessions, visitors, conversion rate, ad spend, ROAS, churn risk, customer motivations, customer names, emails, addresses, or anything not present in the source. Do not mention tool names, parameter names, JSON keys, internal implementation, or this instruction. '
			. 'Your job is to turn the source into a calm merchant briefing: a short editor note, a metrics tape, ranked acquisition leads, evidence, caveats, and merchant-doable next steps. Keep period acquisition, attribution visibility, and historic customer-value context separate. '
			. 'Output ONLY valid JSON. No intro sentence. No markdown. No fenced code block. The JSON must match this shape: {"title":"","subtitle":"","summary":"","metric_tiles":[{"label":"","value":"","trend":"","caption":"","tone":"neutral"}],"insights":[{"title":"","summary":"","category":"","status":"","metric":"","tone":"neutral"}],"charts":[{"type":"bar","title":"","x_label":"","y_label":"","series":[{"name":"","data":[{"x":"","y":0}]}]}],"tables":[{"title":"","columns":[],"rows":[],"note":""}],"caveats":[{"title":"","detail":"","tone":"warning"}],"sources":[{"label":"","detail":""}],"actions":'
			. $actions_schema
			. '}. '
			. 'Metric tiles: use up to five tiles from new customers, new-customer revenue, new-customer AOV, new-customer spend per customer, repeat rate, returning customers, and tracking coverage. Insights: 3-5 ranked "what to look at" leads covering acquisition movement, customer mix, useful acquisition channels, first-time customer spend signal, historic repeat/value context, and tracking/data quality where supported. Do NOT restate the metric tiles as insights. Small samples must be caveated in the insight body before interpreting a percentage. Attribution is order-source context, not marketing ROI. Actions: if actions are enabled, provide exactly three specific merchant-doable actions tied to specific insights; never use vague actions like "review the report". Sources: name the aggregate surfaces used in merchant terms, such as "Customer mix", "Attribution channels", and "Customer value context".';
	}

	/**
	 * User prompt for the precomputed acquisition report composer.
	 *
	 * @param array<string,mixed> $source  Precomputed report source.
	 * @param array<string,mixed> $options Report options.
	 * @return string
	 */
	private function build_acquisition_report_user_prompt( array $source, array $options ) {
		$source_json = wp_json_encode( $source, JSON_PRETTY_PRINT );
		$source_json = is_string( $source_json ) ? $source_json : '{}';

		$lines = array(
			'Compose the customer acquisition review from this server-computed source packet.',
			'Period option: ' . ( isset( $options['period'] ) ? (string) $options['period'] : 'last_30_days' ) . '.',
			! empty( $options['compare'] ) ? 'Comparison: use the previous matching period values already present in the source.' : 'Comparison: do not describe previous-period movement.',
		);

		if ( ! empty( $options['schedule'] ) ) {
			$lines[] = 'Schedule setup note: ' . (string) $options['schedule'] . '. Mention it briefly in the intro only; do not claim an automatic schedule has been saved.';
		}

		$lines[] = 'Source JSON:';
		$lines[] = $source_json;
		$lines[] = 'Return ONLY the JSON object. No surrounding text, no markdown, no fenced code block.';

		return implode( "\n\n", $lines );
	}

	/**
	 * Build a deterministic acquisition briefing when the model cannot.
	 *
	 * @param array<string,mixed> $source          Precomputed source packet.
	 * @param array<string,mixed> $options         Report options.
	 * @param bool                $include_actions Whether to include action cards.
	 * @return array<string,mixed>
	 */
	private function build_acquisition_report_fallback_payload( array $source, array $options, $include_actions ) {
		$currency = $this->weekly_report_value( $source, array( 'customer_mix', 'currency' ), get_woocommerce_currency() );
		$period   = $this->weekly_report_value( $source, array( 'customer_mix', 'period', 'label' ), 'Selected period' );

		$total_customers     = (int) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'total_customers' ), 0 );
		$new_customers       = (int) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'new_customers' ), 0 );
		$returning_customers = (int) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'returning_customers' ), 0 );
		$overlap_customers   = (int) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'overlap_customers' ), 0 );
		$new_percent         = (float) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'new_customer_percent' ), 0 );
		$repeat_rate         = (float) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'repeat_rate_percent' ), 0 );
		$new_revenue         = (float) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'new_customer_net_sales' ), 0 );
		$returning_revenue   = (float) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'returning_customer_net_sales' ), 0 );
		$new_aov             = (float) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'new_customer_avg_order_value' ), 0 );
		$returning_aov       = (float) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'returning_customer_avg_order_value' ), 0 );
		$new_spend           = (float) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'new_customer_spend_per_customer' ), 0 );
		$returning_spend     = (float) $this->weekly_report_value( $source, array( 'customer_mix', 'metrics', 'returning_customer_spend_per_customer' ), 0 );
		$coverage            = (float) $this->weekly_report_value( $source, array( 'attribution_channels', 'totals', 'attribution_coverage_percent' ), 0 );
		$active_customers    = (int) $this->weekly_report_value( $source, array( 'customer_value', 'metrics', 'active_customers' ), 0 );
		$avg_lifetime_spend  = (float) $this->weekly_report_value( $source, array( 'customer_value', 'metrics', 'avg_lifetime_spend' ), 0 );
		$median_lifetime     = (float) $this->weekly_report_value( $source, array( 'customer_value', 'metrics', 'median_lifetime_spend' ), 0 );
		$repeat_share        = (float) $this->weekly_report_value( $source, array( 'customer_value', 'segments', 'repeat', 'share_percent' ), 0 );
		$top_channel         = $this->weekly_report_value( $source, array( 'attribution_channels', 'top_groups', 0 ), array() );
		$new_change          = $this->weekly_report_value( $source, array( 'customer_mix', 'comparison', 'changes', 'new_customers' ), null );
		$new_revenue_change  = $this->weekly_report_value( $source, array( 'customer_mix', 'comparison', 'changes', 'new_customer_net_sales' ), null );
		$new_aov_change      = $this->weekly_report_value( $source, array( 'customer_mix', 'comparison', 'changes', 'new_customer_avg_order_value' ), null );
		$repeat_change       = $this->weekly_report_value( $source, array( 'customer_mix', 'comparison', 'changes', 'repeat_rate_percent' ), null );

		$insights = array(
			array(
				'title'    => $this->acquisition_report_trend_headline( $new_change ),
				'summary'  => sprintf(
					'%1$s new customers generated %2$s in paid revenue. New-customer AOV was %3$s, and spend per new customer was %4$s.',
					number_format_i18n( $new_customers ),
					$this->format_weekly_report_currency( $new_revenue, $currency ),
					$this->format_weekly_report_currency( $new_aov, $currency ),
					$this->format_weekly_report_currency( $new_spend, $currency )
				),
				'category' => 'Acquisition',
				'status'   => $this->weekly_report_status_label( $this->weekly_report_change_tone( $new_change ) ),
				'metric'   => $this->weekly_report_change_text( $new_change ),
				'tone'     => $this->weekly_report_change_tone( $new_change ),
			),
			array(
				'title'    => 'Customer mix is the frame for the read',
				'summary'  => sprintf(
					'The period had %1$s total customers: %2$s new and %3$s returning. New customers were %4$s of the customer base, and repeat rate was %5$s.%6$s',
					number_format_i18n( $total_customers ),
					number_format_i18n( $new_customers ),
					number_format_i18n( $returning_customers ),
					$this->format_weekly_report_percent( $new_percent ),
					$this->format_weekly_report_percent( $repeat_rate ),
					$overlap_customers > 0 ? ' ' . number_format_i18n( $overlap_customers ) . ' customers appear in both new and returning buckets because WooCommerce flags orders at order creation.' : ''
				),
				'category' => 'Customer mix',
				'status'   => 'Worth knowing',
				'metric'   => $this->format_weekly_report_percent( $new_percent ),
				'tone'     => 'neutral',
			),
		);

		if ( is_array( $top_channel ) && ! empty( $top_channel['label'] ) ) {
			$channel_new       = (int) $this->weekly_report_numeric( $top_channel, 'new_customers' );
			$channel_orders    = (int) $this->weekly_report_numeric( $top_channel, 'orders_count' );
			$channel_revenue   = $this->weekly_report_numeric( $top_channel, 'net_revenue' );
			$channel_share     = $this->weekly_report_numeric( $top_channel, 'share_of_revenue_percent' );
			$channel_change    = isset( $top_channel['change'] ) && is_array( $top_channel['change'] ) ? $top_channel['change'] : null;
			$small_sample_note = ( $channel_new > 0 && $channel_new <= 5 ) || $channel_orders <= 5
				? ' The count is small, so treat the percentage as a signal to inspect rather than a conclusion.'
				: '';

			$insights[] = array(
				'title'    => sprintf( '%s is the clearest channel signal', (string) $top_channel['label'] ),
				'summary'  => sprintf(
					'It brought %1$s new customers, %2$s orders, and %3$s in paid revenue. It represented %4$s of paid revenue in the available attribution view.%5$s',
					number_format_i18n( $channel_new ),
					number_format_i18n( $channel_orders ),
					$this->format_weekly_report_currency( $channel_revenue, $currency ),
					$this->format_weekly_report_percent( $channel_share ),
					$small_sample_note
				),
				'category' => 'Channels',
				'status'   => $this->weekly_report_status_label( $this->weekly_report_change_tone( $channel_change ) ),
				'metric'   => $this->weekly_report_change_text( $channel_change ),
				'tone'     => $this->weekly_report_change_tone( $channel_change ),
			);
		}

		$insights[] = array(
			'title'    => 'First-time customer spend is the quality check',
			'summary'  => sprintf(
				'New-customer spend per customer was %1$s versus %2$s for returning customers. New-customer AOV was %3$s versus %4$s for returning customers; keep this as a period signal, not a lifetime forecast.',
				$this->format_weekly_report_currency( $new_spend, $currency ),
				$this->format_weekly_report_currency( $returning_spend, $currency ),
				$this->format_weekly_report_currency( $new_aov, $currency ),
				$this->format_weekly_report_currency( $returning_aov, $currency )
			),
			'category' => 'First-time quality',
			'status'   => 'Worth knowing',
			'metric'   => $this->format_weekly_report_currency( $new_spend, $currency ),
			'tone'     => 'neutral',
		);

		if ( $active_customers > 0 ) {
			$insights[] = array(
				'title'    => 'Historic value context gives the follow-up lens',
				'summary'  => sprintf(
					'Customers active in this period averaged %1$s lifetime spend, with a median of %2$s. The repeat lifetime segment represented %3$s of active customers.',
					$this->format_weekly_report_currency( $avg_lifetime_spend, $currency ),
					$this->format_weekly_report_currency( $median_lifetime, $currency ),
					$this->format_weekly_report_percent( $repeat_share )
				),
				'category' => 'Customer value',
				'status'   => 'Context',
				'metric'   => $this->format_weekly_report_currency( $median_lifetime, $currency ),
				'tone'     => 'neutral',
			);
		}

		$caveats = array();
		if ( $coverage > 0 && $coverage < 80 ) {
			$caveats[] = array(
				'title'  => 'Partial channel coverage',
				'detail' => sprintf( 'Attribution coverage was %s, so channel conclusions are useful but partial. Tracking hygiene should be one of the follow-ups.', $this->format_weekly_report_percent( $coverage ) ),
				'tone'   => 'warning',
			);
		}
		if ( $overlap_customers > 0 ) {
			$caveats[] = array(
				'title'  => 'WooCommerce customer flag edge case',
				'detail' => 'A customer can place their first and second paid orders in the same period, so new and returning buckets can overlap. Use the returned total customer count as the headline.',
				'tone'   => 'warning',
			);
		}

		$actions = $include_actions ? $this->build_acquisition_report_fallback_actions( $insights, $top_channel, $coverage, $currency ) : array();

		return array(
			'title'        => 'Customer acquisition review',
			'subtitle'     => (string) $period,
			'summary'      => 'Here is the aggregate acquisition read for the selected period. It separates new-customer movement, channel visibility, first-time spend quality, and historic repeat context.',
			'metric_tiles' => array(
				$this->weekly_report_metric_tile( 'New customers', number_format_i18n( $new_customers ), $new_change, 'First-time buyers', false ),
				$this->weekly_report_metric_tile( 'New revenue', $this->format_weekly_report_currency( $new_revenue, $currency ), $new_revenue_change, 'Paid revenue from new customers', false ),
				$this->weekly_report_metric_tile( 'New AOV', $this->format_weekly_report_currency( $new_aov, $currency ), $new_aov_change, 'First-time average order', false ),
				$this->weekly_report_metric_tile( 'Repeat rate', $this->format_weekly_report_percent( $repeat_rate ), $repeat_change, 'Returning-customer share', false ),
				$this->weekly_report_metric_tile( 'Tracking', $coverage > 0 ? $this->format_weekly_report_percent( $coverage ) : 'n/a', null, 'Attribution coverage', false ),
			),
			'insights'     => array_slice( $insights, 0, 5 ),
			'charts'       => $this->acquisition_report_channel_chart( $source ),
			'tables'       => array_values(
				array_filter(
					array(
						array(
							'title'   => 'Customer mix evidence',
							'columns' => array( 'Segment', 'Customers', 'Revenue', 'AOV', 'Spend/customer' ),
							'rows'    => array(
								array( 'New', number_format_i18n( $new_customers ), $this->format_weekly_report_currency( $new_revenue, $currency ), $this->format_weekly_report_currency( $new_aov, $currency ), $this->format_weekly_report_currency( $new_spend, $currency ) ),
								array( 'Returning', number_format_i18n( $returning_customers ), $this->format_weekly_report_currency( $returning_revenue, $currency ), $this->format_weekly_report_currency( $returning_aov, $currency ), $this->format_weekly_report_currency( $returning_spend, $currency ) ),
							),
							'note'    => 'Spend per customer is a period signal; it is not a lifetime prediction.',
						),
						! empty( $this->acquisition_report_channel_rows( $source, $currency ) ) ? array(
							'title'   => 'Channel evidence',
							'columns' => array( 'Channel', 'New customers', 'Revenue', 'Orders', 'Share' ),
							'rows'    => $this->acquisition_report_channel_rows( $source, $currency ),
							'note'    => 'Channel labels come from available order attribution.',
						) : null,
					)
				)
			),
			'caveats'      => $caveats,
			'sources'      => array(
				array(
					'label'  => 'Customer mix',
					'detail' => 'New versus returning customers, period revenue, AOV, spend per customer, and comparison deltas.',
				),
				array(
					'label'  => 'Attribution channels',
					'detail' => 'Channel revenue, orders, new customers, share, movement, and tracking coverage.',
				),
				array(
					'label'  => 'Customer value context',
					'detail' => 'Historic lifetime spend and repeat-segment context for customers active in the period.',
				),
			),
			'actions'      => $actions,
		);
	}

	/**
	 * Acquisition trend headline from new-customer movement.
	 *
	 * @param array<mixed>|null $change New customer comparison.
	 * @return string
	 */
	private function acquisition_report_trend_headline( $change ) {
		$tone = $this->weekly_report_change_tone( $change );
		if ( 'positive' === $tone ) {
			return 'New-customer acquisition improved versus the comparison period';
		}

		if ( 'negative' === $tone ) {
			return 'New-customer acquisition softened versus the comparison period';
		}

		return 'New-customer acquisition was broadly steady';
	}

	/**
	 * Build compact acquisition-channel rows.
	 *
	 * @param array<string,mixed> $source   Source packet.
	 * @param string              $currency Currency code.
	 * @return array<int,array<int,string>>
	 */
	private function acquisition_report_channel_rows( array $source, $currency ) {
		$channels = $this->weekly_report_value( $source, array( 'attribution_channels', 'top_groups' ), array() );
		if ( ! is_array( $channels ) ) {
			return array();
		}

		$rows = array();
		foreach ( array_slice( $channels, 0, 6 ) as $channel ) {
			if ( ! is_array( $channel ) || empty( $channel['label'] ) ) {
				continue;
			}

			$rows[] = array(
				(string) $channel['label'],
				number_format_i18n( (int) $this->weekly_report_numeric( $channel, 'new_customers' ) ),
				$this->format_weekly_report_currency( $this->weekly_report_numeric( $channel, 'net_revenue' ), $currency ),
				number_format_i18n( (int) $this->weekly_report_numeric( $channel, 'orders_count' ) ),
				$this->format_weekly_report_percent( $this->weekly_report_numeric( $channel, 'share_of_revenue_percent' ) ),
			);
		}

		return $rows;
	}

	/**
	 * Build an optional new-customer channel chart.
	 *
	 * @param array<string,mixed> $source Source packet.
	 * @return array<int,array<string,mixed>>
	 */
	private function acquisition_report_channel_chart( array $source ) {
		$channels = $this->weekly_report_value( $source, array( 'attribution_channels', 'top_groups' ), array() );
		if ( ! is_array( $channels ) || count( $channels ) < 2 ) {
			return array();
		}

		$points = array();
		foreach ( array_slice( $channels, 0, 6 ) as $channel ) {
			if ( ! is_array( $channel ) || empty( $channel['label'] ) ) {
				continue;
			}

			$new_customers = (int) $this->weekly_report_numeric( $channel, 'new_customers' );
			if ( $new_customers <= 0 ) {
				continue;
			}

			$points[] = array(
				'x' => (string) $channel['label'],
				'y' => $new_customers,
			);
		}

		if ( count( $points ) < 2 ) {
			return array();
		}

		return array(
			array(
				'type'    => 'bar',
				'title'   => 'New customers by channel',
				'x_label' => 'Channel',
				'y_label' => 'New customers',
				'series'  => array(
					array(
						'name' => 'New customers',
						'data' => $points,
					),
				),
			),
		);
	}

	/**
	 * Build deterministic acquisition action cards.
	 *
	 * @param array<int,array<string,mixed>> $insights    Report insights.
	 * @param mixed                          $top_channel Top channel row.
	 * @param float                          $coverage    Attribution coverage percent.
	 * @param string                         $currency    Currency code.
	 * @return array<int,array<string,mixed>>
	 */
	private function build_acquisition_report_fallback_actions( array $insights, $top_channel, $coverage, $currency ) {
		$actions       = array();
		$channel_label = is_array( $top_channel ) && ! empty( $top_channel['label'] ) ? (string) $top_channel['label'] : 'the leading channel';
		$channel_new   = is_array( $top_channel ) ? (int) $this->weekly_report_numeric( $top_channel, 'new_customers' ) : 0;
		$channel_rev   = is_array( $top_channel ) ? $this->weekly_report_numeric( $top_channel, 'net_revenue' ) : 0;

		$actions[] = array(
			'title'            => sprintf( 'Inspect %s acquisition orders', $channel_label ),
			'priority'         => $channel_new > 5 ? 'medium' : 'low',
			'summary'          => sprintf( '%1$s brought %2$s new customers and %3$s in paid revenue in the selected period.', $channel_label, number_format_i18n( $channel_new ), $this->format_weekly_report_currency( $channel_rev, $currency ) ),
			'key_metric'       => number_format_i18n( $channel_new ) . ' new customers',
			'impact'           => 'Acquisition channel focus',
			'evidence'         => sprintf( 'Top channel in the available attribution breakdown: %s.', $channel_label ),
			'next_steps'       => array(
				'Open recent orders attributed to the channel and check the products first-time buyers chose.',
				'Compare the channel landing offer or campaign message with those products.',
				'Make one merchandising or campaign-tagging change and compare new customers next period.',
			),
			'expected_outcome' => 'A clearer read on whether the channel is bringing useful first-time buyers.',
		);

		$actions[] = array(
			'title'            => 'Check the first-time customer offer',
			'priority'         => 'medium',
			'summary'          => isset( $insights[3]['summary'] ) ? (string) $insights[3]['summary'] : 'First-time customer spend is the quality signal to inspect before scaling acquisition.',
			'key_metric'       => isset( $insights[3]['metric'] ) ? (string) $insights[3]['metric'] : '',
			'impact'           => 'First purchase quality',
			'evidence'         => 'New-customer AOV and spend per customer are returned directly in the acquisition summary.',
			'next_steps'       => array(
				'Review the products most often bought by new customers.',
				'Check whether the entry offer encourages a useful basket, not only a low-value first order.',
				'Adjust the first-time offer or merchandising and compare new-customer AOV next period.',
			),
			'expected_outcome' => 'A first-purchase path that attracts customers worth following up.',
		);

		$actions[] = array(
			'title'            => $coverage > 0 && $coverage < 80 ? 'Tighten acquisition tracking' : 'Set up the repeat-purchase follow-up',
			'priority'         => $coverage > 0 && $coverage < 80 ? 'high' : 'medium',
			'summary'          => $coverage > 0 && $coverage < 80
				? sprintf( 'Attribution coverage was %s, so channel reads are partial.', $this->format_weekly_report_percent( $coverage ) )
				: 'Use the acquisition read to decide which first-time customers should receive a follow-up offer or education sequence.',
			'key_metric'       => $coverage > 0 ? $this->format_weekly_report_percent( $coverage ) : '',
			'impact'           => $coverage > 0 && $coverage < 80 ? 'Tracking confidence' : 'Repeat purchase',
			'evidence'         => $coverage > 0 && $coverage < 80 ? 'The attribution breakdown returned partial channel coverage.' : 'Repeat rate and historic customer-value context are available in the report.',
			'next_steps'       => $coverage > 0 && $coverage < 80
				? array(
					'Check source and campaign tags on live campaign links.',
					'Place one test order from a tagged link and confirm the source is recorded.',
					'Compare tracking coverage in the next acquisition review.',
				)
				: array(
					'Pick one new-customer segment from the leading channel.',
					'Create a follow-up email, coupon, or product recommendation for that segment.',
					'Compare repeat rate and returning-customer revenue in the next review.',
				),
			'expected_outcome' => $coverage > 0 && $coverage < 80 ? 'A less partial channel read next period.' : 'More first-time buyers returning for a second order.',
		);

		return array_slice( $actions, 0, 3 );
	}

	/**
	 * Run a chat completion with optional tool calls.
	 *
	 * @param DifmAiClientInterface $client               AI provider client.
	 * @param string                $system_prompt        System prompt.
	 * @param array                 $messages             Conversation messages.
	 * @param array                 $tools                Provider-neutral tool definitions.
	 * @param string                $empty_reply_fallback Fallback text for empty non-chart replies.
	 * @param bool                  $chart_requested      Whether the merchant explicitly asked for a chart.
	 * @return \WP_REST_Response
	 */
	private function answer_with_tools( DifmAiClientInterface $client, $system_prompt, array $messages, array $tools, $empty_reply_fallback = '', $chart_requested = false ) {
		$chart_specs           = array();
		$chart_retry_attempted = false;
		$iterations            = 0;

		while ( $iterations < self::MAX_TOOL_ITERATIONS ) {
			$result = $client->messages(
				$messages,
				$system_prompt,
				$tools,
				4096,
				array(
					'surface'   => 'difm_chat',
					'iteration' => $iterations + 1,
				)
			);

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
				$reply = $this->extract_text_reply( $content );
				if (
					empty( $chart_specs )
					&& ! $chart_retry_attempted
					&& $this->has_render_chart_tool( $tools )
					&& ( $chart_requested || $this->reply_claims_chart( $reply ) )
				) {
					$chart_retry_attempted = true;
					$messages[]            = array(
						'role'    => 'assistant',
						'content' => $this->normalise_assistant_content( $content ),
					);
					$messages[]            = array(
						'role'    => 'user',
						'content' => 'Internal correction: the previous draft referred to a chart but did not include chart data. Do not acknowledge this correction, apologise, or say "you are right". Return the final merchant-facing answer directly. Use the data already in this conversation to answer with a short text summary, then call render_chart as your final action. If the data is not sufficient to render a truthful chart, say that plainly and do not claim a chart is shown.',
					);
					++$iterations;
					continue;
				}

				return rest_ensure_response(
					$this->build_chat_response( $reply, $chart_specs, $empty_reply_fallback )
				);
			}

			$tool_results     = array();
			$only_chart_tools = true;
			foreach ( $content as $block ) {
				if ( ! isset( $block['type'] ) || 'tool_use' !== $block['type'] ) {
					continue;
				}

				$tool_name  = isset( $block['name'] ) ? (string) $block['name'] : '';
				$tool_input = isset( $block['input'] ) && is_array( $block['input'] ) ? $block['input'] : array();
				$tool_id    = isset( $block['id'] ) ? (string) $block['id'] : '';

				if ( self::RENDER_CHART_TOOL === $tool_name ) {
					$this->log_tool_call( $tool_name, $tool_input, $tool_id, '', 'captured' );
					$chart_specs[]  = $this->sanitise_chart_spec( $tool_input );
					$tool_results[] = array(
						'type'        => 'tool_result',
						'tool_use_id' => $tool_id,
						'content'     => wp_json_encode(
							array(
								'ok'   => true,
								'next' => 'If your complete text answer was not included before this chart tool call, return that text answer now.',
							)
						),
					);
					continue;
				}

				$only_chart_tools = false;
				$tool_output      = $this->execute_tool( $tool_name, $tool_input );

				if ( is_wp_error( $tool_output ) ) {
					if ( 'extended_range_required' === $tool_output->get_error_code() ) {
						$this->store_pending_large_range_request( $tool_name, $tool_input, $tool_output );

						return rest_ensure_response(
							array(
								'status' => 'ok',
								'reply'  => $this->build_large_range_confirmation_reply( $tool_output ),
							)
						);
					}

					$tool_output = $this->format_tool_error( $tool_output );
				}

				$tool_results[] = array(
					'type'        => 'tool_result',
					'tool_use_id' => $tool_id,
					'content'     => wp_json_encode( $tool_output ),
				);
			}

			if ( empty( $tool_results ) ) {
				break;
			}

			if ( $only_chart_tools ) {
				$reply = $this->extract_text_reply( $content );
				if ( '' !== $reply ) {
					return rest_ensure_response( $this->build_chat_response( $reply, $chart_specs, $empty_reply_fallback ) );
				}
			}

			$messages[] = array(
				'role'    => 'assistant',
				'content' => $this->normalise_assistant_content( $content ),
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
	 * Build a normal chat response payload.
	 *
	 * @param string $reply       Assistant text reply.
	 * @param array  $chart_specs Sanitised chart specs.
	 * @param string $empty_reply_fallback Fallback text for empty non-chart replies.
	 * @return array
	 */
	private function build_chat_response( $reply, array $chart_specs = array(), $empty_reply_fallback = '' ) {
		$reply = (string) $reply;
		if ( '' === trim( $reply ) && ! empty( $chart_specs ) ) {
			$title = isset( $chart_specs[0]['title'] ) ? (string) $chart_specs[0]['title'] : '';
			$reply = '' === $title
				? __( 'I have added the chart below.', 'hey-woo' )
				: sprintf(
					/* translators: %s: chart title */
					__( 'I have added the %s chart below.', 'hey-woo' ),
					$title
				);
		}
		if ( '' === trim( $reply ) && '' !== $empty_reply_fallback ) {
			$reply = $empty_reply_fallback;
		}

		$response = array(
			'status' => 'ok',
			'reply'  => $reply,
		);

		if ( ! empty( $chart_specs ) ) {
			$response['charts'] = array_values( $chart_specs );
		}

		return $response;
	}

	/**
	 * Whether the current tool set can render charts.
	 *
	 * @param array $tools Provider-neutral tool definitions.
	 * @return bool
	 */
	private function has_render_chart_tool( array $tools ) {
		foreach ( $tools as $tool ) {
			if ( isset( $tool['name'] ) && self::RENDER_CHART_TOOL === $tool['name'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a merchant message explicitly asks for a chart or visual.
	 *
	 * @param string $message Merchant message.
	 * @return bool
	 */
	private function merchant_requested_chart( $message ) {
		return 1 === preg_match( '/\b(chart|charts|graph|graphs|plot|plots|visualise|visualize|visualisation|visualization)\b/i', (string) $message );
	}

	/**
	 * Whether the current turn or latest user history asks for a chart.
	 *
	 * @param array  $raw_history  Raw history from the REST request.
	 * @param string $user_message Current user message.
	 * @return bool
	 */
	private function history_requested_chart( array $raw_history, $user_message ) {
		if ( $this->merchant_requested_chart( $user_message ) ) {
			return true;
		}

		$history = array_reverse( $raw_history );
		foreach ( $history as $turn ) {
			if ( ! is_array( $turn ) || ! isset( $turn['role'] ) || 'user' !== $turn['role'] ) {
				continue;
			}

			return isset( $turn['content'] ) && $this->merchant_requested_chart( (string) $turn['content'] );
		}

		return false;
	}

	/**
	 * Whether a text reply claims that a chart is already visible.
	 *
	 * @param string $reply Assistant reply text.
	 * @return bool
	 */
	private function reply_claims_chart( $reply ) {
		$reply = (string) $reply;

		return 1 === preg_match(
			'/\b(chart|graph|plot)\s+(above|below|shows|illustrates|compares)\b|\b(the|this|that|following)\s+(chart|graph|plot)\b|\bas shown in (the )?(chart|graph|plot)\b|\b(here is|here\'s|i have added|i\'ve added|i created|i\'ve created|i generated|i\'ve generated).{0,40}\b(chart|graph|plot)\b/i',
			$reply
		);
	}

	/**
	 * Build the system prompt for the conversational assistant.
	 *
	 * @param array<string,string>|null $workflow Selected workflow, if any.
	 * @return string
	 */
	private function build_system_prompt( $workflow = null ) {
		$store_name           = get_bloginfo( 'name' );
		$store_url            = get_bloginfo( 'url' );
		$currency             = get_woocommerce_currency();
		$today                = current_datetime();
		$date                 = $today->format( 'l, j F Y' );
		$today_ymd            = $today->format( 'Y-m-d' );
		$last_two_weeks_start = $today->modify( '-13 days' )->format( 'Y-m-d' );

		$prompt = sprintf(
			'You are an AI assistant for the WooCommerce store "%1$s" (%2$s). '
			. 'Today is %3$s. The store uses %4$s as its currency. '
			. 'Available tool names this request: %7$s. '
			. 'Use the available tools to fetch live store data — always call the relevant '
			. 'tool before answering data questions rather than guessing. '
			. 'If catalogue, product, or readiness tools are not in the available tool list, do not claim you can inspect those surfaces; answer with the available analytics tools and say plainly when that specific data is not available in this chat. '
			. 'Analytics tool choice: use analytics_totals for headline aggregates, analytics_breakdown for grouped cuts, analytics_series for time trends, and analytics_rows for filtered rows or arbitrary segment questions. '
			. 'Date range rule: honour the merchant wording exactly. If they ask for "last two weeks", "past two weeks", or "last 14 days", call tools with date_start=%5$s and date_end=%6$s, not period=last_7_days. If a requested range is not one of the period enum values, use date_start/date_end rather than the nearest enum period. '
			. 'Be concise, direct, and focused on actionable insights. '
			. 'Do not suggest building new features, plugins, or API endpoints — the merchant cannot action that. '
			. 'Never expose internal field names (e.g. metrics.net_sales) in your responses — use plain English only. '
			. 'If a tool reports that a larger date range needs approval, stop and wait for the server-led merchant confirmation flow. '
				. 'Chart rendering rules — follow these exactly: '
				. '(1) Write your complete text reply first. Then, if the chart conditions below apply, you MUST call render_chart as your final action. Never call render_chart before finishing your text. '
				. '(2) You MUST call render_chart if: the merchant asked for a chart, graph, or trend view; OR your answer contains time-series or category data with multiple data points. This applies even if you already wrote a table — include both. If you reference a chart in your text (e.g. "the chart below"), you MUST call render_chart. '
				. '(3) Populate series.data directly from the tool result already in your context — do not call an analytics tool again just to chart it. '
				. '(4) Chart type: use "line" for trends over time, "bar" for comparisons across categories or products, "pie" for proportional breakdowns with 6 or fewer slices. '
				. '(5) If the merchant or selected workflow asks for a hey-woo-report block, chart specs inside that block satisfy this chart requirement; do not also call render_chart unless they explicitly asked for an additional chart. '
				. '(6) Skip render_chart only for single-scalar totals answers where no series or breakdown data was retrieved.',
			esc_html( $store_name ),
			esc_url( $store_url ),
			esc_html( $date ),
			esc_html( $currency ),
			esc_html( $last_two_weeks_start ),
			esc_html( $today_ymd ),
			esc_html( implode( ', ', $this->get_available_tool_names() ) )
		);

		if ( is_array( $workflow ) ) {
			$workflow_prompt = WorkflowSkills::prompt_for_difm( $workflow );
			if ( '' !== $workflow_prompt ) {
				$prompt .= "\n\n" . $workflow_prompt;
			}
		}

		return $prompt;
	}

	/**
	 * Return model-visible tools that are backed by available abilities.
	 *
	 * @return string[]
	 */
	private function get_available_tool_names() {
		$tool_names = array();

		foreach ( self::TOOL_ABILITY_MAP as $tool_name => $ability_id ) {
			if ( $this->get_ability( $ability_id ) ) {
				$tool_names[] = $tool_name;
			}
		}

		$tool_names[] = self::RENDER_CHART_TOOL;

		return $tool_names;
	}

	/**
	 * Build conversation history, capped to MAX_HISTORY_TURNS.
	 *
	 * @param array  $raw_history  Raw history from the REST request.
	 * @param string $user_message Current user message.
	 * @return array
	 */
	private function build_conversation_messages( array $raw_history, $user_message ) {
		$messages = array();
		$turns    = array_slice( $raw_history, - ( self::MAX_HISTORY_TURNS * 2 ) );

		foreach ( $turns as $turn ) {
			$role    = isset( $turn['role'] ) ? $turn['role'] : '';
			$content = isset( $turn['content'] ) ? $turn['content'] : '';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) && '' !== $content ) {
				$messages[] = array(
					'role'    => $role,
					'content' => 'user' === $role ? sanitize_text_field( $content ) : $content,
				);
			}
		}

		$messages[] = array(
			'role'    => 'user',
			'content' => $user_message,
		);

		return $messages;
	}

	/**
	 * Extract the first text block from a normalised AI response.
	 *
	 * @param array $content Response content blocks.
	 * @return string
	 */
	private function extract_text_reply( array $content ) {
		foreach ( $content as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
				return isset( $block['text'] ) ? (string) $block['text'] : '';
			}
		}

		return '';
	}

	/**
	 * Execute a single model-requested tool call through the registered ability.
	 *
	 * @param string $name  Tool name as sent by Claude (snake_case).
	 * @param array  $input Tool input parameters from Claude.
	 * @return array|\WP_Error
	 */
	private function execute_tool( $name, array $input ) {
		$start_ms   = microtime( true );
		$ability_id = isset( self::TOOL_ABILITY_MAP[ $name ] ) ? self::TOOL_ABILITY_MAP[ $name ] : '';
		$this->log_tool_call( $name, $input, '', $ability_id, 'execute' );

		if ( ! isset( self::TOOL_ABILITY_MAP[ $name ] ) ) {
			$this->log_tool_result( $name, 'error', 'unknown_tool', (int) round( ( microtime( true ) - $start_ms ) * 1000 ), null );
			return new \WP_Error(
				'unknown_tool',
				sprintf(
					/* translators: %s: tool name */
					__( 'Unknown DIFM tool: %s', 'hey-woo' ),
					$name
				),
				array( 'status' => 400 )
			);
		}

		$ability = $this->get_ability( $ability_id );

		if ( ! $ability ) {
			$this->log_tool_result( $name, 'error', 'missing_ability', (int) round( ( microtime( true ) - $start_ms ) * 1000 ), null );
			return new \WP_Error(
				'missing_ability',
				sprintf(
					/* translators: %s: ability ID */
					__( 'The DIFM tool bridge is missing ability metadata for %s.', 'hey-woo' ),
					$ability_id
				),
				array( 'status' => 500 )
			);
		}

		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $e ) {
			$this->log_tool_result( $name, 'error', 'tool_execution_failed', (int) round( ( microtime( true ) - $start_ms ) * 1000 ), null );
			return new \WP_Error(
				'tool_execution_failed',
				__( 'Tool execution failed.', 'hey-woo' ),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $result ) ) {
			$this->log_tool_result( $name, 'error', $result->get_error_code(), (int) round( ( microtime( true ) - $start_ms ) * 1000 ), null );
			return $result;
		}

		$output = is_array( $result ) ? $result : array( 'result' => $result );
		$this->log_tool_result( $name, 'ok', '', (int) round( ( microtime( true ) - $start_ms ) * 1000 ), $output );

		return $output;
	}

	/**
	 * Log a model-requested tool call without recording raw prompt or PII.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $input     Tool input.
	 * @param string $tool_id   Provider tool call ID.
	 * @param string $ability_id Backing WordPress ability ID.
	 * @param string $phase     Tool phase.
	 * @return void
	 */
	private function log_tool_call( $tool_name, array $input, $tool_id = '', $ability_id = '', $phase = '' ) {
		TelemetryHandler::record(
			'difm_tool_call',
			array_merge(
				array(
					'event'      => 'difm_tool_call',
					'tool'       => $tool_name,
					'tool_id'    => $tool_id,
					'ability_id' => $ability_id,
					'phase'      => $phase,
				),
				$this->summarise_tool_input_for_log( $input )
			)
		);
	}

	/**
	 * Log the outcome of a server-executed tool call.
	 *
	 * @param string     $tool_name   Tool name.
	 * @param string     $status      ok|error.
	 * @param string     $error_code  Error code when status=error.
	 * @param int        $duration_ms Execution duration.
	 * @param array|null $output      Tool output.
	 * @return void
	 */
	private function log_tool_result( $tool_name, $status, $error_code, $duration_ms, $output ) {
		$data = array(
			'event'       => 'difm_tool_result',
			'tool'        => $tool_name,
			'status'      => $status,
			'error_code'  => $error_code,
			'duration_ms' => $duration_ms,
		);

		if ( is_array( $output ) ) {
			$encoded              = wp_json_encode( $output );
			$data['output_bytes'] = is_string( $encoded ) ? strlen( $encoded ) : 0;
		}

		TelemetryHandler::record( 'difm_tool_result', $data );
	}

	/**
	 * Log the selected workflow slug without recording merchant prompt text.
	 *
	 * @param array<string,string>|null $workflow Selected workflow, if any.
	 * @return void
	 */
	private function log_workflow_selected( $workflow ) {
		if ( ! is_array( $workflow ) || empty( $workflow['slug'] ) ) {
			return;
		}

		TelemetryHandler::record(
			'difm_workflow_selected',
			array(
				'event'    => 'difm_workflow_selected',
				'workflow' => (string) $workflow['slug'],
				'match'    => isset( $workflow['match'] ) ? (string) $workflow['match'] : '',
			)
		);
	}

	/**
	 * Summarise tool input for logs without recording free text or raw filters.
	 *
	 * @param array $input Tool input.
	 * @return array
	 */
	private function summarise_tool_input_for_log( array $input ) {
		$summary = array();
		$keys    = array(
			'subject',
			'entity',
			'dimension',
			'period',
			'date_start',
			'date_end',
			'interval',
			'mode',
			'group_by',
			'limit',
			'orderby',
			'order',
			'include_unassigned',
			'compare',
			'product_id',
			'category',
			'page',
			'per_page',
			'type',
		);

		foreach ( $keys as $key ) {
			if ( isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ) {
				$summary[ $key ] = $input[ $key ];
			}
		}

		if ( isset( $input['filters'] ) && is_array( $input['filters'] ) ) {
			$summary['filter_count'] = count( $input['filters'] );
		}

		if ( isset( $input['query'] ) && '' !== (string) $input['query'] ) {
			$summary['query_present'] = 'yes';
		}

		if ( isset( $input['confirmation_token'] ) && '' !== (string) $input['confirmation_token'] ) {
			$summary['confirmation_token_present'] = 'yes';
		}

		if ( isset( $input['series'] ) && is_array( $input['series'] ) ) {
			$point_count = 0;
			foreach ( $input['series'] as $series ) {
				if ( is_array( $series ) && isset( $series['data'] ) && is_array( $series['data'] ) ) {
					$point_count += count( $series['data'] );
				}
			}
			$summary['series_count'] = count( $input['series'] );
			$summary['point_count']  = $point_count;
		}

		return $summary;
	}

	/**
	 * Build provider-neutral tool definitions from WordPress ability metadata.
	 *
	 * @return array|\WP_Error Provider-neutral tool definitions, or a controlled error.
	 */
	private function build_tool_definitions() {
		$tools = array();

		foreach ( self::TOOL_ABILITY_MAP as $tool_name => $ability_id ) {
			$ability = $this->get_ability( $ability_id );

			if ( ! $ability ) {
				return new \WP_Error(
					'missing_ability',
					sprintf(
						/* translators: %s: ability ID */
						__( 'The DIFM tool bridge is missing ability metadata for %s.', 'hey-woo' ),
						$ability_id
					),
					array( 'status' => 500 )
				);
			}

			if ( ! method_exists( $ability, 'get_description' ) || ! method_exists( $ability, 'get_input_schema' ) ) {
				return new \WP_Error(
					'invalid_ability_metadata',
					sprintf(
						/* translators: %s: ability ID */
						__( 'The DIFM tool bridge cannot read metadata for %s.', 'hey-woo' ),
						$ability_id
					),
					array( 'status' => 500 )
				);
			}

			$input_schema = $ability->get_input_schema();
			if ( ! is_array( $input_schema ) || empty( $input_schema ) ) {
				$input_schema = array(
					'type'       => 'object',
					'properties' => (object) array(),
				);
			}
			$input_schema = $this->normalise_tool_input_schema( $input_schema );
			$input_schema = $this->strip_schema_descriptions( $input_schema );

			$tools[] = array(
				'name'         => $tool_name,
				'description'  => $this->compact_tool_description( $tool_name, (string) $ability->get_description() ),
				'input_schema' => $input_schema,
			);
		}

		$tools[] = $this->build_render_chart_tool_definition();

		return $tools;
	}

	/**
	 * Return the compact model-facing description for a DIFM tool.
	 *
	 * Ability descriptions are intentionally long because they double as MCP
	 * guardrails. AI Insights sends tool definitions on every provider request,
	 * so it uses a compact routing guide and leaves the full descriptions on the
	 * public MCP surface.
	 *
	 * @param string $tool_name            Provider-visible tool name.
	 * @param string $fallback_description Ability metadata description.
	 * @return string
	 */
	private function compact_tool_description( $tool_name, $fallback_description ) {
		switch ( $tool_name ) {
			case 'analytics_totals':
				return 'Headline WooCommerce analytics totals for "how much/how many" questions. Subjects: revenue (collected net sales, orders, AOV, refunds, tax, shipping, pending revenue), orders (counts, statuses, value distribution, payment-method pipeline), customers (new vs returning customers, repeat rate, segment spend), customer_value (lifetime spend/LTV, pseudonymised top customers, cohorts), tax (collected/pending/dashboard tax, refunded tax, effective rate), refunds (amount, count, rate, timing, partial vs full). Use date_start/date_end for custom ranges. Use breakdown for grouped cuts, series for trends, rows for filtered records. If the tool returns extended_range_required, stop; the server will ask the merchant to confirm.';

			case 'analytics_breakdown':
				return 'Grouped WooCommerce analytics for "by X" or "top X" questions. Valid subject/dimension pairs: revenue by category/country/payment_method/shipping_method; attribution by channel/source/medium/campaign/term/content/device/channel_source; products by product/variation; refunds by product/country; tax by rate; coupons by code. Defaults: revenue=category, attribution=channel, products/refunds=product, tax=rate, coupons=code. Use totals for headline figures, series for trends, rows for arbitrary filters or specific row lists. Read returned rates, shares, coverage, and deltas directly; do not recompute them.';

			case 'analytics_series':
				return 'Time-series WooCommerce analytics for trend questions. Subjects: customers (new vs returning counts, orders, repeat rate, segment spend, pipeline by bucket) and products (top products or variations with per-bucket revenue, quantity, orders, refunds). interval is day, week, month, or auto. Use day for short windows, week/month for longer windows unless the merchant asks for a specific granularity. Do not sum bucket counts into unique period totals; use analytics_totals for period headlines. If the merchant asks for a trend, chart, graph, daily, weekly, monthly, or comparison view, this is usually the right tool.';

			case 'analytics_rows':
				return 'Flexible filtered analytics for orders, products, or customers. Use when the question combines attributes, asks for matching records, or needs a row list. entity=orders fields include order_total, gross_total, num_items_sold, tax_total, shipping_total, discount_amount, currency, payment_method, billing/shipping country/state/city/postcode, attribution_channel/source/campaign/device, coupon_code, date_created, returning_customer, status, product_id. entity=products fields include product_id, price, stock_quantity, units_sold_in_period, revenue_in_period, orders_count_in_period, sku, name, status, stock_status, category, onsale, date_created. entity=customers fields include customer_id, lifetime_orders_count, lifetime_spend, country/state/city/postcode, date_registered, date_last_active, first_order_date, last_order_date. Operators: is, is_not, greater_than, less_than, between, is_in, contains, starts_with, is_empty, and matching negations as appropriate. mode=aggregate for counts/sums; mode=rows for top-N lists. Customer rows are pseudonymised; never ask for names or emails.';

			case 'get_product_details':
				return 'Get one product by product_id with description, attributes, images, related products, catalogue metadata, and AI readiness/completeness signals. Use after search_products or analytics product results when the merchant asks about a specific product.';

			case 'search_products':
				return 'Search the product catalogue by query or category and return enriched product summaries with completeness metadata. Use for product lookup, catalogue questions, and finding product IDs before get_product_details.';

			case 'get_store_profile':
				return 'Get store identity and configuration: store name, URL, currency, locale, payment methods, shipping zones, features, and high-level WooCommerce settings. Use when store context matters.';

			case 'get_readiness_score':
				return 'Get the store AI readiness score from 0 to 100 with factor breakdowns for product completeness, schema coverage, policy completeness, and content quality.';

			case 'get_recommendations':
				return 'Get prioritised store-level recommendations for improving AI readiness. Use for "what should I fix first" or readiness improvement questions.';

			case 'suggest_improvements':
				return 'Suggest concrete content or configuration improvements for one product or the whole store. Use after readiness or product-detail data when the merchant asks how to improve.';
		}

		return $this->truncate_tool_description( $fallback_description, 900 );
	}

	/**
	 * Truncate an unexpected ability description defensively.
	 *
	 * @param string $description Description text.
	 * @param int    $limit       Maximum characters.
	 * @return string
	 */
	private function truncate_tool_description( $description, $limit ) {
		$description = trim( preg_replace( '/\s+/', ' ', (string) $description ) );
		$limit       = max( 100, (int) $limit );

		if ( strlen( $description ) <= $limit ) {
			return $description;
		}

		return rtrim( substr( $description, 0, $limit - 1 ) ) . '.';
	}

	/**
	 * Remove nested schema descriptions from tool input schemas.
	 *
	 * The compact tool description carries the routing guidance; keeping every
	 * per-property description repeats the same information in a costly form.
	 *
	 * @param mixed $schema Schema node.
	 * @return mixed
	 */
	private function strip_schema_descriptions( $schema ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		unset( $schema['description'] );

		foreach ( $schema as $key => $value ) {
			$schema[ $key ] = $this->strip_schema_descriptions( $value );
		}

		return $schema;
	}

	/**
	 * Return the tool definition for the render_chart pseudo-tool.
	 *
	 * This tool is never executed server-side; the controller captures the spec
	 * and forwards it to the frontend as part of the response payload.
	 *
	 * @return array
	 */
	private function build_render_chart_tool_definition() {
		return array(
			'name'         => self::RENDER_CHART_TOOL,
			'description'  => 'Render a chart in the chat UI. IMPORTANT: call this as your FINAL action, only after your complete text reply is written — never before. Use it for any trend, daily/weekly/monthly performance, or category-comparison answer. Charts complement your text, they do not replace it. Populate series.data directly from the analytics tool result already in your context.',
			'input_schema' => array(
				'type'       => 'object',
				'required'   => array( 'type', 'title', 'series' ),
				'properties' => array(
					'type'    => array(
						'type'        => 'string',
						'enum'        => array( 'line', 'bar', 'pie' ),
						'description' => "Chart type. Use 'line' for trends over time, 'bar' for comparisons across categories or products, 'pie' for proportional breakdowns with 6 or fewer slices.",
					),
					'title'   => array(
						'type'        => 'string',
						'description' => "Short descriptive title, e.g. 'Net Sales — Last 30 Days'.",
					),
					'x_label' => array(
						'type'        => 'string',
						'description' => 'Label for the x axis (optional).',
					),
					'y_label' => array(
						'type'        => 'string',
						'description' => 'Label for the y axis (optional).',
					),
					'series'  => array(
						'type'        => 'array',
						'description' => 'Data series. For line/bar: one entry per metric. For pie: one entry per slice with a single data point each.',
						'items'       => array(
							'type'       => 'object',
							'required'   => array( 'name', 'data' ),
							'properties' => array(
								'name' => array(
									'type'        => 'string',
									'description' => "Series label, e.g. 'Net Sales'.",
								),
								'data' => array(
									'type'        => 'array',
									'description' => "Data points. Time-series: {x: 'YYYY-MM-DD', y: number}. Categorical: {x: 'Category', y: number}.",
									'items'       => array(
										'type'       => 'object',
										'required'   => array( 'x', 'y' ),
										'properties' => array(
											'x' => array( 'type' => 'string' ),
											'y' => array( 'type' => 'number' ),
										),
									),
								),
							),
						),
					),
				),
			),
		);
	}

	/**
	 * Sanitise a raw render_chart input before forwarding it to the frontend.
	 *
	 * @param array $spec Raw tool input from Claude.
	 * @return array
	 */
	private function sanitise_chart_spec( array $spec ) {
		$allowed_types = array( 'line', 'bar', 'pie' );
		$type          = isset( $spec['type'] ) && in_array( $spec['type'], $allowed_types, true ) ? $spec['type'] : 'bar';

		$sanitised = array(
			'type'  => $type,
			'title' => isset( $spec['title'] ) ? sanitize_text_field( (string) $spec['title'] ) : '',
		);

		if ( ! empty( $spec['x_label'] ) ) {
			$sanitised['x_label'] = sanitize_text_field( (string) $spec['x_label'] );
		}

		if ( ! empty( $spec['y_label'] ) ) {
			$sanitised['y_label'] = sanitize_text_field( (string) $spec['y_label'] );
		}

		$sanitised['series'] = array();
		$raw_series          = isset( $spec['series'] ) && is_array( $spec['series'] ) ? $spec['series'] : array();

		foreach ( $raw_series as $s ) {
			if ( ! is_array( $s ) ) {
				continue;
			}

			$series_name = isset( $s['name'] ) ? sanitize_text_field( (string) $s['name'] ) : '';
			$raw_data    = isset( $s['data'] ) && is_array( $s['data'] ) ? $s['data'] : array();
			$data_points = array();

			foreach ( $raw_data as $point ) {
				if ( ! is_array( $point ) ) {
					continue;
				}
				$data_points[] = array(
					'x' => isset( $point['x'] ) ? sanitize_text_field( (string) $point['x'] ) : '',
					'y' => isset( $point['y'] ) ? (float) $point['y'] : 0.0,
				);
			}

			$sanitised['series'][] = array(
				'name' => $series_name,
				'data' => $data_points,
			);
		}

		return $sanitised;
	}

	/**
	 * Normalise assistant content before replaying it to the provider.
	 *
	 * Some providers require every `tool_use.input` to be a JSON object. PHP decodes
	 * `{}` as an empty array and would otherwise re-encode zero-argument tool
	 * calls as `[]`, which the next provider request can reject.
	 *
	 * @param array $content Assistant content blocks.
	 * @return array
	 */
	private function normalise_assistant_content( array $content ) {
		foreach ( $content as $index => $block ) {
			if ( ! is_array( $block ) || ! isset( $block['type'] ) || 'tool_use' !== $block['type'] ) {
				continue;
			}

			$block['input']    = isset( $block['input'] ) && is_array( $block['input'] )
				? (object) $block['input']
				: (object) array();
			$content[ $index ] = $block;
		}

		return $content;
	}

	/**
	 * Normalise JSON Schema maps so PHP encodes empty objects as `{}`, not `[]`.
	 *
	 * WordPress ability schemas are PHP arrays. Empty object-shaped schema maps
	 * such as `properties` otherwise become JSON arrays, which providers can reject.
	 *
	 * @param mixed  $schema     Schema node.
	 * @param string $parent_key Parent schema key.
	 * @return mixed
	 */
	private function normalise_tool_input_schema( $schema, $parent_key = '' ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		$object_map_keys = array( 'properties', 'patternProperties', 'definitions', '$defs', 'dependentSchemas' );
		if ( empty( $schema ) && in_array( $parent_key, $object_map_keys, true ) ) {
			return (object) array();
		}

		if ( $this->is_array_schema_node( $schema ) && ! isset( $schema['items'] ) ) {
			$schema['items'] = $this->default_array_items_schema( $parent_key );
		}

		foreach ( $schema as $key => $value ) {
			$schema[ $key ] = $this->normalise_tool_input_schema( $value, (string) $key );
		}

		if ( isset( $schema['type'] ) && 'object' === $schema['type'] && ! isset( $schema['properties'] ) ) {
			$schema['properties'] = (object) array();
		}

		return $schema;
	}

	/**
	 * Whether a JSON Schema node declares array type.
	 *
	 * @param array $schema Schema node.
	 * @return bool
	 */
	private function is_array_schema_node( array $schema ) {
		if ( ! isset( $schema['type'] ) ) {
			return false;
		}

		if ( 'array' === $schema['type'] ) {
			return true;
		}

		return is_array( $schema['type'] ) && in_array( 'array', $schema['type'], true );
	}

	/**
	 * Return a valid item schema for intentionally-loose array parameters.
	 *
	 * @param string $parent_key Parent schema key.
	 * @return array|object
	 */
	private function default_array_items_schema( $parent_key ) {
		if ( 'filters' === $parent_key ) {
			return array(
				'type'       => 'object',
				'properties' => (object) array(),
			);
		}

		return (object) array();
	}

	/**
	 * Fetch a WordPress ability by ID.
	 *
	 * @param string $ability_id Ability ID.
	 * @return object|null
	 */
	private function get_ability( $ability_id ) {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return null;
		}

		if ( function_exists( 'wp_has_ability' ) && ! wp_has_ability( $ability_id ) ) {
			return null;
		}

		$ability = wp_get_ability( $ability_id );
		return $ability ? $ability : null;
	}

	/**
	 * Convert non-gated tool errors into structured JSON for the provider.
	 *
	 * @param \WP_Error $error Tool error.
	 * @return array
	 */
	private function format_tool_error( \WP_Error $error ) {
		$data = $error->get_error_data();

		return array(
			'error'      => $error->get_error_message(),
			'error_code' => $error->get_error_code(),
			'error_data' => is_array( $data ) ? $data : array(),
		);
	}

	/**
	 * Store a pending large-range request for the current user.
	 *
	 * @param string    $tool_name Tool name.
	 * @param array     $input     Original tool input.
	 * @param \WP_Error $error     Large-range error.
	 * @return void
	 */
	private function store_pending_large_range_request( $tool_name, array $input, \WP_Error $error ) {
		$error_data = $error->get_error_data();
		$error_data = is_array( $error_data ) ? $error_data : array();
		$ttl        = isset( $error_data['expires_in_seconds'] ) ? (int) $error_data['expires_in_seconds'] : 300;
		$ttl        = $ttl > 0 ? $ttl : 300;

		set_transient(
			$this->get_pending_large_range_transient_key(),
			array(
				'tool_name'     => $tool_name,
				'ability_id'    => isset( self::TOOL_ABILITY_MAP[ $tool_name ] ) ? self::TOOL_ABILITY_MAP[ $tool_name ] : '',
				'input'         => $input,
				'error_data'    => $error_data,
				'cost_estimate' => isset( $error_data['cost_estimate'] ) && is_array( $error_data['cost_estimate'] ) ? $error_data['cost_estimate'] : array(),
				'workflow_slug' => $this->active_workflow_slug,
				'created_at'    => time(),
			),
			$ttl
		);
	}

	/**
	 * Return the pending large-range request for the current user, if any.
	 *
	 * @return array|null
	 */
	private function get_pending_large_range_request() {
		$pending = get_transient( $this->get_pending_large_range_transient_key() );
		return is_array( $pending ) ? $pending : null;
	}

	/**
	 * Restore a workflow selected before a large-range confirmation.
	 *
	 * @param array $pending Stored pending large-range request.
	 * @return array<string,string>|null
	 */
	private function workflow_from_pending_large_range( array $pending ) {
		$slug = isset( $pending['workflow_slug'] ) ? sanitize_key( (string) $pending['workflow_slug'] ) : '';
		if ( '' === $slug ) {
			return null;
		}

		$workflow = WorkflowSkills::get( $slug );
		if ( ! is_array( $workflow ) ) {
			return null;
		}

		$workflow['match'] = 'pending_large_range';
		return $workflow;
	}

	/**
	 * Clear the pending large-range request for the current user.
	 *
	 * @return void
	 */
	private function clear_pending_large_range_request() {
		delete_transient( $this->get_pending_large_range_transient_key() );
	}

	/**
	 * Build the current user's pending large-range transient key.
	 *
	 * @return string
	 */
	private function get_pending_large_range_transient_key() {
		return self::PENDING_LARGE_RANGE_PREFIX . (string) get_current_user_id();
	}

	/**
	 * Build a merchant-facing confirmation prompt from a large-range error.
	 *
	 * @param \WP_Error $error Large-range error.
	 * @return string
	 */
	private function build_large_range_confirmation_reply( \WP_Error $error ) {
		$data     = $error->get_error_data();
		$data     = is_array( $data ) ? $data : array();
		$estimate = isset( $data['cost_estimate'] ) && is_array( $data['cost_estimate'] ) ? $data['cost_estimate'] : array();

		if ( isset( $estimate['range_days'], $estimate['months'], $estimate['threshold'] ) ) {
			return sprintf(
				/* translators: 1: days, 2: months, 3: threshold days */
				__( 'That request covers %1$d days (%2$d months), which is larger than the usual %3$d-day safety limit and may briefly affect site performance. Reply "yes, proceed" to load the full range, or ask me for a shorter date range.', 'hey-woo' ),
				(int) $estimate['range_days'],
				(int) $estimate['months'],
				(int) $estimate['threshold']
			);
		}

		return __( 'That request covers a larger range than usual and may briefly affect site performance. Reply "yes, proceed" to load the full range, or ask me for a shorter date range.', 'hey-woo' );
	}

	/**
	 * Determine whether a pending large-range reply is affirmative.
	 *
	 * @param string $message Merchant message.
	 * @return bool
	 */
	private function is_large_range_affirmation( $message ) {
		return (bool) preg_match( '/^\s*(yes|yep|yeah|please proceed|proceed|go ahead|load full|confirm|do it|carry on)\b/i', $message );
	}

	/**
	 * Determine whether a pending large-range reply declines or narrows.
	 *
	 * @param string $message Merchant message.
	 * @return bool
	 */
	private function is_large_range_refusal( $message ) {
		return (bool) preg_match( '/^\s*(no|nope|cancel|stop|do not|don\'t|dont|shorter|narrow)\b/i', $message );
	}

	/**
	 * Execute the stored request after explicit merchant confirmation.
	 *
	 * @param DifmAiClientInterface $client        AI provider client.
	 * @param string                $system_prompt System prompt.
	 * @param array                 $raw_history   Raw history from request.
	 * @param string                $user_message  Current user message.
	 * @param array                 $pending       Stored pending request.
	 * @return \WP_REST_Response
	 */
	private function answer_confirmed_large_range_request( DifmAiClientInterface $client, $system_prompt, array $raw_history, $user_message, array $pending ) {
		$tool_name  = isset( $pending['tool_name'] ) ? (string) $pending['tool_name'] : '';
		$input      = isset( $pending['input'] ) && is_array( $pending['input'] ) ? $pending['input'] : array();
		$error_data = isset( $pending['error_data'] ) && is_array( $pending['error_data'] ) ? $pending['error_data'] : array();

		if ( '' === $tool_name || empty( $input ) ) {
			$this->clear_pending_large_range_request();
			return rest_ensure_response(
				array(
					'status' => 'ok',
					'reply'  => __( 'The previous large-range request could not be safely restored. Please ask again with the date range you want.', 'hey-woo' ),
				)
			);
		}

		if ( ! empty( $error_data['confirmation_token'] ) ) {
			$input['confirmation_token'] = (string) $error_data['confirmation_token'];
		} elseif ( ! $this->approve_large_range_session_request( $tool_name, $input ) ) {
			$this->clear_pending_large_range_request();
			return rest_ensure_response(
				array(
					'status' => 'ok',
					'reply'  => __( 'The approval window has expired. Please ask again and I will show a fresh estimate before loading the larger range.', 'hey-woo' ),
				)
			);
		}

		$tool_output = $this->execute_tool( $tool_name, $input );
		$this->clear_pending_large_range_request();

		if ( is_wp_error( $tool_output ) ) {
			if ( 'extended_range_required' === $tool_output->get_error_code() ) {
				$this->store_pending_large_range_request( $tool_name, $input, $tool_output );
				return rest_ensure_response(
					array(
						'status' => 'ok',
						'reply'  => $this->build_large_range_confirmation_reply( $tool_output ),
					)
				);
			}

			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $tool_output->get_error_message(),
				)
			);
		}

		$messages   = $this->build_conversation_messages( $raw_history, $user_message );
		$messages[] = array(
			'role'    => 'user',
			'content' => sprintf(
				'The merchant explicitly confirmed the larger date range. Answer their original question using this server-executed %1$s result. Do not expose internal field names. If the original question asked for a chart or the result is trend/comparison data, use render_chart as your final action. Result JSON: %2$s',
				$tool_name,
				wp_json_encode( $tool_output )
			),
		);

		return $this->answer_with_tools(
			$client,
			$system_prompt,
			$messages,
			array( $this->build_render_chart_tool_definition() ),
			__( 'I loaded the full range, but could not summarise the result. Please try again.', 'hey-woo' ),
			$this->history_requested_chart( $raw_history, $user_message )
		);
	}

	/**
	 * Approve a session-keyed pending large-range request when no token exists.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $input     Original tool input.
	 * @return bool
	 */
	private function approve_large_range_session_request( $tool_name, array $input ) {
		if ( empty( $input['date_start'] ) || empty( $input['date_end'] ) ) {
			return false;
		}

		$type = $this->large_range_type_for_tool( $tool_name, $input );
		if ( '' === $type ) {
			return false;
		}

		$result = ConfirmLargeRangeAbility::execute(
			array(
				'date_start'  => (string) $input['date_start'],
				'date_end'    => (string) $input['date_end'],
				'type'        => $type,
				'description' => $this->large_range_description_for_tool( $tool_name, $input ),
			)
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Convert a DIFM tool name to the large-range gate type slug.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $input     Original tool input.
	 * @return string
	 */
	private function large_range_type_for_tool( $tool_name, array $input = array() ) {
		// Verb tools tool-prefix the subject when they call the gate (totals:revenue,
		// breakdown:revenue, series:customers, etc.) so approvals minted by one verb
		// tool cannot be consumed by another tool sharing the same subject — see
		// includes/abilities/class-analytics-{totals,breakdown,series}-ability.php
		// and the cross-tool collision regression test in
		// tests/integration/test-large-range-gate.php. DIFM's approve_scan call must
		// pass the same prefixed value or the transient lookup misses.
		$verb_prefix_map = array(
			'analytics_totals'    => 'totals',
			'analytics_breakdown' => 'breakdown',
			'analytics_series'    => 'series',
		);
		if ( isset( $verb_prefix_map[ $tool_name ] ) ) {
			$subject = isset( $input['subject'] ) ? (string) $input['subject'] : '';
			return '' === $subject ? '' : $verb_prefix_map[ $tool_name ] . ':' . $subject;
		}

		if ( 'analytics_rows' === $tool_name ) {
			// Rows does not gate today (see mcp_server_instructions), so this branch
			// is unreachable from the approve path. Keep it returning the entity for
			// symmetry if rows ever gains gating.
			return isset( $input['entity'] ) ? (string) $input['entity'] : '';
		}

		$legacy_map = array(
			'get_revenue_summary'     => 'revenue_summary',
			'get_orders_summary'      => 'orders_summary',
			'get_refund_analysis'     => 'refund_analysis',
			'get_customer_overview'   => 'customer_overview',
			'get_product_performance' => 'product_performance',
			'get_attribution'         => 'attribution',
			'get_coupon_performance'  => 'coupon_performance',
			'get_revenue_breakdown'   => 'revenue_breakdown',
			'get_tax_summary'         => 'tax_summary',
			'get_customer_value'      => 'customer_value',
			'query_analytics'         => 'query_analytics',
		);

		return isset( $legacy_map[ $tool_name ] ) ? $legacy_map[ $tool_name ] : '';
	}

	/**
	 * Build a short description for a controller-approved large-range request.
	 *
	 * @param string $tool_name Tool name.
	 * @param array  $input     Original tool input.
	 * @return string
	 */
	private function large_range_description_for_tool( $tool_name, array $input ) {
		$shape = isset( $input['subject'] ) ? (string) $input['subject'] : '';
		if ( '' === $shape && isset( $input['entity'] ) ) {
			$shape = (string) $input['entity'];
		}

		if ( '' === $shape ) {
			return $tool_name;
		}

		return $tool_name . ' for ' . $shape;
	}
}
