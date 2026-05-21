<?php
/**
 * Integration tests for the Hey Woo feedback REST controller.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\HeyWoo\Difm\DifmConversationsController;
use WooCommerce\HeyWoo\Difm\DifmFeedbackController;
use WooCommerce\HeyWoo\Telemetry\TelemetryHandler as HeyWooTelemetryHandler;
use WooCommerce\HeyWoo\Telemetry\TelemetryHandlerInterface;

require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/interface-telemetry-handler.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/class-telemetry-handler.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/handlers/class-log-handler.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-difm-conversations-controller.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-difm-feedback-controller.php';

/**
 * Tests the feedback REST controller's route, validation, telemetry fan-out,
 * conversation ownership check, and rate limit.
 */
class Test_Hey_Woo_Feedback_Controller extends WP_UnitTestCase {

	/**
	 * Anonymous capturing handler with a public $events array. Installed via the
	 * hey_woo_telemetry_handlers filter so we can assert what the controller
	 * forwarded.
	 *
	 * @var object|null
	 */
	private $capturing_handler = null;

	/**
	 * Primary administrator under test. Owns the seeded conversation IDs.
	 *
	 * @var int
	 */
	private $admin_user_id = 0;

	/**
	 * Install a capturing telemetry handler and a clean REST server before each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->admin_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user                = get_userdata( $this->admin_user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $this->admin_user_id );

		$this->seed_user_conversations(
			$this->admin_user_id,
			array( 'conv-123', 'conv-456', 'conv-789', 'conv-throttle', 'conv-route' )
		);

		$this->capturing_handler = new class() implements TelemetryHandlerInterface {
			/**
			 * Captured events.
			 *
			 * @var array<int,array{event:string,data:array}>
			 */
			public $events = array();

