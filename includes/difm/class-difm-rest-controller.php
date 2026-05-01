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
	 * How long (seconds) to cache the store context snapshot.
	 * One hour — short enough to stay reasonably fresh, long enough to avoid
	 * hammering the analytics layer on every chat message.
	 */
	const STORE_CONTEXT_TTL = HOUR_IN_SECONDS;

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
	 * Accepts { message, history } and calls the Anthropic API synchronously.
	 * The system prompt includes lightweight store context so Claude can give
	 * relevant answers without the merchant needing to provide background.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function send_chat_message( \WP_REST_Request $request ) {
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		if ( ! AnthropicClient::has_api_key() ) {
			return rest_ensure_response( array( 'status' => 'no_key' ) );
		}

		$user_message = (string) $request->get_param( 'message' );
		$raw_history  = (array) $request->get_param( 'history' );

		// Build conversation history, capped to MAX_HISTORY_TURNS.
		$history = array();
		$turns   = array_slice( $raw_history, - ( self::MAX_HISTORY_TURNS * 2 ) );
		foreach ( $turns as $turn ) {
			$role    = isset( $turn['role'] ) ? $turn['role'] : '';
			$content = isset( $turn['content'] ) ? $turn['content'] : '';
			if ( in_array( $role, array( 'user', 'assistant' ), true ) && '' !== $content ) {
				$history[] = array(
					'role'    => $role,
					'content' => sanitize_text_field( $content ),
				);
			}
		}

		// Append the new user message.
		$history[] = array(
			'role'    => 'user',
			'content' => $user_message,
		);

		$client = new AnthropicClient();
		$result = $client->messages( $history, $this->build_system_prompt() );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		// Extract the text from the first content block.
		$reply = '';
		if ( ! empty( $result['content'] ) && is_array( $result['content'] ) ) {
			foreach ( $result['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
					$reply = $block['text'];
					break;
				}
			}
		}

		return rest_ensure_response(
			array(
				'status' => 'ok',
				'reply'  => $reply,
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
	 * Fetches a cached store context snapshot and embeds it as a JSON block so
	 * Claude can answer questions about real store data without the merchant
	 * needing to supply background information.
	 *
	 * @return string
	 */
	private function build_system_prompt() {
		$store_name = get_bloginfo( 'name' );
		$store_url  = get_bloginfo( 'url' );
		$currency   = get_woocommerce_currency();
		$date       = gmdate( 'l, j F Y' );

		$intro = sprintf(
			'You are an AI assistant for the WooCommerce store "%1$s" (%2$s). '
			. 'Today is %3$s. The store uses %4$s as its currency. '
			. 'You help the merchant understand their store performance, orders, products, '
			. 'customers, and revenue. Be concise, direct, and focused on actionable insights. '
			. 'Do not suggest building new features, plugins, or API endpoints — the merchant cannot action that. '
			. 'Never expose internal field names (e.g. metrics.net_sales) in your responses — use plain English only. '
			. 'If the merchant asks about data you genuinely cannot see (e.g. profit margin, inventory history), say so clearly.',
			esc_html( $store_name ),
			esc_url( $store_url ),
			esc_html( $date ),
			esc_html( $currency )
		);

		$store_context = $this->fetch_store_context();
		if ( empty( $store_context ) ) {
			return $intro;
		}

		$data_gaps_note = '';
		if ( ! empty( $store_context['data_gaps'] ) ) {
			$data_gaps_note = sprintf(
				"\n\nNote: the following data sources were unavailable and are absent from the snapshot: %s. "
				. 'Do not draw conclusions about those areas.',
				implode( ', ', $store_context['data_gaps'] )
			);
		}

		unset( $store_context['data_gaps'] );

		return $intro
			. "\n\n<store_data>\n"
			. wp_json_encode( $store_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE )
			. "\n</store_data>"
			. $data_gaps_note;
	}

	/**
	 * Fetch a store data snapshot from the analytics abilities.
	 *
	 * Results are cached for STORE_CONTEXT_TTL seconds to avoid re-running
	 * expensive queries on every chat message. The cache is keyed by date so
	 * it naturally rotates at midnight.
	 *
	 * @return array Merged context array; empty array on total failure.
	 */
	private function fetch_store_context() {
		$cache_key = 'hey_woo_chat_context_' . gmdate( 'Y-m-d' );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$period_input   = array(
			'period'  => 'last_30_days',
			'compare' => true,
		);
		$products_input = array_merge( $period_input, array( 'limit' => 10 ) );

		$abilities = array(
			'revenue'   => array( GetRevenueSummaryAbility::class, 'execute' ),
			'orders'    => array( GetOrdersSummaryAbility::class, 'execute' ),
			'refunds'   => array( GetRefundAnalysisAbility::class, 'execute' ),
			'customers' => array( GetCustomerOverviewAbility::class, 'execute' ),
		);

		$context   = array( 'period' => 'last_30_days' );
		$data_gaps = array();

		foreach ( $abilities as $key => $callback ) {
			try {
				$result = call_user_func( $callback, $period_input );
				if ( is_wp_error( $result ) ) {
					$data_gaps[] = $key;
				} else {
					$context[ $key ] = $result;
				}
			} catch ( \Throwable $e ) {
				$data_gaps[] = $key;
			}
		}

		// Product performance uses a different input (adds limit).
		try {
			$products = GetProductPerformanceAbility::execute( $products_input );
			if ( is_wp_error( $products ) ) {
				$data_gaps[] = 'products';
			} else {
				$context['products'] = $products;
			}
		} catch ( \Throwable $e ) {
			$data_gaps[] = 'products';
		}

		if ( ! empty( $data_gaps ) ) {
			$context['data_gaps'] = $data_gaps;
		}

		set_transient( $cache_key, $context, self::STORE_CONTEXT_TTL );

		return $context;
	}
}
