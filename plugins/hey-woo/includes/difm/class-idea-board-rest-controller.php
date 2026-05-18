<?php
/**
 * REST controller for the AI Insights idea board.
 *
 * Routes:
 *   GET /woocommerce-claude/v1/difm/idea-board - Return the saved board or generate one.
 *   POST /woocommerce-claude/v1/difm/idea-board/save - Save the merchant-edited board.
 *   POST /woocommerce-claude/v1/difm/idea-board/brainstorm - Start a brainstorm session from selected insights.
 *   POST /woocommerce-claude/v1/difm/idea-board/answer-question - Add an AI answer to a brainstorm question.
 *   POST /woocommerce-claude/v1/difm/idea-board/reanalyse - Re-analyse the merchant-edited board.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

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
	const NAMESPACE = 'hey-woo/v1';

	/**
	 * Idea board route.
	 */
	const ROUTE = '/difm/idea-board';

	/**
	 * Idea board save route.
	 */
	const SAVE_ROUTE = '/difm/idea-board/save';

	/**
	 * Idea board brainstorm route.
	 */
	const BRAINSTORM_ROUTE = '/difm/idea-board/brainstorm';

	/**
	 * Idea board AI question-answer route.
	 */
	const ANSWER_QUESTION_ROUTE = '/difm/idea-board/answer-question';

	/**
	 * Idea board re-analysis route.
	 */
	const REANALYSE_ROUTE = '/difm/idea-board/reanalyse';

	/**
	 * Option storing the merchant's latest saved idea board.
	 */
	const SAVED_BOARD_OPTION = 'woocommerce_claude_difm_idea_board_saved';

	/**
	 * Version marker for saved idea-board payloads.
	 */
	const CACHE_VERSION = '2026-05-15-commercial-decision-layer-v1';

	/**
	 * Minimum supported trailing period for the idea board.
	 */
	const MIN_IDEA_BOARD_DAYS = 7;

	/**
	 * Maximum supported trailing period for the idea board.
	 */
	const MAX_IDEA_BOARD_DAYS = 365;

	/**
	 * Maximum number of cards accepted in a merchant-edited board.
	 */
	const MAX_CARDS = 24;

	/**
	 * Maximum number of saved brainstorm sessions accepted in a board.
	 */
	const MAX_SESSIONS = 12;

	/**
	 * Maximum number of saved merchant notes accepted in a board.
	 */
	const MAX_NOTES = 80;

	/**
	 * Maximum number of initial insight cards returned by AI.
	 */
	const MAX_INITIAL_CARDS = 6;

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
							'minimum'           => self::MIN_IDEA_BOARD_DAYS,
							'maximum'           => self::MAX_IDEA_BOARD_DAYS,
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

		register_rest_route(
			self::NAMESPACE,
			self::SAVE_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_idea_board' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'board' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::BRAINSTORM_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'brainstorm_idea_board' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'board'          => array(
							'type'     => 'object',
							'required' => true,
						),
						'rootInsightIds' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type' => 'string',
							),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ANSWER_QUESTION_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'answer_idea_board_question' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'board'  => array(
							'type'     => 'object',
							'required' => true,
						),
						'cardId' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::REANALYSE_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'reanalyse_idea_board' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'board' => array(
							'type'     => 'object',
							'required' => true,
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
	 * GET /woocommerce-claude/v1/difm/idea-board - saved or AI-generated decision board.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function get_idea_board( \WP_REST_Request $request ) {
		$days    = $this->normalise_idea_board_days( $request->get_param( 'days' ) );
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
	 * POST /woocommerce-claude/v1/difm/idea-board/save - persist the submitted board.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function save_idea_board( \WP_REST_Request $request ) {
		$raw_board = $request->get_param( 'board' );
		if ( ! is_array( $raw_board ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'The submitted idea board used an unexpected shape.', 'woocommerce-claude' ),
				)
			);
		}

		// Optimistic concurrency: reject the save if another session already wrote
		// a newer version of the board since this client last loaded it.
		$client_saved_at = isset( $raw_board['savedAt'] ) ? (string) $raw_board['savedAt'] : '';
		if ( '' !== $client_saved_at ) {
			$stored = get_option( self::SAVED_BOARD_OPTION, array() );
			$stored_saved_at = isset( $stored['savedAt'] ) ? (string) $stored['savedAt'] : '';
			if ( '' !== $stored_saved_at && $stored_saved_at !== $client_saved_at ) {
				return rest_ensure_response(
					array(
						'status'  => 'error',
						'code'    => 'conflict',
						'message' => __( 'The board was modified by another session. Please refresh and try again.', 'woocommerce-claude' ),
					)
				);
			}
		}

		$board = $this->normalise_submitted_idea_board( $raw_board, 0 );
		if ( is_wp_error( $board ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $board->get_error_message(),
				)
			);
		}

		$payload = array(
			'status' => 'ok',
			'board'  => $board,
		);
		$this->save_idea_board_payload( $payload );

		$days  = $this->normalise_idea_board_days( isset( $board['period']['days'] ) ? (int) $board['period']['days'] : 90 );
		$dates = $this->get_idea_board_dates( $days );

		return rest_ensure_response( $this->add_idea_board_freshness( $payload, $days, $dates ) );
	}

	/**
	 * POST /woocommerce-claude/v1/difm/idea-board/brainstorm - start a brainstorm session.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function brainstorm_idea_board( \WP_REST_Request $request ) {
		if ( ! AnthropicClient::has_api_key() ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'Add an Anthropic API key to start a brainstorm session.', 'woocommerce-claude' ),
				)
			);
		}

		$raw_board = $request->get_param( 'board' );
		if ( ! is_array( $raw_board ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'The submitted idea board used an unexpected shape.', 'woocommerce-claude' ),
				)
			);
		}

		$board = $this->normalise_submitted_idea_board( $raw_board );
		if ( is_wp_error( $board ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $board->get_error_message(),
				)
			);
		}

		$root_insight_ids = $this->normalise_brainstorm_root_insight_ids( $request->get_param( 'rootInsightIds' ), $board['cards'] );
		if ( is_wp_error( $root_insight_ids ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $root_insight_ids->get_error_message(),
				)
			);
		}

		$payload = $this->build_brainstorm_idea_board_payload( $board, $root_insight_ids );
		if ( is_wp_error( $payload ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $payload->get_error_message(),
				)
			);
		}

		$response = array(
			'status' => 'ok',
			'board'  => $payload,
		);
		$this->save_idea_board_payload( $response );

		$days  = $this->normalise_idea_board_days( isset( $payload['period']['days'] ) ? (int) $payload['period']['days'] : 90 );
		$dates = $this->get_idea_board_dates( $days );

		return rest_ensure_response( $this->add_idea_board_freshness( $response, $days, $dates ) );
	}

	/**
	 * POST /woocommerce-claude/v1/difm/idea-board/answer-question - add an AI answer to a question card.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function answer_idea_board_question( \WP_REST_Request $request ) {
		if ( ! AnthropicClient::has_api_key() ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'Add an Anthropic API key to answer a question with AI.', 'woocommerce-claude' ),
				)
			);
		}

		$raw_board = $request->get_param( 'board' );
		if ( ! is_array( $raw_board ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'The submitted idea board used an unexpected shape.', 'woocommerce-claude' ),
				)
			);
		}

		$board = $this->normalise_submitted_idea_board( $raw_board );
		if ( is_wp_error( $board ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $board->get_error_message(),
				)
			);
		}

		$card_id  = sanitize_key( (string) $request->get_param( 'cardId' ) );
		$question = $this->find_idea_board_card_by_id( $board['cards'], $card_id );
		if ( ! is_array( $question ) || 'question' !== $question['kind'] || empty( $question['sessionId'] ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'Choose a valid brainstorm question for AI to answer.', 'woocommerce-claude' ),
				)
			);
		}

		if ( ! $this->is_idea_board_question_store_related( $question ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'Ask a question about the store, products, customers, orders, marketing, operations, or the selected insight before using AI to answer it.', 'woocommerce-claude' ),
				)
			);
		}

		$session = $this->find_idea_board_session_by_id( $board['sessions'], $question['sessionId'] );
		if ( ! is_array( $session ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'The selected question is not linked to a saved brainstorm session.', 'woocommerce-claude' ),
				)
			);
		}

		$answer = $this->request_ai_idea_board_question_answer( $board, $question );
		if ( is_wp_error( $answer ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $answer->get_error_message(),
				)
			);
		}

		$now      = current_datetime()->format( DATE_ATOM );
		$root_ids = ! empty( $question['rootInsightIds'] ) && is_array( $question['rootInsightIds'] )
			? $question['rootInsightIds']
			: $session['rootInsightIds'];
		$note     = array(
			'id'             => $this->unique_idea_board_note_id( $question['title'], $board['notes'] ),
			'sessionId'      => $question['sessionId'],
			'rootInsightIds' => $root_ids,
			'parentCardId'   => $question['id'],
			'body'           => $answer,
			'kind'           => 'answer',
			'createdBy'      => 'ai',
			'authorName'     => __( 'AI', 'woocommerce-claude' ),
			'createdAt'      => $now,
		);

		$board['notes'][]     = $note;
		$board['sessions']    = array_map(
			static function ( $candidate ) use ( $question, $now ) {
				if ( isset( $candidate['id'] ) && $candidate['id'] === $question['sessionId'] ) {
					$candidate['status']    = 'ready_to_reanalyse';
					$candidate['updatedAt'] = $now;
				}

				return $candidate;
			},
			$board['sessions']
		);
		$board['links']       = $this->build_idea_board_links( $board['cards'] );
		$board['generatedAt'] = $now;

		$response = array(
			'status' => 'ok',
			'board'  => $board,
		);
		$this->save_idea_board_payload( $response );

		$days  = $this->normalise_idea_board_days( isset( $board['period']['days'] ) ? (int) $board['period']['days'] : 90 );
		$dates = $this->get_idea_board_dates( $days );

		return rest_ensure_response( $this->add_idea_board_freshness( $response, $days, $dates ) );
	}

	/**
	 * POST /woocommerce-claude/v1/difm/idea-board/reanalyse - refine the submitted board.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public function reanalyse_idea_board( \WP_REST_Request $request ) {
		if ( ! AnthropicClient::has_api_key() ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'Add an Anthropic API key to re-analyse the idea board.', 'woocommerce-claude' ),
				)
			);
		}

		$raw_board = $request->get_param( 'board' );
		if ( ! is_array( $raw_board ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'The submitted idea board used an unexpected shape.', 'woocommerce-claude' ),
				)
			);
		}

		$board = $this->normalise_submitted_idea_board( $raw_board );
		if ( is_wp_error( $board ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $board->get_error_message(),
				)
			);
		}

		$payload = $this->build_reanalysed_idea_board_payload( $board );
		if ( is_wp_error( $payload ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => $payload->get_error_message(),
				)
			);
		}

		$response = array(
			'status' => 'ok',
			'board'  => $payload,
		);
		$this->save_idea_board_payload( $response );

		$days  = $this->normalise_idea_board_days( isset( $payload['period']['days'] ) ? (int) $payload['period']['days'] : 90 );
		$dates = $this->get_idea_board_dates( $days );

		return rest_ensure_response( $this->add_idea_board_freshness( $response, $days, $dates ) );
	}

	/**
	 * Build the idea-board payload from existing analytics helpers.
	 *
	 * @param int  $days    Number of trailing days to include.
	 * @param bool $refresh Whether to bypass the saved board and cached analytics results.
	 * @return array|\WP_Error Board payload or error.
	 */
	private function build_idea_board_payload( $days, $refresh = false ) {
		$days  = $this->normalise_idea_board_days( $days );
		$dates = $this->get_idea_board_dates( $days );

		if ( ! $refresh ) {
			$saved = $this->get_saved_idea_board_payload( $days, $dates );
			if ( is_array( $saved ) ) {
				return $saved;
			}
		}

		if ( ! AnthropicClient::has_api_key() ) {
			return new \WP_Error(
				'no_anthropic_key',
				__( 'Add an Anthropic API key to generate the idea board.', 'woocommerce-claude' )
			);
		}

		if ( $refresh ) {
			$this->clear_idea_board_analytics_caches();
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
				'columns'         => $this->default_idea_board_columns(),
				'cards'           => $content['cards'],
				'links'           => $this->build_idea_board_links( $content['cards'] ),
				'sessions'        => array(),
				'notes'           => array(),
				'summary'         => $content['summary'],
				'decisionBrief'   => $content['decisionBrief'],
				'content'         => array(
					'source' => isset( $content['source'] ) ? $content['source'] : 'ai',
				),
				'layout'          => array(
					'source' => 'sessions',
				),
				'generatedAt'     => current_datetime()->format( DATE_ATOM ),
			),
		);

		$this->save_idea_board_payload( $payload );

		return $this->add_idea_board_freshness( $payload, $days, $dates );
	}

	/**
	 * Build a re-analysed board from the merchant-edited board state.
	 *
	 * @param array $board Sanitised board payload submitted by the browser.
	 * @return array|\WP_Error Board payload or error.
	 */
	private function build_reanalysed_idea_board_payload( array $board ) {
		$result = $this->request_ai_idea_board_reanalysis( $board );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$board['cards']         = $result['cards'];
		$board['links']         = $this->build_idea_board_links( $result['cards'] );
		$board['sessions']      = $this->mark_sessions_analysed( $board['sessions'], $result['summary'], $result['decisionBrief'] );
		$board['summary']       = $result['summary'];
		$board['decisionBrief'] = $result['decisionBrief'];
		$board['content']       = array(
			'source' => 'ai',
		);
		$board['layout']        = array(
			'source' => 'sessions',
		);
		$board['generatedAt']   = current_datetime()->format( DATE_ATOM );

		return $board;
	}

	/**
	 * Add a brainstorm session and questions to a submitted board.
	 *
	 * @param array $board            Sanitised board payload submitted by the browser.
	 * @param array $root_insight_ids Selected insight card IDs.
	 * @return array|\WP_Error Board payload or error.
	 */
	private function build_brainstorm_idea_board_payload( array $board, array $root_insight_ids ) {
		$result = $this->request_ai_idea_board_brainstorm( $board, $root_insight_ids );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$now        = current_datetime()->format( DATE_ATOM );
		$session_id = $this->unique_idea_board_session_id( $result['session']['title'], $board['sessions'] );
		$session    = array(
			'id'             => $session_id,
			'title'          => $result['session']['title'],
			'rootInsightIds' => $root_insight_ids,
			'summary'        => $result['session']['summary'],
			'decisionBrief'  => $result['session']['decisionBrief'],
			'status'         => 'active',
			'createdAt'      => $now,
			'updatedAt'      => $now,
			'lastAnalysedAt' => $now,
		);

		$seen_ids = array();
		foreach ( $board['cards'] as $card ) {
			$seen_ids[ $card['id'] ] = true;
		}

		$new_cards = array();
		foreach ( $result['cards'] as $index => $card ) {
			if ( count( $board['cards'] ) + count( $new_cards ) >= self::MAX_CARDS ) {
				break;
			}

			if ( empty( $card['id'] ) ) {
				$card['id'] = $this->unique_idea_board_card_id( $card['title'], $seen_ids );
			}
			$card['sessionId']      = $session_id;
			$card['rootInsightIds'] = ! empty( $card['rootInsightIds'] ) && is_array( $card['rootInsightIds'] )
				? $this->normalise_root_insight_ids_list( $card['rootInsightIds'], $root_insight_ids )
				: $root_insight_ids;
			$card['rootInsightId']  = ! empty( $card['rootInsightId'] )
				? $this->normalise_root_insight_id( sanitize_key( (string) $card['rootInsightId'] ), $board['cards'] )
				: $card['rootInsightIds'][0];
			$card['parentCardId']   = isset( $card['parentCardId'] ) ? sanitize_key( (string) $card['parentCardId'] ) : '';
			$card['createdBy']      = 'ai';

			$normalised = $this->normalise_idea_board_card( $card, $index, false, 'invalid_board_brainstorm' );
			if ( is_wp_error( $normalised ) || 'insight' === $normalised['kind'] ) {
				continue;
			}

			$seen_ids[ $normalised['id'] ] = true;
			$new_cards[]                   = $normalised;
		}

		$board['sessions'][]    = $session;
		$board['cards']         = $this->normalise_card_orders( array_merge( $board['cards'], $new_cards ) );
		$board['links']         = $this->build_idea_board_links( $board['cards'] );
		$board['summary']       = $result['summary'];
		$board['decisionBrief'] = $result['decisionBrief'];
		$board['content']       = array(
			'source' => 'ai',
		);
		$board['layout']        = array(
			'source' => 'sessions',
		);
		$board['generatedAt']   = $now;

		return $board;
	}

	/**
	 * Return a saved board payload with current freshness metadata.
	 *
	 * @param int   $days          Requested trailing days.
	 * @param array $current_dates Current date range for the requested trailing days.
	 * @return array|false
	 */
	private function get_saved_idea_board_payload( $days, array $current_dates ) {
		$saved = get_option( self::SAVED_BOARD_OPTION, array() );
		if ( ! is_array( $saved ) || ! isset( $saved['payload'] ) || ! is_array( $saved['payload'] ) ) {
			return false;
		}

		$payload = $saved['payload'];
		$payload = $this->ensure_idea_board_workspace_defaults( $payload );
		if (
			! isset( $payload['status'], $payload['board'] )
			|| 'ok' !== $payload['status']
			|| ! is_array( $payload['board'] )
			|| ! isset( $payload['board']['cards'] )
			|| ! is_array( $payload['board']['cards'] )
		) {
			return false;
		}

		if ( ! isset( $saved['version'] ) || self::CACHE_VERSION !== $saved['version'] ) {
			$this->save_idea_board_payload( $payload );
		}

		return $this->add_idea_board_freshness( $payload, $days, $current_dates );
	}

	/**
	 * Save a board payload permanently.
	 *
	 * @param array $payload Board response payload.
	 * @return void
	 */
	private function save_idea_board_payload( array $payload ) {
		if ( ! isset( $payload['status'], $payload['board'] ) || 'ok' !== $payload['status'] || ! is_array( $payload['board'] ) ) {
			return;
		}

		$payload = $this->ensure_idea_board_workspace_defaults( $payload );
		unset( $payload['board']['freshness'] );

		update_option(
			self::SAVED_BOARD_OPTION,
			array(
				'version'  => self::CACHE_VERSION,
				'savedAt'  => current_datetime()->format( DATE_ATOM ),
				'days'     => isset( $payload['board']['period']['days'] ) ? (int) $payload['board']['period']['days'] : 0,
				'currency' => isset( $payload['board']['currency'] ) ? (string) $payload['board']['currency'] : '',
				'payload'  => $payload,
			),
			false
		);
	}

	/**
	 * Backfill workspace fields for saved boards created by earlier idea-board layouts.
	 *
	 * @param array $payload Board response payload.
	 * @return array
	 */
	private function ensure_idea_board_workspace_defaults( array $payload ) {
		if ( ! isset( $payload['board'] ) || ! is_array( $payload['board'] ) ) {
			return $payload;
		}

		if ( empty( $payload['board']['sessions'] ) || ! is_array( $payload['board']['sessions'] ) ) {
			$payload['board']['sessions'] = array();
		}
		foreach ( $payload['board']['sessions'] as $index => $session ) {
			if ( ! is_array( $session ) ) {
				continue;
			}

			$payload['board']['sessions'][ $index ]['decisionBrief'] = $this->normalise_idea_board_decision_brief( isset( $session['decisionBrief'] ) && is_array( $session['decisionBrief'] ) ? $session['decisionBrief'] : array(), isset( $session['summary'] ) ? (string) $session['summary'] : '' );
		}
		if ( empty( $payload['board']['notes'] ) || ! is_array( $payload['board']['notes'] ) ) {
			$payload['board']['notes'] = array();
		}
		if ( isset( $payload['board']['cards'] ) && is_array( $payload['board']['cards'] ) ) {
			foreach ( $payload['board']['cards'] as $index => $card ) {
				if ( ! is_array( $card ) ) {
					continue;
				}

				$root_insight_id  = isset( $card['rootInsightId'] ) ? sanitize_key( (string) $card['rootInsightId'] ) : '';
				$root_insight_ids = isset( $card['rootInsightIds'] ) && is_array( $card['rootInsightIds'] )
					? $this->normalise_root_insight_ids_list( $card['rootInsightIds'], array() )
					: array();
				if ( '' !== $root_insight_id && ! in_array( $root_insight_id, $root_insight_ids, true ) ) {
					array_unshift( $root_insight_ids, $root_insight_id );
				}

				$payload['board']['cards'][ $index ]['rootInsightId']  = $root_insight_id;
				$payload['board']['cards'][ $index ]['rootInsightIds'] = $root_insight_ids;
				$payload['board']['cards'][ $index ]['sessionId']      = isset( $card['sessionId'] ) ? sanitize_key( (string) $card['sessionId'] ) : '';
				$payload['board']['cards'][ $index ]['parentCardId']   = isset( $card['parentCardId'] ) ? sanitize_key( (string) $card['parentCardId'] ) : '';
				$payload['board']['cards'][ $index ]['x']              = isset( $card['x'] ) && is_numeric( $card['x'] ) ? max( 0, min( 1200, (int) $card['x'] ) ) : 0;
				$payload['board']['cards'][ $index ]['y']              = isset( $card['y'] ) && is_numeric( $card['y'] ) ? max( 0, min( 900, (int) $card['y'] ) ) : 0;
				$payload['board']['cards'][ $index ]['rotation']       = isset( $card['rotation'] ) && is_numeric( $card['rotation'] ) ? max( -6, min( 6, (float) $card['rotation'] ) ) : 0;
				$payload['board']['cards'][ $index ]                   = $this->backfill_idea_board_card_decision_fields( $payload['board']['cards'][ $index ] );
			}
		}
		if ( empty( $payload['board']['columns'] ) || ! is_array( $payload['board']['columns'] ) ) {
			$payload['board']['columns'] = $this->default_idea_board_columns();
		}
		if ( empty( $payload['board']['links'] ) || ! is_array( $payload['board']['links'] ) ) {
			$payload['board']['links'] = isset( $payload['board']['cards'] ) && is_array( $payload['board']['cards'] )
				? $this->build_idea_board_links( $payload['board']['cards'] )
				: array();
		}
		if ( empty( $payload['board']['layout'] ) || ! is_array( $payload['board']['layout'] ) ) {
			$payload['board']['layout'] = array( 'source' => 'sessions' );
		}
		if ( empty( $payload['board']['layout']['source'] ) ) {
			$payload['board']['layout']['source'] = 'sessions';
		}
		$payload['board']['decisionBrief'] = $this->normalise_idea_board_decision_brief( isset( $payload['board']['decisionBrief'] ) && is_array( $payload['board']['decisionBrief'] ) ? $payload['board']['decisionBrief'] : array(), isset( $payload['board']['summary'] ) ? (string) $payload['board']['summary'] : '' );

		return $payload;
	}

	/**
	 * Attach freshness metadata to a board payload.
	 *
	 * @param array $payload       Board response payload.
	 * @param int   $days          Requested trailing days.
	 * @param array $current_dates Current date range for the requested trailing days.
	 * @return array
	 */
	private function add_idea_board_freshness( array $payload, $days, array $current_dates ) {
		if ( ! isset( $payload['board'] ) || ! is_array( $payload['board'] ) ) {
			return $payload;
		}

		$period      = isset( $payload['board']['period'] ) && is_array( $payload['board']['period'] ) ? $payload['board']['period'] : array();
		$period_days = isset( $period['days'] ) ? (int) $period['days'] : 0;
		$is_stale    = $period_days !== (int) $days
			|| ! isset( $period['start'], $period['end'] )
			|| $period['start'] !== $current_dates['start']
			|| $period['end'] !== $current_dates['end'];

		$payload['board']['freshness'] = array(
			'isStale'       => $is_stale,
			'currentPeriod' => array(
				'start' => $current_dates['start'],
				'end'   => $current_dates['end'],
				'label' => sprintf(
					/* translators: %d: number of days. */
					__( 'Last %d days', 'woocommerce-claude' ),
					$days
				),
				'days'  => (int) $days,
			),
			'savedPeriod'   => array(
				'start' => isset( $period['start'] ) ? (string) $period['start'] : '',
				'end'   => isset( $period['end'] ) ? (string) $period['end'] : '',
				'label' => isset( $period['label'] ) ? (string) $period['label'] : '',
				'days'  => $period_days,
			),
		);

		// Expose the server-authoritative savedAt timestamp so the client can
		// send it back on the next save for optimistic concurrency checking.
		$stored = get_option( self::SAVED_BOARD_OPTION, array() );
		if ( isset( $stored['savedAt'] ) ) {
			$payload['board']['savedAt'] = (string) $stored['savedAt'];
		}

		return $payload;
	}

	/**
	 * Clamp board days to the public route bounds.
	 *
	 * @param int $days Requested trailing days.
	 * @return int
	 */
	private function normalise_idea_board_days( $days ) {
		$days = (int) $days;
		if ( $days < self::MIN_IDEA_BOARD_DAYS ) {
			return self::MIN_IDEA_BOARD_DAYS;
		}
		if ( $days > self::MAX_IDEA_BOARD_DAYS ) {
			return self::MAX_IDEA_BOARD_DAYS;
		}

		return $days;
	}

	/**
	 * Clear the cached analytics slices used by the idea board.
	 *
	 * @return void
	 */
	private function clear_idea_board_analytics_caches() {
		global $wpdb;

		// Only clear the date-range analytics transients used to build the idea board.
		// Readiness, catalogue size, and SKU count are store-configuration caches shared
		// with the chat surface and scoring engine — they are not date-ranged and should
		// not be nuked by a board refresh.
		$prefixes = array(
			'woocommerce_claude_difm_idea_board_',
			'woocommerce_claude_revenue_',
			'woocommerce_claude_orders_',
			'woocommerce_claude_products_',
			'woocommerce_claude_customers_',
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
	 * Normalise a merchant-submitted board before saving or sending to AI.
	 *
	 * @param array $board         Raw board payload from the REST request.
	 * @param int   $minimum_cards Minimum accepted card count.
	 * @return array|\WP_Error
	 */
	private function normalise_submitted_idea_board( array $board, $minimum_cards = 1 ) {
		if ( ! isset( $board['cards'] ) || ! is_array( $board['cards'] ) ) {
			return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board must include a card list.', 'woocommerce-claude' ) );
		}

		$cards = $this->normalise_submitted_idea_board_cards( $board['cards'], $minimum_cards );
		if ( is_wp_error( $cards ) ) {
			return $cards;
		}
		$sessions = $this->normalise_idea_board_sessions( isset( $board['sessions'] ) && is_array( $board['sessions'] ) ? $board['sessions'] : array(), $cards );
		if ( is_wp_error( $sessions ) ) {
			return $sessions;
		}
		$notes = $this->normalise_idea_board_notes( isset( $board['notes'] ) && is_array( $board['notes'] ) ? $board['notes'] : array(), $sessions, $cards );
		if ( is_wp_error( $notes ) ) {
			return $notes;
		}

		$id = isset( $board['id'] ) ? sanitize_key( (string) $board['id'] ) : 'store-idea-board';
		if ( '' === $id ) {
			$id = 'store-idea-board';
		}

		$title = isset( $board['title'] ) ? $this->trim_card_text( $board['title'], 90 ) : '';
		if ( '' === $title ) {
			$title = __( 'Idea board', 'woocommerce-claude' );
		}

		return array(
			'id'              => $id,
			'title'           => $title,
			'period'          => $this->normalise_idea_board_period( isset( $board['period'] ) && is_array( $board['period'] ) ? $board['period'] : array() ),
			'currency'        => isset( $board['currency'] ) ? sanitize_text_field( (string) $board['currency'] ) : get_woocommerce_currency(),
			'headlineMetrics' => $this->normalise_idea_board_headline_metrics( isset( $board['headlineMetrics'] ) && is_array( $board['headlineMetrics'] ) ? $board['headlineMetrics'] : array() ),
			'columns'         => $this->default_idea_board_columns(),
			'cards'           => $cards,
			'links'           => $this->build_idea_board_links( $cards ),
			'sessions'        => $sessions,
			'notes'           => $notes,
			'summary'         => isset( $board['summary'] ) ? $this->trim_card_text( $board['summary'], 300 ) : '',
			'decisionBrief'   => $this->normalise_idea_board_decision_brief( isset( $board['decisionBrief'] ) && is_array( $board['decisionBrief'] ) ? $board['decisionBrief'] : array(), isset( $board['summary'] ) ? (string) $board['summary'] : '' ),
			'content'         => array(
				'source' => 'ai',
			),
			'layout'          => array(
				'source' => 'sessions',
			),
			'generatedAt'     => isset( $board['generatedAt'] ) ? sanitize_text_field( (string) $board['generatedAt'] ) : '',
		);
	}

	/**
	 * Normalise submitted card rows.
	 *
	 * @param array $raw_cards     Raw card rows.
	 * @param int   $minimum_cards Minimum accepted card count.
	 * @return array|\WP_Error
	 */
	private function normalise_submitted_idea_board_cards( array $raw_cards, $minimum_cards = 1 ) {
		$count = count( $raw_cards );
		if ( $count < $minimum_cards || $count > self::MAX_CARDS ) {
			if ( 0 === (int) $minimum_cards ) {
				return new \WP_Error(
					'invalid_submitted_board',
					sprintf(
						/* translators: %d: maximum number of cards. */
						__( 'The submitted idea board must include no more than %d cards.', 'woocommerce-claude' ),
						self::MAX_CARDS
					)
				);
			}

			return new \WP_Error(
				'invalid_submitted_board',
				sprintf(
					/* translators: %d: maximum number of cards. */
					__( 'The submitted idea board must include between 1 and %d cards.', 'woocommerce-claude' ),
					self::MAX_CARDS
				)
			);
		}

		$cards    = array();
		$seen_ids = array();
		foreach ( $raw_cards as $index => $card ) {
			$normalised = $this->normalise_idea_board_card( $card, $index, false, 'invalid_submitted_board' );
			if ( is_wp_error( $normalised ) ) {
				return $normalised;
			}

			if ( isset( $seen_ids[ $normalised['id'] ] ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board repeated a card ID.', 'woocommerce-claude' ) );
			}

			$seen_ids[ $normalised['id'] ] = true;
			$cards[]                       = $normalised;
		}

		return $this->normalise_card_orders( $cards );
	}

	/**
	 * Normalise submitted brainstorm sessions.
	 *
	 * @param array $raw_sessions Raw session rows.
	 * @param array $cards        Sanitised board cards.
	 * @return array|\WP_Error
	 */
	private function normalise_idea_board_sessions( array $raw_sessions, array $cards ) {
		if ( count( $raw_sessions ) > self::MAX_SESSIONS ) {
			return new \WP_Error(
				'invalid_submitted_board',
				sprintf(
					/* translators: %d: maximum number of sessions. */
					__( 'The submitted idea board must include no more than %d brainstorm sessions.', 'woocommerce-claude' ),
					self::MAX_SESSIONS
				)
			);
		}

		$insight_ids = $this->insight_card_ids( $cards );
		$sessions    = array();
		$seen_ids    = array();

		foreach ( $raw_sessions as $session ) {
			if ( ! is_array( $session ) || empty( $session['id'] ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board included an invalid brainstorm session.', 'woocommerce-claude' ) );
			}

			$id = sanitize_key( (string) $session['id'] );
			if ( '' === $id || isset( $seen_ids[ $id ] ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board repeated a brainstorm session ID.', 'woocommerce-claude' ) );
			}

			$root_ids = $this->normalise_root_insight_ids_list( isset( $session['rootInsightIds'] ) && is_array( $session['rootInsightIds'] ) ? $session['rootInsightIds'] : array(), $insight_ids );
			if ( empty( $root_ids ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'A brainstorm session must be linked to at least one insight.', 'woocommerce-claude' ) );
			}

			$status = isset( $session['status'] ) ? sanitize_key( (string) $session['status'] ) : 'active';
			if ( ! isset( $this->allowed_session_statuses()[ $status ] ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board included an unsupported session status.', 'woocommerce-claude' ) );
			}

			$title = isset( $session['title'] ) ? $this->trim_card_text( $session['title'], 100 ) : '';
			if ( '' === $title ) {
				$title = __( 'Brainstorm session', 'woocommerce-claude' );
			}

			$seen_ids[ $id ] = true;
			$sessions[]      = array(
				'id'             => $id,
				'title'          => $title,
				'rootInsightIds' => $root_ids,
				'summary'        => isset( $session['summary'] ) ? $this->trim_card_text( $session['summary'], 400 ) : '',
				'decisionBrief'  => $this->normalise_idea_board_decision_brief( isset( $session['decisionBrief'] ) && is_array( $session['decisionBrief'] ) ? $session['decisionBrief'] : array(), isset( $session['summary'] ) ? (string) $session['summary'] : '' ),
				'status'         => $status,
				'createdAt'      => isset( $session['createdAt'] ) ? sanitize_text_field( (string) $session['createdAt'] ) : '',
				'updatedAt'      => isset( $session['updatedAt'] ) ? sanitize_text_field( (string) $session['updatedAt'] ) : '',
				'lastAnalysedAt' => isset( $session['lastAnalysedAt'] ) ? sanitize_text_field( (string) $session['lastAnalysedAt'] ) : '',
			);
		}

		return $sessions;
	}

	/**
	 * Normalise submitted notes and answers.
	 *
	 * @param array $raw_notes Raw note rows.
	 * @param array $sessions  Sanitised sessions.
	 * @param array $cards     Sanitised cards.
	 * @return array|\WP_Error
	 */
	private function normalise_idea_board_notes( array $raw_notes, array $sessions, array $cards ) {
		if ( count( $raw_notes ) > self::MAX_NOTES ) {
			return new \WP_Error(
				'invalid_submitted_board',
				sprintf(
					/* translators: %d: maximum number of notes. */
					__( 'The submitted idea board must include no more than %d notes.', 'woocommerce-claude' ),
					self::MAX_NOTES
				)
			);
		}

		$session_ids = array_fill_keys( wp_list_pluck( $sessions, 'id' ), true );
		$card_ids    = array_fill_keys( wp_list_pluck( $cards, 'id' ), true );
		$insight_ids = $this->insight_card_ids( $cards );
		$notes       = array();
		$seen_ids    = array();

		foreach ( $raw_notes as $note ) {
			if ( ! is_array( $note ) || empty( $note['id'] ) || empty( $note['sessionId'] ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board included an invalid note.', 'woocommerce-claude' ) );
			}

			$id         = sanitize_key( (string) $note['id'] );
			$session_id = sanitize_key( (string) $note['sessionId'] );
			if ( '' === $id || isset( $seen_ids[ $id ] ) || ! isset( $session_ids[ $session_id ] ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board included an invalid note link.', 'woocommerce-claude' ) );
			}

			$body = isset( $note['body'] ) ? $this->trim_card_text( $note['body'], 600 ) : '';
			if ( '' === $body ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'A note cannot be empty.', 'woocommerce-claude' ) );
			}

			$kind = isset( $note['kind'] ) ? sanitize_key( (string) $note['kind'] ) : 'note';
			if ( ! in_array( $kind, array( 'note', 'answer', 'context' ), true ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board included an unsupported note type.', 'woocommerce-claude' ) );
			}

			$created_by = isset( $note['createdBy'] ) ? sanitize_key( (string) $note['createdBy'] ) : 'merchant';
			if ( ! in_array( $created_by, array( 'ai', 'merchant' ), true ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board included an unsupported note author.', 'woocommerce-claude' ) );
			}

			$parent_card_id = isset( $note['parentCardId'] ) ? sanitize_key( (string) $note['parentCardId'] ) : '';
			if ( '' !== $parent_card_id && ! isset( $card_ids[ $parent_card_id ] ) ) {
				return new \WP_Error( 'invalid_submitted_board', __( 'The submitted idea board included an invalid note target.', 'woocommerce-claude' ) );
			}

			$seen_ids[ $id ] = true;
			$notes[]         = array(
				'id'             => $id,
				'sessionId'      => $session_id,
				'rootInsightIds' => $this->normalise_root_insight_ids_list( isset( $note['rootInsightIds'] ) && is_array( $note['rootInsightIds'] ) ? $note['rootInsightIds'] : array(), $insight_ids ),
				'parentCardId'   => $parent_card_id,
				'body'           => $body,
				'kind'           => $kind,
				'createdBy'      => $created_by,
				'authorName'     => isset( $note['authorName'] ) ? $this->trim_card_text( $note['authorName'], 80 ) : __( 'Store team', 'woocommerce-claude' ),
				'createdAt'      => isset( $note['createdAt'] ) ? sanitize_text_field( (string) $note['createdAt'] ) : '',
			);
		}

		return $notes;
	}

	/**
	 * Normalise board period metadata.
	 *
	 * @param array $period Raw period metadata.
	 * @return array
	 */
	private function normalise_idea_board_period( array $period ) {
		return array(
			'start'      => isset( $period['start'] ) ? sanitize_text_field( (string) $period['start'] ) : '',
			'end'        => isset( $period['end'] ) ? sanitize_text_field( (string) $period['end'] ) : '',
			'label'      => isset( $period['label'] ) ? $this->trim_card_text( $period['label'], 80 ) : '',
			'days'       => isset( $period['days'] ) ? absint( $period['days'] ) : 0,
			'comparison' => isset( $period['comparison'] ) ? $this->trim_card_text( $period['comparison'], 140 ) : '',
		);
	}

	/**
	 * Normalise aggregate metrics used as re-analysis context.
	 *
	 * @param array $metrics Raw metrics.
	 * @return array
	 */
	private function normalise_idea_board_headline_metrics( array $metrics ) {
		return array(
			'net_sales'           => isset( $metrics['net_sales'] ) ? (float) $metrics['net_sales'] : 0.0,
			'orders_count'        => isset( $metrics['orders_count'] ) ? (int) $metrics['orders_count'] : 0,
			'average_order_value' => isset( $metrics['average_order_value'] ) ? (float) $metrics['average_order_value'] : 0.0,
			'total_customers'     => isset( $metrics['total_customers'] ) ? (int) $metrics['total_customers'] : 0,
		);
	}

	/**
	 * Normalise the compact decision brief shown above a board or session.
	 *
	 * @param array  $brief            Raw decision brief.
	 * @param string $fallback_summary Summary to use when the brief is sparse.
	 * @return array
	 */
	private function normalise_idea_board_decision_brief( array $brief, $fallback_summary = '' ) {
		$fallback_summary = $this->trim_card_text( $fallback_summary, 220 );

		return array(
			'whatChanged'     => isset( $brief['whatChanged'] ) ? $this->trim_card_text( $brief['whatChanged'], 220 ) : $fallback_summary,
			'commercialWhy'   => isset( $brief['commercialWhy'] ) ? $this->trim_card_text( $brief['commercialWhy'], 220 ) : '',
			'biggestUnknowns' => isset( $brief['biggestUnknowns'] ) ? $this->trim_card_text( $brief['biggestUnknowns'], 220 ) : '',
			'bestNextMove'    => isset( $brief['bestNextMove'] ) ? $this->trim_card_text( $brief['bestNextMove'], 220 ) : '',
			'upsideRisk'      => isset( $brief['upsideRisk'] ) ? $this->trim_card_text( $brief['upsideRisk'], 220 ) : '',
		);
	}

	/**
	 * Normalise expanded evidence drawer fields for a card.
	 *
	 * @param array $details Raw evidence details.
	 * @return array
	 */
	private function normalise_idea_board_evidence_details( array $details ) {
		return array(
			'metricBaseline'    => $this->normalise_evidence_detail_text( isset( $details['metricBaseline'] ) ? $details['metricBaseline'] : '', 180 ),
			'comparisonPeriod'  => $this->normalise_evidence_detail_text( isset( $details['comparisonPeriod'] ) ? $details['comparisonPeriod'] : '', 160 ),
			'involvedProducts'  => $this->normalise_evidence_detail_text( isset( $details['involvedProducts'] ) ? $details['involvedProducts'] : '', 220 ),
			'involvedOrders'    => $this->normalise_evidence_detail_text( isset( $details['involvedOrders'] ) ? $details['involvedOrders'] : '', 180 ),
			'involvedCustomers' => $this->normalise_evidence_detail_text( isset( $details['involvedCustomers'] ) ? $details['involvedCustomers'] : '', 180 ),
			'confidenceReason'  => $this->normalise_evidence_detail_text( isset( $details['confidenceReason'] ) ? $details['confidenceReason'] : '', 220 ),
			'dataFreshness'     => $this->normalise_evidence_detail_text( isset( $details['dataFreshness'] ) ? $details['dataFreshness'] : '', 160 ),
			'unknowns'          => $this->normalise_evidence_detail_text( isset( $details['unknowns'] ) ? $details['unknowns'] : '', 220 ),
			'wooLinks'          => $this->normalise_idea_board_evidence_links( isset( $details['wooLinks'] ) && is_array( $details['wooLinks'] ) ? $details['wooLinks'] : array() ),
		);
	}

	/**
	 * Normalise a text-ish evidence detail value.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $limit Character limit.
	 * @return string
	 */
	private function normalise_evidence_detail_text( $value, $limit ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( 'strval', $value ) );
		}

		return $this->trim_card_text( $value, $limit );
	}

	/**
	 * Normalise optional WooCommerce report/admin links for evidence details.
	 *
	 * @param array $raw_links Raw links.
	 * @return array
	 */
	private function normalise_idea_board_evidence_links( array $raw_links ) {
		$links = array();
		foreach ( $raw_links as $link ) {
			if ( ! is_array( $link ) || empty( $link['label'] ) || empty( $link['url'] ) ) {
				continue;
			}

			$label = $this->trim_card_text( $link['label'], 80 );
			$url   = esc_url_raw( (string) $link['url'] );
			if ( '' === $label || '' === $url ) {
				continue;
			}

			$links[] = array(
				'label' => $label,
				'url'   => $url,
			);
			if ( count( $links ) >= 3 ) {
				break;
			}
		}

		return $links;
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
	 * Build board content from AI-generated insight cards.
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
			$error_code = $ai_content->get_error_code();
			if ( 'invalid_board_content' === $error_code && false !== strpos( $ai_content->get_error_message(), 'did not include JSON' ) ) {
				return $ai_content;
			}
			if ( ! in_array( $error_code, array( 'invalid_board_content', 'http_request_failed' ), true ) ) {
				return $ai_content;
			}

			return $this->build_idea_board_fallback_content( $revenue, $orders, $products, $customers, $days );
		}

		return array(
			'cards'         => $ai_content['cards'],
			'summary'       => $ai_content['summary'],
			'decisionBrief' => $ai_content['decisionBrief'],
			'source'        => 'ai',
		);
	}

	/**
	 * Build conservative initial cards from aggregated analytics when AI returns malformed card JSON.
	 *
	 * @param array $revenue   Revenue payload.
	 * @param array $orders    Orders payload.
	 * @param array $products  Product payload.
	 * @param array $customers Customer payload.
	 * @param int   $days      Number of trailing days.
	 * @return array|\WP_Error
	 */
	private function build_idea_board_fallback_content( array $revenue, array $orders, array $products, array $customers, $days ) {
		$currency        = isset( $revenue['currency'] ) ? (string) $revenue['currency'] : get_woocommerce_currency();
		$net_sales       = isset( $revenue['metrics']['net_sales'] ) ? (float) $revenue['metrics']['net_sales'] : 0.0;
		$orders_count    = isset( $orders['metrics']['orders_count'] ) ? (int) $orders['metrics']['orders_count'] : 0;
		$aov             = isset( $revenue['metrics']['average_order_value'] ) ? (float) $revenue['metrics']['average_order_value'] : 0.0;
		$total_customers = isset( $customers['metrics']['total_customers'] ) ? (int) $customers['metrics']['total_customers'] : 0;
		$top_product     = $this->first_idea_board_row( $products, array( 'rows', 'items', 'products' ) );
		$product_name    = $this->first_idea_board_string( $top_product, array( 'name', 'product_name', 'productName', 'title', 'sku' ) );
		$product_revenue = $this->first_idea_board_number( $top_product, array( 'net_revenue', 'netRevenue', 'revenue', 'sales' ) );
		$period_label    = sprintf(
			/* translators: %d: number of days. */
			__( 'Last %d days', 'woocommerce-claude' ),
			(int) $days
		);

		$cards = array(
			array(
				'id'               => 'revenue-baseline',
				'kind'             => 'insight',
				'stage'            => 'insights',
				'title'            => __( 'Revenue baseline is ready', 'woocommerce-claude' ),
				'body'             => sprintf(
					/* translators: 1: revenue amount, 2: order count. */
					__( 'The selected period shows %1$s net sales across %2$d paid orders, giving the board a commercial baseline.', 'woocommerce-claude' ),
					$this->format_idea_board_money( $net_sales, $currency ),
					$orders_count
				),
				'colour'           => 'yellow',
				'order'            => 0,
				'prompt'           => __( 'Which revenue lever should we investigate first?', 'woocommerce-claude' ),
				'confidence'       => 'high',
				'evidence'         => sprintf(
					/* translators: 1: revenue amount, 2: order count. */
					__( '%1$s net sales, %2$d paid orders', 'woocommerce-claude' ),
					$this->format_idea_board_money( $net_sales, $currency ),
					$orders_count
				),
				'evidenceDetails'  => array(
					'metricBaseline'    => sprintf(
						/* translators: 1: revenue amount, 2: order count. */
						__( '%1$s net sales from %2$d paid orders', 'woocommerce-claude' ),
						$this->format_idea_board_money( $net_sales, $currency ),
						$orders_count
					),
					'comparisonPeriod'  => isset( $revenue['comparison']['period']['label'] ) ? $revenue['comparison']['period']['label'] : __( 'Previous matching period when available', 'woocommerce-claude' ),
					'involvedOrders'    => __( 'Aggregated paid order count only', 'woocommerce-claude' ),
					'involvedCustomers' => __( 'Aggregated customer count only', 'woocommerce-claude' ),
					'confidenceReason'  => __( 'The signal comes directly from WooCommerce Analytics totals.', 'woocommerce-claude' ),
					'dataFreshness'     => $period_label,
					'unknowns'          => __( 'The board still needs merchant context before recommending operational changes.', 'woocommerce-claude' ),
				),
				'timeframe'        => $period_label,
				'source'           => __( 'WooCommerce Analytics totals', 'woocommerce-claude' ),
				'status'           => 'new',
				'approvalRequired' => false,
				'revenueLevers'    => array( 'conversion', 'aov', 'retention' ),
				'severity'         => 0 < $net_sales ? 'medium' : 'low',
				'estimatedImpact'  => __( 'Use this as the baseline for prioritising revenue actions.', 'woocommerce-claude' ),
				'whyItMatters'     => __( 'A decision board needs a current revenue baseline before turning signals into actions.', 'woocommerce-claude' ),
				'createdBy'        => 'ai',
			),
			array(
				'id'               => 'product-focus',
				'kind'             => 'insight',
				'stage'            => 'insights',
				'title'            => $product_name ? __( 'Top product needs context', 'woocommerce-claude' ) : __( 'Catalogue signal needs context', 'woocommerce-claude' ),
				'body'             => $product_name
					? sprintf(
						/* translators: 1: product name, 2: revenue amount. */
						__( '%1$s is the clearest product signal in the period, with %2$s attributed revenue to validate.', 'woocommerce-claude' ),
						$product_name,
						$this->format_idea_board_money( $product_revenue, $currency )
					)
					: __( 'Product-level analytics are available for the period, but the board needs a merchant-selected product question next.', 'woocommerce-claude' ),
				'colour'           => 'blue',
				'order'            => 1,
				'prompt'           => __( 'Which product context would change the next decision?', 'woocommerce-claude' ),
				'confidence'       => $product_name ? 'high' : 'medium',
				'evidence'         => $product_name ? $product_name : __( 'Product performance analytics available', 'woocommerce-claude' ),
				'evidenceDetails'  => array(
					'metricBaseline'   => $product_name ? $this->format_idea_board_money( $product_revenue, $currency ) : __( 'Product performance available in aggregate', 'woocommerce-claude' ),
					'involvedProducts' => $product_name ? $product_name : __( 'Aggregated product rows only', 'woocommerce-claude' ),
					'confidenceReason' => __( 'The signal comes from the product performance analytics payload.', 'woocommerce-claude' ),
					'dataFreshness'    => $period_label,
					'unknowns'         => __( 'The board does not know stock plans, margin strategy, or campaign intent yet.', 'woocommerce-claude' ),
				),
				'timeframe'        => $period_label,
				'source'           => __( 'WooCommerce product analytics', 'woocommerce-claude' ),
				'status'           => 'new',
				'approvalRequired' => false,
				'revenueLevers'    => array( 'catalogue_quality', 'conversion', 'inventory' ),
				'severity'         => $product_name ? 'medium' : 'low',
				'estimatedImpact'  => __( 'Product focus can protect or grow revenue once merchant context is added.', 'woocommerce-claude' ),
				'whyItMatters'     => __( 'Product signals are often where merchandising, stock, and content decisions become concrete.', 'woocommerce-claude' ),
				'createdBy'        => 'ai',
			),
			array(
				'id'               => 'customer-context',
				'kind'             => 'insight',
				'stage'            => 'insights',
				'title'            => __( 'Customer context is needed', 'woocommerce-claude' ),
				'body'             => sprintf(
					/* translators: 1: customer count, 2: average order value. */
					__( 'The period includes %1$d customers and %2$s average order value, so retention and AOV questions are worth separating.', 'woocommerce-claude' ),
					$total_customers,
					$this->format_idea_board_money( $aov, $currency )
				),
				'colour'           => 'green',
				'order'            => 2,
				'prompt'           => __( 'What customer behaviour should we separate before acting?', 'woocommerce-claude' ),
				'confidence'       => 'high',
				'evidence'         => sprintf(
					/* translators: 1: customer count, 2: average order value. */
					__( '%1$d customers, %2$s AOV', 'woocommerce-claude' ),
					$total_customers,
					$this->format_idea_board_money( $aov, $currency )
				),
				'evidenceDetails'  => array(
					'metricBaseline'    => sprintf(
						/* translators: 1: customer count, 2: average order value. */
						__( '%1$d customers and %2$s average order value', 'woocommerce-claude' ),
						$total_customers,
						$this->format_idea_board_money( $aov, $currency )
					),
					'involvedCustomers' => __( 'Aggregated customer count only', 'woocommerce-claude' ),
					'confidenceReason'  => __( 'The signal comes from aggregated customer and revenue analytics.', 'woocommerce-claude' ),
					'dataFreshness'     => $period_label,
					'unknowns'          => __( 'The board does not know campaign intent, lifecycle strategy, or contact permissions.', 'woocommerce-claude' ),
				),
				'timeframe'        => $period_label,
				'source'           => __( 'WooCommerce customer analytics', 'woocommerce-claude' ),
				'status'           => 'new',
				'approvalRequired' => false,
				'revenueLevers'    => array( 'retention', 'aov', 'conversion' ),
				'severity'         => 0 < $total_customers ? 'medium' : 'low',
				'estimatedImpact'  => __( 'Customer mix can change whether the next action should protect retention, AOV, or conversion.', 'woocommerce-claude' ),
				'whyItMatters'     => __( 'Retention and AOV work need different questions before they become actions.', 'woocommerce-claude' ),
				'createdBy'        => 'ai',
			),
		);

		$content = $this->normalise_idea_board_initial_content(
			array(
				'summary'       => __( 'Review the strongest aggregated store signals, then start a brainstorm from the related insights worth exploring.', 'woocommerce-claude' ),
				'decisionBrief' => array(
					'whatChanged'     => sprintf(
						/* translators: 1: revenue amount, 2: order count. */
						__( 'The period shows %1$s net sales across %2$d paid orders, with product and customer signals ready to investigate.', 'woocommerce-claude' ),
						$this->format_idea_board_money( $net_sales, $currency ),
						$orders_count
					),
					'commercialWhy'   => __( 'The next decision should stay tied to revenue levers instead of turning every signal into work.', 'woocommerce-claude' ),
					'biggestUnknowns' => __( 'Stock plans, margin strategy, campaign intent, and customer-contact permissions still need merchant context.', 'woocommerce-claude' ),
					'bestNextMove'    => __( 'Select the signal that most affects revenue, then add the merchant-only context before drafting actions.', 'woocommerce-claude' ),
					'upsideRisk'      => __( 'Upside comes from focusing the first action on a measurable lever; risk is acting before the required context is known.', 'woocommerce-claude' ),
				),
				'cards'         => $cards,
			)
		);

		if ( is_wp_error( $content ) ) {
			return $content;
		}

		$content['source'] = 'analytics_fallback';
		return $content;
	}

	/**
	 * Return the first row from a known analytics payload list.
	 *
	 * @param array $payload Payload.
	 * @param array $keys    Candidate list keys.
	 * @return array
	 */
	private function first_idea_board_row( array $payload, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $payload[ $key ][0] ) && is_array( $payload[ $key ][0] ) ) {
				return $payload[ $key ][0];
			}
		}

		return array();
	}

	/**
	 * Return the first usable string from a row.
	 *
	 * @param array $row  Row.
	 * @param array $keys Candidate keys.
	 * @return string
	 */
	private function first_idea_board_string( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return $this->trim_card_text( $row[ $key ], 80 );
			}
		}

		return '';
	}

	/**
	 * Return the first usable number from a row.
	 *
	 * @param array $row  Row.
	 * @param array $keys Candidate keys.
	 * @return float
	 */
	private function first_idea_board_number( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_numeric( $row[ $key ] ) ) {
				return (float) $row[ $key ];
			}
		}

		return 0.0;
	}

	/**
	 * Format a compact money value for board text.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function format_idea_board_money( $amount, $currency ) {
		return strtoupper( (string) $currency ) . ' ' . number_format_i18n( (float) $amount, 2 );
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
	 * Request AI-generated initial insight cards from store context.
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
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

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
			2400,
			30
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
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response used an unexpected shape.', 'woocommerce-claude' ) );
		}

		return $this->normalise_idea_board_initial_content( $decoded );
	}

	/**
	 * Request a re-analysis of the merchant-edited board.
	 *
	 * @param array $board Sanitised board payload.
	 * @return array|\WP_Error
	 */
	private function request_ai_idea_board_reanalysis( array $board ) {
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		$client   = new AnthropicClient();
		$response = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => $this->build_idea_board_reanalysis_prompt( $board ),
				),
			),
			$this->build_idea_board_reanalysis_system_prompt(),
			array(),
			2600
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->extract_text_reply( isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array() );
		$json = $this->extract_json_object( $text );
		if ( '' === $json ) {
			return new \WP_Error( 'invalid_board_reanalysis', __( 'The idea-board re-analysis response did not include JSON.', 'woocommerce-claude' ) );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'invalid_board_reanalysis', __( 'The idea-board re-analysis response used an unexpected shape.', 'woocommerce-claude' ) );
		}

		return $this->normalise_idea_board_reanalysis_content( $decoded, $board );
	}

	/**
	 * Request AI-generated questions for selected insights.
	 *
	 * @param array $board            Sanitised board payload.
	 * @param array $root_insight_ids Selected insight card IDs.
	 * @return array|\WP_Error
	 */
	private function request_ai_idea_board_brainstorm( array $board, array $root_insight_ids ) {
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		$client   = new AnthropicClient();
		$response = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => $this->build_idea_board_brainstorm_prompt( $board, $root_insight_ids ),
				),
			),
			$this->build_idea_board_brainstorm_system_prompt(),
			array(),
			2600
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->extract_text_reply( isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array() );
		$json = $this->extract_json_object( $text );
		if ( '' === $json ) {
			return new \WP_Error( 'invalid_board_brainstorm', __( 'The idea-board brainstorm response did not include JSON.', 'woocommerce-claude' ) );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'invalid_board_brainstorm', __( 'The idea-board brainstorm response used an unexpected shape.', 'woocommerce-claude' ) );
		}

		return $this->normalise_idea_board_brainstorm_content( $decoded, $board, $root_insight_ids );
	}

	/**
	 * Request an AI answer for a single brainstorm question.
	 *
	 * @param array $board    Sanitised board payload.
	 * @param array $question Sanitised question card.
	 * @return string|\WP_Error
	 */
	private function request_ai_idea_board_question_answer( array $board, array $question ) {
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';

		$client   = new AnthropicClient();
		$response = $client->messages(
			array(
				array(
					'role'    => 'user',
					'content' => $this->build_idea_board_question_answer_prompt( $board, $question ),
				),
			),
			$this->build_idea_board_question_answer_system_prompt(),
			array(),
			1600
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$text = $this->extract_text_reply( isset( $response['content'] ) && is_array( $response['content'] ) ? $response['content'] : array() );
		$json = $this->extract_json_object( $text );
		if ( '' === $json ) {
			return new \WP_Error( 'invalid_board_question_answer', __( 'The idea-board question answer response did not include JSON.', 'woocommerce-claude' ) );
		}

		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'invalid_board_question_answer', __( 'The idea-board question answer response used an unexpected shape.', 'woocommerce-claude' ) );
		}

		return $this->normalise_idea_board_question_answer_content( $decoded );
	}

	/**
	 * Build the system prompt for AI question answering.
	 *
	 * @return string
	 */
	private function build_idea_board_question_answer_system_prompt() {
		return 'You answer the exact question a WooCommerce merchant asked on a brainstorming board. '
			. 'Treat exactQuestion as the merchant question to answer directly. Use the submitted board text, decision brief, existing notes, period, currency, and aggregated headline metrics as store context. '
			. 'Respect the question gate metadata: if answerability is ai or both, answer what can be answered from board facts; if answerability is merchant, name the specific merchant-only fact needed. '
			. 'You may add general WooCommerce, ecommerce, merchandising, marketing, and product-content reasoning when it helps answer the question. '
			. 'Clearly distinguish facts from the board from likely interpretations or recommended checks. '
			. 'Do not fetch live data, infer, or invent supplier data, campaign intent, margin strategy, stock commitments, or customer-level facts that are not already on the board. '
			. 'Use "merchant input needed" only for facts that truly require private operational knowledge, not as the default opening. '
			. 'Do not include names, emails, addresses, order IDs, or other PII. '
			. 'Do not suggest building plugins, custom endpoints, REST routes, MCP tools, or developer-only work. '
			. 'Return only valid JSON in this exact shape: {"answer":"..."}. '
			. 'Keep the answer under 650 characters and make it useful as a note attached to the question.';
	}

	/**
	 * Build the user prompt for AI question answering.
	 *
	 * @param array $board    Sanitised board payload.
	 * @param array $question Sanitised question card.
	 * @return string
	 */
	private function build_idea_board_question_answer_prompt( array $board, array $question ) {
		return wp_json_encode(
			array(
				'task'              => 'Answer the exact merchant question directly. Use the board as store context, then add practical ecommerce insight without inventing store-specific facts.',
				'exactQuestion'     => $question['title'],
				'questionContext'   => $question['body'],
				'questionGate'      => array(
					'gatePriority'  => $question['gatePriority'],
					'answerability' => $question['answerability'],
				),
				'privacy_boundary'  => 'Use only aggregated headline metrics and submitted board text. Do not repeat names, emails, addresses, order IDs, or other PII if a merchant typed them into a card.',
				'merchant_boundary' => 'Answer store, product, customer, order, marketing, operations, or insight questions. If the answer depends on supplier realities, margin strategy, campaign intent, brand judgement, or operational commitment not present on the board, say which specific fact needs merchant input.',
				'question'          => $question,
				'board'             => array(
					'id'              => $board['id'],
					'title'           => $board['title'],
					'period'          => $board['period'],
					'currency'        => $board['currency'],
					'headlineMetrics' => $board['headlineMetrics'],
					'cards'           => $board['cards'],
					'sessions'        => $board['sessions'],
					'notes'           => $board['notes'],
					'summary'         => $board['summary'],
					'decisionBrief'   => $board['decisionBrief'],
				),
			)
		);
	}

	/**
	 * Build the system prompt for creating a brainstorm session.
	 *
	 * @return string
	 */
	private function build_idea_board_brainstorm_system_prompt() {
		return 'You start an AI-assisted brainstorming session for a WooCommerce merchant from selected insight cards. '
			. 'Use only the submitted board state, period, currency, and aggregated headline metrics. '
			. 'Do not fetch, infer, or invent additional analytics. '
			. 'Do not include names, emails, addresses, order IDs, or other PII. '
			. 'Do not suggest building plugins, custom endpoints, REST routes, MCP tools, or developer-only work. '
			. 'Generate linked investigation questions that help a merchant understand related store signals before deciding what to do. '
			. 'Classify every question with gatePriority required, useful, or optional, and answerability ai, merchant, or both. Put blockers first. '
			. 'Questions must mark supplier timing, margin strategy, campaign intent, brand judgement, and discontinuation assumptions as merchant_input_needed unless the submitted board already contains that context. '
			. 'Do not create action, recommendation, idea, task, or proposed_action cards during brainstorm setup. Actions come later, after merchant context or re-analysis. '
			. 'Return only valid JSON in this exact shape: {"summary":"...","decisionBrief":{"whatChanged":"...","commercialWhy":"...","biggestUnknowns":"...","bestNextMove":"...","upsideRisk":"..."},"session":{"title":"...","summary":"...","decisionBrief":{"whatChanged":"...","commercialWhy":"...","biggestUnknowns":"...","bestNextMove":"...","upsideRisk":"..."}},"questions":[{"id":"short-slug","title":"...","body":"...","gatePriority":"required|useful|optional","answerability":"ai|merchant|both","revenueLevers":["inventory"],"rootInsightIds":["insight-id"],"parentCardId":"optional-insight-or-question-id"}]}. '
			. 'Return 2 to 6 questions. Titles must be under 70 characters and bodies under 220 characters.';
	}

	/**
	 * Build the user prompt for a brainstorm session.
	 *
	 * @param array $board            Sanitised board payload.
	 * @param array $root_insight_ids Selected insight card IDs.
	 * @return string
	 */
	private function build_idea_board_brainstorm_prompt( array $board, array $root_insight_ids ) {
		return wp_json_encode(
			array(
				'task'                    => 'Start a saved brainstorm session for one or more related store insight cards.',
				'privacy_boundary'        => 'Use only aggregated headline metrics and submitted board text. Do not repeat names, emails, addresses, order IDs, or other PII if a merchant typed them into a card.',
				'merchant_boundary'       => 'Recommendations must be things a merchant can action in WooCommerce, marketing, merchandising, operations, support, settings, or connected tools.',
				'approval_boundary'       => 'Do not create action cards in this brainstorm response. Actions are created only after merchant context is added or during re-analysis.',
				'selectedInsightIds'      => $root_insight_ids,
				'allowed_kinds'           => array( 'question' ),
				'allowed_statuses'        => array_keys( $this->allowed_statuses() ),
				'allowed_revenue_levers'  => array_keys( $this->allowed_revenue_levers() ),
				'allowed_gate_priorities' => array_keys( $this->allowed_gate_priorities() ),
				'allowed_answerability'   => array_keys( $this->allowed_answerability() ),
				'decision_brief_shape'    => array_keys( $this->normalise_idea_board_decision_brief( array() ) ),
				'board'                   => array(
					'id'              => $board['id'],
					'title'           => $board['title'],
					'period'          => $board['period'],
					'currency'        => $board['currency'],
					'headlineMetrics' => $board['headlineMetrics'],
					'cards'           => $board['cards'],
					'sessions'        => $board['sessions'],
					'notes'           => $board['notes'],
					'summary'         => $board['summary'],
					'decisionBrief'   => $board['decisionBrief'],
				),
			)
		);
	}

	/**
	 * Build the system prompt for board re-analysis.
	 *
	 * @return string
	 */
	private function build_idea_board_reanalysis_system_prompt() {
		return 'You re-analyse an existing WooCommerce merchant brainstorming workspace. '
			. 'Use only the submitted board state, period, currency, and aggregated headline metrics. '
			. 'Do not fetch, infer, or invent additional analytics. '
			. 'Do not include names, emails, addresses, order IDs, or other PII. '
			. 'If merchant-added card text contains PII, generalise it and do not repeat the specific value. '
			. 'Do not suggest building plugins, custom endpoints, REST routes, MCP tools, or developer-only work. '
			. 'The server preserves every submitted card. Do not return existing cards unless you are using the legacy cards field; prefer returning only new cards. '
			. 'Read selected insight sessions, linked questions, merchant notes, answers, edits, existing draft actions, decision briefs, question gates, evidence details, and revenue levers. '
			. 'Update the decision brief so it explains what changed, why it matters commercially, the biggest unknowns, the best next move, and upside/risk. '
			. 'Do not create action cards while a selected session still has required merchant-answerable blockers without answers or context. Add or refine blocker questions instead. '
			. 'Add or refine draft action cards only when the submitted human context makes the next decision clearer. '
			. 'Mark supplier timing, margin strategy, campaign intent, brand judgement, and discontinuation assumptions as merchant_input_needed unless the merchant already provided that context. '
			. 'Action cards are drafts only. Any spend, customer contact, ads, prices, refunds, coupons, publishing, or stock commitment must use status approval_required and approvalRequired true. '
			. 'Every action card must include actionType, revenueLevers, expectedRevenueImpact, effort, timeToImpact, riskApprovalNeeded, primaryMetric, owner, reviewDate, successCriteria, and evidenceDetails. The server computes iceScore. '
			. 'Return only valid JSON in this exact shape: {"summary":"...","decisionBrief":{"whatChanged":"...","commercialWhy":"...","biggestUnknowns":"...","bestNextMove":"...","upsideRisk":"..."},"newCards":[{"id":"short-new-slug","kind":"question|context|action","stage":"investigate|context|proposed_actions","title":"...","body":"...","colour":"blue|orange|lime|white","order":0,"prompt":"...","confidence":"low|medium|high","evidence":"...","evidenceDetails":{"metricBaseline":"...","comparisonPeriod":"...","involvedProducts":"...","involvedOrders":"...","involvedCustomers":"...","confidenceReason":"...","dataFreshness":"...","unknowns":"...","wooLinks":[]},"timeframe":"...","source":"Board re-analysis","status":"merchant_input_needed|draft|approval_required","approvalRequired":false,"revenueLevers":["inventory"],"gatePriority":"required|useful|optional","answerability":"ai|merchant|both","actionType":"investigate|merchandise|restock|pause_campaign|create_offer|improve_product_content|retention_campaign|pricing_test|checkout_fix|customer_follow_up","expectedRevenueImpact":"low|medium|high","effort":"low|medium|high","timeToImpact":"...","riskApprovalNeeded":"...","primaryMetric":"...","owner":"...","reviewDate":"YYYY-MM-DD","successCriteria":"...","rootInsightId":"insight-id","rootInsightIds":["insight-id"],"sessionId":"session-id","parentCardId":"optional-card-id","createdBy":"ai"}]}. '
			. 'If no new cards are useful, return an empty newCards array with the summary. Titles must be under 70 characters and bodies under 220 characters.';
	}

	/**
	 * Build the user prompt for board re-analysis.
	 *
	 * @param array $board Sanitised board payload.
	 * @return string
	 */
	private function build_idea_board_reanalysis_prompt( array $board ) {
		return wp_json_encode(
			array(
				'task'                    => 'Re-analyse this merchant-edited brainstorming workspace without rebuilding it from analytics.',
				'privacy_boundary'        => 'Use only aggregated headline metrics and submitted board text. Do not repeat names, emails, addresses, order IDs, or other PII if a merchant typed them into a card.',
				'merchant_boundary'       => 'Recommendations must be things a merchant can action in WooCommerce, marketing, merchandising, operations, support, settings, or connected tools.',
				'approval_boundary'       => 'Anything involving spend, customer contact, ads, prices, refunds, coupons, publishing product copy, or stock commitments needs approval_required.',
				'flow_rules'              => array(
					'submitted_cards_are_preserved_by_server' => true,
					'return_only_new_cards'             => true,
					'insight_in_investigate'            => 'Add linked question cards that identify merchant input needed before action.',
					'human_context_present'             => 'Use it in the summary and, when enough context exists, draft approval-gated action cards.',
					'unknown_supplier_or_campaign_data' => 'Mark as merchant_input_needed. Do not invent it.',
					'required_merchant_blockers'        => 'Do not create action cards for sessions that still have unanswered required merchant or both-answerable question gates.',
					'maximum_total_cards_after_merge'   => self::MAX_CARDS,
				),
				'allowed_stages'          => array_keys( $this->allowed_stages() ),
				'allowed_kinds'           => array_keys( $this->allowed_kinds() ),
				'allowed_statuses'        => array_keys( $this->allowed_statuses() ),
				'allowed_revenue_levers'  => array_keys( $this->allowed_revenue_levers() ),
				'allowed_gate_priorities' => array_keys( $this->allowed_gate_priorities() ),
				'allowed_answerability'   => array_keys( $this->allowed_answerability() ),
				'allowed_action_types'    => array_keys( $this->allowed_action_types() ),
				'decision_brief_shape'    => array_keys( $this->normalise_idea_board_decision_brief( array() ) ),
				'evidence_details_shape'  => array_keys( $this->normalise_idea_board_evidence_details( array() ) ),
				'board'                   => array(
					'id'              => $board['id'],
					'title'           => $board['title'],
					'period'          => $board['period'],
					'currency'        => $board['currency'],
					'headlineMetrics' => $board['headlineMetrics'],
					'cards'           => $board['cards'],
					'sessions'        => $board['sessions'],
					'notes'           => $board['notes'],
					'summary'         => $board['summary'],
					'decisionBrief'   => $board['decisionBrief'],
					'generatedAt'     => $board['generatedAt'],
				),
			)
		);
	}

	/**
	 * Build the system prompt for AI-generated board cards.
	 *
	 * @return string
	 */
	private function build_idea_board_content_system_prompt() {
		return 'You create the first set of idea-board insight cards for a WooCommerce merchant. '
			. 'Use only the supplied aggregated analytics, store profile, country/location context, and readiness recommendations. '
			. 'Do not invent customer-level details or include names, emails, addresses, order IDs, or other PII. '
			. 'The initial board is only the signal stage: create insight cards in stage insights. Do not create questions, human context cards, actions, tasks, or execution workflow cards yet. '
			. 'Each insight card must reference at least one concrete metric, product, or trend from the analytics. '
			. 'Every insight card must include revenueLevers, severity, estimatedImpact, whyItMatters, relatedSignalIds, and evidenceDetails. '
			. 'Also return a decisionBrief with what changed, why it matters commercially, the biggest unknowns, the best next move, and upside/risk. '
			. 'The prompt field must be a specific suggested next question a merchant could ask to investigate the signal. '
			. 'Confidence rubric: high = directly evidenced by the analytics data; medium = inferred from a pattern; low = speculative or context-only. '
			. 'Do not suggest building plugins, custom endpoints, REST routes, MCP tools, or developer-only work. '
			. 'Return only valid JSON in this exact shape: {"summary":"...","decisionBrief":{"whatChanged":"...","commercialWhy":"...","biggestUnknowns":"...","bestNextMove":"...","upsideRisk":"..."},"cards":[{"id":"short-slug","kind":"insight","stage":"insights","title":"...","body":"...","colour":"yellow|pink|green|blue|orange|lime|white","order":0,"prompt":"...","confidence":"low|medium|high","evidence":"...","evidenceDetails":{"metricBaseline":"...","comparisonPeriod":"...","involvedProducts":"...","involvedOrders":"...","involvedCustomers":"...","confidenceReason":"...","dataFreshness":"...","unknowns":"...","wooLinks":[]},"timeframe":"...","source":"...","status":"new","approvalRequired":false,"revenueLevers":["inventory"],"severity":"low|medium|high|critical","estimatedImpact":"...","whyItMatters":"...","relatedSignalIds":[],"rootInsightId":"","createdBy":"ai"}]}. '
			. 'Return 2 to 4 insight cards. Titles must be under 70 characters and bodies under 220 characters.';
	}

	/**
	 * Build the user prompt for AI-generated initial insight cards.
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
					'I am a store owner of %1$s in %2$s. Create only the first store-signal cards for a brainstorming workspace. Merchants will select one or more related insights later to start a brainstorm session.',
					$store_name,
					$country
				),
				'period_days'               => $days,
				'store'                     => array(
					'name'    => $store_name,
					'country' => $country,
					'profile' => $this->limit_nested_payload( $profile, 4000 ),
				),
				'analytics'                 => array(
					'revenue'   => $this->limit_nested_payload( $revenue, 2500 ),
					'orders'    => $this->limit_nested_payload( $orders, 2500 ),
					'products'  => $this->limit_nested_payload( $products, 3000 ),
					'customers' => $this->limit_nested_payload( $customers, 1800 ),
				),
				'readiness_recommendations' => $this->limit_nested_payload( $recs, 2800 ),
				'allowed_stages'            => array_keys( $this->allowed_stages() ),
				'allowed_kinds'             => array_keys( $this->allowed_kinds() ),
				'allowed_revenue_levers'    => array_keys( $this->allowed_revenue_levers() ),
				'allowed_severities'        => array_keys( $this->allowed_severities() ),
				'decision_brief_shape'      => array_keys( $this->normalise_idea_board_decision_brief( array() ) ),
				'evidence_details_shape'    => array_keys( $this->normalise_idea_board_evidence_details( array() ) ),
				'initial_generation_rule'   => 'Only kind=insight and stage=insights are allowed on initial generation.',
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
	 * Normalise AI-generated initial board content.
	 *
	 * @param array $decoded Decoded model response.
	 * @return array|\WP_Error
	 */
	private function normalise_idea_board_initial_content( array $decoded ) {
		$decoded = $this->normalise_idea_board_initial_content_shape( $decoded );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		if ( count( $decoded['cards'] ) < 1 || count( $decoded['cards'] ) > self::MAX_INITIAL_CARDS ) {
			return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response did not include the expected number of insight cards.', 'woocommerce-claude' ) );
		}

		$cards    = array();
		$seen_ids = array();
		foreach ( $decoded['cards'] as $index => $card ) {
			$card = $this->prepare_initial_idea_board_card_candidate( $card, $index, $seen_ids );

			$normalised = $this->normalise_idea_board_card( $card, $index, true, 'invalid_board_content' );
			if ( is_wp_error( $normalised ) ) {
				return $normalised;
			}

			if ( 'insight' !== $normalised['kind'] || 'insights' !== $normalised['stage'] ) {
				return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response included a non-insight card.', 'woocommerce-claude' ) );
			}

			if ( isset( $seen_ids[ $normalised['id'] ] ) ) {
				return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response repeated a card ID.', 'woocommerce-claude' ) );
			}

			$seen_ids[ $normalised['id'] ] = true;
			$cards[]                       = $normalised;
		}

		return array(
			'cards'         => $this->normalise_card_orders( $cards ),
			'summary'       => isset( $decoded['summary'] ) ? $this->trim_card_text( $decoded['summary'], 300 ) : __( 'Review the strongest store signals, then start a brainstorm from the related insights worth exploring.', 'woocommerce-claude' ),
			'decisionBrief' => $this->normalise_idea_board_decision_brief( isset( $decoded['decisionBrief'] ) && is_array( $decoded['decisionBrief'] ) ? $decoded['decisionBrief'] : array(), isset( $decoded['summary'] ) ? (string) $decoded['summary'] : '' ),
		);
	}

	/**
	 * Accept common AI envelopes for initial insight cards.
	 *
	 * @param array $decoded Decoded model response.
	 * @return array|\WP_Error
	 */
	private function normalise_idea_board_initial_content_shape( array $decoded ) {
		if ( empty( $decoded['decisionBrief'] ) && isset( $decoded['decision_brief'] ) && is_array( $decoded['decision_brief'] ) ) {
			$decoded['decisionBrief'] = $decoded['decision_brief'];
		}

		if ( isset( $decoded['cards'] ) && is_array( $decoded['cards'] ) ) {
			$cards = $this->normalise_initial_idea_board_card_list_candidate( $decoded['cards'] );
			if ( is_array( $cards ) ) {
				$decoded['cards'] = $cards;
				return $decoded;
			}
		}

		$cards = $this->extract_initial_idea_board_card_list( $decoded );
		if ( is_array( $cards ) ) {
			$decoded['cards'] = $cards;
			return $decoded;
		}

		foreach ( array( 'board', 'ideaBoard', 'idea_board', 'content', 'payload', 'response', 'result' ) as $container_key ) {
			if ( ! isset( $decoded[ $container_key ] ) || ! is_array( $decoded[ $container_key ] ) ) {
				continue;
			}

			$container = $decoded[ $container_key ];
			$cards     = $this->extract_initial_idea_board_card_list( $container );
			if ( is_array( $cards ) ) {
				if ( empty( $decoded['summary'] ) && isset( $container['summary'] ) ) {
					$decoded['summary'] = $container['summary'];
				}
				if ( empty( $decoded['decisionBrief'] ) && isset( $container['decisionBrief'] ) && is_array( $container['decisionBrief'] ) ) {
					$decoded['decisionBrief'] = $container['decisionBrief'];
				}
				if ( empty( $decoded['decisionBrief'] ) && isset( $container['decision_brief'] ) && is_array( $container['decision_brief'] ) ) {
					$decoded['decisionBrief'] = $container['decision_brief'];
				}
				$decoded['cards'] = $cards;
				return $decoded;
			}
		}

		$cards = $this->normalise_initial_idea_board_card_list_candidate( $decoded );
		if ( is_array( $cards ) ) {
			return array(
				'cards' => $cards,
			);
		}

		return new \WP_Error( 'invalid_board_content', __( 'The idea-board content response used an unexpected shape.', 'woocommerce-claude' ) );
	}

	/**
	 * Extract an initial insight-card list from common AI response field names.
	 *
	 * @param array $container Candidate response container.
	 * @return array|false
	 */
	private function extract_initial_idea_board_card_list( array $container ) {
		foreach ( array( 'cards', 'insights', 'insightCards', 'insight_cards', 'signals', 'signalCards', 'signal_cards', 'storeSignals', 'store_signals', 'initialCards', 'initial_cards', 'ideaCards', 'idea_cards', 'ideaBoardCards', 'idea_board_cards' ) as $key ) {
			if ( isset( $container[ $key ] ) && is_array( $container[ $key ] ) ) {
				$cards = $this->normalise_initial_idea_board_card_list_candidate( $container[ $key ] );
				if ( is_array( $cards ) ) {
					return $cards;
				}
			}
		}

		foreach ( $container as $key => $value ) {
			if ( ! is_string( $key ) || ! is_array( $value ) || ! preg_match( '/(card|cards|insight|insights|signal|signals)/i', $key ) ) {
				continue;
			}

			$cards = $this->normalise_initial_idea_board_card_list_candidate( $value );
			if ( is_array( $cards ) ) {
				return $cards;
			}
		}

		return false;
	}

	/**
	 * Normalise list-shaped or ID-keyed card candidates to a numeric array.
	 *
	 * @param array $value Candidate array.
	 * @return array|false
	 */
	private function normalise_initial_idea_board_card_list_candidate( array $value ) {
		if ( empty( $value ) ) {
			return false;
		}

		$cards = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				return false;
			}
			$cards[] = $row;
		}

		return $cards;
	}

	/**
	 * Fill safe defaults on initial AI insight candidates before strict normalisation.
	 *
	 * @param mixed $card     Candidate card.
	 * @param int   $index    Card order.
	 * @param array $seen_ids IDs already prepared.
	 * @return mixed
	 */
	private function prepare_initial_idea_board_card_candidate( $card, $index, array $seen_ids ) {
		if ( ! is_array( $card ) ) {
			return $card;
		}

		if ( empty( $card['kind'] ) ) {
			$card['kind'] = 'insight';
		}
		if ( empty( $card['stage'] ) ) {
			$card['stage'] = 'insights';
		}
		if ( empty( $card['id'] ) && ! empty( $card['title'] ) ) {
			$card['id'] = $this->unique_idea_board_card_id( (string) $card['title'], $seen_ids );
		}
		if ( empty( $card['colour'] ) ) {
			$card['colour'] = $this->default_colour_for_kind( sanitize_key( (string) $card['kind'] ) );
		}
		if ( ! isset( $card['order'] ) ) {
			$card['order'] = $index;
		}
		if ( empty( $card['status'] ) ) {
			$card['status'] = 'new';
		}
		if ( empty( $card['createdBy'] ) ) {
			$card['createdBy'] = 'ai';
		}

		return $card;
	}

	/**
	 * Normalise AI re-analysis output while preserving submitted cards.
	 *
	 * @param array $decoded Decoded model response.
	 * @param array $board   Sanitised board submitted by the browser.
	 * @return array|\WP_Error
	 */
	private function normalise_idea_board_reanalysis_content( array $decoded, array $board ) {
		$submitted_cards = $board['cards'];
		$default_session = $this->default_reanalysis_session( $board );
		$cards           = array_values( $submitted_cards );
		$seen_ids        = array();
		foreach ( $submitted_cards as $card ) {
			$seen_ids[ $card['id'] ] = true;
		}

		$candidate_cards = $this->extract_reanalysis_card_candidates( $decoded );
		foreach ( $candidate_cards as $index => $card ) {
			if ( count( $cards ) >= self::MAX_CARDS ) {
				break;
			}

			$card = $this->prepare_reanalysis_card_candidate( $card, $index, $seen_ids );
			if ( ! is_array( $card ) ) {
				continue;
			}

			$normalised = $this->normalise_idea_board_card( $card, $index, false, 'invalid_board_reanalysis' );
			if ( is_wp_error( $normalised ) ) {
				continue;
			}

			if ( isset( $seen_ids[ $normalised['id'] ] ) || 'insight' === $normalised['kind'] ) {
				continue;
			}

			$normalised['createdBy'] = 'ai';
			if ( '' === $normalised['sessionId'] && $default_session ) {
				$normalised['sessionId'] = $default_session['id'];
			}
			if ( empty( $normalised['rootInsightIds'] ) && $default_session ) {
				$normalised['rootInsightIds'] = $default_session['rootInsightIds'];
			}
			$normalised['rootInsightIds'] = $this->normalise_root_insight_ids_list( $normalised['rootInsightIds'], $default_session ? $default_session['rootInsightIds'] : array() );
			$normalised['rootInsightId']  = $this->normalise_root_insight_id( $normalised['rootInsightId'], $submitted_cards );
			if ( '' === $normalised['rootInsightId'] && ! empty( $normalised['rootInsightIds'] ) ) {
				$normalised['rootInsightId'] = $normalised['rootInsightIds'][0];
			}
			if ( empty( $normalised['rootInsightIds'] ) && '' !== $normalised['rootInsightId'] ) {
				$normalised['rootInsightIds'] = array( $normalised['rootInsightId'] );
			}
			if ( 'action' === $normalised['kind'] && $this->session_has_unanswered_required_merchant_gate( $board, $normalised['sessionId'] ) ) {
				continue;
			}
			$seen_ids[ $normalised['id'] ] = true;
			$cards[]                       = $normalised;
		}

		return array(
			'cards'         => $this->normalise_card_orders( $cards ),
			'summary'       => isset( $decoded['summary'] ) ? $this->trim_card_text( $decoded['summary'], 300 ) : __( 'The board has been re-analysed using the current cards and merchant context.', 'woocommerce-claude' ),
			'decisionBrief' => $this->normalise_idea_board_decision_brief( isset( $decoded['decisionBrief'] ) && is_array( $decoded['decisionBrief'] ) ? $decoded['decisionBrief'] : array(), isset( $decoded['summary'] ) ? (string) $decoded['summary'] : $board['summary'] ),
		);
	}

	/**
	 * Pick the session AI should attach new re-analysis cards to when the model omits session IDs.
	 *
	 * @param array $board Sanitised board submitted by the browser.
	 * @return array|null
	 */
	private function default_reanalysis_session( array $board ) {
		if ( empty( $board['sessions'] ) || ! is_array( $board['sessions'] ) ) {
			return null;
		}

		foreach ( $board['sessions'] as $session ) {
			if ( isset( $session['status'] ) && 'ready_to_reanalyse' === $session['status'] ) {
				return $session;
			}
		}

		foreach ( $board['sessions'] as $session ) {
			if ( isset( $session['status'] ) && 'active' === $session['status'] ) {
				return $session;
			}
		}

		return $board['sessions'][0];
	}

	/**
	 * Normalise AI brainstorm output.
	 *
	 * @param array $decoded          Decoded model response.
	 * @param array $board            Sanitised submitted board.
	 * @param array $root_insight_ids Selected insight card IDs.
	 * @return array|\WP_Error
	 */
	private function normalise_idea_board_brainstorm_content( array $decoded, array $board, array $root_insight_ids ) {
		$session_title   = isset( $decoded['session']['title'] ) ? $this->trim_card_text( $decoded['session']['title'], 100 ) : '';
		$session_summary = isset( $decoded['session']['summary'] ) ? $this->trim_card_text( $decoded['session']['summary'], 400 ) : '';
		if ( '' === $session_title ) {
			$session_title = $this->build_default_session_title( $board['cards'], $root_insight_ids );
		}
		if ( '' === $session_summary ) {
			$session_summary = __( 'A focused brainstorm session is ready for merchant notes, answers, and action review.', 'woocommerce-claude' );
		}
		$session_brief = $this->normalise_idea_board_decision_brief( isset( $decoded['session']['decisionBrief'] ) && is_array( $decoded['session']['decisionBrief'] ) ? $decoded['session']['decisionBrief'] : array(), $session_summary );

		$candidates = array();
		foreach ( array(
			'questions' => 'question',
			'newCards'  => null,
			'cards'     => null,
		) as $key => $default_kind ) {
			if ( empty( $decoded[ $key ] ) || ! is_array( $decoded[ $key ] ) ) {
				continue;
			}

			foreach ( $decoded[ $key ] as $card ) {
				if ( is_string( $card ) && $default_kind ) {
					$card = array(
						'kind'  => $default_kind,
						'title' => $card,
					);
				}
				if ( is_array( $card ) && $default_kind && empty( $card['kind'] ) ) {
					$card['kind'] = $default_kind;
				}
				if ( is_array( $card ) ) {
					$candidates[] = $card;
				}
			}
		}

		$cards    = array();
		$seen_ids = array();
		foreach ( $board['cards'] as $existing_card ) {
			if ( ! empty( $existing_card['id'] ) ) {
				$seen_ids[ $existing_card['id'] ] = true;
			}
		}
		foreach ( $candidates as $index => $card ) {
			$kind = isset( $card['kind'] ) ? sanitize_key( (string) $card['kind'] ) : '';
			if ( ! in_array( $kind, array( 'question' ), true ) ) {
				continue;
			}

			$title = isset( $card['title'] ) ? $this->trim_card_text( $card['title'], 80 ) : '';
			if ( '' === $title ) {
				continue;
			}

			if ( empty( $card['id'] ) ) {
				$card['id'] = $this->unique_idea_board_card_id( $title, $seen_ids );
			}
			$card['kind']             = $kind;
			$card['stage']            = $this->default_stage_for_kind( $kind );
			$card['title']            = $title;
			$card['body']             = isset( $card['body'] ) && '' !== $this->trim_card_text( $card['body'], 240 ) ? $card['body'] : $this->default_body_for_kind( $kind );
			$card['colour']           = isset( $card['colour'] ) ? $card['colour'] : $this->default_colour_for_kind( $kind );
			$card['order']            = isset( $card['order'] ) ? $card['order'] : $index;
			$card['prompt']           = isset( $card['prompt'] ) ? $card['prompt'] : $title;
			$card['confidence']       = isset( $card['confidence'] ) ? $card['confidence'] : 'medium';
			$card['evidence']         = isset( $card['evidence'] ) ? $card['evidence'] : '';
			$card['timeframe']        = isset( $card['timeframe'] ) ? $card['timeframe'] : $board['period']['label'];
			$card['source']           = isset( $card['source'] ) ? $card['source'] : __( 'Brainstorm session', 'woocommerce-claude' );
			$card['status']           = isset( $card['status'] ) ? $card['status'] : $this->default_status_for_kind( $kind );
			$card['approvalRequired'] = isset( $card['approvalRequired'] ) ? wc_string_to_bool( $card['approvalRequired'] ) : false;
			$card['gatePriority']     = isset( $card['gatePriority'] ) ? $card['gatePriority'] : 'required';
			$card['answerability']    = isset( $card['answerability'] ) ? $card['answerability'] : 'merchant';
			$card['createdBy']        = 'ai';
			$card['rootInsightIds']   = ! empty( $card['rootInsightIds'] ) && is_array( $card['rootInsightIds'] )
				? $this->normalise_root_insight_ids_list( $card['rootInsightIds'], $root_insight_ids )
				: $root_insight_ids;
			$card['rootInsightId']    = ! empty( $card['rootInsightId'] ) ? sanitize_key( (string) $card['rootInsightId'] ) : $card['rootInsightIds'][0];

			$normalised = $this->normalise_idea_board_card( $card, $index, false, 'invalid_board_brainstorm' );
			if ( is_wp_error( $normalised ) ) {
				continue;
			}

			$seen_ids[ $normalised['id'] ] = true;
			$cards[]                       = $normalised;
		}

		if ( empty( $cards ) ) {
			return new \WP_Error( 'invalid_board_brainstorm', __( 'The idea-board brainstorm response did not include usable questions.', 'woocommerce-claude' ) );
		}

		return array(
			'session'       => array(
				'title'         => $session_title,
				'summary'       => $session_summary,
				'decisionBrief' => $session_brief,
			),
			'cards'         => $cards,
			'summary'       => isset( $decoded['summary'] ) ? $this->trim_card_text( $decoded['summary'], 300 ) : $session_summary,
			'decisionBrief' => $this->normalise_idea_board_decision_brief( isset( $decoded['decisionBrief'] ) && is_array( $decoded['decisionBrief'] ) ? $decoded['decisionBrief'] : array(), isset( $decoded['summary'] ) ? (string) $decoded['summary'] : $session_summary ),
		);
	}

	/**
	 * Normalise AI question-answer output.
	 *
	 * @param array $decoded Decoded model response.
	 * @return string|\WP_Error
	 */
	private function normalise_idea_board_question_answer_content( array $decoded ) {
		$answer = isset( $decoded['answer'] ) ? $this->trim_card_text( $decoded['answer'], 600 ) : '';
		if ( '' === $answer ) {
			return new \WP_Error( 'invalid_board_question_answer', __( 'The idea-board question answer response did not include a usable answer.', 'woocommerce-claude' ) );
		}

		return $answer;
	}

	/**
	 * Extract cards from tolerant re-analysis response shapes.
	 *
	 * @param array $decoded Decoded model response.
	 * @return array
	 */
	private function extract_reanalysis_card_candidates( array $decoded ) {
		$candidates = array();
		$groups     = array(
			'newCards'        => null,
			'new_cards'       => null,
			'cards'           => null,
			'questions'       => 'question',
			'contextCards'    => 'context',
			'context_cards'   => 'context',
			'contexts'        => 'context',
			'actionCards'     => 'action',
			'action_cards'    => 'action',
			'actions'         => 'action',
			'recommendations' => 'action',
		);

		if ( isset( $decoded['board']['cards'] ) && is_array( $decoded['board']['cards'] ) ) {
			foreach ( $decoded['board']['cards'] as $card ) {
				$candidates[] = $card;
			}
		}

		foreach ( $groups as $key => $default_kind ) {
			if ( empty( $decoded[ $key ] ) || ! is_array( $decoded[ $key ] ) ) {
				continue;
			}

			foreach ( $decoded[ $key ] as $card ) {
				if ( is_array( $card ) && $default_kind && empty( $card['kind'] ) ) {
					$card['kind'] = $default_kind;
				} elseif ( is_string( $card ) && $default_kind ) {
					$card = array(
						'kind'  => $default_kind,
						'title' => $card,
					);
				}

				$candidates[] = $card;
			}
		}

		return $candidates;
	}

	/**
	 * Fill safe defaults into a model-proposed new card.
	 *
	 * @param mixed $card     Raw card candidate.
	 * @param int   $index    Candidate index.
	 * @param array $seen_ids IDs already present on the board.
	 * @return array|null
	 */
	private function prepare_reanalysis_card_candidate( $card, $index, array $seen_ids ) {
		if ( ! is_array( $card ) ) {
			return null;
		}

		$kind = isset( $card['kind'] ) ? sanitize_key( (string) $card['kind'] ) : '';
		if ( 'proposed_action' === $kind || 'recommendation' === $kind ) {
			$kind = 'action';
		} elseif ( 'human_context' === $kind || 'context_gap' === $kind ) {
			$kind = 'context';
		}

		if ( ! isset( $this->allowed_kinds()[ $kind ] ) || 'insight' === $kind ) {
			return null;
		}

		$title = isset( $card['title'] ) ? $this->trim_card_text( $card['title'], 80 ) : '';
		if ( '' === $title ) {
			return null;
		}

		if ( empty( $card['id'] ) ) {
			$card['id'] = $this->unique_idea_board_card_id( $title, $seen_ids );
		}

		$card['kind']      = $kind;
		$card['stage']     = $this->default_stage_for_kind( $kind );
		$card['body']      = isset( $card['body'] ) && '' !== $this->trim_card_text( $card['body'], 240 )
			? $card['body']
			: $this->default_body_for_kind( $kind );
		$card['colour']    = isset( $card['colour'] ) ? $card['colour'] : $this->default_colour_for_kind( $kind );
		$card['order']     = isset( $card['order'] ) ? $card['order'] : $index;
		$card['source']    = isset( $card['source'] ) ? $card['source'] : __( 'Board re-analysis', 'woocommerce-claude' );
		$card['status']    = isset( $card['status'] ) ? $card['status'] : $this->default_status_for_kind( $kind );
		$card['createdBy'] = 'ai';

		if ( 'action' === $kind ) {
			$card['approvalRequired'] = true;
			$card['status']           = 'approval_required';
			$card['actionType']       = isset( $card['actionType'] ) ? $card['actionType'] : 'investigate';
			$card['effort']           = isset( $card['effort'] ) ? $card['effort'] : 'medium';
		}

		return $card;
	}

	/**
	 * Normalise one idea-board card.
	 *
	 * @param mixed  $card       Raw card row.
	 * @param int    $index      Fallback order.
	 * @param bool   $is_initial Whether this is initial generation.
	 * @param string $error_code Error code for validation failures.
	 * @return array|\WP_Error
	 */
	private function normalise_idea_board_card( $card, $index, $is_initial, $error_code ) {
		if ( ! is_array( $card ) || ! isset( $card['id'], $card['kind'], $card['stage'], $card['title'], $card['body'] ) ) {
			return new \WP_Error( $error_code, __( 'The idea-board card omitted a required field.', 'woocommerce-claude' ) );
		}

		$id = sanitize_key( (string) $card['id'] );
		if ( '' === $id ) {
			return new \WP_Error( $error_code, __( 'The idea-board card omitted a card ID.', 'woocommerce-claude' ) );
		}

		$kind                    = sanitize_key( (string) $card['kind'] );
		$stage                   = sanitize_key( (string) $card['stage'] );
		$colour                  = isset( $card['colour'] ) ? sanitize_key( (string) $card['colour'] ) : $this->default_colour_for_kind( $kind );
		$confidence              = isset( $card['confidence'] ) ? sanitize_key( (string) $card['confidence'] ) : 'medium';
		$status                  = isset( $card['status'] ) ? $this->normalise_status_slug( $card['status'] ) : $this->default_status_for_kind( $kind );
		$created_by              = isset( $card['createdBy'] ) ? sanitize_key( (string) $card['createdBy'] ) : ( $is_initial ? 'ai' : 'merchant' );
		$severity                = $this->normalise_allowed_key( isset( $card['severity'] ) ? $card['severity'] : '', $this->allowed_severities(), $this->default_severity_for_kind( $kind ) );
		$gate_priority           = $this->normalise_allowed_key( isset( $card['gatePriority'] ) ? $card['gatePriority'] : '', $this->allowed_gate_priorities(), $this->default_gate_priority_for_kind( $kind ) );
		$answerability           = $this->normalise_allowed_key( isset( $card['answerability'] ) ? $card['answerability'] : '', $this->allowed_answerability(), $this->default_answerability_for_kind( $kind ) );
		$action_type             = $this->normalise_allowed_key( isset( $card['actionType'] ) ? $card['actionType'] : '', $this->allowed_action_types(), 'investigate' );
		$expected_revenue_impact = $this->normalise_level( isset( $card['expectedRevenueImpact'] ) ? $card['expectedRevenueImpact'] : '', 'medium' );
		$effort                  = $this->normalise_level( isset( $card['effort'] ) ? $card['effort'] : '', 'medium' );

		if (
			! isset( $this->allowed_kinds()[ $kind ] )
			|| ! isset( $this->allowed_stages()[ $stage ] )
			|| ! isset( $this->allowed_colours()[ $colour ] )
			|| ! in_array( $confidence, array( 'low', 'medium', 'high' ), true )
			|| ! isset( $this->allowed_statuses()[ $status ] )
			|| ! in_array( $created_by, array( 'ai', 'merchant' ), true )
		) {
			return new \WP_Error( $error_code, __( 'The idea-board card included an unsupported value.', 'woocommerce-claude' ) );
		}

		$title = $this->trim_card_text( $card['title'], 80 );
		$body  = $this->trim_card_text( $card['body'], 240 );
		if ( '' === $title || '' === $body ) {
			return new \WP_Error( $error_code, __( 'The idea-board card included an empty title or body.', 'woocommerce-claude' ) );
		}

		$approval_required = isset( $card['approvalRequired'] ) ? wc_string_to_bool( $card['approvalRequired'] ) : 'approval_required' === $status || 'action' === $kind;
		if ( $approval_required ) {
			$status = 'approval_required';
		}

		$root_insight_id  = isset( $card['rootInsightId'] ) ? sanitize_key( (string) $card['rootInsightId'] ) : '';
		$root_insight_ids = isset( $card['rootInsightIds'] ) && is_array( $card['rootInsightIds'] )
			? $this->normalise_root_insight_ids_list( $card['rootInsightIds'], array() )
			: array();
		if ( '' !== $root_insight_id && ! in_array( $root_insight_id, $root_insight_ids, true ) ) {
			array_unshift( $root_insight_ids, $root_insight_id );
		}
		if ( '' === $root_insight_id && ! empty( $root_insight_ids ) ) {
			$root_insight_id = $root_insight_ids[0];
		}
		$review_date = isset( $card['reviewDate'] ) ? sanitize_text_field( (string) $card['reviewDate'] ) : '';
		if ( 'action' === $kind && '' === $review_date ) {
			$review_date = current_datetime()->modify( '+7 days' )->format( 'Y-m-d' );
		}
		$ice_score = 'action' === $kind ? $this->compute_idea_board_ice_score( $expected_revenue_impact, $confidence, $effort ) : 0;

		return array(
			'id'                    => $id,
			'kind'                  => $kind,
			'stage'                 => $stage,
			'title'                 => $title,
			'body'                  => $body,
			'colour'                => $colour,
			'order'                 => isset( $card['order'] ) && is_numeric( $card['order'] ) ? (int) $card['order'] : (int) $index,
			'prompt'                => isset( $card['prompt'] ) ? $this->trim_card_text( $card['prompt'], 220 ) : '',
			'confidence'            => $confidence,
			'evidence'              => isset( $card['evidence'] ) ? $this->trim_card_text( $card['evidence'], 140 ) : '',
			'evidenceDetails'       => $this->normalise_idea_board_evidence_details( isset( $card['evidenceDetails'] ) && is_array( $card['evidenceDetails'] ) ? $card['evidenceDetails'] : array() ),
			'timeframe'             => isset( $card['timeframe'] ) ? $this->trim_card_text( $card['timeframe'], 80 ) : '',
			'source'                => isset( $card['source'] ) ? $this->trim_card_text( $card['source'], 120 ) : '',
			'status'                => $status,
			'approvalRequired'      => $approval_required,
			'revenueLevers'         => $this->normalise_revenue_levers( isset( $card['revenueLevers'] ) && is_array( $card['revenueLevers'] ) ? $card['revenueLevers'] : array(), $kind ),
			'severity'              => $severity,
			'estimatedImpact'       => isset( $card['estimatedImpact'] ) ? $this->trim_card_text( $card['estimatedImpact'], 120 ) : '',
			'whyItMatters'          => isset( $card['whyItMatters'] ) ? $this->trim_card_text( $card['whyItMatters'], 220 ) : '',
			'relatedSignalIds'      => $this->normalise_related_signal_ids( isset( $card['relatedSignalIds'] ) && is_array( $card['relatedSignalIds'] ) ? $card['relatedSignalIds'] : array() ),
			'gatePriority'          => $gate_priority,
			'answerability'         => $answerability,
			'actionType'            => $action_type,
			'expectedRevenueImpact' => $expected_revenue_impact,
			'effort'                => $effort,
			'timeToImpact'          => isset( $card['timeToImpact'] ) ? $this->trim_card_text( $card['timeToImpact'], 80 ) : '',
			'riskApprovalNeeded'    => isset( $card['riskApprovalNeeded'] ) ? $this->trim_card_text( $card['riskApprovalNeeded'], 160 ) : ( 'action' === $kind ? __( 'Merchant approval needed before changing spend, stock, prices, customer contact, or publishing.', 'woocommerce-claude' ) : '' ),
			'primaryMetric'         => isset( $card['primaryMetric'] ) ? $this->trim_card_text( $card['primaryMetric'], 120 ) : '',
			'owner'                 => isset( $card['owner'] ) ? $this->trim_card_text( $card['owner'], 80 ) : '',
			'reviewDate'            => $review_date,
			'successCriteria'       => isset( $card['successCriteria'] ) ? $this->trim_card_text( $card['successCriteria'], 180 ) : '',
			'iceScore'              => $ice_score,
			'rootInsightId'         => $root_insight_id,
			'rootInsightIds'        => $root_insight_ids,
			'sessionId'             => isset( $card['sessionId'] ) ? sanitize_key( (string) $card['sessionId'] ) : '',
			'parentCardId'          => isset( $card['parentCardId'] ) ? sanitize_key( (string) $card['parentCardId'] ) : '',
			'createdBy'             => $created_by,
			'x'                     => isset( $card['x'] ) && is_numeric( $card['x'] ) ? max( 0, min( 1200, (int) $card['x'] ) ) : 0,
			'y'                     => isset( $card['y'] ) && is_numeric( $card['y'] ) ? max( 0, min( 900, (int) $card['y'] ) ) : 0,
			'rotation'              => isset( $card['rotation'] ) && is_numeric( $card['rotation'] ) ? max( -6, min( 6, (float) $card['rotation'] ) ) : 0,
		);
	}

	/**
	 * Backfill decision-layer fields on saved cards created by earlier board versions.
	 *
	 * @param array $card Card.
	 * @return array
	 */
	private function backfill_idea_board_card_decision_fields( array $card ) {
		$kind       = isset( $card['kind'] ) ? sanitize_key( (string) $card['kind'] ) : 'insight';
		$confidence = isset( $card['confidence'] ) ? $this->normalise_level( $card['confidence'], 'medium' ) : 'medium';
		$impact     = isset( $card['expectedRevenueImpact'] ) ? $this->normalise_level( $card['expectedRevenueImpact'], 'medium' ) : 'medium';
		$effort     = isset( $card['effort'] ) ? $this->normalise_level( $card['effort'], 'medium' ) : 'medium';

		$card['evidenceDetails']       = $this->normalise_idea_board_evidence_details( isset( $card['evidenceDetails'] ) && is_array( $card['evidenceDetails'] ) ? $card['evidenceDetails'] : array() );
		$card['revenueLevers']         = $this->normalise_revenue_levers( isset( $card['revenueLevers'] ) && is_array( $card['revenueLevers'] ) ? $card['revenueLevers'] : array(), $kind );
		$card['severity']              = $this->normalise_allowed_key( isset( $card['severity'] ) ? $card['severity'] : '', $this->allowed_severities(), $this->default_severity_for_kind( $kind ) );
		$card['estimatedImpact']       = isset( $card['estimatedImpact'] ) ? $this->trim_card_text( $card['estimatedImpact'], 120 ) : '';
		$card['whyItMatters']          = isset( $card['whyItMatters'] ) ? $this->trim_card_text( $card['whyItMatters'], 220 ) : '';
		$card['relatedSignalIds']      = $this->normalise_related_signal_ids( isset( $card['relatedSignalIds'] ) && is_array( $card['relatedSignalIds'] ) ? $card['relatedSignalIds'] : array() );
		$card['gatePriority']          = $this->normalise_allowed_key( isset( $card['gatePriority'] ) ? $card['gatePriority'] : '', $this->allowed_gate_priorities(), $this->default_gate_priority_for_kind( $kind ) );
		$card['answerability']         = $this->normalise_allowed_key( isset( $card['answerability'] ) ? $card['answerability'] : '', $this->allowed_answerability(), $this->default_answerability_for_kind( $kind ) );
		$card['actionType']            = $this->normalise_allowed_key( isset( $card['actionType'] ) ? $card['actionType'] : '', $this->allowed_action_types(), 'investigate' );
		$card['expectedRevenueImpact'] = $impact;
		$card['effort']                = $effort;
		$card['timeToImpact']          = isset( $card['timeToImpact'] ) ? $this->trim_card_text( $card['timeToImpact'], 80 ) : '';
		$card['riskApprovalNeeded']    = isset( $card['riskApprovalNeeded'] ) ? $this->trim_card_text( $card['riskApprovalNeeded'], 160 ) : '';
		$card['primaryMetric']         = isset( $card['primaryMetric'] ) ? $this->trim_card_text( $card['primaryMetric'], 120 ) : '';
		$card['owner']                 = isset( $card['owner'] ) ? $this->trim_card_text( $card['owner'], 80 ) : '';
		$card['reviewDate']            = isset( $card['reviewDate'] ) ? sanitize_text_field( (string) $card['reviewDate'] ) : '';
		$card['successCriteria']       = isset( $card['successCriteria'] ) ? $this->trim_card_text( $card['successCriteria'], 180 ) : '';
		$card['iceScore']              = 'action' === $kind ? $this->compute_idea_board_ice_score( $impact, $confidence, $effort ) : 0;

		return $card;
	}

	/**
	 * Trim model-provided text for a card field.
	 *
	 * @param mixed $value Text value.
	 * @param int   $limit Character limit.
	 * @return string
	 */
	private function trim_card_text( $value, $limit ) {
		$text = trim( wp_strip_all_tags( (string) $value ) );
		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		return rtrim( substr( $text, 0, $limit - 3 ) ) . '...';
	}

	/**
	 * Normalise a user or AI status value into a supported slug.
	 *
	 * @param mixed $value Raw status.
	 * @return string
	 */
	private function normalise_status_slug( $value ) {
		$status = strtolower( (string) $value );
		$status = preg_replace( '/[^a-z0-9]+/', '_', $status );
		$status = trim( (string) $status, '_' );

		return '' !== $status ? $status : 'new';
	}

	/**
	 * Sort and compact per-stage card order values.
	 *
	 * @param array $cards Cards.
	 * @return array
	 */
	private function normalise_card_orders( array $cards ) {
		$stages = array_keys( $this->allowed_stages() );
		$result = array();

		foreach ( $stages as $stage ) {
			$stage_cards = array_values(
				array_filter(
					$cards,
					static function ( $card ) use ( $stage ) {
						return isset( $card['stage'] ) && $stage === $card['stage'];
					}
				)
			);

			usort(
				$stage_cards,
				static function ( $a, $b ) {
					if ( (int) $a['order'] === (int) $b['order'] ) {
						return strcmp( (string) $a['title'], (string) $b['title'] );
					}

					return (int) $a['order'] < (int) $b['order'] ? -1 : 1;
				}
			);

			foreach ( $stage_cards as $index => $card ) {
				$card['order'] = $index;
				$result[]      = $card;
			}
		}

		return $result;
	}

	/**
	 * Build board links from rootInsightId metadata.
	 *
	 * @param array $cards Cards.
	 * @return array
	 */
	private function build_idea_board_links( array $cards ) {
		$ids   = array_fill_keys( wp_list_pluck( $cards, 'id' ), true );
		$links = array();

		foreach ( $cards as $card ) {
			if ( empty( $card['id'] ) ) {
				continue;
			}

			$root_ids = ! empty( $card['rootInsightIds'] ) && is_array( $card['rootInsightIds'] )
				? $card['rootInsightIds']
				: ( ! empty( $card['rootInsightId'] ) ? array( $card['rootInsightId'] ) : array() );

			foreach ( $root_ids as $root_id ) {
				if ( empty( $root_id ) || $root_id === $card['id'] || ! isset( $ids[ $root_id ] ) ) {
					continue;
				}

				$links[] = array(
					'from'  => $root_id,
					'to'    => $card['id'],
					'label' => __( 'Linked insight', 'woocommerce-claude' ),
				);
			}
		}

		return $links;
	}

	/**
	 * Return the fixed v1 board columns.
	 *
	 * @return array
	 */
	private function default_idea_board_columns() {
		return array(
			array(
				'id'          => 'insights',
				'title'       => __( 'Insights', 'woocommerce-claude' ),
				'description' => __( 'Signals from store data that may matter.', 'woocommerce-claude' ),
			),
			array(
				'id'          => 'investigate',
				'title'       => __( 'Investigate', 'woocommerce-claude' ),
				'description' => __( 'Questions and checks before deciding.', 'woocommerce-claude' ),
			),
			array(
				'id'          => 'context',
				'title'       => __( 'Human context', 'woocommerce-claude' ),
				'description' => __( 'Supplier, campaign, support, and brand judgement.', 'woocommerce-claude' ),
			),
			array(
				'id'          => 'proposed_actions',
				'title'       => __( 'Proposed actions', 'woocommerce-claude' ),
				'description' => __( 'Draft recommendations that still need approval.', 'woocommerce-claude' ),
			),
		);
	}

	/**
	 * Allowed stage IDs.
	 *
	 * @return array
	 */
	private function allowed_stages() {
		return array(
			'insights'         => true,
			'investigate'      => true,
			'context'          => true,
			'proposed_actions' => true,
		);
	}

	/**
	 * Allowed card kind IDs.
	 *
	 * @return array
	 */
	private function allowed_kinds() {
		return array(
			'insight'  => true,
			'question' => true,
			'context'  => true,
			'action'   => true,
		);
	}

	/**
	 * Allowed card status IDs.
	 *
	 * @return array
	 */
	private function allowed_statuses() {
		return array(
			'new'                   => true,
			'merchant_input_needed' => true,
			'reviewed'              => true,
			'draft'                 => true,
			'approval_required'     => true,
			'accepted'              => true,
		);
	}

	/**
	 * Allowed brainstorm session status IDs.
	 *
	 * @return array
	 */
	private function allowed_session_statuses() {
		return array(
			'active'             => true,
			'ready_to_reanalyse' => true,
			'analysed'           => true,
		);
	}

	/**
	 * Allowed card colours.
	 *
	 * @return array
	 */
	private function allowed_colours() {
		return array(
			'yellow' => true,
			'pink'   => true,
			'green'  => true,
			'blue'   => true,
			'orange' => true,
			'lime'   => true,
			'white'  => true,
		);
	}

	/**
	 * Allowed commercial lever IDs.
	 *
	 * @return array
	 */
	private function allowed_revenue_levers() {
		return array(
			'traffic'            => true,
			'conversion'         => true,
			'aov'                => true,
			'retention'          => true,
			'margin'             => true,
			'inventory'          => true,
			'pricing'            => true,
			'campaign_spend'     => true,
			'catalogue_quality'  => true,
			'revenue_protection' => true,
		);
	}

	/**
	 * Allowed signal severity values.
	 *
	 * @return array
	 */
	private function allowed_severities() {
		return array(
			'low'      => true,
			'medium'   => true,
			'high'     => true,
			'critical' => true,
		);
	}

	/**
	 * Allowed question gate priorities.
	 *
	 * @return array
	 */
	private function allowed_gate_priorities() {
		return array(
			'required' => true,
			'useful'   => true,
			'optional' => true,
		);
	}

	/**
	 * Allowed answerability values.
	 *
	 * @return array
	 */
	private function allowed_answerability() {
		return array(
			'ai'       => true,
			'merchant' => true,
			'both'     => true,
		);
	}

	/**
	 * Allowed draft action types.
	 *
	 * @return array
	 */
	private function allowed_action_types() {
		return array(
			'investigate'             => true,
			'merchandise'             => true,
			'restock'                 => true,
			'pause_campaign'          => true,
			'create_offer'            => true,
			'improve_product_content' => true,
			'retention_campaign'      => true,
			'pricing_test'            => true,
			'checkout_fix'            => true,
			'customer_follow_up'      => true,
		);
	}

	/**
	 * Return default card colour for a kind.
	 *
	 * @param string $kind Card kind.
	 * @return string
	 */
	private function default_colour_for_kind( $kind ) {
		if ( 'question' === $kind ) {
			return 'blue';
		}
		if ( 'context' === $kind ) {
			return 'orange';
		}
		if ( 'action' === $kind ) {
			return 'lime';
		}

		return 'yellow';
	}

	/**
	 * Return default card status for a kind.
	 *
	 * @param string $kind Card kind.
	 * @return string
	 */
	private function default_status_for_kind( $kind ) {
		if ( 'question' === $kind || 'context' === $kind ) {
			return 'merchant_input_needed';
		}
		if ( 'action' === $kind ) {
			return 'approval_required';
		}

		return 'new';
	}

	/**
	 * Return the default severity for a card kind.
	 *
	 * @param string $kind Card kind.
	 * @return string
	 */
	private function default_severity_for_kind( $kind ) {
		return 'insight' === $kind ? 'medium' : 'low';
	}

	/**
	 * Return the default gate priority for a card kind.
	 *
	 * @param string $kind Card kind.
	 * @return string
	 */
	private function default_gate_priority_for_kind( $kind ) {
		return 'question' === $kind ? 'required' : 'optional';
	}

	/**
	 * Return the default answerability for a card kind.
	 *
	 * @param string $kind Card kind.
	 * @return string
	 */
	private function default_answerability_for_kind( $kind ) {
		return 'question' === $kind ? 'merchant' : 'both';
	}

	/**
	 * Normalise a key against an allowed-value map.
	 *
	 * @param mixed  $value   Raw value.
	 * @param array  $allowed Allowed values.
	 * @param string $fallback Default value.
	 * @return string
	 */
	private function normalise_allowed_key( $value, array $allowed, $fallback ) {
		$key = sanitize_key( (string) $value );

		return isset( $allowed[ $key ] ) ? $key : $fallback;
	}

	/**
	 * Normalise low/medium/high scorecard levels.
	 *
	 * @param mixed  $value   Raw value.
	 * @param string $fallback Default value.
	 * @return string
	 */
	private function normalise_level( $value, $fallback = 'medium' ) {
		$key = sanitize_key( (string) $value );

		return in_array( $key, array( 'low', 'medium', 'high' ), true ) ? $key : $fallback;
	}

	/**
	 * Normalise revenue lever tags, with a conservative default by card kind.
	 *
	 * @param array  $raw_levers Raw levers.
	 * @param string $kind       Card kind.
	 * @return array
	 */
	private function normalise_revenue_levers( array $raw_levers, $kind ) {
		$allowed = $this->allowed_revenue_levers();
		$levers  = array();
		$seen    = array();

		foreach ( $raw_levers as $raw_lever ) {
			$lever = sanitize_key( (string) $raw_lever );
			if ( '' === $lever || ! isset( $allowed[ $lever ] ) || isset( $seen[ $lever ] ) ) {
				continue;
			}

			$seen[ $lever ] = true;
			$levers[]       = $lever;
		}

		if ( empty( $levers ) && in_array( $kind, array( 'insight', 'action' ), true ) ) {
			$levers[] = 'revenue_protection';
		}

		return $levers;
	}

	/**
	 * Normalise a list of related signal IDs.
	 *
	 * @param array $raw_ids Raw IDs.
	 * @return array
	 */
	private function normalise_related_signal_ids( array $raw_ids ) {
		$ids  = array();
		$seen = array();
		foreach ( $raw_ids as $raw_id ) {
			$id = sanitize_key( (string) $raw_id );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$ids[]       = $id;
		}

		return $ids;
	}

	/**
	 * Compute a simple ICE score from action impact, confidence, and ease.
	 *
	 * @param string $impact     Impact level.
	 * @param string $confidence Confidence level.
	 * @param string $effort     Effort level.
	 * @return int
	 */
	private function compute_idea_board_ice_score( $impact, $confidence, $effort ) {
		$impact_score     = $this->level_score( $impact );
		$confidence_score = $this->level_score( $confidence );
		$ease_score       = 4 - $this->level_score( $effort );

		return $impact_score * $confidence_score * $ease_score;
	}

	/**
	 * Convert low/medium/high to 1/2/3.
	 *
	 * @param string $level Level.
	 * @return int
	 */
	private function level_score( $level ) {
		if ( 'high' === $level ) {
			return 3;
		}
		if ( 'low' === $level ) {
			return 1;
		}

		return 2;
	}

	/**
	 * Return the default lifecycle stage for a card kind.
	 *
	 * @param string $kind Card kind.
	 * @return string
	 */
	private function default_stage_for_kind( $kind ) {
		if ( 'question' === $kind ) {
			return 'investigate';
		}
		if ( 'context' === $kind ) {
			return 'context';
		}
		if ( 'action' === $kind ) {
			return 'proposed_actions';
		}

		return 'insights';
	}

	/**
	 * Return a safe fallback body for sparse model card candidates.
	 *
	 * @param string $kind Card kind.
	 * @return string
	 */
	private function default_body_for_kind( $kind ) {
		if ( 'question' === $kind ) {
			return __( 'Answer this before turning the signal into an action. Mark any unknown supplier, campaign, or customer-contact detail as merchant input needed.', 'woocommerce-claude' );
		}
		if ( 'context' === $kind ) {
			return __( 'Capture the human context the store data cannot know before recommendations become actions.', 'woocommerce-claude' );
		}
		if ( 'action' === $kind ) {
			return __( 'Review and approve this draft before changing spend, customer contact, prices, refunds, coupons, publishing, or stock commitments.', 'woocommerce-claude' );
		}

		return __( 'Review this signal before deciding whether it matters.', 'woocommerce-claude' );
	}

	/**
	 * Create a unique card ID from a title.
	 *
	 * @param string $title    Card title.
	 * @param array  $seen_ids IDs already present.
	 * @return string
	 */
	private function unique_idea_board_card_id( $title, array $seen_ids ) {
		$base = sanitize_title( $title );
		if ( '' === $base ) {
			$base = 'card';
		}

		$id     = $base;
		$suffix = 2;
		while ( isset( $seen_ids[ $id ] ) ) {
			$id = $base . '-' . $suffix;
			++$suffix;
		}

		return $id;
	}

	/**
	 * Create a unique session ID from a title.
	 *
	 * @param string $title    Session title.
	 * @param array  $sessions Existing sessions.
	 * @return string
	 */
	private function unique_idea_board_session_id( $title, array $sessions ) {
		$seen_ids = array_fill_keys( wp_list_pluck( $sessions, 'id' ), true );
		$base     = sanitize_title( $title );
		if ( '' === $base ) {
			$base = 'brainstorm-session';
		}

		$id     = $base;
		$suffix = 2;
		while ( isset( $seen_ids[ $id ] ) ) {
			$id = $base . '-' . $suffix;
			++$suffix;
		}

		return $id;
	}

	/**
	 * Create a unique note ID from a title.
	 *
	 * @param string $title Existing title or note seed.
	 * @param array  $notes Existing notes.
	 * @return string
	 */
	private function unique_idea_board_note_id( $title, array $notes ) {
		$seen_ids = array_fill_keys( wp_list_pluck( $notes, 'id' ), true );
		$base     = sanitize_title( $title );
		if ( '' === $base ) {
			$base = 'answer';
		}
		$base = 'answer-' . $base;

		$id     = $base;
		$suffix = 2;
		while ( isset( $seen_ids[ $id ] ) ) {
			$id = $base . '-' . $suffix;
			++$suffix;
		}

		return $id;
	}

	/**
	 * Check whether a question is within the merchant/store brainstorming scope.
	 *
	 * @param array $question Question card.
	 * @return bool
	 */
	private function is_idea_board_question_store_related( array $question ) {
		$text = strtolower(
			(string) ( isset( $question['title'] ) ? $question['title'] : '' )
			. ' '
			. (string) ( isset( $question['body'] ) ? $question['body'] : '' )
		);

		$keywords = array(
			'ad',
			'ads',
			'attribute',
			'attributes',
			'back-in-stock',
			'bestseller',
			'bestsellers',
			'campaign',
			'catalogue',
			'checkout',
			'conversion',
			'coupon',
			'customer',
			'customers',
			'description',
			'descriptions',
			'discount',
			'email',
			'inventory',
			'margin',
			'marketing',
			'order',
			'orders',
			'payment',
			'price',
			'product',
			'products',
			'refund',
			'returns',
			'revenue',
			'sales',
			'search',
			'shipping',
			'sku',
			'stock',
			'store',
			'supplier',
			'support',
			'traffic',
			'woocommerce',
		);

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $text, $keyword ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Find a board card by ID.
	 *
	 * @param array  $cards   Board cards.
	 * @param string $card_id Card ID.
	 * @return array|null
	 */
	private function find_idea_board_card_by_id( array $cards, $card_id ) {
		foreach ( $cards as $card ) {
			if ( isset( $card['id'] ) && $card['id'] === $card_id ) {
				return $card;
			}
		}

		return null;
	}

	/**
	 * Find a brainstorm session by ID.
	 *
	 * @param array  $sessions   Board sessions.
	 * @param string $session_id Session ID.
	 * @return array|null
	 */
	private function find_idea_board_session_by_id( array $sessions, $session_id ) {
		foreach ( $sessions as $session ) {
			if ( isset( $session['id'] ) && $session['id'] === $session_id ) {
				return $session;
			}
		}

		return null;
	}

	/**
	 * Return insight card IDs from a board card set.
	 *
	 * @param array $cards Board cards.
	 * @return array
	 */
	private function insight_card_ids( array $cards ) {
		$ids = array();
		foreach ( $cards as $card ) {
			if ( isset( $card['id'], $card['kind'] ) && 'insight' === $card['kind'] ) {
				$ids[] = $card['id'];
			}
		}

		return $ids;
	}

	/**
	 * Normalise a root insight ID list.
	 *
	 * @param array $raw_ids     Raw IDs.
	 * @param array $allowed_ids Allowed insight IDs. Empty means only sanitise/dedupe.
	 * @return array
	 */
	private function normalise_root_insight_ids_list( array $raw_ids, array $allowed_ids ) {
		$allowed = ! empty( $allowed_ids ) ? array_fill_keys( $allowed_ids, true ) : array();
		$ids     = array();
		$seen    = array();

		foreach ( $raw_ids as $raw_id ) {
			$id = sanitize_key( (string) $raw_id );
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( ! empty( $allowed ) && ! isset( $allowed[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$ids[]       = $id;
		}

		return $ids;
	}

	/**
	 * Normalise selected root insight IDs for a brainstorm request.
	 *
	 * @param mixed $raw_ids Raw request value.
	 * @param array $cards   Sanitised board cards.
	 * @return array|\WP_Error
	 */
	private function normalise_brainstorm_root_insight_ids( $raw_ids, array $cards ) {
		if ( ! is_array( $raw_ids ) ) {
			return new \WP_Error( 'invalid_board_brainstorm', __( 'Select at least one insight before starting a brainstorm session.', 'woocommerce-claude' ) );
		}

		$ids = $this->normalise_root_insight_ids_list( $raw_ids, $this->insight_card_ids( $cards ) );
		if ( empty( $ids ) ) {
			return new \WP_Error( 'invalid_board_brainstorm', __( 'Select at least one valid insight before starting a brainstorm session.', 'woocommerce-claude' ) );
		}

		return $ids;
	}

	/**
	 * Build a fallback session title from selected insight titles.
	 *
	 * @param array $cards            Board cards.
	 * @param array $root_insight_ids Selected insight IDs.
	 * @return string
	 */
	private function build_default_session_title( array $cards, array $root_insight_ids ) {
		$titles = array();
		$wanted = array_fill_keys( $root_insight_ids, true );
		foreach ( $cards as $card ) {
			if ( isset( $card['id'], $card['title'] ) && isset( $wanted[ $card['id'] ] ) ) {
				$titles[] = $card['title'];
			}
		}

		if ( count( $titles ) > 1 ) {
			return $this->trim_card_text( implode( ' + ', array_slice( $titles, 0, 2 ) ), 100 );
		}

		return ! empty( $titles ) ? $this->trim_card_text( $titles[0], 100 ) : __( 'Brainstorm session', 'woocommerce-claude' );
	}

	/**
	 * Mark sessions as analysed after the AI has read fresh board context.
	 *
	 * @param array  $sessions Session rows.
	 * @param string $summary        Re-analysis summary.
	 * @param array  $decision_brief Decision brief.
	 * @return array
	 */
	private function mark_sessions_analysed( array $sessions, $summary, array $decision_brief ) {
		$now = current_datetime()->format( DATE_ATOM );
		foreach ( $sessions as $index => $session ) {
			if ( ! is_array( $session ) ) {
				continue;
			}
			$sessions[ $index ]['status']         = 'analysed';
			$sessions[ $index ]['updatedAt']      = $now;
			$sessions[ $index ]['lastAnalysedAt'] = $now;
			if ( '' !== $summary ) {
				$sessions[ $index ]['summary'] = $this->trim_card_text( $summary, 400 );
			}
			$sessions[ $index ]['decisionBrief'] = $decision_brief;
		}

		return $sessions;
	}

	/**
	 * Check whether a session still has unanswered required merchant-input gates.
	 *
	 * @param array  $board      Board.
	 * @param string $session_id Session ID.
	 * @return bool
	 */
	private function session_has_unanswered_required_merchant_gate( array $board, $session_id ) {
		if ( '' === $session_id ) {
			return false;
		}

		$answered = array();
		foreach ( $board['notes'] as $note ) {
			if ( ! is_array( $note ) || empty( $note['parentCardId'] ) || empty( $note['sessionId'] ) || $note['sessionId'] !== $session_id ) {
				continue;
			}
			if ( in_array( $note['kind'], array( 'answer', 'context' ), true ) ) {
				$answered[ $note['parentCardId'] ] = true;
			}
		}

		foreach ( $board['cards'] as $card ) {
			if (
				! is_array( $card )
				|| 'question' !== $card['kind']
				|| $card['sessionId'] !== $session_id
				|| 'required' !== $card['gatePriority']
				|| 'ai' === $card['answerability']
				|| isset( $answered[ $card['id'] ] )
			) {
				continue;
			}

			return true;
		}

		return false;
	}

	/**
	 * Keep rootInsightId linked to a submitted insight when possible.
	 *
	 * @param string $root_id         Proposed root insight ID.
	 * @param array  $submitted_cards Submitted board cards.
	 * @return string
	 */
	private function normalise_root_insight_id( $root_id, array $submitted_cards ) {
		$insight_ids = array();
		foreach ( $submitted_cards as $card ) {
			if ( isset( $card['id'], $card['kind'] ) && 'insight' === $card['kind'] ) {
				$insight_ids[ $card['id'] ] = true;
			}
		}

		if ( '' !== $root_id && isset( $insight_ids[ $root_id ] ) ) {
			return $root_id;
		}

		$ids = array_keys( $insight_ids );
		return $ids ? $ids[0] : '';
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
		if ( false === $start ) {
			return '';
		}

		// Walk forward from the opening brace, tracking nesting depth and
		// whether we are inside a string (to ignore braces inside strings).
		$len    = strlen( $text );
		$depth  = 0;
		$in_str = false;
		$escape = false;

		for ( $i = $start; $i < $len; $i++ ) {
			$char = $text[ $i ];

			if ( $escape ) {
				$escape = false;
				continue;
			}

			if ( $in_str ) {
				if ( '\\' === $char ) {
					$escape = true;
				} elseif ( '"' === $char ) {
					$in_str = false;
				}
				continue;
			}

			if ( '"' === $char ) {
				$in_str = true;
			} elseif ( '{' === $char ) {
				$depth++;
			} elseif ( '}' === $char ) {
				$depth--;
				if ( 0 === $depth ) {
					return substr( $text, $start, ( $i - $start ) + 1 );
				}
			}
		}

		return '';
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
