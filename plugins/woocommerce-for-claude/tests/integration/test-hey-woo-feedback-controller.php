<?php
/**
 * Integration tests for the Hey Woo feedback REST controller.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\HeyWoo\Difm\DifmFeedbackController;
use WooCommerce\HeyWoo\Telemetry\TelemetryHandler as HeyWooTelemetryHandler;
use WooCommerce\HeyWoo\Telemetry\TelemetryHandlerInterface;

require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/interface-telemetry-handler.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/class-telemetry-handler.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/handlers/class-log-handler.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-difm-feedback-controller.php';

/**
 * Tests the feedback REST controller's route, validation, and telemetry fan-out.
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
	 * Install a capturing telemetry handler and a clean REST server before each test.
	 */
	public function set_up() {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_userdata( $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

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
	}

	/**
	 * Reset telemetry and REST server state after each test.
	 */
	public function tear_down() {
		remove_filter( 'hey_woo_telemetry_handlers', array( $this, 'inject_capturing_handler' ) );
		HeyWooTelemetryHandler::init();
		$this->capturing_handler = null;

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
		$this->assertSame( DifmFeedbackController::EVENT_NAME, $event['event'] );
		$this->assertSame( 'conv-123', $event['data']['conversation_id'] );
		$this->assertSame( 7, $event['data']['message_id'] );
		$this->assertSame( 'up', $event['data']['rating'] );
		$this->assertSame( 'no', $event['data']['has_comment'] );
		$this->assertSame( 0, $event['data']['comment_length'] );
		$this->assertSame( '', $event['data']['comment'] );
	}

	/**
	 * Happy path: thumbs-down with a comment flags has_comment and forwards the text.
	 */
	public function test_thumbs_down_with_comment_includes_comment_payload() {
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
		$this->assertSame( 'Numbers looked off for refund rate.', $event['data']['comment'] );
	}

	/**
	 * Comments longer than the cap are truncated in the telemetry payload.
	 */
	public function test_long_comments_are_capped_at_the_maximum_length() {
		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-789',
				'message_id'      => 1,
				'rating'          => 'up',
				'comment'         => str_repeat( 'a', DifmFeedbackController::COMMENT_MAX_LENGTH + 50 ),
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$event = $this->capturing_handler->events[0];
		$this->assertSame( DifmFeedbackController::COMMENT_MAX_LENGTH, $event['data']['comment_length'] );
	}

	/**
	 * Invalid rating values are rejected by the schema and never reach telemetry.
	 */
	public function test_invalid_rating_is_rejected() {
		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-1',
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
				'conversation_id' => 'conv-1',
				'rating'          => 'up',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertEmpty( $this->capturing_handler->events );
	}

	/**
	 * Users without manage_woocommerce cannot post feedback.
	 */
	public function test_unauthorised_users_are_rejected() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$response = $this->post_feedback(
			array(
				'conversation_id' => 'conv-1',
				'message_id'      => 1,
				'rating'          => 'up',
			)
		);

		$this->assertSame( 403, $response->get_status() );
		$this->assertEmpty( $this->capturing_handler->events );
	}

	/**
	 * The controller registers the expected REST route.
	 */
	public function test_register_routes_exposes_the_feedback_endpoint() {
		$routes = rest_get_server()->get_routes();
		$this->assertArrayHasKey( $this->endpoint_url(), $routes );
	}
}
