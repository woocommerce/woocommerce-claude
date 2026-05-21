<?php
/**
 * Integration tests for the Hey Woo conversations REST controller.
 *
 * Focused on the per-user advisory lock and stale-write guard in
 * {@see WooCommerce\HeyWoo\Difm\DifmConversationsController::save_conversation()}.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\HeyWoo\Difm\DifmConversationsController;

require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-difm-conversations-controller.php';

/**
 * Tests the conversations controller's lock + stale-write semantics.
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
	 *
	 * Also asserts the 409 body carries the conflict timestamps so the client
	 * could reconcile against the rejection without guessing.
	 */
	public function test_older_update_does_not_overwrite_newer_stored_entry() {
		$conv_id = 'conv-race';
		$newer   = $this->build_payload( $conv_id, 2000, 'newer-title' );
		$older   = $this->build_payload( $conv_id, 1000, 'older-title' );

		$first = $this->post_conversation( $newer );
		$this->assertSame( 200, $first->get_status() );

		$second = $this->post_conversation( $older );
		$this->assertSame( 409, $second->get_status(), 'Stale write must be rejected.' );

		$error_body = $second->get_data();
		$this->assertIsArray( $error_body );
		$this->assertSame( 'hey_woo_stale_conversation_write', $error_body['code'] );
		$this->assertSame( 2000, $error_body['data']['storedAt'] );
		$this->assertSame( 1000, $error_body['data']['incomingAt'] );

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

	/**
	 * When another connection already holds the per-user advisory lock, a
	 * concurrent save must return 503 so the client can retry rather than
	 * silently stomping on the in-flight write.
	 *
	 * Simulates the contention case the in-process integration test cannot
	 * otherwise exercise: two PHP-FPM workers reaching save_conversation()
	 * for the same user at once.
	 */
	public function test_returns_503_when_lock_is_held_by_concurrent_worker() {
		$lock_key = DifmConversationsController::LOCK_KEY_PREFIX . $this->admin_user_id;
		$other    = $this->open_secondary_db_connection();
		$acquired = $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, 5 ) );
		$this->assertSame( '1', (string) $acquired, 'Pre-acquired lock must succeed on the secondary connection.' );

		// Force the controller's own GET_LOCK call to give up immediately so the
		// test does not have to wait out the production timeout.
		add_filter( 'hey_woo_conversation_lock_timeout', '__return_zero' );

		try {
			$response = $this->post_conversation( $this->build_payload( 'conv-locked', 1000, 'first' ) );

			$this->assertSame( 503, $response->get_status(), 'Contended save must surface 503.' );
			$body = $response->get_data();
			$this->assertIsArray( $body );
			$this->assertSame( 'hey_woo_conversation_lock_timeout', $body['code'] );

			// Nothing was persisted — the write was rejected before the read.
			$this->assertCount( 0, $this->get_stored_conversations() );
		} finally {
			remove_filter( 'hey_woo_conversation_lock_timeout', '__return_zero' );
			$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
			$other->close();
		}
	}

	/**
	 * Once the contending worker releases the lock, the next save succeeds.
	 * Asserts the lock is correctly released by the controller (no leak across
	 * requests) by running an immediately-following save after the simulated
	 * contention ends.
	 */
	public function test_save_succeeds_after_concurrent_worker_releases_lock() {
		$lock_key = DifmConversationsController::LOCK_KEY_PREFIX . $this->admin_user_id;
		$other    = $this->open_secondary_db_connection();
		$other->get_var( $other->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_key, 5 ) );
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
		$other->close();

		$response = $this->post_conversation( $this->build_payload( 'conv-after-lock', 1000, 'ok' ) );

		$this->assertSame( 200, $response->get_status() );
		$stored = $this->get_stored_conversations();
		$this->assertCount( 1, $stored );
		$this->assertSame( 'conv-after-lock', $stored[0]['id'] );
	}

	/**
	 * Open a second wpdb connection so it can hold a GET_LOCK independently
	 * of the connection the controller uses.
	 *
	 * @return \wpdb
	 */
	private function open_secondary_db_connection() {
		// phpcs:ignore WordPress.DB.RestrictedClasses.mysql__wpdb -- Intentional: we need a separate session to simulate cross-worker contention.
		return new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	}
}
