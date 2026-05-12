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
		$this->delete_idea_board_transients();
		parent::tear_down();
	}

	/**
	 * The idea-board route is registered.
	 */
	public function test_route_is_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/' . IdeaBoardRestController::NAMESPACE . IdeaBoardRestController::ROUTE, $routes );
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

				if ( 1 === $call_count ) {
					$text = wp_json_encode(
						array(
							'notes'  => array(
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
							'arrows' => array(
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
									),
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
		$this->assertSame( 2, $call_count );
		$this->assertSame( 'ai', $data['board']['content']['source'] );
		$this->assertSame( 'ai', $data['board']['layout']['source'] );
		$this->assertStringContainsString( 'merchant_prompt', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'readiness_recommendations', $captured_bodies[0]['messages'][0]['content'] );
		$this->assertStringContainsString( 'Canvas Tote', wp_json_encode( $data['board']['notes'] ) );
		$this->assertStringContainsString( '"width":1480', $captured_bodies[1]['messages'][0]['content'] );
		$this->assertStringContainsString( '"width":245', $captured_bodies[1]['messages'][0]['content'] );
		$this->assertStringContainsString( 'do not write, edit, add, remove', strtolower( $captured_bodies[1]['system'] ) );

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
