<?php
/**
 * REST controller for the AI Insights idea board.
 *
 * Routes:
 *   GET /woocommerce-claude/v1/difm/idea-board - Generate a brainstorming board.
 *
 * @package WooCommerce\Claude\Difm
 */

namespace WooCommerce\Claude\Difm;

use WooCommerce\Claude\API\AnalyticsController;
use WooCommerce\Claude\API\ReadinessController;
use WooCommerce\Claude\API\StoreController;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the idea-board REST route.
 *
 * PHP 7.4 compatible - no union types, no match, no enums.
 */
class IdeaBoardRestController {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'woocommerce-claude/v1';

	/**
	 * Idea board route.
	 */
	const ROUTE = '/difm/idea-board';

	/**
	 * Version marker for cached idea-board payloads.
	 */
	const CACHE_VERSION = '2026-05-12-ai-content-layout-v3';

	/**
	 * Canonical board width used for AI coordinate planning.
	 */
	const CANVAS_WIDTH = 1480;

	/**
	 * Canonical board height used for AI coordinate planning.
	 */
	const CANVAS_HEIGHT = 760;

	/**
	 * Canonical sticky-note width used for AI coordinate planning.
	 */
	const CARD_WIDTH = 245;

	/**
	 * Canonical sticky-note height used for AI coordinate planning.
	 */
	const CARD_HEIGHT = 150;

	/**
	 * Minimum spacing between sticky notes in the canonical layout.
	 */
	const CARD_GAP = 36;

	/**
	 * Minimum X coordinate percentage accepted for a card.
	 */
	const MIN_X_PERCENT = 3.0;

	/**
	 * Maximum X coordinate percentage accepted for a card.
	 */
	const MAX_X_PERCENT = 72.0;

	/**
	 * Minimum Y coordinate percentage accepted for a card.
	 */
	const MIN_Y_PERCENT = 6.0;