			/**
			 * Record an event in-memory.
			 *
			 * @param string $event_name Event name.
			 * @param array  $data       Event payload.
			 * @return void
			 */
			public function record( $event_name, $data ) {
				$this->events[] = array(
					'event' => $event_name,
					'data'  => $data,
				);
			}
		};
		add_filter( 'hey_woo_telemetry_handlers', array( $this, 'inject_capturing_handler' ) );
		HeyWooTelemetryHandler::init();

		// Fresh REST server for each test so register_rest_route is observed reliably.
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new DifmFeedbackController() )->register();
		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Re-firing core WP hook so route registration runs after the fresh server is in place.
		do_action( 'rest_api_init', $wp_rest_server );

		$this->clear_throttle( $this->admin_user_id );
	}

	/**
	 * Reset telemetry, REST server, and throttle state after each test.
	 */
	public function tear_down() {
		remove_filter( 'hey_woo_telemetry_handlers', array( $this, 'inject_capturing_handler' ) );
		HeyWooTelemetryHandler::init();
		$this->capturing_handler = null;

		$this->clear_throttle( $this->admin_user_id );

		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	/**
	 * Filter callback that appends the capturing handler to the registered set.
	 *
	 * @param array $handlers Currently registered telemetry handlers.
	 * @return array
	 */
	public function inject_capturing_handler( $handlers ) {
		if ( $this->capturing_handler ) {
			$handlers[] = $this->capturing_handler;
		}
		return $handlers;
	}

	/**
	 * Seed the per-user conversation store with the given conversation IDs.
	 *
	 * @param int      $user_id          Target user.
	 * @param string[] $conversation_ids Conversation IDs to insert.
	 * @return void
	 */
	private function seed_user_conversations( $user_id, array $conversation_ids ) {
		$conversations = array();
		foreach ( $conversation_ids as $index => $conversation_id ) {
			$conversations[] = array(
				'id'        => $conversation_id,
				'title'     => 'Test ' . $conversation_id,
				'messages'  => array(),
				'updatedAt' => time() * 1000 + $index,
			);
		}
		update_user_meta( $user_id, DifmConversationsController::USER_META_KEY, $conversations );
	}

	/**
	 * Remove the per-user throttle transient so consecutive tests are not throttled.
	 *
	 * @param int $user_id Target user.
	 * @return void
	 */
	private function clear_throttle( $user_id ) {
		delete_transient( DifmFeedbackController::FEEDBACK_THROTTLE_PREFIX . (int) $user_id );
	}

	/**
	 * Build the standard endpoint URL.
	 *
	 * @return string
	 */
	private function endpoint_url() {
		return '/' . DifmFeedbackController::NAMESPACE . DifmFeedbackController::FEEDBACK_ROUTE;
	}

	/**
	 * Submit a POST request to the feedback endpoint with the given body.
	 *
	 * @param array $body Request body.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function post_feedback( array $body ) {
		$request = new WP_REST_Request( 'POST', $this->endpoint_url() );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Happy path: thumbs-up with no comment returns ok and fans the event to handlers.
	 */
	public function test_thumbs_up_without_comment_records_telemetry() {
		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-123',
				'message_id'      => 7,
				'rating'          => 'up',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'status' => 'ok' ), $response->get_data() );

		$this->assertCount( 1, $this->capturing_handler->events );
		$event = $this->capturing_handler->events[0];
		$this->assertSame( 'difm_feedback', $event['event'] );
		$this->assertSame( 'conv-123', $event['data']['conversation_id'] );
		$this->assertSame( 7, $event['data']['message_id'] );
		$this->assertSame( 'up', $event['data']['rating'] );
		$this->assertSame( 'no', $event['data']['has_comment'] );
		$this->assertSame( 0, $event['data']['comment_length'] );
	}

	/**
	 * Happy path: thumbs-down with a comment flags has_comment without forwarding the text.
	 *
	 * The raw comment is deliberately omitted from the telemetry payload (PII /
	 * DIFM convention); merchants get the text back via the conversation upsert.
	 */
	public function test_thumbs_down_with_comment_records_summary_but_not_text() {
		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-456',
				'message_id'      => 12,
				'rating'          => 'down',
				'comment'         => 'Numbers looked off for refund rate.',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$event = $this->capturing_handler->events[0];
		$this->assertSame( 'down', $event['data']['rating'] );
		$this->assertSame( 'yes', $event['data']['has_comment'] );
		$this->assertSame( strlen( 'Numbers looked off for refund rate.' ), $event['data']['comment_length'] );
		$this->assertArrayNotHasKey( 'comment', $event['data'], 'Raw comment text must not be forwarded to telemetry.' );
	}

	/**
	 * Oversize comments are rejected by the REST schema before reaching the handler.
	 */
	public function test_oversize_comments_are_rejected_by_the_schema() {
		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-789',
				'message_id'      => 1,
				'rating'          => 'up',
				'comment'         => str_repeat( 'a', DifmFeedbackController::COMMENT_MAX_LENGTH + 50 ),
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertEmpty( $this->capturing_handler->events );
	}

	/**
	 * Invalid rating values are rejected by the schema and never reach telemetry.
	 */
	public function test_invalid_rating_is_rejected() {
		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-123',
				'message_id'      => 1,
				'rating'          => 'maybe',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertEmpty( $this->capturing_handler->events );
	}

	/**
	 * Missing required fields are rejected by the schema.
	 */
	public function test_missing_required_fields_are_rejected() {
		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-123',
				'rating'          => 'up',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertEmpty( $this->capturing_handler->events );
	}

	/**
	 * Authenticated users without manage_woocommerce cannot post feedback.
	 */
	public function test_users_without_manage_woocommerce_are_rejected() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-123',
				'message_id'      => 1,
				'rating'          => 'up',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->capturing_handler->events );
	}

	/**
	 * Unauthenticated requests are rejected without recording telemetry.
	 */
	public function test_unauthenticated_requests_are_rejected() {
		wp_set_current_user( 0 );

		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-123',
				'message_id'      => 1,
				'rating'          => 'up',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->capturing_handler->events );
	}

	/**
	 * Feedback against a conversation the user does not own is rejected with 404.
	 *
	 * Regression test for the IDOR Codex flagged on the first review pass.
	 */
	public function test_feedback_against_another_users_conversation_is_rejected() {
		$other_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$other_user    = get_userdata( $other_user_id );
		$other_user->add_cap( 'manage_woocommerce' );
		$this->seed_user_conversations( $other_user_id, array( 'conv-other' ) );

		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-other',
				'message_id'      => 1,
				'rating'          => 'up',
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertEmpty( $this->capturing_handler->events );
	}

	/**
	 * Unknown conversation IDs are rejected with 404 before reaching telemetry.
	 */
	public function test_unknown_conversation_id_is_rejected() {
		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-does-not-exist',
				'message_id'      => 1,
				'rating'          => 'up',
			)
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertEmpty( $this->capturing_handler->events );
	}

	/**
	 * Back-to-back submissions are throttled with a 429.
	 */
	public function test_rapid_submissions_are_rate_limited() {
		$first = $this->post_feedback(
			array(
				'conversation_id' => 'conv-throttle',
				'message_id'      => 1,
				'rating'          => 'up',
			)
		);
		$this->assertSame( 200, $first->get_status() );

		$second = $this->post_feedback(
			array(
				'conversation_id' => 'conv-throttle',
				'message_id'      => 2,
				'rating'          => 'down',
			)
		);

		$this->assertSame( 429, $second->get_status() );
		$this->assertCount( 1, $this->capturing_handler->events, 'Only the first request should reach telemetry.' );
	}

	/**
	 * The throttle does not fire when no prior submission has set the transient.
	 */
	public function test_throttle_does_not_fire_on_first_submission() {
		$this->clear_throttle( $this->admin_user_id );

		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-route',
				'message_id'      => 1,
				'rating'          => 'up',
			)
		);

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The controller registers the expected REST route.
	 */
	public function test_register_routes_exposes_the_feedback_endpoint() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( $this->endpoint_url(), $routes );
	}
}
