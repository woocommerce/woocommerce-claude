<?php
/**
 * Integration tests for IdeaBoardRestController.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\HeyWoo\Difm\IdeaBoardRestController;

/**
 * Tests for IdeaBoardRestController.
 */
class Test_Idea_Board_Rest_Controller extends WP_UnitTestCase {

	use \WooCommerce\Claude\Tests\Integration\AnalyticsFixtures;

	/**
	 * REST server instance used for dispatching test requests.
	 *
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * Admin user ID used by tests.
	 *
	 * @var int
	 */
	protected $admin_user_id = 0;

	/**
	 * Whether the Hey Woo idea-board REST callback has been hooked for this process.
	 *
	 * @var bool
	 */
	protected static $idea_board_routes_hooked = false;

	/**
	 * Set up a REST server for each test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;

		$this->load_hey_woo_idea_board_controller();
		update_option( 'hey_woo_enable_idea_board', 'yes' );
		if ( ! self::$idea_board_routes_hooked ) {
			( new IdeaBoardRestController() )->register();
			self::$idea_board_routes_hooked = true;
		}
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- This action is documented in wp-includes/rest-api.php.
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down: remove API key option and cached board payloads.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		delete_option( 'hey_woo_enable_idea_board' );
		delete_option( IdeaBoardRestController::SAVED_BOARD_OPTION );
		$this->delete_idea_board_transients();
		self::$idea_board_routes_hooked = false;
		parent::tear_down();
		$this->delete_woocommerce_analytics_fixture_rows();
	}

	/**
	 * The idea-board route is registered.
	 */
	public function test_route_is_registered() {
		$routes = $this->server->get_routes();

		$this->assertTrue( class_exists( IdeaBoardRestController::class ) );
		$this->assertSame( 'yes', get_option( 'hey_woo_enable_idea_board' ) );
		$this->assertArrayHasKey( '/' . IdeaBoardRestController::NAMESPACE . IdeaBoardRestController::ROUTE, $routes );
		$this->assertArrayHasKey( '/' . IdeaBoardRestController::NAMESPACE . IdeaBoardRestController::SAVE_ROUTE, $routes );
		$this->assertArrayHasKey( '/' . IdeaBoardRestController::NAMESPACE . IdeaBoardRestController::BRAINSTORM_ROUTE, $routes );
		$this->assertArrayHasKey( '/' . IdeaBoardRestController::NAMESPACE . IdeaBoardRestController::ANSWER_QUESTION_ROUTE, $routes );
		$this->assertArrayHasKey( '/' . IdeaBoardRestController::NAMESPACE . IdeaBoardRestController::REANALYSE_ROUTE, $routes );
	}

	/**
	 * Unauthenticated requests to GET /difm/idea-board receive a 403.
	 */
	public function test_idea_board_requires_manage_woocommerce() {
		$request  = new \WP_REST_Request( 'GET', '/hey-woo/v1/difm/idea-board' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Unauthenticated requests to POST /difm/idea-board/save receive a 403.
	 */
	public function test_idea_board_save_requires_manage_woocommerce() {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/idea-board/save' );
		$request->set_param( 'board', $this->sample_current_period_board() );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Unauthenticated requests to POST /difm/idea-board/brainstorm receive a 403.
	 */
	public function test_idea_board_brainstorm_requires_manage_woocommerce() {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/idea-board/brainstorm' );
		$request->set_param( 'board', $this->sample_current_period_board() );
		$request->set_param( 'rootInsightIds', array( 'stock-out-signal' ) );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Unauthenticated requests to POST /difm/idea-board/answer-question receive a 403.
	 */
	public function test_idea_board_question_answer_requires_manage_woocommerce() {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/idea-board/answer-question' );
		$request->set_param( 'board', $this->sample_current_period_board() );
		$request->set_param( 'cardId', 'stock-question' );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Unauthenticated requests to POST /difm/idea-board/reanalyse receive a 403.
	 */
	public function test_idea_board_reanalysis_requires_manage_woocommerce() {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/idea-board/reanalyse' );
		$request->set_param( 'board', $this->sample_reanalysis_board() );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * GET /difm/idea-board requires AI content instead of building fallback cards.
	 */
	public function test_idea_board_requires_anthropic_key_for_content() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$product_id  = $this->seed_simple_product(
			array(
				'name'  => 'Canvas Tote',
				'sku'   => 'TOTE-CANVAS-' . wp_rand(),
				'price' => 42,
			)
		);
		$customer_id = $this->seed_customer( 'idea-board-customer@example.test' );

		$this->seed_paid_order(
			array(
				'customer_id' => $customer_id,
				'total'       => 84.00,
				'date'        => current_datetime()->modify( '-5 days' )->format( 'Y-m-d H:i:s' ),
				'items'       => array(
					array(
						'product_id' => $product_id,
						'qty'        => 2,
					),
				),
			)
		);

		$response = $this->dispatch_idea_board();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'Anthropic API key', $data['message'] );
		$this->assertArrayNotHasKey( 'board', $data );
	}

	/**
	 * A saved board from the old freeform layout version is ignored.
	 */
	public function test_idea_board_ignores_old_saved_board_versions() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		update_option(
			IdeaBoardRestController::SAVED_BOARD_OPTION,
			array(
				'version' => '2026-05-12-ai-content-layout-v3',
				'payload' => array(
					'status' => 'ok',
					'board'  => array(
						'notes' => array(),
					),
				),
			),
			false
		);

		$response = $this->dispatch_idea_board();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'Anthropic API key', $data['message'] );
	}

	/**
	 * Valid saved boards from earlier versions are upgraded instead of forcing an AI refresh.
	 */
	public function test_idea_board_upgrades_valid_old_saved_board_without_ai_refresh() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$board = $this->sample_current_period_board();
		update_option(
			IdeaBoardRestController::SAVED_BOARD_OPTION,
			array(
				'version' => '2026-05-12-ai-content-layout-v3',
				'payload' => array(
					'status' => 'ok',
					'board'  => $board,
				),
			),
			false
		);

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				return new \WP_Error( 'unexpected_http_call', 'Unexpected Anthropic call.' );
			}
		);

