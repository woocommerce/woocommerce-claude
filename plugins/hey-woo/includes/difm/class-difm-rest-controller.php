<?php
/**
 * REST controller for Hey Woo endpoints.
 *
 * Routes:
 *   POST /hey-woo/v1/difm/chat — Send a chat message; returns Claude's reply.
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
	 * Maximum number of Anthropic API calls per chat request (including tool-use rounds).
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
	 * Anthropic-visible tool names mapped to registered WordPress abilities.
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
		'get_product_details'  => 'woocommerce-claude/get-product-details',
		'search_products'      => 'woocommerce-claude/search-products',
		'get_store_profile'    => 'woocommerce-claude/get-store-profile',
		'get_readiness_score'  => 'woocommerce-claude/get-readiness-score',
		'get_recommendations'  => 'woocommerce-claude/get-recommendations',
		'suggest_improvements' => 'woocommerce-claude/suggest-improvements',
	);

	/**
	 * Return the DIFM Anthropic tool allowlist.
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
	 * POST /hey-woo/v1/difm/chat — send a message and return Claude's reply.
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
		$client       = new AnthropicClient();

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
	 * Run a chat completion with optional tool calls.
	 *
	 * @param AnthropicClient $client        Anthropic client.
	 * @param string          $system_prompt System prompt.
	 * @param array           $messages      Conversation messages.
	 * @param array           $tools         Anthropic-format tool definitions.
	 * @param string          $empty_reply_fallback Fallback text for empty non-chart replies.
	 * @param bool            $chart_requested Whether the merchant explicitly asked for a chart.
	 * @return \WP_REST_Response
	 */
	private function answer_with_tools( AnthropicClient $client, $system_prompt, array $messages, array $tools, $empty_reply_fallback = '', $chart_requested = false ) {
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
						'content' => $this->normalise_anthropic_assistant_content( $content ),
					);
					$messages[]            = array(
						'role'    => 'user',
						'content' => 'The previous answer said or implied that a chart was shown, but no render_chart tool call was made. Use the data already in this conversation to answer with a short text summary, then call render_chart as your final action. If the data is not sufficient to render a truthful chart, say that plainly and do not claim a chart is shown.',
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
				'content' => $this->normalise_anthropic_assistant_content( $content ),
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
	 * @param array $tools Anthropic-format tool definitions.
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
				. '(1) Always write your full text reply first, then call render_chart as your final action. Never call render_chart before finishing your text. '
				. '(2) For any question about trends, daily/weekly/monthly performance, or comparisons across products/categories — always call render_chart. Charts complement your text; they do not replace it. Do not skip the chart because you already wrote a table — include both. '
				. '(3) Populate series.data directly from the tool result already in your context — do not call an analytics tool again just to chart it. '
				. '(4) Chart type: use "line" for trends over time, "bar" for comparisons across categories or products, "pie" for proportional breakdowns with 6 or fewer slices. '
				. '(5) Skip render_chart only for single-scalar totals answers where no series or breakdown data was retrieved.',
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
	 * Return Anthropic-visible tools that are backed by available abilities.
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
	 * Extract the first text block from an Anthropic response.
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
	 * Execute a single Anthropic tool call through the registered ability.
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
	 * @param string $tool_id   Anthropic tool_use ID.
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
	 * Build Anthropic tool definitions from WordPress ability metadata.
	 *
	 * @return array|\WP_Error Anthropic-format tool definitions, or a controlled error.
	 */
	private function build_tool_definitions() {
		$tools = array();

		foreach ( self::TOOL_ABILITY_MAP as $tool_name => $ability_id ) {
			$ability = $this->get_ability( $ability_id );

			if ( ! $ability ) {
				if ( $this->is_optional_external_ability( $ability_id ) ) {
					continue;
				}

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
			$input_schema = $this->normalise_anthropic_input_schema( $input_schema );
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
	 * Whether an ability belongs to another plugin and should be omitted when absent.
	 *
	 * Hey Woo can use WooCommerce for Claude's product/readiness abilities when
	 * both plugins are active, but the standalone BYOK plugin must still work
	 * with the shared analytics abilities only.
	 *
	 * @param string $ability_id Ability ID.
	 * @return bool
	 */
	private function is_optional_external_ability( $ability_id ) {
		return 0 === strpos( (string) $ability_id, 'woocommerce-claude/' );
	}

	/**
	 * Return the compact Anthropic-facing description for a DIFM tool.
	 *
	 * Ability descriptions are intentionally long because they double as MCP
	 * guardrails. Hey Woo sends tool definitions on every Anthropic request,
	 * so it uses a compact routing guide and leaves the full descriptions on the
	 * public MCP surface.
	 *
	 * @param string $tool_name            Anthropic-visible tool name.
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
	 * Remove nested schema descriptions from Anthropic tool input schemas.
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
	 * Return the Anthropic tool definition for the render_chart pseudo-tool.
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
	 * Normalise assistant content before replaying it to Anthropic.
	 *
	 * Anthropic requires every `tool_use.input` to be a JSON object. PHP decodes
	 * `{}` as an empty array and would otherwise re-encode zero-argument tool
	 * calls as `[]`, which the next Messages API request rejects.
	 *
	 * @param array $content Assistant content blocks from Anthropic.
	 * @return array
	 */
	private function normalise_anthropic_assistant_content( array $content ) {
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
	 * such as `properties` otherwise become JSON arrays, which Anthropic rejects.
	 *
	 * @param mixed  $schema     Schema node.
	 * @param string $parent_key Parent schema key.
	 * @return mixed
	 */
	private function normalise_anthropic_input_schema( $schema, $parent_key = '' ) {
		if ( ! is_array( $schema ) ) {
			return $schema;
		}

		$object_map_keys = array( 'properties', 'patternProperties', 'definitions', '$defs', 'dependentSchemas' );
		if ( empty( $schema ) && in_array( $parent_key, $object_map_keys, true ) ) {
			return (object) array();
		}

		foreach ( $schema as $key => $value ) {
			$schema[ $key ] = $this->normalise_anthropic_input_schema( $value, (string) $key );
		}

		if ( isset( $schema['type'] ) && 'object' === $schema['type'] && ! isset( $schema['properties'] ) ) {
			$schema['properties'] = (object) array();
		}

		return $schema;
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
	 * Convert non-gated tool errors into structured JSON for Anthropic.
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
	 * @param AnthropicClient $client        Anthropic client.
	 * @param string          $system_prompt System prompt.
	 * @param array           $raw_history   Raw history from request.
	 * @param string          $user_message  Current user message.
	 * @param array           $pending       Stored pending request.
	 * @return \WP_REST_Response
	 */
	private function answer_confirmed_large_range_request( AnthropicClient $client, $system_prompt, array $raw_history, $user_message, array $pending ) {
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
