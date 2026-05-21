<?php
/**
 * Integration tests for the Hey Woo conversations REST controller.
 *
 * Focused on the stale-write guard: out-of-order POSTs to the same conversation
 * ID must not let an older `updatedAt` overwrite a newer stored entry.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\HeyWoo\Difm\DifmConversationsController;

require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-difm-conversations-controller.php';

/**
 * Tests the conversations controller's stale-write guard.
 */
class Test_Hey_Woo_Conversations_Controller extends WP_UnitTestCase {

	/**
	 * Administrator under test.
	 *
	 * @var int
	 */
	private $admin_user_id = 0;

	/**
	 * Set up a fresh REST server and an admin user with the required cap.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user                = get_userdata( $this->admin_user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $this->admin_user_id );

		// Fresh REST server so register_rest_route is observed reliably.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new DifmConversationsController() )->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Re-firing core WP hook so route registration runs after the fresh server is in place.
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Reset REST server and stored conversations after each test.
	 */
	public function tear_down() {
		delete_user_meta( $this->admin_user_id, DifmConversationsController::USER_META_KEY );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Build the conversations endpoint URL.
	 *
	 * @return string
	 */
	private function endpoint_url() {
		return '/' . DifmConversationsController::NAMESPACE . DifmConversationsController::CONVERSATIONS_ROUTE;
	}

	/**
	 * POST a conversation payload to the endpoint.
	 *
	 * @param array $body Request body.
	 * @return \WP_REST_Response
	 */
	private function post_conversation( array $body ) {
		$request = new WP_REST_Request( 'POST', $this->endpoint_url() );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * GET stored conversations for the current user.
	 *
	 * @return array
	 */
	private function get_stored_conversations() {
		$request  = new WP_REST_Request( 'GET', $this->endpoint_url() );
		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build a minimal conversation payload.
	 *
	 * @param string $id         Conversation ID.
	 * @param int    $updated_at updatedAt timestamp.
	 * @param string $title      Title.
	 * @return array
	 */
	private function build_payload( $id, $updated_at, $title ) {
		return array(
			'id'        => $id,
			'title'     => $title,
			'messages'  => array(
				array(
					'id'      => 0,
					'role'    => 'user',
					'content' => 'hello',
				),
			),
			'updatedAt' => $updated_at,
		);
	}

	/**
	 * Out-of-order saves: the newer state must survive even when the older
	 * POST arrives second. Regression test for the conversation-save race.
	 */
	public function test_older_update_does_not_overwrite_newer_stored_entry() {
		$conv_id = 'conv-race';
		$newer   = $this->build_payload( $conv_id, 2000, 'newer-title' );
		$older   = $this->build_payload( $conv_id, 1000, 'older-title' );

		$first = $this->post_conversation( $newer );
		$this->assertSame( 200, $first->get_status() );

		$second = $this->post_conversation( $older );
		$this->assertSame( 409, $second->get_status(), 'Stale write must be rejected.' );

		$stored = $this->get_stored_conversations();
		$this->assertCount( 1, $stored );
		$this->assertSame( $conv_id, $stored[0]['id'] );
		$this->assertSame( 2000, (int) $stored[0]['updatedAt'] );
		$this->assertSame( 'newer-title', $stored[0]['title'] );
	}

	/**
	 * A newer save after an existing entry replaces the stored content.
	 */
	public function test_newer_update_replaces_stored_entry() {
		$conv_id = 'conv-update';

		$first = $this->post_conversation( $this->build_payload( $conv_id, 1000, 'original' ) );
		$this->assertSame( 200, $first->get_status() );

		$second = $this->post_conversation( $this->build_payload( $conv_id, 2000, 'updated' ) );
		$this->assertSame( 200, $second->get_status() );

		$stored = $this->get_stored_conversations();
		$this->assertCount( 1, $stored );
		$this->assertSame( 2000, (int) $stored[0]['updatedAt'] );
		$this->assertSame( 'updated', $stored[0]['title'] );
	}

	/**
	 * A save with the same updatedAt is accepted — the guard only blocks
	 * strictly older writes, so duplicate / idempotent saves still succeed.
	 */
	public function test_equal_updated_at_is_accepted() {
		$conv_id = 'conv-equal';

		$first = $this->post_conversation( $this->build_payload( $conv_id, 1500, 'first' ) );
		$this->assertSame( 200, $first->get_status() );

		$second = $this->post_conversation( $this->build_payload( $conv_id, 1500, 'second' ) );
		$this->assertSame( 200, $second->get_status() );

		$stored = $this->get_stored_conversations();
		$this->assertCount( 1, $stored );
		$this->assertSame( 1500, (int) $stored[0]['updatedAt'] );
		$this->assertSame( 'second', $stored[0]['title'] );
	}

	/**
	 * The first save for a never-seen conversation ID is always accepted.
	 */
	public function test_first_save_is_accepted_regardless_of_updated_at() {
		$response = $this->post_conversation( $this->build_payload( 'conv-fresh', 1, 'fresh' ) );

		$this->assertSame( 200, $response->get_status() );
		$stored = $this->get_stored_conversations();
		$this->assertCount( 1, $stored );
		$this->assertSame( 'conv-fresh', $stored[0]['id'] );
	}
}