		$response = $this->dispatch_idea_board();
		$data     = $response->get_data();
		$saved    = get_option( IdeaBoardRestController::SAVED_BOARD_OPTION, array() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 0, $call_count );
		$this->assertSame( IdeaBoardRestController::CACHE_VERSION, $saved['version'] );
		$this->assertSame( 'stock-out-signal', $data['board']['cards'][0]['id'] );
		$this->assertArrayHasKey( 'decisionBrief', $data['board'] );
	}

	/**
	 * POST /difm/idea-board/save persists a merchant-edited board without requiring AI.
	 */
	public function test_idea_board_save_persists_current_board() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$board                      = $this->sample_current_period_board();
		$board['cards'][0]['stage'] = 'investigate';
		$board['cards'][0]['order'] = 3;
		$board['cards'][1]['order'] = 0;

		$response = $this->dispatch_idea_board_save( $board );
		$data     = $response->get_data();
		$saved    = get_option( IdeaBoardRestController::SAVED_BOARD_OPTION, array() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertFalse( $data['board']['freshness']['isStale'] );
		$this->assertSame( 'sessions', $data['board']['layout']['source'] );
		$this->assertSame( 'investigate', $this->cards_by_id( $data['board']['cards'] )['stock-out-signal']['stage'] );
		$this->assertSame( 1, $this->cards_by_id( $data['board']['cards'] )['stock-out-signal']['order'] );
		$this->assertIsArray( $saved );
		$this->assertSame( IdeaBoardRestController::CACHE_VERSION, $saved['version'] );
		$this->assertArrayNotHasKey( 'freshness', $saved['payload']['board'] );
		$this->assertSame(
			array(
				'stock-context',
				'stock-out-signal',
				'stock-question',
			),
			$this->sorted_card_ids( $saved['payload']['board']['cards'] )
		);
	}

	/**
	 * POST /difm/idea-board/save accepts and normalises decision-layer metadata.
	 */
	public function test_idea_board_save_normalises_decision_layer_fields() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$board                  = $this->sample_current_period_board();
		$board['decisionBrief'] = $this->valid_decision_brief();

		$board['cards'][0]['revenueLevers']   = array( 'inventory', 'not-a-lever', 'revenue_protection', 'inventory' );
		$board['cards'][0]['severity']        = 'critical';
		$board['cards'][0]['estimatedImpact'] = 'About £77k period revenue affected';
		$board['cards'][0]['whyItMatters']    = 'Unavailable best-sellers can waste demand and paid traffic.';
		$board['cards'][0]['evidenceDetails'] = array(
			'metricBaseline'    => '4 products, 44% of revenue',
			'involvedProducts'  => array( 'Hoodie', 'Headphones' ),
			'involvedOrders'    => 'Aggregated order count only',
			'involvedCustomers' => 'No customer-level data',
			'unknowns'          => 'Restock ETA',
		);

		$board['cards'][1]['gatePriority']  = 'nonsense';
		$board['cards'][1]['answerability'] = 'unknown';

		$board['cards'][] = array_merge(
			$this->valid_reanalysis_action_card(),
			array(
				'id'                    => 'metadata-action',
				'actionType'            => 'not-real',
				'expectedRevenueImpact' => 'high',
				'effort'                => 'low',
			)
		);

		$response = $this->dispatch_idea_board_save( $board );
		$data     = $response->get_data();
		$cards    = $this->cards_by_id( $data['board']['cards'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'Confirm blockers before approving a campaign change.', $data['board']['decisionBrief']['bestNextMove'] );
		$this->assertSame( array( 'inventory', 'revenue_protection' ), $cards['stock-out-signal']['revenueLevers'] );
		$this->assertSame( 'critical', $cards['stock-out-signal']['severity'] );
		$this->assertSame( 'Hoodie, Headphones', $cards['stock-out-signal']['evidenceDetails']['involvedProducts'] );
		$this->assertSame( 'required', $cards['stock-question']['gatePriority'] );
		$this->assertSame( 'merchant', $cards['stock-question']['answerability'] );
		$this->assertSame( 'investigate', $cards['metadata-action']['actionType'] );
		$this->assertSame( 27, $cards['metadata-action']['iceScore'] );
	}

	/**
	 * POST /difm/idea-board/save rejects unsupported stage and kind values.
	 *
	 * @dataProvider invalid_card_value_provider
	 *
	 * @param string $field Field to mutate.
	 * @param string $value Invalid value.
	 */
	public function test_idea_board_save_rejects_unsupported_card_values( $field, $value ) {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$board                       = $this->sample_current_period_board();
		$board['cards'][0][ $field ] = $value;

		$response = $this->dispatch_idea_board_save( $board );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'unsupported value', $data['message'] );
	}

	/**
	 * Invalid card values.
	 *
	 * @return array
	 */
	public function invalid_card_value_provider() {
		return array(
			'stage' => array( 'stage', 'done' ),
			'kind'  => array( 'kind', 'task' ),
		);
	}

	/**
	 * POST /difm/idea-board/save allows an empty board so card removals persist.
	 */
	public function test_idea_board_save_allows_empty_board() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$board             = $this->sample_current_period_board();
		$board['cards']    = array();
		$board['links']    = array();
		$board['sessions'] = array();
		$board['notes']    = array();

		$save_response = $this->dispatch_idea_board_save( $board );
		$save_data     = $save_response->get_data();
		$this->assertSame( 200, $save_response->get_status() );
		$this->assertSame( 'ok', $save_data['status'] );
		$this->assertSame( array(), $save_data['board']['cards'] );

		$get_response = $this->dispatch_idea_board();
		$get_data     = $get_response->get_data();
		$this->assertSame( 200, $get_response->get_status() );
		$this->assertSame( 'ok', $get_data['status'] );
		$this->assertSame( array(), $get_data['board']['cards'] );
	}

	/**
	 * GET /difm/idea-board returns the saved board without an Anthropic key.
	 */
	public function test_idea_board_returns_saved_board_without_anthropic_key() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$saved_response = $this->dispatch_idea_board_save( $this->sample_current_period_board() );
		$this->assertSame( 'ok', $saved_response->get_data()['status'] );

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$call_count ) {
				++$call_count;
				return $preempt;
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 0, $call_count );
		$this->assertFalse( $data['board']['freshness']['isStale'] );
		$this->assertSame(
			array(
				'stock-context',
				'stock-out-signal',
				'stock-question',
			),
			$this->sorted_card_ids( $data['board']['cards'] )
		);
	}

	/**
	 * GET /difm/idea-board supports the date presets shown in the UI.
	 */
	public function test_idea_board_returns_saved_boards_for_supported_date_presets() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		foreach ( array( 7, 30, 90, 365 ) as $days ) {
			$saved_response = $this->dispatch_idea_board_save( $this->sample_current_period_board( $days ) );
			$this->assertSame( 'ok', $saved_response->get_data()['status'] );

			$response = $this->dispatch_idea_board( false, $days );
			$data     = $response->get_data();

			$this->assertSame( 200, $response->get_status() );
			$this->assertSame( 'ok', $data['status'] );
			$this->assertSame( $days, $data['board']['period']['days'] );
			$this->assertSame( sprintf( 'Last %d days', $days ), $data['board']['period']['label'] );
			$this->assertFalse( $data['board']['freshness']['isStale'] );
			$this->assertSame( $days, $data['board']['freshness']['currentPeriod']['days'] );
		}
	}

	/**
	 * GET /difm/idea-board keeps a saved board and marks it stale when its period has moved.
	 */
	public function test_idea_board_marks_saved_board_stale_without_rebuilding() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$board                    = $this->sample_current_period_board();
		$board['period']['start'] = current_datetime()->modify( '-90 days' )->format( 'Y-m-d' );
		$board['period']['end']   = current_datetime()->modify( '-1 day' )->format( 'Y-m-d' );

		$saved_response = $this->dispatch_idea_board_save( $board );
		$this->assertSame( 'ok', $saved_response->get_data()['status'] );

		$response = $this->dispatch_idea_board();
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertTrue( $data['board']['freshness']['isStale'] );
		$this->assertSame( $board['period']['end'], $data['board']['period']['end'] );
		$this->assertSame( $board['period']['end'], $data['board']['freshness']['savedPeriod']['end'] );
		$this->assertNotSame( $board['period']['end'], $data['board']['freshness']['currentPeriod']['end'] );
	}

	/**
	 * GET /difm/idea-board returns an error when AI content cannot be parsed.
	 */
	public function test_idea_board_does_not_fall_back_when_ai_content_is_invalid() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$call_count = 0;
		add_filter(
			'pre_http_request',
			static function () use ( &$call_count ) {
				++$call_count;

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'    => 'message',
							'content' => array(
								array(
									'type' => 'text',
									'text' => 'No JSON here.',
								),
							),
						)
					),
					'headers'  => array(),
				);
			}
		);

		$response = $this->dispatch_idea_board( true );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $call_count );
		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'did not include JSON', $data['message'] );
		$this->assertArrayNotHasKey( 'board', $data );
	}

	/**
	 * GET /difm/idea-board lets Claude create the initial insight-only board.
	 */
	public function test_idea_board_uses_ai_generated_initial_insight_cards() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$product_id  = $this->seed_simple_product(
			array(
				'name'  => 'Canvas Tote',
				'sku'   => 'TOTE-CANVAS-' . wp_rand(),
				'price' => 42,
			)
		);
		$customer_id = $this->seed_customer( 'idea-board-customer@example.test' );

		$this->seed_paid_order(
			array(
				'customer_id' => $customer_id,
				'total'       => 84.00,
				'date'        => current_datetime()->modify( '-5 days' )->format( 'Y-m-d H:i:s' ),
				'items'       => array(
					array(
						'product_id' => $product_id,
						'qty'        => 2,
					),
				),
			)
		);

		$captured_bodies = array();
		$call_count      = 0;
		$decision_brief  = $this->valid_decision_brief();
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args ) use ( &$captured_bodies, &$call_count, $decision_brief ) {
				$captured_bodies[] = json_decode( $parsed_args['body'], true );
				++$call_count;

				$text = wp_json_encode(
					array(
						'summary'       => 'Two store signals are ready for merchant review.',
						'decisionBrief' => $decision_brief,
						'cards'         => array(
							array(
								'id'               => 'canvas-momentum',
								'kind'             => 'insight',
								'stage'            => 'insights',
								'title'            => 'Canvas Tote is moving',
								'body'             => 'Recent paid orders give Canvas Tote a clear merchandising signal to build from.',
								'colour'           => 'yellow',
								'order'            => 0,
								'prompt'           => 'What would make this product worth investigating next?',
								'confidence'       => 'medium',
								'evidence'         => '2 units sold',
								'timeframe'        => 'Last 90 days',
								'source'           => 'Product sales analytics',
								'status'           => 'new',
								'approvalRequired' => false,
								'revenueLevers'    => array( 'conversion', 'catalogue_quality' ),
								'severity'         => 'medium',
								'estimatedImpact'  => 'Moderate product revenue opportunity',
								'whyItMatters'     => 'The product is already selling, so merchandising improvements have a live demand signal.',
								'relatedSignalIds' => array( 'repeat-buyer-signal' ),
								'evidenceDetails'  => array(
									'metricBaseline'   => '2 units sold',
									'comparisonPeriod' => 'Last 90 days',
									'involvedProducts' => 'Canvas Tote',
									'confidenceReason' => 'The signal comes directly from product sales analytics.',
									'dataFreshness'    => 'Current board period',
									'unknowns'         => 'The board does not know product page intent or stock plans.',
								),
								'rootInsightId'    => '',
								'createdBy'        => 'ai',
							),
							array(
								'id'               => 'repeat-buyer-signal',
								'kind'             => 'insight',
								'stage'            => 'insights',
								'title'            => 'Repeat buyers are active',
								'body'             => 'Returning customers are present, so post-purchase ideas may be worth exploring.',
								'colour'           => 'blue',
								'order'            => 1,
								'prompt'           => 'Which repeat-buyer question should we investigate first?',
								'confidence'       => 'medium',
								'evidence'         => 'Returning customers present',
								'timeframe'        => 'Last 90 days',
								'source'           => 'Customer analytics',
								'status'           => 'new',
								'approvalRequired' => false,
								'rootInsightId'    => '',
								'createdBy'        => 'ai',
							),
						),
					)
				);

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'    => 'message',
							'content' => array(
								array(
									'type' => 'text',
									'text' => $text,
								),
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board( true );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $call_count );
		$this->assertSame( 'ai', $data['board']['content']['source'] );
		$this->assertSame( 'sessions', $data['board']['layout']['source'] );
		$this->assertStringContainsString( 'initial_generation_rule', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'readiness_recommendations', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'allowed_revenue_levers', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'decision_brief_shape', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'evidence_details_shape', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringNotContainsString( 'positions', strtolower( $captured_bodies[0]['system'] ) );
		$this->assertStringContainsString( 'Canvas Tote', wp_json_encode( $data['board']['cards'] ) );
		$this->assertSame( 'Two store signals are ready for merchant review.', $data['board']['summary'] );
		$this->assertSame( 'Top products are unavailable while campaign context is active.', $data['board']['decisionBrief']['whatChanged'] );
		$this->assertSame( array( 'conversion', 'catalogue_quality' ), $data['board']['cards'][0]['revenueLevers'] );
		$this->assertSame( 'medium', $data['board']['cards'][0]['severity'] );
		$this->assertSame( '2 units sold', $data['board']['cards'][0]['evidenceDetails']['metricBaseline'] );
		$this->assertSame( array( 'insights' ), array_values( array_unique( wp_list_pluck( $data['board']['cards'], 'stage' ) ) ) );
		$this->assertSame( array( 'insight' ), array_values( array_unique( wp_list_pluck( $data['board']['cards'], 'kind' ) ) ) );
		$this->assertSame( 0, $data['board']['cards'][0]['x'] );
		$this->assertSame( 0, $data['board']['cards'][0]['y'] );
		$this->assertSame( 0, $data['board']['cards'][0]['rotation'] );

		$saved = get_option( IdeaBoardRestController::SAVED_BOARD_OPTION, array() );
		$this->assertSame( IdeaBoardRestController::CACHE_VERSION, $saved['version'] );
		$this->assertArrayNotHasKey( 'freshness', $saved['payload']['board'] );
		$this->assertSame(
			$this->sorted_card_ids( $data['board']['cards'] ),
			$this->sorted_card_ids( $saved['payload']['board']['cards'] )
		);
	}

	/**
	 * Initial AI generation accepts common insight-card envelope names.
	 */
	public function test_idea_board_accepts_initial_insight_alias_shape() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$decision_brief = $this->valid_decision_brief();
		add_filter(
			'pre_http_request',
			static function () use ( $decision_brief ) {
				$text = wp_json_encode(
					array(
						'summary' => 'One store signal is ready for merchant review.',
						'board'   => array(
							'decisionBrief' => $decision_brief,
							'insights'      => array(
								array(
									'title'           => 'Stock signal needs attention',
									'body'            => 'A product availability signal should be investigated before campaign work starts.',
									'confidence'      => 'high',
									'evidence'        => '4 products unavailable',
									'revenueLevers'   => array( 'inventory', 'revenue_protection' ),
									'severity'        => 'critical',
									'estimatedImpact' => 'High revenue protection opportunity',
									'whyItMatters'    => 'Unavailable products can waste demand.',
								),
							),
						),
					)
				);

				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'    => 'message',
							'content' => array(
								array(
									'type' => 'text',
									'text' => $text,
								),
							),
						)
					),
					'headers'  => array(),
				);
			}
		);

		$response = $this->dispatch_idea_board( true );
		$data     = $response->get_data();
		$card     = $data['board']['cards'][0];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'stock-signal-needs-attention', $card['id'] );
		$this->assertSame( 'insight', $card['kind'] );
		$this->assertSame( 'insights', $card['stage'] );
		$this->assertSame( 'new', $card['status'] );
		$this->assertSame( 'ai', $card['createdBy'] );
		$this->assertSame( array( 'inventory', 'revenue_protection' ), $card['revenueLevers'] );
		$this->assertSame( 'critical', $card['severity'] );
		$this->assertSame( 'Top products are unavailable while campaign context is active.', $data['board']['decisionBrief']['whatChanged'] );
	}

	/**
	 * Initial AI generation falls back to aggregated signals when card JSON is malformed.
	 */
	public function test_idea_board_falls_back_when_initial_ai_omits_cards() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'    => 'message',
							'content' => array(
								array(
									'type' => 'text',
									'text' => wp_json_encode(
										array(
											'summary' => 'Signals are available.',
											'decisionBrief' => array(
												'whatChanged' => 'Revenue changed.',
											),
										)
									),
								),
							),
						)
					),
					'headers'  => array(),
				);
			}
		);

		$response = $this->dispatch_idea_board( true );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'analytics_fallback', $data['board']['content']['source'] );
		$this->assertNotEmpty( $data['board']['cards'] );
		$this->assertSame( array( 'insight' ), array_values( array_unique( wp_list_pluck( $data['board']['cards'], 'kind' ) ) ) );
		$this->assertStringContainsString( 'signals ready to investigate', $data['board']['decisionBrief']['whatChanged'] );
	}

	/**
	 * Initial fallback starts with commercially problematic aggregated signals.
	 */
	public function test_idea_board_fallback_prioritises_problematic_aggregated_signals() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$product_id  = $this->seed_simple_product(
			array(
				'name'         => 'Noise Cancelling Headphones',
				'sku'          => 'HEADPHONES-' . wp_rand(),
				'price'        => 100,
				'stock_status' => 'outofstock',
			)
		);
		$customer_id = $this->seed_customer( 'idea-board-fallback-' . wp_rand() . '@example.test' );

		$this->seed_paid_order(
			array(
				'customer_id' => $customer_id,
				'total'       => 100.00,
				'date'        => current_datetime()->modify( '-5 days' )->format( 'Y-m-d H:i:s' ),
				'items'       => array(
					array(
						'product_id' => $product_id,
						'qty'        => 1,
					),
				),
			)
		);

		for ( $i = 0; $i < 4; ++$i ) {
			$this->seed_paid_order(
				array(
					'customer_id' => $customer_id,
					'total'       => 100.00,
					'date'        => current_datetime()->modify( '-100 days' )->format( 'Y-m-d H:i:s' ),
					'items'       => array(
						array(
							'product_id' => $product_id,
							'qty'        => 1,
						),
					),
				)
			);
		}

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'body'     => wp_json_encode(
						array(
							'type'    => 'message',
							'content' => array(
								array(
									'type' => 'text',
									'text' => wp_json_encode(
										array(
											'summary' => 'Signals are available.',
											'decisionBrief' => array(
												'whatChanged' => 'Revenue changed.',
											),
										)
									),
								),
							),
						)
					),
					'headers'  => array(),
				);
			}
		);

		$response = $this->dispatch_idea_board( true );
		$data     = $response->get_data();
		$ids      = wp_list_pluck( $data['board']['cards'], 'id' );
		$cards    = $this->cards_by_id( $data['board']['cards'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'analytics_fallback', $data['board']['content']['source'] );
		$this->assertSame( 'revenue-order-drop', $ids[0] );
		$this->assertContains( 'top-products-out-of-stock', $ids );
		$this->assertSame( array( 'revenue_protection', 'conversion', 'aov' ), $cards['revenue-order-drop']['revenueLevers'] );
		$this->assertSame( array( 'inventory', 'conversion', 'revenue_protection' ), $cards['top-products-out-of-stock']['revenueLevers'] );
		$this->assertStringContainsString( 'Noise Cancelling Headphones', $cards['top-products-out-of-stock']['evidenceDetails']['involvedProducts'] );
	}

	/**
	 * POST /difm/idea-board/reanalyse requires an Anthropic key.
	 */
	public function test_idea_board_reanalysis_requires_anthropic_key() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$response = $this->dispatch_idea_board_reanalysis( $this->sample_reanalysis_board() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'Anthropic API key', $data['message'] );
		$this->assertArrayNotHasKey( 'board', $data );
	}

	/**
	 * POST /difm/idea-board/brainstorm requires an Anthropic key.
	 */
	public function test_idea_board_brainstorm_requires_anthropic_key() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$response = $this->dispatch_idea_board_brainstorm( $this->sample_current_period_board(), array( 'stock-out-signal' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'Anthropic API key', $data['message'] );
		$this->assertArrayNotHasKey( 'board', $data );
	}

	/**
	 * POST /difm/idea-board/answer-question requires an Anthropic key.
	 */
	public function test_idea_board_question_answer_requires_anthropic_key() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$response = $this->dispatch_idea_board_question_answer( $this->sample_reanalysis_board(), 'stock-question' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'Anthropic API key', $data['message'] );
		$this->assertArrayNotHasKey( 'board', $data );
	}

	/**
	 * POST /difm/idea-board/brainstorm creates one multi-insight session with linked question cards.
	 */
	public function test_idea_board_brainstorm_accepts_multiple_insights() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$board            = $this->sample_current_period_board();
		$board['cards'][] = $this->sample_campaign_insight_card();

		$captured_bodies = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args ) use ( &$captured_bodies ) {
				$captured_bodies[] = json_decode( $parsed_args['body'], true );

				return $this->anthropic_text_response( wp_json_encode( $this->valid_brainstorm_payload() ) );
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_brainstorm( $board, array( 'stock-out-signal', 'campaign-mismatch' ) );
		$data     = $response->get_data();
		$cards    = $this->cards_by_id( $data['board']['cards'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertStringContainsString( 'selectedInsightIds', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'allowed_gate_priorities', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'allowed_answerability', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertCount( 2, $data['board']['sessions'][1]['rootInsightIds'] );
		$this->assertSame( 'Stock-out and campaign mismatch', $data['board']['sessions'][1]['title'] );
		$this->assertSame( 'Confirm blockers before approving a campaign change.', $data['board']['sessions'][1]['decisionBrief']['bestNextMove'] );
		$this->assertSame( 'stock-campaign-question', $cards['stock-campaign-question']['id'] );
		$this->assertSame( 'question', $cards['stock-campaign-question']['kind'] );
		$this->assertSame( 'stock-out-and-campaign-mismatch', $cards['stock-campaign-question']['sessionId'] );
		$this->assertSame( array( 'stock-out-signal', 'campaign-mismatch' ), $cards['stock-campaign-question']['rootInsightIds'] );
		$this->assertSame( 'required', $cards['stock-campaign-question']['gatePriority'] );
		$this->assertSame( 'both', $cards['stock-campaign-question']['answerability'] );
		$this->assertSame( array( 'inventory', 'campaign_spend', 'revenue_protection' ), $cards['stock-campaign-question']['revenueLevers'] );
		$this->assertArrayNotHasKey( 'pause-campaign', $cards );
		$this->assertSame( 'sessions', $data['board']['layout']['source'] );

		$saved = get_option( IdeaBoardRestController::SAVED_BOARD_OPTION, array() );
		$this->assertSame( IdeaBoardRestController::CACHE_VERSION, $saved['version'] );
		$this->assertArrayNotHasKey( 'freshness', $saved['payload']['board'] );
	}

	/**
	 * POST /difm/idea-board/answer-question appends an AI answer note to the selected question.
	 */
	public function test_idea_board_question_answer_adds_ai_answer_note() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$captured_bodies = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args ) use ( &$captured_bodies ) {
				$captured_bodies[] = json_decode( $parsed_args['body'], true );

				return $this->anthropic_text_response(
					wp_json_encode(
						array(
							'answer' => 'The board shows hoodies have an ETA, but headphones still need merchant input before action.',
						)
					)
				);
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_question_answer( $this->sample_reanalysis_board(), 'stock-question' );
		$data     = $response->get_data();
		$notes    = $data['board']['notes'];
		$prompt   = json_decode( $captured_bodies[0]['messages'][0]['content'], true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'When will stock return?', $prompt['exactQuestion'] );
		$this->assertStringContainsString( 'Answer the exact merchant question directly', $prompt['task'] );
		$this->assertSame( 'required', $prompt['questionGate']['gatePriority'] );
		$this->assertSame( 'merchant', $prompt['questionGate']['answerability'] );
		$this->assertCount( 2, $notes );
		$this->assertSame( 'answer', $notes[1]['kind'] );
		$this->assertSame( 'ai', $notes[1]['createdBy'] );
		$this->assertSame( 'stock-question', $notes[1]['parentCardId'] );
		$this->assertStringContainsString( 'headphones still need merchant input', $notes[1]['body'] );
		$this->assertSame( 'ready_to_reanalyse', $data['board']['sessions'][0]['status'] );

		$saved = get_option( IdeaBoardRestController::SAVED_BOARD_OPTION, array() );
		$this->assertSame( $notes[1]['id'], $saved['payload']['board']['notes'][1]['id'] );
	}

	/**
	 * AI answers do not keep the confusing merchant-input-needed prefix.
	 */
	public function test_idea_board_question_answer_rewrites_merchant_input_needed_prefix() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			function () {
				return $this->anthropic_text_response(
					wp_json_encode(
						array(
							'answer' => '**Merchant input needed:** Supplier records are not part of the current store data.',
						)
					)
				);
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_question_answer( $this->sample_reanalysis_board(), 'stock-question' );
		$data     = $response->get_data();
		$notes    = $data['board']['notes'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertStringStartsWith( 'AI can\'t answer this from the current store data.', $notes[1]['body'] );
		$this->assertStringNotContainsString( 'Merchant input needed', $notes[1]['body'] );
	}

	/**
	 * Product and substitute questions receive current catalogue context.
	 */
	public function test_idea_board_question_answer_includes_catalogue_context_for_product_questions() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$audio_term = wp_insert_term( 'Audio', 'product_cat' );
		$audio_id   = is_array( $audio_term ) ? (int) $audio_term['term_id'] : 0;
		$this->seed_simple_product(
			array(
				'name'         => 'Noise Cancelling Headphones',
				'sku'          => 'HEADPHONES-NOISE-' . wp_rand(),
				'price'        => 199,
				'stock_status' => 'outofstock',
				'category_ids' => array( $audio_id ),
			)
		);
		$this->seed_simple_product(
			array(
				'name'         => 'Premium Wireless Headphones',
				'sku'          => 'HEADPHONES-WIRELESS-' . wp_rand(),
				'price'        => 149,
				'stock_status' => 'instock',
				'category_ids' => array( $audio_id ),
			)
		);
		$this->seed_simple_product(
			array(
				'name'         => 'Classic Hoodie',
				'sku'          => 'HOODIE-CLASSIC-' . wp_rand(),
				'price'        => 59,
				'stock_status' => 'instock',
			)
		);

		$board = $this->sample_reanalysis_board();
		foreach ( $board['cards'] as &$card ) {
			if ( 'stock-question' === $card['id'] ) {
				$card['title']         = 'How many type of headphones do we have?';
				$card['body']          = 'This relates to similar products that may be in stock.';
				$card['answerability'] = 'ai';
				$card['createdBy']     = 'merchant';
			}
		}
		unset( $card );

		$captured_bodies = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args ) use ( &$captured_bodies ) {
				$captured_bodies[] = json_decode( $parsed_args['body'], true );

				return $this->anthropic_text_response(
					wp_json_encode(
						array(
							'answer' => 'The current catalogue context lists two headphone products: Noise Cancelling Headphones and Premium Wireless Headphones.',
						)
					)
				);
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_question_answer( $board, 'stock-question' );
		$data     = $response->get_data();
		$prompt   = json_decode( $captured_bodies[0]['messages'][0]['content'], true );
		$matches  = array_column( $prompt['catalogueContext']['matchingProducts'], 'name' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertTrue( $prompt['catalogueContext']['available'] );
		$this->assertContains( 'headphones', $prompt['catalogueContext']['detectedTerms'] );
		$this->assertContains( 'headphone', $prompt['catalogueContext']['detectedTerms'] );
		$this->assertContains( 'Noise Cancelling Headphones', $matches );
		$this->assertContains( 'Premium Wireless Headphones', $matches );
		$this->assertStringContainsString( 'two headphone products', $data['board']['notes'][1]['body'] );
	}

	/**
	 * AI-generated linked questions can be answered even when their wording is broad.
	 */
	public function test_idea_board_question_answer_allows_ai_linked_question_without_keywords() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$board = $this->sample_reanalysis_board();
		foreach ( $board['cards'] as &$card ) {
			if ( 'stock-question' === $card['id'] ) {
				$card['title'] = 'What changed most?';
				$card['body']  = 'Use the linked signal and board context to answer this.';
			}
		}
		unset( $card );

		$call_count = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$call_count ) {
				++$call_count;

				return $this->anthropic_text_response(
					wp_json_encode(
						array(
							'body' => 'The linked signal still points to unavailable best-sellers, so supplier timing is the main blocker.',
						)
					)
				);
			}
		);

		$response = $this->dispatch_idea_board_question_answer( $board, 'stock-question' );
		$data     = $response->get_data();
		$notes    = $data['board']['notes'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 1, $call_count );
		$this->assertSame( 'answer', $notes[1]['kind'] );
		$this->assertStringContainsString( 'unavailable best-sellers', $notes[1]['body'] );
	}

	/**
	 * POST /difm/idea-board/answer-question rejects unrelated custom questions before calling AI.
	 */
	public function test_idea_board_question_answer_rejects_unrelated_question() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$board = $this->sample_reanalysis_board();
		foreach ( $board['cards'] as &$card ) {
			if ( 'stock-question' === $card['id'] ) {
				$card['title']     = 'What is the weather today?';
				$card['body']      = 'Can you tell me whether it will rain this afternoon?';
				$card['createdBy'] = 'merchant';
			}
		}
		unset( $card );

		$call_count = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$call_count ) {
				++$call_count;

				return false;
			}
		);

		$response = $this->dispatch_idea_board_question_answer( $board, 'stock-question' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'Ask a question about the store', $data['message'] );
		$this->assertSame( 0, $call_count );
	}

	/**
	 * POST /difm/idea-board/save persists merchant notes linked to a brainstorm session.
	 */
	public function test_idea_board_save_persists_session_notes() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$board            = $this->sample_current_period_board();
		$board['notes'][] = array(
			'id'             => 'marketing-answer',
			'sessionId'      => 'stock-session',
			'rootInsightIds' => array( 'stock-out-signal' ),
			'parentCardId'   => 'stock-question',
			'body'           => 'Marketing says paid traffic is still landing on unavailable headphones.',
			'kind'           => 'context',
			'createdBy'      => 'merchant',
			'authorName'     => 'Marketing',
			'createdAt'      => '2026-05-12T10:18:00+00:00',
		);

		$response = $this->dispatch_idea_board_save( $board );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertCount( 2, $data['board']['notes'] );
		$this->assertSame( 'stock-session', $data['board']['notes'][1]['sessionId'] );
		$this->assertSame( array( 'stock-out-signal' ), $data['board']['notes'][1]['rootInsightIds'] );
	}

	/**
	 * POST /difm/idea-board/reanalyse submits only the current board state and preserves transients.
	 */
	public function test_idea_board_reanalysis_uses_submitted_board_and_preserves_transients() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		set_transient( 'woocommerce_claude_difm_idea_board_sentinel', 'keep-me', HOUR_IN_SECONDS );
		$this->set_admin_user();

		$captured_bodies = array();
		$call_count      = 0;
		add_filter(
			'pre_http_request',
			function ( $preempt, $parsed_args ) use ( &$captured_bodies, &$call_count ) {
				$captured_bodies[] = json_decode( $parsed_args['body'], true );
				++$call_count;

				return $this->anthropic_text_response( wp_json_encode( $this->valid_reanalysis_new_cards_payload() ) );
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $this->sample_reanalysis_board() );
		$data     = $response->get_data();
		$cards    = $this->cards_by_id( $data['board']['cards'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 1, $call_count );
		$this->assertSame( 'keep-me', get_transient( 'woocommerce_claude_difm_idea_board_sentinel' ) );
		$this->assertStringContainsString( 'stock-question', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringNotContainsString( 'readiness_recommendations', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'allowed_action_types', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'required_merchant_blockers', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertSame( 'sessions', $data['board']['layout']['source'] );
		$this->assertSame( 'investigate', $cards['stock-question']['stage'] );
		$this->assertSame( 'question', $cards['stock-question']['kind'] );
		$this->assertSame( 'approval_required', $cards['pause-ads']['status'] );
		$this->assertTrue( $cards['pause-ads']['approvalRequired'] );
		$this->assertSame( 'stock-out-signal', $cards['pause-ads']['rootInsightId'] );
		$this->assertSame( 'pause_campaign', $cards['pause-ads']['actionType'] );
		$this->assertSame( 'high', $cards['pause-ads']['expectedRevenueImpact'] );
		$this->assertSame( 'low', $cards['pause-ads']['effort'] );
		$this->assertSame( 27, $cards['pause-ads']['iceScore'] );
		$this->assertSame( 'Recovered product revenue', $cards['pause-ads']['primaryMetric'] );
		$this->assertSame( '4 unavailable products produced 44% of period revenue.', $cards['pause-ads']['evidenceDetails']['metricBaseline'] );
		$this->assertSame( 'This looks like a stock-out decision that needs merchant context before action.', $data['board']['summary'] );
		$this->assertSame( 'Confirm blockers before approving a campaign change.', $data['board']['decisionBrief']['bestNextMove'] );

		$saved = get_option( IdeaBoardRestController::SAVED_BOARD_OPTION, array() );
		$this->assertSame( IdeaBoardRestController::CACHE_VERSION, $saved['version'] );
		$this->assertArrayNotHasKey( 'freshness', $saved['payload']['board'] );
		$this->assertSame(
			$this->sorted_card_ids( $data['board']['cards'] ),
			$this->sorted_card_ids( $saved['payload']['board']['cards'] )
		);
	}

	/**
	 * Re-analysis accepts JSON split across multiple Anthropic text blocks.
	 */
	public function test_idea_board_reanalysis_parses_multi_text_block_response() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			function () {
				return $this->anthropic_text_blocks_response(
					array(
						'Here is the JSON: {"summary":"The board can keep moving after the supplied context.",',
						'"decision_brief":{"what_changed":"Merchant context changed the stock decision.","commercial_why":"Inventory risk is tied to revenue protection.","biggest_unknowns":"Campaign budget remains unclear.","best_next_move":"Review the draft before changing traffic.","upside_risk":"Upside is protected demand; risk is pausing too much."},"newCards":[',
						wp_json_encode( $this->valid_reanalysis_action_card() ),
						']}',
					)
				);
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $this->sample_reanalysis_board() );
		$data     = $response->get_data();
		$cards    = $this->cards_by_id( $data['board']['cards'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertArrayHasKey( 'pause-ads', $cards );
		$this->assertSame( 'Merchant context changed the stock decision.', $data['board']['decisionBrief']['whatChanged'] );
		$this->assertSame( 'Review the draft before changing traffic.', $data['board']['decisionBrief']['bestNextMove'] );
	}

	/**
	 * POST /difm/idea-board/reanalyse does not add actions while required merchant gates are unanswered.
	 */
	public function test_idea_board_reanalysis_blocks_actions_when_required_merchant_gates_are_unanswered() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$board          = $this->sample_reanalysis_board();
		$board['notes'] = array();

		add_filter(
			'pre_http_request',
			function () {
				return $this->anthropic_text_response( wp_json_encode( $this->valid_reanalysis_new_cards_payload() ) );
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $board );
		$data     = $response->get_data();
		$cards    = $this->cards_by_id( $data['board']['cards'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertArrayNotHasKey( 'pause-ads', $cards );
		$this->assertSame(
			array(
				'stock-context',
				'stock-out-signal',
				'stock-question',
			),
			$this->sorted_card_ids( $data['board']['cards'] )
		);
	}

	/**
	 * Both-answerable required gates also block draft action cards until answered.
	 */
	public function test_idea_board_reanalysis_blocks_actions_when_required_both_gates_are_unanswered() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$board          = $this->sample_reanalysis_board();
		$board['notes'] = array();
		foreach ( $board['cards'] as &$card ) {
			if ( 'stock-question' === $card['id'] ) {
				$card['answerability'] = 'both';
			}
		}
		unset( $card );

		add_filter(
			'pre_http_request',
			function () {
				return $this->anthropic_text_response( wp_json_encode( $this->valid_reanalysis_new_cards_payload() ) );
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $board );
		$data     = $response->get_data();
		$cards    = $this->cards_by_id( $data['board']['cards'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertArrayNotHasKey( 'pause-ads', $cards );
	}

	/**
	 * AI draft actions need concrete scorecard fields before they are usable.
	 */
	public function test_idea_board_reanalysis_requires_action_scorecard_fields() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			function () {
				$action = $this->valid_reanalysis_action_card();
				unset( $action['primaryMetric'], $action['successCriteria'], $action['evidenceDetails']['metricBaseline'] );

				return $this->anthropic_text_response(
					wp_json_encode(
						array(
							'summary'       => 'The action is too thin to prioritise.',
							'decisionBrief' => $this->valid_decision_brief(),
							'newCards'      => array( $action ),
						)
					)
				);
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $this->sample_reanalysis_board() );
		$data     = $response->get_data();
		$cards    = $this->cards_by_id( $data['board']['cards'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertArrayNotHasKey( 'pause-ads', $cards );
		$this->assertSame(
			array(
				'stock-context',
				'stock-out-signal',
				'stock-question',
			),
			$this->sorted_card_ids( $data['board']['cards'] )
		);
	}

	/**
	 * POST /difm/idea-board/reanalyse accepts summary-only responses and keeps the board intact.
	 */
	public function test_idea_board_reanalysis_accepts_summary_only_response() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			function () {
				return $this->anthropic_text_response(
					wp_json_encode(
						array(
							'summary'        => 'The stock-out signal still needs supplier and campaign context before action.',
							'decision_brief' => array(
								'what_changed'   => 'No safe action changed yet.',
								'commercial_why' => 'Inventory risk still controls revenue protection.',
								'best_next_move' => 'Answer the required blocker before drafting action.',
							),
							'newCards'       => array(),
						)
					)
				);
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $this->sample_reanalysis_board() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'The stock-out signal still needs supplier and campaign context before action.', $data['board']['summary'] );
		$this->assertSame( 'No safe action changed yet.', $data['board']['decisionBrief']['whatChanged'] );
		$this->assertSame( 'Answer the required blocker before drafting action.', $data['board']['decisionBrief']['bestNextMove'] );
		$this->assertSame(
			array(
				'stock-context',
				'stock-out-signal',
				'stock-question',
			),
			$this->sorted_card_ids( $data['board']['cards'] )
		);
	}

	/**
	 * POST /difm/idea-board/reanalyse preserves the board when AI returns prose instead of JSON.
	 */
	public function test_idea_board_reanalysis_preserves_board_when_response_has_no_json() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			function () {
				return $this->anthropic_text_response( 'I would prioritise the stock-out question first, then the payment pipeline.' );
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $this->sample_reanalysis_board() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame(
			array(
				'stock-context',
				'stock-out-signal',
				'stock-question',
			),
			$this->sorted_card_ids( $data['board']['cards'] )
		);
		$this->assertStringContainsString( 'kept intact', $data['board']['summary'] );
		$this->assertStringContainsString( 'no new scored action', $data['board']['decisionBrief']['whatChanged'] );
		$this->assertSame( 'analysed', $data['board']['sessions'][0]['status'] );
	}

	/**
	 * POST /difm/idea-board/reanalyse ignores AI attempts to remove or mutate submitted cards.
	 *
	 * @dataProvider invalid_reanalysis_card_set_provider
	 *
	 * @param string $mutation Mutation to apply to the valid AI payload.
	 */
	public function test_idea_board_reanalysis_ignores_changed_card_sets( $mutation ) {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			function () use ( $mutation ) {
				$payload = $this->valid_reanalysis_payload();
				if ( 'omit' === $mutation ) {
					array_splice( $payload['cards'], 1, 1 );
				} elseif ( 'kind' === $mutation ) {
					$payload['cards'][0]['kind'] = 'question';
				} elseif ( 'stage' === $mutation ) {
					$payload['cards'][1]['stage'] = 'proposed_actions';
				}

				return $this->anthropic_text_response( wp_json_encode( $payload ) );
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $this->sample_reanalysis_board() );
		$data     = $response->get_data();
		$cards    = $this->cards_by_id( $data['board']['cards'] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'insight', $cards['stock-out-signal']['kind'] );
		$this->assertSame( 'insights', $cards['stock-out-signal']['stage'] );
		$this->assertSame( 'question', $cards['stock-question']['kind'] );
		$this->assertSame( 'investigate', $cards['stock-question']['stage'] );
		$this->assertSame( 'approval_required', $cards['pause-ads']['status'] );
	}

	/**
	 * Invalid changed-card-set mutations.
	 *
	 * @return array
	 */
	public function invalid_reanalysis_card_set_provider() {
		return array(
			'omitted card'  => array( 'omit' ),
			'changed kind'  => array( 'kind' ),
			'changed stage' => array( 'stage' ),
		);
	}

	/**
	 * Set the current user to a WooCommerce admin.
	 *
	 * @return void
	 */
	private function set_admin_user() {
		$this->admin_user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_user_id );
	}

	/**
	 * Load the moved Hey Woo idea-board controller without booting the whole Hey Woo plugin.
	 *
	 * @return void
	 */
	private function load_hey_woo_idea_board_controller() {
		$hey_woo_dir = ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : ABSPATH . 'wp-content/plugins' ) . '/hey-woo/';
		if ( ! defined( 'HEY_WOO_PLUGIN_DIR' ) ) {
			define( 'HEY_WOO_PLUGIN_DIR', $hey_woo_dir );
		}

		require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/interface-telemetry-handler.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/class-telemetry-handler.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/handlers/class-log-handler.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/telemetry/class-difm-ai-telemetry.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/interface-difm-ai-client.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';
		require_once HEY_WOO_PLUGIN_DIR . 'includes/difm/class-idea-board-rest-controller.php';
	}

	/**
	 * Dispatch an idea-board request.
	 *
	 * @param bool $refresh Whether to force refresh.
	 * @param int  $days    Requested trailing days.
	 * @return \WP_REST_Response
	 */
	private function dispatch_idea_board( $refresh = false, $days = 90 ) {
		$request = new \WP_REST_Request( 'GET', '/hey-woo/v1/difm/idea-board' );
		$request->set_param( 'days', $days );
		if ( $refresh ) {
			$request->set_param( 'refresh', true );
		}
		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch an idea-board re-analysis request.
	 *
	 * @param array $board Board payload.
	 * @return \WP_REST_Response
	 */
	private function dispatch_idea_board_reanalysis( array $board ) {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/idea-board/reanalyse' );
		$request->set_param( 'board', $board );
		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch an idea-board brainstorm request.
	 *
	 * @param array $board            Board payload.
	 * @param array $root_insight_ids Selected insight card IDs.
	 * @return \WP_REST_Response
	 */
	private function dispatch_idea_board_brainstorm( array $board, array $root_insight_ids ) {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/idea-board/brainstorm' );
		$request->set_param( 'board', $board );
		$request->set_param( 'rootInsightIds', $root_insight_ids );
		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch an idea-board AI question-answer request.
	 *
	 * @param array  $board   Board payload.
	 * @param string $card_id Question card ID.
	 * @return \WP_REST_Response
	 */
	private function dispatch_idea_board_question_answer( array $board, $card_id ) {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/idea-board/answer-question' );
		$request->set_param( 'board', $board );
		$request->set_param( 'cardId', $card_id );
		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch an idea-board save request.
	 *
	 * @param array $board Board payload.
	 * @return \WP_REST_Response
	 */
	private function dispatch_idea_board_save( array $board ) {
		$request = new \WP_REST_Request( 'POST', '/hey-woo/v1/difm/idea-board/save' );
		$request->set_param( 'board', $board );
		return $this->server->dispatch( $request );
	}

	/**
	 * Return a sample board whose period matches the current date range.
	 *
	 * @param int $days Number of trailing days.
	 * @return array
	 */
	private function sample_current_period_board( $days = 90 ) {
		$board = $this->sample_reanalysis_board();
		$dates = $this->current_test_period_dates( $days );

		$board['period']['start'] = $dates['start'];
		$board['period']['end']   = $dates['end'];
		$board['period']['label'] = sprintf( 'Last %d days', $days );
		$board['period']['days']  = (int) $days;

		return $board;
	}

	/**
	 * Return the current trailing period dates used by the route.
	 *
	 * @param int $days Number of trailing days.
	 * @return array
	 */
	private function current_test_period_dates( $days ) {
		$today = current_datetime();
		$start = $today->modify( '-' . ( (int) $days - 1 ) . ' days' );

		return array(
			'start' => $start->format( 'Y-m-d' ),
			'end'   => $today->format( 'Y-m-d' ),
		);
	}

	/**
	 * Return a minimal board payload for re-analysis tests.
	 *
	 * @return array
	 */
	private function sample_reanalysis_board() {
		return array(
			'id'              => 'store-idea-board',
			'title'           => 'Idea board',
			'period'          => array(
				'start'      => '2026-02-12',
				'end'        => '2026-05-12',
				'label'      => 'Last 90 days',
				'days'       => 90,
				'comparison' => 'Compared with the previous matching period',
			),
			'currency'        => 'GBP',
			'headlineMetrics' => array(
				'net_sales'           => 1200.50,
				'orders_count'        => 24,
				'average_order_value' => 50.02,
				'total_customers'     => 18,
			),
			'columns'         => $this->sample_columns(),
			'cards'           => array(
				array(
					'id'               => 'stock-out-signal',
					'kind'             => 'insight',
					'stage'            => 'insights',
					'title'            => 'Best-sellers are out of stock',
					'body'             => 'Four top products are unavailable and need merchant validation before action.',
					'colour'           => 'yellow',
					'order'            => 0,
					'prompt'           => 'When will these products be available again?',
					'confidence'       => 'high',
					'evidence'         => '4 products, 44% of revenue',
					'timeframe'        => 'Last 90 days',
					'source'           => 'Inventory + product sales',
					'status'           => 'new',
					'approvalRequired' => false,
					'rootInsightId'    => '',
					'rootInsightIds'   => array(),
					'sessionId'        => '',
					'parentCardId'     => '',
					'createdBy'        => 'ai',
				),
				array(
					'id'               => 'stock-question',
					'kind'             => 'question',
					'stage'            => 'investigate',
					'title'            => 'When will stock return?',
					'body'             => 'Supplier timing is not in the store data and needs merchant input.',
					'colour'           => 'blue',
					'order'            => 0,
					'prompt'           => 'Capture supplier ETAs for the unavailable products.',
					'confidence'       => 'medium',
					'evidence'         => '',
					'timeframe'        => 'Last 90 days',
					'source'           => 'Merchant input needed',
					'status'           => 'merchant_input_needed',
					'approvalRequired' => false,
					'rootInsightId'    => 'stock-out-signal',
					'rootInsightIds'   => array( 'stock-out-signal' ),
					'sessionId'        => 'stock-session',
					'parentCardId'     => 'stock-out-signal',
					'createdBy'        => 'ai',
				),
				array(
					'id'               => 'stock-context',
					'kind'             => 'context',
					'stage'            => 'context',
					'title'            => 'Supplier delay noted',
					'body'             => 'Ops says hoodies arrive next week; headphones are unknown.',
					'colour'           => 'orange',
					'order'            => 0,
					'prompt'           => 'Use this context when proposing actions.',
					'confidence'       => 'medium',
					'evidence'         => '',
					'timeframe'        => 'Last 90 days',
					'source'           => 'Merchant input',
					'status'           => 'reviewed',
					'approvalRequired' => false,
					'rootInsightId'    => 'stock-out-signal',
					'rootInsightIds'   => array( 'stock-out-signal' ),
					'sessionId'        => 'stock-session',
					'parentCardId'     => 'stock-question',
					'createdBy'        => 'merchant',
				),
			),
			'links'           => array(),
			'sessions'        => array(
				array(
					'id'             => 'stock-session',
					'title'          => 'Stock-out brainstorm',
					'rootInsightIds' => array( 'stock-out-signal' ),
					'summary'        => 'Confirm supplier and campaign context before drafting actions.',
					'status'         => 'ready_to_reanalyse',
					'createdAt'      => '2026-05-12T10:05:00+00:00',
					'updatedAt'      => '2026-05-12T10:10:00+00:00',
					'lastAnalysedAt' => '2026-05-12T10:05:00+00:00',
				),
			),
			'notes'           => array(
				array(
					'id'             => 'supplier-note',
					'sessionId'      => 'stock-session',
					'rootInsightIds' => array( 'stock-out-signal' ),
					'parentCardId'   => 'stock-question',
					'body'           => 'Ops says hoodies arrive next week; headphones are unknown.',
					'kind'           => 'answer',
					'createdBy'      => 'merchant',
					'authorName'     => 'Ops',
					'createdAt'      => '2026-05-12T10:10:00+00:00',
				),
			),
			'summary'         => 'Review the stock-out signal before drafting actions.',
			'content'         => array(
				'source' => 'ai',
			),
			'layout'          => array(
				'source' => 'sessions',
			),
			'generatedAt'     => '2026-05-12T10:00:00+00:00',
		);
	}

	/**
	 * Return the fixed sample columns.
	 *
	 * @return array
	 */
	private function sample_columns() {
		return array(
			array(
				'id'          => 'insights',
				'title'       => 'Insights',
				'description' => 'Signals from store data that may matter.',
			),
			array(
				'id'          => 'investigate',
				'title'       => 'Investigate',
				'description' => 'Questions and checks before deciding.',
			),
			array(
				'id'          => 'context',
				'title'       => 'Human context',
				'description' => 'Supplier, campaign, support, and brand judgement.',
			),
			array(
				'id'          => 'proposed_actions',
				'title'       => 'Proposed actions',
				'description' => 'Draft recommendations that still need approval.',
			),
		);
	}

	/**
	 * Return a second sample insight for multi-insight brainstorm tests.
	 *
	 * @return array
	 */
	private function sample_campaign_insight_card() {
		return array(
			'id'               => 'campaign-mismatch',
			'kind'             => 'insight',
			'stage'            => 'insights',
			'title'            => 'Campaign traffic hits unavailable products',
			'body'             => 'Paid traffic appears to be reaching products that cannot currently be purchased.',
			'colour'           => 'pink',
			'order'            => 1,
			'prompt'           => 'Which campaign traffic should be paused or redirected?',
			'confidence'       => 'medium',
			'evidence'         => 'Campaign context from merchant notes',
			'timeframe'        => 'Last 90 days',
			'source'           => 'Merchant context',
			'status'           => 'new',
			'approvalRequired' => false,
			'rootInsightId'    => '',
			'rootInsightIds'   => array(),
			'sessionId'        => '',
			'parentCardId'     => '',
			'createdBy'        => 'ai',
		);
	}

	/**
	 * Return a valid brainstorm payload from Anthropic.
	 *
	 * @return array
	 */
	private function valid_brainstorm_payload() {
		return array(
			'summary'       => 'The selected signals point to a stock-out and campaign mismatch brainstorm.',
			'decisionBrief' => $this->valid_decision_brief(),
			'session'       => array(
				'title'         => 'Stock-out and campaign mismatch',
				'summary'       => 'Confirm restock timing and campaign intent before proposing customer-facing changes.',
				'decisionBrief' => $this->valid_decision_brief(),
			),
			'questions'     => array(
				array(
					'id'             => 'stock-campaign-question',
					'title'          => 'Which traffic is reaching unavailable products?',
					'body'           => 'Confirm which paid or email campaigns still point at the unavailable products.',
					'gatePriority'   => 'required',
					'answerability'  => 'both',
					'revenueLevers'  => array( 'inventory', 'campaign_spend', 'revenue_protection' ),
					'rootInsightIds' => array( 'stock-out-signal', 'campaign-mismatch' ),
					'parentCardId'   => 'stock-out-signal',
				),
			),
			'actions'       => array(
				array(
					'id'               => 'pause-campaign',
					'title'            => 'Pause traffic to unavailable products',
					'body'             => 'Draft an approval request before pausing or redirecting campaigns.',
					'rootInsightIds'   => array( 'stock-out-signal', 'campaign-mismatch' ),
					'parentCardId'     => 'stock-campaign-question',
					'confidence'       => 'high',
					'evidence'         => 'Stock-out signal plus campaign mismatch',
					'status'           => 'approval_required',
					'approvalRequired' => true,
				),
			),
		);
	}

	/**
	 * Return a valid re-analysis payload from Anthropic.
	 *
	 * @return array
	 */
	private function valid_reanalysis_payload() {
		$payload = $this->sample_reanalysis_board();

		$payload['summary'] = 'This looks like a stock-out decision that needs merchant context before action.';
		$payload['cards'][] = $this->valid_reanalysis_action_card();

		return array(
			'summary' => $payload['summary'],
			'cards'   => $payload['cards'],
		);
	}

	/**
	 * Return a valid new-cards-only re-analysis payload from Anthropic.
	 *
	 * @return array
	 */
	private function valid_reanalysis_new_cards_payload() {
		return array(
			'summary'       => 'This looks like a stock-out decision that needs merchant context before action.',
			'decisionBrief' => $this->valid_decision_brief(),
			'newCards'      => array(
				$this->valid_reanalysis_action_card(),
			),
		);
	}

	/**
	 * Return a valid proposed action card for re-analysis tests.
	 *
	 * @return array
	 */
	private function valid_reanalysis_action_card() {
		return array(
			'id'                    => 'pause-ads',
			'kind'                  => 'action',
			'stage'                 => 'proposed_actions',
			'title'                 => 'Pause ads for unavailable products',
			'body'                  => 'Draft a change for merchant approval before pausing paid traffic.',
			'colour'                => 'lime',
			'order'                 => 0,
			'prompt'                => 'Prepare the approval note for pausing paid ads.',
			'confidence'            => 'high',
			'evidence'              => 'Stock-out signal plus campaign context',
			'timeframe'             => 'Last 90 days',
			'source'                => 'Board re-analysis',
			'status'                => 'approval_required',
			'approvalRequired'      => true,
			'revenueLevers'         => array( 'inventory', 'campaign_spend', 'revenue_protection' ),
			'actionType'            => 'pause_campaign',
			'expectedRevenueImpact' => 'high',
			'effort'                => 'low',
			'timeToImpact'          => 'Same day once approved',
			'riskApprovalNeeded'    => 'Needs approval before changing paid media spend.',
			'primaryMetric'         => 'Recovered product revenue',
			'owner'                 => 'Marketing',
			'reviewDate'            => '2026-05-19',
			'successCriteria'       => 'Unavailable products stop receiving paid traffic.',
			'evidenceDetails'       => array(
				'metricBaseline'   => '4 unavailable products produced 44% of period revenue.',
				'comparisonPeriod' => 'Previous matching period',
				'involvedProducts' => 'Top unavailable products, aggregated only',
				'confidenceReason' => 'Stock-out signal plus merchant campaign context.',
				'dataFreshness'    => 'Last 90 days',
				'unknowns'         => 'Campaign budget and supplier ETAs need merchant confirmation.',
			),
			'rootInsightId'         => 'stock-out-signal',
			'rootInsightIds'        => array( 'stock-out-signal' ),
			'sessionId'             => 'stock-session',
			'parentCardId'          => 'stock-question',
			'createdBy'             => 'ai',
		);
	}

	/**
	 * Return a valid decision brief for mocked AI responses.
	 *
	 * @return array
	 */
	private function valid_decision_brief() {
		return array(
			'whatChanged'     => 'Top products are unavailable while campaign context is active.',
			'commercialWhy'   => 'Inventory and campaign spend are tied to revenue protection.',
			'biggestUnknowns' => 'Restock ETA and campaign budget.',
			'bestNextMove'    => 'Confirm blockers before approving a campaign change.',
			'upsideRisk'      => 'Upside is recovered demand; risk is pausing useful traffic too early.',
		);
	}

	/**
	 * Build a successful Anthropic text response.
	 *
	 * @param string $text Text block returned by Anthropic.
	 * @return array
	 */
	private function anthropic_text_response( $text ) {
		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'type'    => 'message',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $text,
						),
					),
				)
			),
			'headers'  => array(),
		);
	}

	/**
	 * Build a successful Anthropic response with multiple text blocks.
	 *
	 * @param array $texts Text blocks returned by Anthropic.
	 * @return array
	 */
	private function anthropic_text_blocks_response( array $texts ) {
		$content = array();
		foreach ( $texts as $text ) {
			$content[] = array(
				'type' => 'text',
				'text' => (string) $text,
			);
		}

		return array(
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'body'     => wp_json_encode(
				array(
					'type'    => 'message',
					'content' => $content,
				)
			),
			'headers'  => array(),
		);
	}

	/**
	 * Return sorted card IDs.
	 *
	 * @param array $cards Board cards.
	 * @return array
	 */
	private function sorted_card_ids( array $cards ) {
		$ids = wp_list_pluck( $cards, 'id' );
		sort( $ids );
		return $ids;
	}

	/**
	 * Return board cards keyed by ID.
	 *
	 * @param array $cards Board cards.
	 * @return array
	 */
	private function cards_by_id( array $cards ) {
		$by_id = array();
		foreach ( $cards as $card ) {
			$by_id[ $card['id'] ] = $card;
		}

		return $by_id;
	}

	/**
	 * Delete idea-board transients between tests.
	 *
	 * @return void
	 */
	private function delete_idea_board_transients() {
		global $wpdb;

		$like         = $wpdb->esc_like( '_transient_woocommerce_claude_difm_idea_board_' ) . '%';
		$timeout_like = $wpdb->esc_like( '_transient_timeout_woocommerce_claude_difm_idea_board_' ) . '%';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$like,
				$timeout_like
			)
		);
	}

	/**
	 * Clear WooCommerce custom analytics/order tables touched by the idea-board fixtures.
	 *
	 * @return void
	 */
	private function delete_woocommerce_analytics_fixture_rows() {
		global $wpdb;

		foreach ( array(
			'wc_order_product_lookup',
			'wc_order_tax_lookup',
			'wc_order_stats',
			'wc_customer_lookup',
			'wc_product_meta_lookup',
			'wc_orders_meta',
			'wc_order_addresses',
			'wc_order_operational_data',
			'wc_orders',
		) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test cleanup for known custom WooCommerce tables.
			$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
		}
	}
}
