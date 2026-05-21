<?php
/**
 * Integration tests for the Hey Woo chat-progress REST endpoint.
 *
 * The endpoint backs the streaming-progress hint shown in the chat UI
 * while the controller is iterating through tool calls. The frontend
 * polls it every ~600ms with a per-turn progress_id; this suite covers
 * the read path, auth, validation, and the empty-state fallback.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\HeyWoo\Difm\DifmRestController;

require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-difm-rest-controller.php';

/**
 * Tests the chat-progress route shape, validation, and transient read path.
 */
class Test_Hey_Woo_Chat_Progress extends WP_UnitTestCase {

	/**
	 * Primary administrator under test. Has manage_woocommerce.
	 *
	 * @var int
	 */
	private $admin_user_id = 0;

	/**
	 * Set up an authorised user, a clean REST server, and clear any leftover
	 * progress transients from prior tests in this process.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user                = get_userdata( $this->admin_user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $this->admin_user_id );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new DifmRestController() )->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Re-firing core WP hook so route registration runs after the fresh server is in place.
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Clean up transients + REST server state after each test.
	 */
	public function tear_down() {
		// Best-effort cleanup of any progress key our tests seeded.
		$this->seed_progress( 'happy-path-progress-id', null );
		$this->seed_progress( 'persisted-progress-id', null );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Build the chat-progress URL.
	 *
	 * @param string|null $progress_id Optional progress_id to append as a query arg.
	 * @return string
	 */
	private function endpoint_url( $progress_id = null ) {
		$path = '/' . DifmRestController::NAMESPACE . DifmRestController::PROGRESS_ROUTE;
		if ( null === $progress_id ) {
			return $path;
		}
		return $path . '?progress_id=' . rawurlencode( (string) $progress_id );
	}

	/**
	 * Write (or delete) a chat-progress payload directly.
	 *
	 * @param string                                                $progress_id Progress identifier.
	 * @param array{tool?:string,phase?:string,timestamp?:int}|null $payload     Payload to set, or null to delete.
	 * @return void
	 */
	private function seed_progress( $progress_id, $payload ) {
		$key = DifmRestController::PROGRESS_TRANSIENT_PREFIX . $progress_id;
		if ( null === $payload ) {
			delete_transient( $key );
			return;
		}
		set_transient( $key, $payload, DifmRestController::PROGRESS_TTL_SECONDS );
	}

	/**
	 * Issue a GET request against the chat-progress endpoint.
	 *
	 * @param string|null $progress_id Optional progress_id query arg.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function get_progress( $progress_id ) {
		$request = new WP_REST_Request( 'GET', '/' . DifmRestController::NAMESPACE . DifmRestController::PROGRESS_ROUTE );
		if ( null !== $progress_id ) {
			$request->set_param( 'progress_id', $progress_id );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Happy path: a seeded transient is returned verbatim.
	 */
	public function test_returns_seeded_progress_payload() {
		$this->seed_progress(
			'happy-path-progress-id',
			array(
				'tool'      => 'analytics_totals',
				'phase'     => 'execute',
				'timestamp' => 1700000000,
			)
		);

		$response = $this->get_progress( 'happy-path-progress-id' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'ok', $data['status'] );
		$this->assertSame( 'analytics_totals', $data['tool'] );
		$this->assertSame( 'execute', $data['phase'] );
		$this->assertSame( 1700000000, $data['timestamp'] );
	}

	/**
	 * Absent transient yields a structured empty payload, not an error.
	 */
	public function test_returns_null_fields_when_no_transient_exists() {
		$response = $this->get_progress( 'never-seeded-progress-id' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'ok', $data['status'] );
		$this->assertNull( $data['tool'] );
		$this->assertNull( $data['phase'] );
		$this->assertNull( $data['timestamp'] );
	}

	/**
	 * Missing progress_id is rejected by the REST schema.
	 */
	public function test_missing_progress_id_is_rejected() {
		$response = $this->get_progress( null );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Empty progress_id is rejected by the REST schema (minLength: 1).
	 */
	public function test_empty_progress_id_is_rejected() {
		$response = $this->get_progress( '' );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Oversize progress_id is rejected by the REST schema (maxLength enforced).
	 */
	public function test_oversize_progress_id_is_rejected() {
		$response = $this->get_progress( str_repeat( 'a', DifmRestController::PROGRESS_ID_MAX_LENGTH + 5 ) );

		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * Authenticated users without manage_woocommerce are rejected.
	 */
	public function test_users_without_manage_woocommerce_are_rejected() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$response = $this->get_progress( 'persisted-progress-id' );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Unauthenticated requests are rejected.
	 */
	public function test_unauthenticated_requests_are_rejected() {
		wp_set_current_user( 0 );

		$response = $this->get_progress( 'persisted-progress-id' );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Disallowed characters in progress_id yield the empty payload — the
	 * controller's defensive sanitiser drops them before the transient
	 * lookup runs. The schema-level validator does not reject these because
	 * they are within the length budget, so the empty payload is the right
	 * behaviour.
	 */
	public function test_progress_id_with_disallowed_characters_returns_empty_payload() {
		$this->seed_progress(
			'persisted-progress-id',
			array(
				'tool'      => 'analytics_series',
				'phase'     => 'complete',
				'timestamp' => 1700000001,
			)
		);

		$response = $this->get_progress( 'persisted-progress-id; rm -rf /' );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNull( $data['tool'] );
		$this->assertNull( $data['phase'] );
	}
}
