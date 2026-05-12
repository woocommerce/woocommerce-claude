<?php
/**
 * Integration tests for DifmConversationsController.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\DifmConversationsController;

/**
 * Tests for AI Insights conversation persistence.
 */
class Test_Difm_Conversations_Controller extends WP_UnitTestCase {

	/**
	 * REST server instance used for dispatching test requests.
	 *
	 * @var \WP_REST_Server
	 */
	protected $server;

	/**
	 * User IDs created during a test.
	 *
	 * @var int[]
	 */
	private $user_ids = array();

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
	 * Tear down persisted user meta.
	 */
	public function tear_down() {
		foreach ( $this->user_ids as $user_id ) {
			delete_user_meta( $user_id, DifmConversationsController::USER_META_KEY );
		}

		parent::tear_down();
	}

	/**
	 * The conversations route is registered.
	 */
	public function test_route_is_registered() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( '/woocommerce-claude/v1/difm/conversations', $routes );
	}

	/**
	 * Unauthenticated requests to GET /difm/conversations receive a 403.
	 */
	public function test_conversations_require_manage_woocommerce() {
		$request  = new \WP_REST_Request( 'GET', '/woocommerce-claude/v1/difm/conversations' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Saving conversations keeps only the five most recently updated entries.
	 */
	public function test_save_keeps_top_five_conversations_by_updated_at() {
		$user_id = $this->set_admin_user();

		for ( $i = 1; $i <= 6; $i++ ) {
			$this->save_conversation(
				array(
					'id'        => 'conversation-' . $i,
					'title'     => 'Conversation ' . $i,
					'messages'  => array(
						array(
							'id'      => 0,
							'role'    => 'user',
							'content' => 'Question ' . $i,
						),
					),
					'updatedAt' => $i,
				)
			);
		}

		$conversations = DifmConversationsController::get_recent_conversations( $user_id );

		$this->assertCount( 5, $conversations );
		$this->assertSame(
			array(
				'conversation-6',
				'conversation-5',
				'conversation-4',
				'conversation-3',
				'conversation-2',
			),
			wp_list_pluck( $conversations, 'id' )
		);
	}

	/**
	 * Persisted messages keep markdown-significant content intact.
	 */
	public function test_save_preserves_message_content() {
		$this->set_admin_user();

		$content = '## Heading' . "\n\n" . 'Path C:\\tmp\\orders and marker \\n stay intact.';
		$this->save_conversation(
			array(
				'id'        => 'formatting-test',
				'title'     => 'Formatting test',
				'messages'  => array(
					array(
						'id'      => 0,
						'role'    => 'assistant',
						'content' => $content,
					),
				),
				'updatedAt' => 10,
			)
		);

		$request       = new \WP_REST_Request( 'GET', '/woocommerce-claude/v1/difm/conversations' );
		$response      = $this->server->dispatch( $request );
		$conversations = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $content, $conversations[0]['messages'][0]['content'] );
	}

	/**
	 * Set the current user to an administrator with WooCommerce capabilities.
	 *
	 * @return int User ID.
	 */
	private function set_admin_user() {
		$user_id          = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$this->user_ids[] = $user_id;
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * Save one conversation through the REST endpoint.
	 *
	 * @param array $conversation Conversation payload.
	 * @return void
	 */
	private function save_conversation( array $conversation ) {
		$request = new \WP_REST_Request( 'POST', '/woocommerce-claude/v1/difm/conversations' );
		foreach ( $conversation as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = $this->server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
	}
}