	/**
	 * Maximum Y coordinate percentage accepted for a card.
	 */
	const MAX_Y_PERCENT = 76.0;

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the idea-board REST route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_idea_board' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'days'    => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 90,
							'minimum'           => 30,
							'maximum'           => 180,
							'sanitize_callback' => 'absint',
						),
						'refresh' => array(
							'type'              => 'boolean',
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'wc_string_to_bool',
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
				__( 'Permission denied.', 'woocommerce-claude' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * GET /woocommerce-claude/v1/difm/idea-board - AI-generated brainstorming board.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function get_idea_board( \WP_REST_Request $request ) {
		$days = (int) $request->get_param( 'days' );
		if ( $days < 30 ) {
			$days = 30;
		}
		if ( $days > 180 ) {
			$days = 180;
		}

		$refresh = wc_string_to_bool( $request->get_param( 'refresh' ) );
		$payload = $this->build_idea_board_payload( $days, $refresh );
		if ( is_wp_error( $payload ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $payload->get_error_message(),
				)
			);
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Build the idea-board payload from existing analytics helpers.
	 *
	 * @param int  $days    Number of trailing days to include.
	 * @param bool $refresh Whether to bypass cached board and analytics results.
	 * @return array|\WP_Error Board payload or error.
	 */
	private function build_idea_board_payload( $days, $refresh = false ) {
		if ( ! AnthropicClient::has_api_key() ) {
			return new \WP_Error(
				'no_anthropic_key',
				__( 'Add an Anthropic API key to generate the idea board.', 'woocommerce-claude' )
			);
		}

		$dates     = $this->get_idea_board_dates( $days );
		$cache_key = 'woocommerce_claude_difm_idea_board_' . md5(
			$dates['start'] . '_' . $dates['end'] . '_' . $days . '_' . get_woocommerce_currency() . '_' . self::CACHE_VERSION
		);

		if ( $refresh ) {
			$this->clear_idea_board_analytics_caches();
		} else {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$revenue   = AnalyticsController::fetch_revenue_summary( '', $dates['start'], $dates['end'], true );
		$orders    = AnalyticsController::fetch_orders_summary( '', $dates['start'], $dates['end'], true );
		$products  = AnalyticsController::fetch_product_performance( '', $dates['start'], $dates['end'], true, 5, 'net_revenue', 'product', '' );
		$customers = AnalyticsController::fetch_customer_overview( '', $dates['start'], $dates['end'], true, '' );
		$profile   = $this->get_idea_board_store_profile();
		$recs      = $this->get_idea_board_recommendations();

		foreach ( array( $revenue, $orders, $products, $customers ) as $result ) {
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$content = $this->build_idea_board_content( $revenue, $orders, $products, $customers, $profile, $recs, $days );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$notes         = $content['notes'];
		$arrows        = $content['arrows'];
		$layout_result = $this->layout_idea_board_notes( $notes, $arrows );
		$notes         = $layout_result['notes'];

		$payload = array(
			'status' => 'ok',
			'board'  => array(
				'id'              => 'store-idea-board',
				'title'           => __( 'Idea board', 'woocommerce-claude' ),
				'period'          => array(
					'start'      => $dates['start'],
					'end'        => $dates['end'],
					'label'      => sprintf(
						/* translators: %d: number of days. */
						__( 'Last %d days', 'woocommerce-claude' ),
						$days
					),
					'days'       => $days,
					'comparison' => __( 'Compared with the previous matching period', 'woocommerce-claude' ),
				),
				'currency'        => get_woocommerce_currency(),
				'headlineMetrics' => array(
					'net_sales'           => isset( $revenue['metrics']['net_sales'] ) ? (float) $revenue['metrics']['net_sales'] : 0.0,
					'orders_count'        => isset( $orders['metrics']['orders_count'] ) ? (int) $orders['metrics']['orders_count'] : 0,
					'average_order_value' => isset( $revenue['metrics']['average_order_value'] ) ? (float) $revenue['metrics']['average_order_value'] : 0.0,
					'total_customers'     => isset( $customers['metrics']['total_customers'] ) ? (int) $customers['metrics']['total_customers'] : 0,
				),
				'notes'           => $notes,
				'arrows'          => $arrows,
				'content'         => array(
					'source' => $content['source'],
				),
				'layout'          => array(
					'source' => $layout_result['source'],
					'board'  => array(
						'width'  => self::CANVAS_WIDTH,
						'height' => self::CANVAS_HEIGHT,
					),
					'card'   => array(
						'width'  => self::CARD_WIDTH,
						'height' => self::CARD_HEIGHT,
						'gap'    => self::CARD_GAP,
					),
				),
				'generatedAt'     => current_datetime()->format( DATE_ATOM ),
			),
		);

		set_transient( $cache_key, $payload, 30 * MINUTE_IN_SECONDS );

		return $payload;
	}

	/**
	 * Clear the cached analytics slices used by the idea board.
	 *
	 * The board is only a composition layer; the underlying analytics helpers
	 * also cache their responses. A merchant pressing Refresh expects newly
	 * imported or newly created orders to show up immediately, so the forced
	 * refresh path clears both levels before rebuilding the board.
	 *
	 * @return void
	 */
	private function clear_idea_board_analytics_caches() {
		global $wpdb;

		$prefixes = array(
			'woocommerce_claude_difm_idea_board_',
			'woocommerce_claude_revenue_',
			'woocommerce_claude_orders_',
			'woocommerce_claude_products_',
			'woocommerce_claude_customers_',
			'woocommerce_claude_catalogue_size',
			'woocommerce_claude_sku_count',
			'woocommerce_claude_readiness_',
		);

		foreach ( $prefixes as $prefix ) {
			$like         = $wpdb->esc_like( '_transient_' . $prefix ) . '%';
			$timeout_like = $wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%';
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$like,
					$timeout_like
				)
			);
		}
	}

	/**
	 * Return the trailing date range for the board.
	 *
	 * @param int $days Number of trailing days.
	 * @return array
	 */
	private function get_idea_board_dates( $days ) {
		$today = current_datetime();
		$start = $today->modify( '-' . ( $days - 1 ) . ' days' );

		return array(
			'start' => $start->format( 'Y-m-d' ),
			'end'   => $today->format( 'Y-m-d' ),
		);
	}

	/**
	 * Build board content from AI-generated cards.
	 *
	 * @param array $revenue   Revenue payload.
	 * @param array $orders    Orders payload.
	 * @param array $products  Product payload.
	 * @param array $customers Customer payload.
	 * @param array $profile   Store profile payload.
	 * @param array $recs      Readiness recommendations payload.
	 * @param int   $days      Number of trailing days.
	 * @return array|\WP_Error
	 */
	private function build_idea_board_content( array $revenue, array $orders, array $products, array $customers, array $profile, array $recs, $days ) {
		$ai_content = $this->request_ai_idea_board_content( $revenue, $orders, $products, $customers, $profile, $recs, $days );
		if ( is_wp_error( $ai_content ) ) {
			return $ai_content;
		}

		return array(
			'notes'  => $ai_content['notes'],
			'arrows' => $ai_content['arrows'],
			'source' => 'ai',
		);
	}

	/**
	 * Return the store profile for the idea-board prompt.
	 *
	 * @return array
	 */
	private function get_idea_board_store_profile() {
		$response = StoreController::get_profile();
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : $response;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Return readiness recommendations for the idea-board prompt.
	 *
	 * @return array
	 */
	private function get_idea_board_recommendations() {
		$response = ReadinessController::get_recommendations();
		$data     = $response instanceof \WP_REST_Response ? $response->get_data() : $response;

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Request AI-generated board cards and arrows from store context.
	 *
	 * @param array $revenue   Revenue payload.
	 * @param array $orders    Orders payload.
	 * @param array $products  Product payload.
	 * @param array $customers Customer payload.
	 * @param array $profile   Store profile payload.
	 * @param array $recs      Readiness recommendations payload.
	 * @param int   $days      Number of trailing days.
	 * @return array|\WP_Error
	 */
	private function request_ai_idea_board_content( array $revenue, array $orders, array $products, array $customers, array $profile, array $recs, $days ) {
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		$client   = new AnthropicClient();
		$response = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => $this->build_idea_board_content_prompt( $revenue, $orders, $products, $customers, $profile, $recs, $days ),
				),
			),
			$this->build_idea_board_content_system_prompt(),
			array(),
			1800
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->extract_text_reply( isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array() );
		$json = $this->extract_json_object( $text );
		if ( '' === $json ) {
			return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response did not include JSON.', 'woocommerce-claude' ) );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['notes'] ) || ! is_array( $decoded['notes'] ) ) {
			return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response used an unexpected shape.', 'woocommerce-claude' ) );
		}

		return $this->normalise_idea_board_content( $decoded );
	}

	/**
	 * Build the system prompt for AI-generated board cards.
	 *
	 * @return string
	 */
	private function build_idea_board_content_system_prompt() {
		return 'You create brainstorming-board sticky notes for a WooCommerce merchant. '
			. 'Use only the supplied aggregated analytics, store profile, country/location context, and readiness recommendations. '
			. 'Do not invent customer-level details or include names, emails, addresses, order IDs, or other PII. '
			. 'Create cards that help the merchant brainstorm revenue growth: key insights, initial ideas, and open questions. '
			. 'Prefer store-specific notes over generic advice, and use location context when it is genuinely useful. '
			. 'Do not suggest building plugins, custom endpoints, or developer-only work. '
			. 'Return only valid JSON in this exact shape: {"notes":[{"id":"short-slug","type":"insight|idea|question","title":"...","body":"...","colour":"yellow|pink|green|blue|orange|lime|white","prompt":"...","confidence":"low|medium|high"}],"arrows":[{"from":"note-id","to":"note-id","label":"..."}]}. '
			. 'Return 5 to 8 notes, including at least 2 insights, 2 ideas, and 1 question. Titles must be under 55 characters and bodies under 145 characters.';
	}

	/**
	 * Build the user prompt for AI-generated board cards.
	 *
	 * @param array $revenue   Revenue payload.
	 * @param array $orders    Orders payload.
	 * @param array $products  Product payload.
	 * @param array $customers Customer payload.
	 * @param array $profile   Store profile payload.
	 * @param array $recs      Readiness recommendations payload.
	 * @param int   $days      Number of trailing days.
	 * @return string
	 */
	private function build_idea_board_content_prompt( array $revenue, array $orders, array $products, array $customers, array $profile, array $recs, $days ) {
		$store_name = get_bloginfo( 'name' );
		$country    = $this->get_store_country_name();

		return wp_json_encode(
			array(
				'merchant_prompt'           => sprintf(
					'I am a store owner of %1$s in %2$s and looking to brainstorm some ideas on what we can do as a store to increase revenue. This will be displayed on a brainstorming board. I am looking for a couple key insights, including insights that may relate to my store location, plus a couple initial questions and ideas.',
					$store_name,
					$country
				),
				'period_days'               => $days,
				'store'                     => array(
					'name'    => $store_name,
					'country' => $country,
					'profile' => $this->limit_nested_payload( $profile, 6000 ),
				),
				'analytics'                 => array(
					'revenue'   => $this->limit_nested_payload( $revenue, 3500 ),
					'orders'    => $this->limit_nested_payload( $orders, 3500 ),
					'products'  => $this->limit_nested_payload( $products, 3500 ),
					'customers' => $this->limit_nested_payload( $customers, 2500 ),
				),
				'readiness_recommendations' => $this->limit_nested_payload( $recs, 4500 ),
				'allowed_note_types'        => array( 'insight', 'idea', 'question' ),
				'allowed_colours'           => array( 'yellow', 'pink', 'green', 'blue', 'orange', 'lime', 'white' ),
			)
		);
	}

	/**
	 * Return a compact country name for store context.
	 *
	 * @return string
	 */
	private function get_store_country_name() {
		$default_country = (string) get_option( 'woocommerce_default_country', '' );
		$country_code    = '' !== $default_country ? explode( ':', $default_country )[0] : '';

		if ( function_exists( 'WC' ) && WC()->countries && isset( WC()->countries->countries[ $country_code ] ) ) {
			return (string) WC()->countries->countries[ $country_code ];
		}

		return '' !== $country_code ? $country_code : __( 'the store market', 'woocommerce-claude' );
	}

	/**
	 * Limit nested prompt payload size without hand-parsing source data.
	 *
	 * @param array $payload Payload to limit.
	 * @param int   $limit   Maximum JSON character length.
	 * @return array
	 */
	private function limit_nested_payload( array $payload, $limit ) {
		$json = wp_json_encode( $payload );
		if ( ! is_string( $json ) || strlen( $json ) <= $limit ) {
			return $payload;
		}

		return array(
			'truncated' => true,
			'summary'   => substr( $json, 0, $limit ),
		);
	}

	/**
	 * Layout notes on the visual board.
	 *
	 * When a key is configured, the model receives only the already-built card
	 * metadata and canonical board/card dimensions, then returns coordinates.
	 * Validation keeps the response constrained to the original card set.
	 *
	 * @param array $notes  Board notes.
	 * @param array $arrows Board arrows.
	 * @return array
	 */
	private function layout_idea_board_notes( array $notes, array $arrows ) {
		if ( AnthropicClient::has_api_key() ) {
			$ai_notes = $this->request_ai_idea_board_layout( $notes, $arrows );
			if ( ! is_wp_error( $ai_notes ) ) {
				return array(
					'notes'  => $ai_notes,
					'source' => 'ai',
				);
			}
		}

		return array(
			'notes'  => $this->create_algorithmic_idea_board_layout( $notes ),
			'source' => 'fallback',
		);
	}

	/**
	 * Normalise AI-generated board content into the public note shape.
	 *
	 * @param array $decoded Decoded model response.
	 * @return array|\WP_Error
	 */
	private function normalise_idea_board_content( array $decoded ) {
		$allowed_types   = array( 'insight', 'idea', 'question' );
		$allowed_colours = array( 'yellow', 'pink', 'green', 'blue', 'orange', 'lime', 'white' );
		$notes           = array();
		$seen_ids        = array();
		$type_counts     = array(
			'insight'  => 0,
			'idea'     => 0,
			'question' => 0,
		);

		foreach ( $decoded['notes'] as $note ) {
			if ( ! is_array( $note ) || ! isset( $note['id'], $note['type'], $note['title'], $note['body'], $note['colour'], $note['prompt'] ) ) {
				return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response omitted a required note field.', 'woocommerce-claude' ) );
			}

			$id = sanitize_key( (string) $note['id'] );
			if ( '' === $id || isset( $seen_ids[ $id ] ) ) {
				return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response repeated or omitted a note ID.', 'woocommerce-claude' ) );
			}

			$type   = sanitize_key( (string) $note['type'] );
			$colour = sanitize_key( (string) $note['colour'] );
			if ( ! in_array( $type, $allowed_types, true ) || ! in_array( $colour, $allowed_colours, true ) ) {
				return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response used an unsupported note type or colour.', 'woocommerce-claude' ) );
			}

			$title  = $this->trim_note_text( $note['title'], 70 );
			$body   = $this->trim_note_text( $note['body'], 190 );
			$prompt = $this->trim_note_text( $note['prompt'], 220 );
			if ( '' === $title || '' === $body || '' === $prompt ) {
				return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response included an empty note.', 'woocommerce-claude' ) );
			}

			$confidence = isset( $note['confidence'] ) ? sanitize_key( (string) $note['confidence'] ) : 'medium';
			if ( ! in_array( $confidence, array( 'low', 'medium', 'high' ), true ) ) {
				$confidence = 'medium';
			}

			$seen_ids[ $id ] = true;
			++$type_counts[ $type ];

			$notes[] = array(
				'id'         => $id,
				'type'       => $type,
				'title'      => $title,
				'body'       => $body,
				'colour'     => $colour,
				'x'          => 0,
				'y'          => 0,
				'rotation'   => $this->default_note_rotation( count( $notes ) ),
				'prompt'     => $prompt,
				'confidence' => $confidence,
			);
		}

		if ( count( $notes ) < 5 || count( $notes ) > 8 || $type_counts['insight'] < 2 || $type_counts['idea'] < 2 || $type_counts['question'] < 1 ) {
			return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response did not include the required mix of notes.', 'woocommerce-claude' ) );
		}

		$arrows = $this->normalise_idea_board_arrows( isset( $decoded['arrows'] ) && is_array( $decoded['arrows'] ) ? $decoded['arrows'] : array(), $seen_ids );
		if ( empty( $arrows ) ) {
			$arrows = $this->build_generic_idea_board_arrows( $notes );
		}

		return array(
			'notes'  => $notes,
			'arrows' => $arrows,
		);
	}

	/**
	 * Trim model-provided text for a note field.
	 *
	 * @param mixed $value Text value.
	 * @param int   $limit Character limit.
	 * @return string
	 */
	private function trim_note_text( $value, $limit ) {
		$text = trim( wp_strip_all_tags( (string) $value ) );
		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		return rtrim( substr( $text, 0, $limit - 3 ) ) . '...';
	}

	/**
	 * Return a modest default rotation by note index.
	 *
	 * @param int $index Note index.
	 * @return int
	 */
	private function default_note_rotation( $index ) {
		$rotations = array( -2, 1, -1, 2, -2, 1, 0, 2 );
		return $rotations[ $index % count( $rotations ) ];
	}

	/**
	 * Normalise AI-generated arrow rows.
	 *
	 * @param array $arrows   Raw arrows.
	 * @param array $seen_ids Valid note IDs.
	 * @return array
	 */
	private function normalise_idea_board_arrows( array $arrows, array $seen_ids ) {
		$normalised = array();
		$seen_edges = array();

		foreach ( $arrows as $arrow ) {
			if ( ! is_array( $arrow ) || empty( $arrow['from'] ) || empty( $arrow['to'] ) ) {
				continue;
			}

			$from = sanitize_key( (string) $arrow['from'] );
			$to   = sanitize_key( (string) $arrow['to'] );
			$key  = $from . '->' . $to;
			if ( $from === $to || ! isset( $seen_ids[ $from ], $seen_ids[ $to ] ) || isset( $seen_edges[ $key ] ) ) {
				continue;
			}

			$seen_edges[ $key ] = true;
			$normalised[]       = array(
				'from'  => $from,
				'to'    => $to,
				'label' => isset( $arrow['label'] ) ? $this->trim_note_text( $arrow['label'], 30 ) : '',
			);
		}

		return array_slice( $normalised, 0, 8 );
	}

	/**
	 * Build generic arrows for dynamic note IDs.
	 *
	 * @param array $notes Board notes.
	 * @return array
	 */
	private function build_generic_idea_board_arrows( array $notes ) {
		$questions = array_values(
			array_filter(
				$notes,
				static function ( $note ) {
					return isset( $note['type'] ) && 'question' === $note['type'];
				}
			)
		);

		if ( empty( $questions ) ) {
			return array();
		}

		$target = $questions[0]['id'];
		$arrows = array();
		foreach ( $notes as $note ) {
			if ( ! isset( $note['id'], $note['type'] ) || $target === $note['id'] ) {
				continue;
			}

			$arrows[] = array(
				'from'  => $note['id'],
				'to'    => $target,
				'label' => 'connect',
			);

			if ( count( $arrows ) >= 6 ) {
				break;
			}
		}

		return $arrows;
	}

	/**
	 * Request AI coordinates for the fixed card set.
	 *
	 * @param array $notes  Board notes.
	 * @param array $arrows Board arrows.
	 * @return array|\WP_Error Notes with AI coordinates, or error.
	 */
	private function request_ai_idea_board_layout( array $notes, array $arrows ) {
		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		$client   = new AnthropicClient();
		$response = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => $this->build_idea_board_layout_prompt( $notes, $arrows ),
				),
			),
			$this->build_idea_board_layout_system_prompt(),
			array(),
			1200
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->extract_text_reply( isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array() );
		$json = $this->extract_json_object( $text );
		if ( '' === $json ) {
			return new \WP_Error( 'invalid_layout_response', __( 'The idea-board layout response did not include JSON.', 'woocommerce-claude' ) );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) || empty( $decoded['positions'] ) || ! is_array( $decoded['positions'] ) ) {
			return new \WP_Error( 'invalid_layout_response', __( 'The idea-board layout response used an unexpected shape.', 'woocommerce-claude' ) );
		}

		$positions = $this->normalise_idea_board_layout_positions( $decoded['positions'] );
		if ( is_wp_error( $positions ) ) {
			return $positions;
		}

		$validation = $this->validate_idea_board_layout_positions( $notes, $positions );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		return $this->apply_idea_board_positions( $notes, $positions );
	}

	/**
	 * Build the system prompt for AI-only card layout.
	 *
	 * @return string
	 */
	private function build_idea_board_layout_system_prompt() {
		return 'You lay out sticky-note cards on a WooCommerce store brainstorming board. '
			. 'You do not write, edit, add, remove, rename, merge, or omit cards. '
			. 'Your only job is to return top-left x/y coordinates for every provided card ID. '
			. 'Use the card titles, bodies, types, colours, and arrows to group related cards. '
			. 'Keep cards readable, avoid card overlap, leave whitespace, and keep related cards near each other. '
			. 'Return only valid JSON in this exact shape: {"positions":[{"id":"card-id","x":12.3,"y":45.6}]}. '
			. 'Coordinates are percentages of the board, measured from the top-left of each card.';
	}

	/**
	 * Build the user prompt for AI-only card layout.
	 *
	 * @param array $notes  Board notes.
	 * @param array $arrows Board arrows.
	 * @return string
	 */
	private function build_idea_board_layout_prompt( array $notes, array $arrows ) {
		$cards = array();
		foreach ( $notes as $note ) {
			$cards[] = array(
				'id'     => $note['id'],
				'type'   => $note['type'],
				'title'  => $note['title'],
				'body'   => $note['body'],
				'colour' => $note['colour'],
			);
		}

		return wp_json_encode(
			array(
				'board'       => array(
					'width'  => self::CANVAS_WIDTH,
					'height' => self::CANVAS_HEIGHT,
				),
				'card'        => array(
					'width'  => self::CARD_WIDTH,
					'height' => self::CARD_HEIGHT,
					'gap'    => self::CARD_GAP,
				),
				'constraints' => array(
					'must_return_every_card_id_once' => true,
					'must_not_add_or_remove_cards'   => true,
					'must_not_overlap_cards'         => true,
					'x_range_percent'                => array( self::MIN_X_PERCENT, self::MAX_X_PERCENT ),
					'y_range_percent'                => array( self::MIN_Y_PERCENT, self::MAX_Y_PERCENT ),
				),
				'cards'       => $cards,
				'arrows'      => $arrows,
			)
		);
	}

	/**
	 * Extract the first JSON object from a model response.
	 *
	 * @param string $text Response text.
	 * @return string
	 */
	private function extract_json_object( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return '';
		}

		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return '';
		}

		return substr( $text, $start, ( $end - $start ) + 1 );
	}

	/**
	 * Normalise AI layout rows into an ID-keyed map.
	 *
	 * @param array $positions Raw positions.
	 * @return array|\WP_Error
	 */
	private function normalise_idea_board_layout_positions( array $positions ) {
		$normalised = array();
		foreach ( $positions as $position ) {
			if ( ! is_array( $position ) || ! isset( $position['id'], $position['x'], $position['y'] ) ) {
				return new \WP_Error( 'invalid_layout_response', __( 'The idea-board layout response omitted a required coordinate field.', 'woocommerce-claude' ) );
			}

			$id = sanitize_key( (string) $position['id'] );
			if ( '' === $id || isset( $normalised[ $id ] ) ) {
				return new \WP_Error( 'invalid_layout_response', __( 'The idea-board layout response repeated or omitted a card ID.', 'woocommerce-claude' ) );
			}

			$normalised[ $id ] = array(
				'x' => round( (float) $position['x'], 1 ),
				'y' => round( (float) $position['y'], 1 ),
			);
		}

		return $normalised;
	}

	/**
	 * Validate that coordinates keep exactly the original cards and avoid overlap.
	 *
	 * @param array $notes     Board notes.
	 * @param array $positions ID-keyed positions.
	 * @return true|\WP_Error
	 */
	private function validate_idea_board_layout_positions( array $notes, array $positions ) {
		$note_ids     = wp_list_pluck( $notes, 'id' );
		$expected_ids = array_fill_keys( $note_ids, true );
		$actual_ids   = array_fill_keys( array_keys( $positions ), true );
		ksort( $expected_ids );
		ksort( $actual_ids );

		if ( $expected_ids !== $actual_ids ) {
			return new \WP_Error( 'invalid_layout_response', __( 'The idea-board layout response changed the card set.', 'woocommerce-claude' ) );
		}

		$min_x = self::MIN_X_PERCENT;
		$min_y = self::MIN_Y_PERCENT;
		$max_x = self::MAX_X_PERCENT;
		$max_y = self::MAX_Y_PERCENT;

		$boxes = array();
		foreach ( $positions as $id => $position ) {
			if ( $position['x'] < $min_x || $position['x'] > $max_x || $position['y'] < $min_y || $position['y'] > $max_y ) {
				return new \WP_Error( 'invalid_layout_response', __( 'The idea-board layout response placed a card outside the board.', 'woocommerce-claude' ) );
			}

			$left = $this->board_x_percent_to_pixels( $position['x'] );
			$top  = $this->board_y_percent_to_pixels( $position['y'] );

			$boxes[ $id ] = array(
				'left'   => $left,
				'top'    => $top,
				'right'  => $left + self::CARD_WIDTH,
				'bottom' => $top + self::CARD_HEIGHT,
			);
		}

		$ids   = array_keys( $boxes );
		$count = count( $ids );
		for ( $i = 0; $i < $count; $i++ ) {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( $this->idea_board_boxes_overlap( $boxes[ $ids[ $i ] ], $boxes[ $ids[ $j ] ] ) ) {
					return new \WP_Error( 'invalid_layout_response', __( 'The idea-board layout response overlapped cards.', 'woocommerce-claude' ) );
				}
			}
		}

		return true;
	}

	/**
	 * Apply an ID-keyed coordinate map to notes.
	 *
	 * @param array $notes     Board notes.
	 * @param array $positions ID-keyed positions.
	 * @return array
	 */
	private function apply_idea_board_positions( array $notes, array $positions ) {
		foreach ( $notes as $index => $note ) {
			if ( isset( $note['id'], $positions[ $note['id'] ] ) ) {
				$notes[ $index ]['x'] = $positions[ $note['id'] ]['x'];
				$notes[ $index ]['y'] = $positions[ $note['id'] ]['y'];
			}
		}

		return $notes;
	}

	/**
	 * Generic non-AI packing fallback.
	 *
	 * This is intentionally not a per-card placement map. It groups by note
	 * type, then distributes cards evenly within each group. Card content still
	 * comes from AI; this only protects the visual layout when coordinates fail.
	 *
	 * @param array $notes Board notes.
	 * @return array
	 */
	private function create_algorithmic_idea_board_layout( array $notes ) {
		if ( empty( $notes ) ) {
			return $notes;
		}

		$groups = array(
			'insight'  => array(),
			'question' => array(),
			'idea'     => array(),
			'other'    => array(),
		);

		foreach ( $notes as $index => $note ) {
			$type = isset( $note['type'], $groups[ $note['type'] ] ) ? $note['type'] : 'other';

			$groups[ $type ][] = $index;
		}

		$columns = array_filter(
			array(
				$groups['insight'],
				array_merge( $groups['question'], $groups['other'] ),
				$groups['idea'],
			)
		);

		$column_count = count( $columns );
		$min_x        = self::MIN_X_PERCENT;
		$max_x        = self::MAX_X_PERCENT;

		foreach ( array_values( $columns ) as $column_index => $note_indexes ) {
			$x = 1 === $column_count
				? ( $min_x + $max_x ) / 2
				: $min_x + ( ( $max_x - $min_x ) * ( $column_index / ( $column_count - 1 ) ) );

			$row_count = count( $note_indexes );
			$min_y     = self::MIN_Y_PERCENT;
			$max_y     = self::MAX_Y_PERCENT;

			foreach ( $note_indexes as $row_index => $note_index ) {
				$y = 1 === $row_count
					? ( $min_y + $max_y ) / 2
					: $min_y + ( ( $max_y - $min_y ) * ( $row_index / ( $row_count - 1 ) ) );

				$notes[ $note_index ]['x'] = round( $x, 1 );
				$notes[ $note_index ]['y'] = round( $y, 1 );
			}
		}

		return $notes;
	}

	/**
	 * Check whether two canonical card boxes overlap.
	 *
	 * @param array $a First box.
	 * @param array $b Second box.
	 * @return bool
	 */
	private function idea_board_boxes_overlap( array $a, array $b ) {
		$gap = 12;
		return ! (
			$a['right'] + $gap <= $b['left']
			|| $b['right'] + $gap <= $a['left']
			|| $a['bottom'] + $gap <= $b['top']
			|| $b['bottom'] + $gap <= $a['top']
		);
	}

	/**
	 * Convert board X percentage to canonical pixels.
	 *
	 * @param float $value Percentage.
	 * @return float
	 */
	private function board_x_percent_to_pixels( $value ) {
		return ( (float) $value / 100 ) * self::CANVAS_WIDTH;
	}

	/**
	 * Convert board Y percentage to canonical pixels.
	 *
	 * @param float $value Percentage.
	 * @return float
	 */
	private function board_y_percent_to_pixels( $value ) {
		return ( (float) $value / 100 ) * self::CANVAS_HEIGHT;
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
}
