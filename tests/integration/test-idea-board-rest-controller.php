<?php
/**
 * Integration tests for IdeaBoardRestController.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\IdeaBoardRestController;

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
	 * Set up a REST server for each test.
	 */
	public function set_up() {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		$this->server   = $wp_rest_server;
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- This action is documented in wp-includes/rest-api.php.
		do_action( 'rest_api_init' );
	}

	/**
	 * Tear down: remove API key option and cached board payloads.
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		delete_option( IdeaBoardRestController::SAVED_BOARD_OPTION );
		$this->delete_idea_board_transients();
		parent::tear_down();
	}

	/**
	 * The idea-board route is registered.
	 */
	public function test_route_is_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/' . IdeaBoardRestController::NAMESPACE . IdeaBoardRestController::ROUTE, $routes );
		$this->assertArrayHasKey( '/' . IdeaBoardRestController::NAMESPACE . IdeaBoardRestController::SAVE_ROUTE, $routes );
		$this->assertArrayHasKey( '/' . IdeaBoardRestController::NAMESPACE . IdeaBoardRestController::REANALYSE_ROUTE, $routes );
	}

	/**
	 * Unauthenticated requests to GET /difm/idea-board receive a 403.
	 */
	public function test_idea_board_requires_manage_woocommerce() {
		$request  = new \WP_REST_Request( 'GET', '/woocommerce-claude/v1/difm/idea-board' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Unauthenticated requests to POST /difm/idea-board/save receive a 403.
	 */
	public function test_idea_board_save_requires_manage_woocommerce() {
		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/idea-board/save' );
		$request->set_param( 'board', $this->sample_current_period_board() );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Unauthenticated requests to POST /difm/idea-board/reanalyse receive a 403.
	 */
	public function test_idea_board_reanalysis_requires_manage_woocommerce() {
		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/idea-board/reanalyse' );
		$request->set_param( 'board', $this->sample_reanalysis_board() );

		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * GET /difm/idea-board requires AI content instead of building fallback notes.
	 */
	public function test_idea_board_requires_anthropic_key_for_content() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$product_id  = $this->seed_simple_product(
			array(
				'name'  => 'Canvas Tote',
				'sku'   => 'TOTE-CANVAS',
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
	 * POST /difm/idea-board/save persists a merchant-edited board without requiring AI.
	 */
	public function test_idea_board_save_persists_current_board() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$board           = $this->sample_current_period_board();
		$board['notes']  = array_slice( $board['notes'], 0, 2 );
		$board['arrows'] = array_merge(
			$board['arrows'],
			array(
				array(
					'from'  => 'removed-card',
					'to'    => 'retention-signal',
					'label' => 'ignore',
				),
			)
		);

		$response = $this->dispatch_idea_board_save( $board );
		$data     = $response->get_data();
		$saved    = get_option( IdeaBoardRestController::SAVED_BOARD_OPTION, array() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertFalse( $data['board']['freshness']['isStale'] );
		$this->assertSame( array( 'retention-signal' ), wp_list_pluck( $data['board']['arrows'], 'from' ) );
		$this->assertIsArray( $saved );
		$this->assertSame( IdeaBoardRestController::CACHE_VERSION, $saved['version'] );
		$this->assertArrayNotHasKey( 'freshness', $saved['payload']['board'] );
		$this->assertSame(
			array(
				'merchant-pop-up-offer',
				'retention-signal',
			),
			$this->sorted_note_ids( $saved['payload']['board']['notes'] )
		);
	}

	/**
	 * POST /difm/idea-board/save allows an empty board so card removals persist.
	 */
	public function test_idea_board_save_allows_empty_board() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->set_admin_user();

		$board           = $this->sample_current_period_board();
		$board['notes']  = array();
		$board['arrows'] = array();

		$save_response = $this->dispatch_idea_board_save( $board );
		$save_data     = $save_response->get_data();
		$this->assertSame( 200, $save_response->get_status() );
		$this->assertSame( 'ok', $save_data['status'] );
		$this->assertSame( array(), $save_data['board']['notes'] );

		$get_response = $this->dispatch_idea_board();
		$get_data     = $get_response->get_data();
		$this->assertSame( 200, $get_response->get_status() );
		$this->assertSame( 'ok', $get_data['status'] );
		$this->assertSame( array(), $get_data['board']['notes'] );
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
				'first-test',
				'merchant-pop-up-offer',
				'retention-signal',
			),
			$this->sorted_note_ids( $data['board']['notes'] )
		);
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
	 * GET /difm/idea-board lets Claude create cards from store data and then arrange them.
	 */
	public function test_idea_board_uses_ai_generated_cards_and_layout() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$product_id  = $this->seed_simple_product(
			array(
				'name'  => 'Canvas Tote',
				'sku'   => 'TOTE-CANVAS',
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
		add_filter(
			'pre_http_request',
			static function ( $preempt, $parsed_args ) use ( &$captured_bodies, &$call_count ) {
				$captured_bodies[] = json_decode( $parsed_args['body'], true );
				++$call_count;

				$text = wp_json_encode(
					array(
						'notes'     => array(
							array(
								'id'         => 'canvas-momentum',
								'type'       => 'insight',
								'title'      => 'Canvas Tote is moving',
								'body'       => 'Recent paid orders give Canvas Tote a clear merchandising signal to build from.',
								'colour'     => 'yellow',
								'prompt'     => 'Brainstorm revenue ideas from Canvas Tote momentum.',
								'confidence' => 'medium',
							),
							array(
								'id'         => 'repeat-buyer-signal',
								'type'       => 'insight',
								'title'      => 'Repeat buyers are active',
								'body'       => 'Returning customers are present, so post-purchase ideas are worth testing.',
								'colour'     => 'blue',
								'prompt'     => 'Suggest repeat-buyer campaigns for this store.',
								'confidence' => 'medium',
							),
							array(
								'id'         => 'bundle-canvas-tote',
								'type'       => 'idea',
								'title'      => 'Bundle the tote?',
								'body'       => 'Pair Canvas Tote with a complementary product or timed offer.',
								'colour'     => 'pink',
								'prompt'     => 'Create bundle ideas around Canvas Tote.',
								'confidence' => 'medium',
							),
							array(
								'id'         => 'local-angle',
								'type'       => 'idea',
								'title'      => 'Use the local angle',
								'body'       => 'Test campaign copy that reflects the store location and current season.',
								'colour'     => 'lime',
								'prompt'     => 'Draft localised campaign angles for this store.',
								'confidence' => 'low',
							),
							array(
								'id'         => 'first-test',
								'type'       => 'question',
								'title'      => 'What should we test first?',
								'body'       => 'Pick one measurable experiment from these signals before widening the plan.',
								'colour'     => 'white',
								'prompt'     => 'Help prioritise the first idea-board experiment.',
								'confidence' => 'high',
							),
						),
						'arrows'    => array(
							array(
								'from'  => 'canvas-momentum',
								'to'    => 'bundle-canvas-tote',
								'label' => 'build',
							),
							array(
								'from'  => 'bundle-canvas-tote',
								'to'    => 'first-test',
								'label' => 'choose',
							),
							array(
								'from'  => 'repeat-buyer-signal',
								'to'    => 'local-angle',
								'label' => 'adapt',
							),
						),
						'positions' => array(
							array(
								'id' => 'canvas-momentum',
								'x'  => 3,
								'y'  => 6,
							),
							array(
								'id' => 'repeat-buyer-signal',
								'x'  => 3,
								'y'  => 36,
							),
							array(
								'id' => 'bundle-canvas-tote',
								'x'  => 31,
								'y'  => 6,
							),
							array(
								'id' => 'local-angle',
								'x'  => 31,
								'y'  => 36,
							),
							array(
								'id' => 'first-test',
								'x'  => 60,
								'y'  => 36,
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
		$this->assertSame( 'ai', $data['board']['layout']['source'] );
		$this->assertStringContainsString( 'merchant_prompt', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'readiness_recommendations', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'Canvas Tote', wp_json_encode( $data['board']['notes'] ) );
		$this->assertStringContainsString( '"width":1480', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( '"width":245', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'layout positions', strtolower( $captured_bodies[0]['system'] ) );

		$notes_by_id = array();
		foreach ( $data['board']['notes'] as $note ) {
			$notes_by_id[ $note['id'] ] = $note;
		}

		$this->assertSame( 3.0, $notes_by_id['canvas-momentum']['x'] );
		$this->assertSame( 6.0, $notes_by_id['canvas-momentum']['y'] );
		$this->assertSame( 60.0, $notes_by_id['first-test']['x'] );
		$this->assertSame( 36.0, $notes_by_id['first-test']['y'] );
		$this->assertSame(
			array(
				'bundle-canvas-tote',
				'canvas-momentum',
				'first-test',
				'local-angle',
				'repeat-buyer-signal',
			),
			$this->sorted_note_ids( $data['board']['notes'] )
		);

		$saved = get_option( IdeaBoardRestController::SAVED_BOARD_OPTION, array() );
		$this->assertSame( IdeaBoardRestController::CACHE_VERSION, $saved['version'] );
		$this->assertArrayNotHasKey( 'freshness', $saved['payload']['board'] );
		$this->assertSame(
			$this->sorted_note_ids( $data['board']['notes'] ),
			$this->sorted_note_ids( $saved['payload']['board']['notes'] )
		);
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

				return $this->anthropic_text_response(
					wp_json_encode(
						array(
							'notes'     => array(
								array(
									'id'         => 'retention-signal',
									'type'       => 'insight',
									'title'      => 'Repeat buyers are ready',
									'body'       => 'Keep the repeat-buyer signal, but make the next action sharper.',
									'colour'     => 'blue',
									'prompt'     => 'Shape a repeat-buyer campaign from the edited board.',
									'confidence' => 'medium',
								),
								array(
									'id'         => 'merchant-pop-up-offer',
									'type'       => 'idea',
									'title'      => 'Test a weekend offer',
									'body'       => 'Run the merchant-added offer as a small timed experiment.',
									'colour'     => 'orange',
									'prompt'     => 'Turn the weekend offer into a measurable promotion.',
									'confidence' => 'medium',
								),
								array(
									'id'         => 'first-test',
									'type'       => 'question',
									'title'      => 'Which test is first?',
									'body'       => 'Choose the edited idea with the cleanest signal and next step.',
									'colour'     => 'white',
									'prompt'     => 'Prioritise the first board experiment.',
									'confidence' => 'high',
								),
							),
							'arrows'    => array(
								array(
									'from'  => 'retention-signal',
									'to'    => 'merchant-pop-up-offer',
									'label' => 'supports',
								),
								array(
									'from'  => 'removed-card',
									'to'    => 'first-test',
									'label' => 'ignore',
								),
							),
							'positions' => array(
								array(
									'id' => 'retention-signal',
									'x'  => 3,
									'y'  => 6,
								),
								array(
									'id' => 'merchant-pop-up-offer',
									'x'  => 31,
									'y'  => 6,
								),
								array(
									'id' => 'first-test',
									'x'  => 60,
									'y'  => 36,
								),
							),
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
		$this->assertSame( 1, $call_count );
		$this->assertSame( 'keep-me', get_transient( 'woocommerce_claude_difm_idea_board_sentinel' ) );
		$this->assertStringContainsString( 'merchant-pop-up-offer', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringNotContainsString( 'removed-card', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringNotContainsString( 'readiness_recommendations', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertSame( 'ai', $data['board']['layout']['source'] );
		$this->assertSame(
			array(
				'first-test',
				'merchant-pop-up-offer',
				'retention-signal',
			),
			$this->sorted_note_ids( $data['board']['notes'] )
		);
		$this->assertSame(
			array(
				array(
					'from'  => 'retention-signal',
					'to'    => 'merchant-pop-up-offer',
					'label' => 'supports',
				),
			),
			$data['board']['arrows']
		);

		$saved = get_option( IdeaBoardRestController::SAVED_BOARD_OPTION, array() );
		$this->assertSame( IdeaBoardRestController::CACHE_VERSION, $saved['version'] );
		$this->assertArrayNotHasKey( 'freshness', $saved['payload']['board'] );
		$this->assertSame(
			$this->sorted_note_ids( $data['board']['notes'] ),
			$this->sorted_note_ids( $saved['payload']['board']['notes'] )
		);
	}

	/**
	 * POST /difm/idea-board/reanalyse may add relevant cards while preserving submitted cards.
	 */
	public function test_idea_board_reanalysis_allows_added_cards() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			function () {
				$payload                = $this->valid_reanalysis_payload();
				$payload['notes'][]     = array(
					'id'         => 'ai-added-insight',
					'type'       => 'insight',
					'title'      => 'New insight from the board',
					'body'       => 'The edited board suggests one extra angle worth testing next.',
					'colour'     => 'yellow',
					'prompt'     => 'Explore the extra insight added during board re-analysis.',
					'confidence' => 'medium',
				);
				$payload['positions'][] = array(
					'id' => 'ai-added-insight',
					'x'  => 31,
					'y'  => 36,
				);

				return $this->anthropic_text_response( wp_json_encode( $payload ) );
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
				'ai-added-insight',
				'first-test',
				'merchant-pop-up-offer',
				'retention-signal',
			),
			$this->sorted_note_ids( $data['board']['notes'] )
		);
	}

	/**
	 * POST /difm/idea-board/reanalyse rejects AI responses that remove or mutate submitted cards.
	 *
	 * @dataProvider invalid_reanalysis_card_set_provider
	 *
	 * @param string $mutation Mutation to apply to the valid AI payload.
	 */
	public function test_idea_board_reanalysis_rejects_changed_card_sets( $mutation ) {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		add_filter(
			'pre_http_request',
			function () use ( $mutation ) {
				$payload = $this->valid_reanalysis_payload();
				if ( 'omit' === $mutation ) {
					array_pop( $payload['notes'] );
					array_pop( $payload['positions'] );
				} elseif ( 'rename' === $mutation ) {
					$payload['notes'][0]['id']     = 'renamed-signal';
					$payload['positions'][0]['id'] = 'renamed-signal';
				} elseif ( 'type' === $mutation ) {
					$payload['notes'][0]['type'] = 'idea';
				}

				return $this->anthropic_text_response( wp_json_encode( $payload ) );
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $this->sample_reanalysis_board() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'error', $data['status'] );
		$this->assertStringContainsString( 'card', $data['message'] );
		$this->assertArrayNotHasKey( 'board', $data );
	}

	/**
	 * Invalid changed-card-set mutations.
	 *
	 * @return array
	 */
	public function invalid_reanalysis_card_set_provider() {
		return array(
			'omitted card' => array( 'omit' ),
			'renamed card' => array( 'rename' ),
			'changed type' => array( 'type' ),
		);
	}

	/**
	 * POST /difm/idea-board/reanalyse keeps refined notes but falls back when layout is invalid.
	 */
	public function test_idea_board_reanalysis_falls_back_when_layout_is_invalid() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		$this->set_admin_user();

		$call_count = 0;
		add_filter(
			'pre_http_request',
			function () use ( &$call_count ) {
				++$call_count;
				$payload = $this->valid_reanalysis_payload();
				foreach ( $payload['positions'] as $index => $position ) {
					$payload['positions'][ $index ]['x'] = 3;
					$payload['positions'][ $index ]['y'] = 6;
				}

				return $this->anthropic_text_response( wp_json_encode( $payload ) );
			},
			10,
			3
		);

		$response = $this->dispatch_idea_board_reanalysis( $this->sample_reanalysis_board() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 1, $call_count );
		$this->assertSame( 'fallback', $data['board']['layout']['source'] );
		$this->assertSame(
			array(
				'first-test',
				'merchant-pop-up-offer',
				'retention-signal',
			),
			$this->sorted_note_ids( $data['board']['notes'] )
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
	 * Dispatch an idea-board request.
	 *
	 * @param bool $refresh Whether to force refresh.
	 * @return \WP_REST_Response
	 */
	private function dispatch_idea_board( $refresh = false ) {
		$request = new \WP_REST_Request( 'GET', '/woocommerce-claude/v1/difm/idea-board' );
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
		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/idea-board/reanalyse' );
		$request->set_param( 'board', $board );
		return $this->server->dispatch( $request );
	}

	/**
	 * Dispatch an idea-board save request.
	 *
	 * @param array $board Board payload.
	 * @return \WP_REST_Response
	 */
	private function dispatch_idea_board_save( array $board ) {
		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/idea-board/save' );
		$request->set_param( 'board', $board );
		return $this->server->dispatch( $request );
	}

	/**
	 * Return a sample board whose period matches the current date range.
	 *
	 * @return array
	 */
	private function sample_current_period_board() {
		$board = $this->sample_reanalysis_board();
		$dates = $this->current_test_period_dates( 90 );

		$board['period']['start'] = $dates['start'];
		$board['period']['end']   = $dates['end'];

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
			'notes'           => array(
				array(
					'id'         => 'retention-signal',
					'type'       => 'insight',
					'title'      => 'Repeat buyers are active',
					'body'       => 'Returning customers are present, so post-purchase ideas are worth testing.',
					'colour'     => 'blue',
					'x'          => 3,
					'y'          => 6,
					'rotation'   => -1,
					'prompt'     => 'Suggest repeat-buyer campaigns for this store.',
					'confidence' => 'medium',
				),
				array(
					'id'         => 'merchant-pop-up-offer',
					'type'       => 'idea',
					'title'      => 'Weekend pop-up offer',
					'body'       => 'Try a short weekend offer for the product line with the clearest recent signal.',
					'colour'     => 'orange',
					'x'          => 31,
					'y'          => 6,
					'rotation'   => 2,
					'prompt'     => 'Explore this merchant-added idea as a measurable promotion.',
					'confidence' => 'medium',
				),
				array(
					'id'         => 'first-test',
					'type'       => 'question',
					'title'      => 'What should we test first?',
					'body'       => 'Pick one measurable experiment before widening the plan.',
					'colour'     => 'white',
					'x'          => 60,
					'y'          => 36,
					'rotation'   => 0,
					'prompt'     => 'Help prioritise the first idea-board experiment.',
					'confidence' => 'high',
				),
			),
			'arrows'          => array(
				array(
					'from'  => 'retention-signal',
					'to'    => 'merchant-pop-up-offer',
					'label' => 'supports',
				),
			),
			'content'         => array(
				'source' => 'ai',
			),
			'layout'          => array(
				'source' => 'ai',
				'board'  => array(
					'width'  => 1480,
					'height' => 760,
				),
				'card'   => array(
					'width'  => 245,
					'height' => 150,
					'gap'    => 36,
				),
			),
			'generatedAt'     => '2026-05-12T10:00:00+00:00',
		);
	}

	/**
	 * Return a valid re-analysis payload from Anthropic.
	 *
	 * @return array
	 */
	private function valid_reanalysis_payload() {
		return array(
			'notes'     => array(
				array(
					'id'         => 'retention-signal',
					'type'       => 'insight',
					'title'      => 'Repeat buyers are ready',
					'body'       => 'Keep the repeat-buyer signal, but make the next action sharper.',
					'colour'     => 'blue',
					'prompt'     => 'Shape a repeat-buyer campaign from the edited board.',
					'confidence' => 'medium',
				),
				array(
					'id'         => 'merchant-pop-up-offer',
					'type'       => 'idea',
					'title'      => 'Test a weekend offer',
					'body'       => 'Run the merchant-added offer as a small timed experiment.',
					'colour'     => 'orange',
					'prompt'     => 'Turn the weekend offer into a measurable promotion.',
					'confidence' => 'medium',
				),
				array(
					'id'         => 'first-test',
					'type'       => 'question',
					'title'      => 'Which test is first?',
					'body'       => 'Choose the edited idea with the cleanest signal and next step.',
					'colour'     => 'white',
					'prompt'     => 'Prioritise the first board experiment.',
					'confidence' => 'high',
				),
			),
			'arrows'    => array(
				array(
					'from'  => 'retention-signal',
					'to'    => 'merchant-pop-up-offer',
					'label' => 'supports',
				),
			),
			'positions' => array(
				array(
					'id' => 'retention-signal',
					'x'  => 3,
					'y'  => 6,
				),
				array(
					'id' => 'merchant-pop-up-offer',
					'x'  => 31,
					'y'  => 6,
				),
				array(
					'id' => 'first-test',
					'x'  => 60,
					'y'  => 36,
				),
			),
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
	 * Return sorted note IDs.
	 *
	 * @param array $notes Board notes.
	 * @return array
	 */
	private function sorted_note_ids( array $notes ) {
		$ids = wp_list_pluck( $notes, 'id' );
		sort( $ids );
		return $ids;
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
}
