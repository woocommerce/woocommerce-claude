<?php
/**
 * REST controller for Hey Woo DIFM (Do It For Me) endpoints.
 *
 * Routes:
 *   POST /hey-woo/v1/difm/chat — Send a chat message; returns Claude's reply.
 *
 * @package HeyWoo\Difm
 */

namespace HeyWoo\Difm;

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
	 * Transient prefix for pending large-range confirmations.
	 */
	const PENDING_LARGE_RANGE_PREFIX = 'hey_woo_difm_large_range_';

	/**
	 * Anthropic-visible tool names mapped to registered WordPress abilities.
	 *
	 * The confirm-large-range ability is intentionally absent. Large-range
	 * approval is a controller-level merchant consent flow, not a model tool.
	 */
	private const TOOL_ABILITY_MAP = array(
		'get_revenue_summary'     => 'wc-analytics/get-revenue-summary',
		'get_orders_summary'      => 'wc-analytics/get-orders-summary',
		'get_refund_analysis'     => 'wc-analytics/get-refund-analysis',
		'get_customer_overview'   => 'wc-analytics/get-customer-overview',
		'get_product_performance' => 'wc-analytics/get-product-performance',
		'get_attribution'         => 'wc-analytics/get-attribution',
		'get_coupon_performance'  => 'wc-analytics/get-coupon-performance',
		'get_revenue_breakdown'   => 'wc-analytics/get-revenue-breakdown',
		'get_tax_summary'         => 'wc-analytics/get-tax-summary',
		'get_customer_value'      => 'wc-analytics/get-customer-value',
		'query_analytics'         => 'wc-analytics/query-analytics',
		'get_product_details'     => 'hey-woo/get-product-details',
		'search_products'         => 'hey-woo/search-products',
		'get_store_profile'       => 'hey-woo/get-store-profile',
		'get_readiness_score'     => 'hey-woo/get-readiness-score',
		'get_recommendations'     => 'hey-woo/get-recommendations',
		'suggest_improvements'    => 'hey-woo/suggest-improvements',
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

		$user_message  = (string) $request->get_param( 'message' );
		$raw_history   = (array) $request->get_param( 'history' );
		$client        = new AnthropicClient();
		$system_prompt = $this->build_system_prompt();

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
				return $this->answer_confirmed_large_range_request( $client, $system_prompt, $raw_history, $user_message, $pending_large_range );
			}

			return rest_ensure_response(
				array(
					'status' => 'ok',
					'reply'  => __( 'I still need an explicit confirmation before loading the larger range. Reply with "yes, proceed" to continue, or ask for a shorter date range.', 'hey-woo' ),
				)
			);
		}

		$messages = $this->build_conversation_messages( $raw_history, $user_message );
		$tools    = $this->build_tool_definitions();

		if ( is_wp_error( $tools ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $tools->get_error_message(),
				)
			);
		}

		$iterations = 0;

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
				return rest_ensure_response(
					array(
						'status' => 'ok',
						'reply'  => $this->extract_text_reply( $content ),
					)
				);
			}

			$tool_results = array();
			foreach ( $content as $block ) {
				if ( ! isset( $block['type'] ) || 'tool_use' !== $block['type'] ) {
					continue;
				}

				$tool_name   = isset( $block['name'] ) ? (string) $block['name'] : '';
				$tool_input  = isset( $block['input'] ) && is_array( $block['input'] ) ? $block['input'] : array();
				$tool_output = $this->execute_tool( $tool_name, $tool_input );

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
					'tool_use_id' => isset( $block['id'] ) ? (string) $block['id'] : '',
					'content'     => wp_json_encode( $tool_output ),
				);
			}

			if ( empty( $tool_results ) ) {
				break;
			}

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
	 * Build the system prompt for the conversational assistant.
	 *
	 * @return string
	 */
	private function build_system_prompt() {
		$store_name           = get_bloginfo( 'name' );
		$store_url            = get_bloginfo( 'url' );
		$currency             = get_woocommerce_currency();
		$today                = current_datetime();
		$date                 = $today->format( 'l, j F Y' );
		$today_ymd            = $today->format( 'Y-m-d' );
		$last_two_weeks_start = $today->modify( '-13 days' )->format( 'Y-m-d' );

		return sprintf(
			'You are an AI assistant for the WooCommerce store "%1$s" (%2$s). '
			. 'Today is %3$s. The store uses %4$s as its currency. '
			. 'Use the available tools to fetch live store data — always call the relevant '
			. 'tool before answering data questions rather than guessing. '
			. 'Date range rule: honour the merchant wording exactly. If they ask for "last two weeks", "past two weeks", or "last 14 days", call tools with date_start=%5$s and date_end=%6$s, not period=last_7_days. If a requested range is not one of the period enum values, use date_start/date_end rather than the nearest enum period. '
			. 'Be concise, direct, and focused on actionable insights. '
			. 'Do not suggest building new features, plugins, or API endpoints — the merchant cannot action that. '
			. 'Never expose internal field names (e.g. metrics.net_sales) in your responses — use plain English only. '
			. 'If a tool reports that a larger date range needs approval, stop and wait for the server-led merchant confirmation flow.',
			esc_html( $store_name ),
			esc_url( $store_url ),
			esc_html( $date ),
			esc_html( $currency ),
			esc_html( $last_two_weeks_start ),
			esc_html( $today_ymd )
		);
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
					'content' => sanitize_text_field( $content ),
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
		if ( ! isset( self::TOOL_ABILITY_MAP[ $name ] ) ) {
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

		$ability_id = self::TOOL_ABILITY_MAP[ $name ];
		$ability    = $this->get_ability( $ability_id );

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

		try {
			$result = $ability->execute( $input );
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'tool_execution_failed',
				__( 'Tool execution failed.', 'hey-woo' ),
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return is_array( $result ) ? $result : array( 'result' => $result );
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

			$tools[] = array(
				'name'         => $tool_name,
				'description'  => (string) $ability->get_description(),
				'input_schema' => $input_schema,
			);
		}

		return $tools;
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
				'The merchant explicitly confirmed the larger date range. Answer their original question using this server-executed %1$s result. Do not expose internal field names. Result JSON: %2$s',
				$tool_name,
				wp_json_encode( $tool_output )
			),
		);

		$result = $client->messages( $messages, $system_prompt, array() );

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

		return rest_ensure_response(
			array(
				'status' => 'ok',
				'reply'  => '' === $reply ? __( 'I loaded the full range, but could not summarise the result. Please try again.', 'hey-woo' ) : $reply,
			)
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

		$type = $this->large_range_type_for_tool( $tool_name );
		if ( '' === $type ) {
			return false;
		}

		$result = ConfirmLargeRangeAbility::execute(
			array(
				'date_start'  => (string) $input['date_start'],
				'date_end'    => (string) $input['date_end'],
				'type'        => $type,
				'description' => $tool_name,
			)
		);

		return ! is_wp_error( $result );
	}

	/**
	 * Convert a DIFM tool name to the large-range gate type slug.
	 *
	 * @param string $tool_name Tool name.
	 * @return string
	 */
	private function large_range_type_for_tool( $tool_name ) {
		$map = array(
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

		return isset( $map[ $tool_name ] ) ? $map[ $tool_name ] : '';
	}
}
