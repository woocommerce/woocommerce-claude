<?php
/**
 * REST controller for Hey Woo per-message feedback (thumbs + optional comment).
 *
 * Routes:
 *   POST /hey-woo/v1/difm/feedback — Record a thumbs-up/down (with optional
 *   comment) on a specific assistant message and fan the event out through
 *   TelemetryHandler so the Tracks handler picks it up when usage tracking
 *   is enabled.
 *
 * The endpoint never forwards the raw comment to telemetry — only rating,
 * IDs, and a has_comment / comment_length summary, matching the rest of the
 * DIFM telemetry surface. The free text persists on the merchant's own
 * conversation record via the conversations endpoint so reloads show their
 * note back to them; nothing leaves the store.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

use WooCommerce\HeyWoo\Telemetry\TelemetryHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the per-message feedback REST route.
 *
 * PHP 7.4 compatible — no union types, no match, no enums.
 */
class DifmFeedbackController {

	/**
	 * REST namespace — matches the chat controller.
	 */
	const NAMESPACE = 'hey-woo/v1';

	/**
	 * Feedback route.
	 */
	const FEEDBACK_ROUTE = '/difm/feedback';

	/**
	 * Telemetry event name passed to TelemetryHandler::record().
	 *
	 * Matches the difm_* naming used by tool-call / tool-result / workflow-selected.
	 */
	const EVENT_NAME = 'difm_feedback';

	/**
	 * Maximum characters retained from a merchant comment.
	 *
	 * Enforced both as a REST arg maxLength (so oversize bodies are rejected
	 * up front) and as a defensive UTF-8 cap inside the handler.
	 */
	const COMMENT_MAX_LENGTH = 1000;

	/**
	 * Per-user cooldown between feedback submissions, in seconds.
	 *
	 * Real merchant gestures fire at human cadence — thumb click followed by
	 * a Send several seconds later. A short cooldown bounds bursts from a
	 * compromised or scripted session without disrupting normal use; the
	 * client surfaces 429 responses as a generic retry error.
	 */
	const FEEDBACK_COOLDOWN_SECONDS = 1;

	/**
	 * Object cache group for the per-user feedback throttle.
	 *
	 * Used with wp_cache_add for an atomic check-and-set claim — see
	 * record_feedback for the BYOK-beta caveats on cross-process behaviour.
	 */
	const FEEDBACK_CACHE_GROUP = 'hey_woo_feedback_throttle';

	/**
	 * Register REST route hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the feedback REST route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::FEEDBACK_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'record_feedback' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'conversation_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
							'validate_callback' => 'rest_validate_request_arg',
							'minLength'         => 1,
							'maxLength'         => 100,
						),
						'message_id'      => array(
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => 'rest_validate_request_arg',
							'minimum'           => 0,
						),
						'rating'          => array(
							'type'              => 'string',
							'required'          => true,
							'validate_callback' => 'rest_validate_request_arg',
							'enum'              => array( 'up', 'down' ),
						),
						'comment'         => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'maxLength'         => self::COMMENT_MAX_LENGTH,
							'sanitize_callback' => 'sanitize_textarea_field',
							'validate_callback' => 'rest_validate_request_arg',
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
				__( 'Permission denied.', 'hey-woo' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * POST /hey-woo/v1/difm/feedback — fan a rating out to registered handlers.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function record_feedback( \WP_REST_Request $request ) {
		$user_id         = (int) get_current_user_id();
		$conversation_id = (string) $request->get_param( 'conversation_id' );
		$message_id      = (int) $request->get_param( 'message_id' );
		$rating          = (string) $request->get_param( 'rating' );
		$comment         = (string) $request->get_param( 'comment' );

		// Atomic check-and-set: wp_cache_add returns false if the slot is
		// already claimed, so two parallel requests can't both pass the gate.
		// The claim happens before the ownership check on purpose — probes
		// against unknown conversation IDs consume the slot too, so a spammer
		// cannot loop on cheap 404s to learn which IDs exist.
		//
		// Without a persistent object cache, wp_cache_add only protects within
		// a single PHP process and the throttle is effectively best-effort.
		// That's acceptable for the BYOK beta: the endpoint is gated by
		// manage_woocommerce and the cooldown is a defensive cap on bursts
		// from a scripted session, not a security boundary.
		$throttle_key = (string) $user_id;
		if ( ! wp_cache_add( $throttle_key, 1, self::FEEDBACK_CACHE_GROUP, self::FEEDBACK_COOLDOWN_SECONDS ) ) {
			return new \WP_Error(
				'hey_woo_feedback_throttled',
				__( 'Feedback is rate limited. Try again in a moment.', 'hey-woo' ),
				array( 'status' => 429 )
			);
		}

		if ( ! self::user_owns_conversation( $user_id, $conversation_id ) ) {
			return new \WP_Error(
				'hey_woo_feedback_unknown_conversation',
				__( 'Conversation not found.', 'hey-woo' ),
				array( 'status' => 404 )
			);
		}

		$comment = function_exists( 'mb_substr' )
			? mb_substr( $comment, 0, self::COMMENT_MAX_LENGTH )
			: substr( $comment, 0, self::COMMENT_MAX_LENGTH );

		$has_comment = '' !== trim( $comment );

		// Telemetry deliberately omits the raw comment text — see class-level
		// docblock and the summarise_tool_input_for_log pattern in
		// DifmRestController. The comment still persists on the merchant's
		// own conversation record via the conversations endpoint.
		TelemetryHandler::record(
			self::EVENT_NAME,
			array(
				'event'           => self::EVENT_NAME,
				'conversation_id' => $conversation_id,
				'message_id'      => $message_id,
				'rating'          => $rating,
				'has_comment'     => $has_comment ? 'yes' : 'no',
				'comment_length'  => function_exists( 'mb_strlen' )
					? mb_strlen( $comment )
					: strlen( $comment ),
			)
		);

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/**
	 * Whether the given user has a stored conversation with the given ID.
	 *
	 * Conversations live in per-user user_meta — see DifmConversationsController.
	 * The ownership check prevents one authorised user from forging feedback
	 * against another user's conversation IDs.
	 *
	 * @param int    $user_id         Current user ID.
	 * @param string $conversation_id Submitted conversation ID.
	 * @return bool
	 */
	private static function user_owns_conversation( $user_id, $conversation_id ) {
		if ( $user_id <= 0 || '' === $conversation_id ) {
			return false;
		}

		$conversations = DifmConversationsController::get_recent_conversations( $user_id );
		foreach ( $conversations as $conversation ) {
			if ( isset( $conversation['id'] ) && (string) $conversation['id'] === $conversation_id ) {
				return true;
			}
		}

		return false;
	}
}
