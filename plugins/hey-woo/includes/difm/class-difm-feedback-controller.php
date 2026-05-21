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
 * The conversation itself is re-saved client-side via the conversations
 * endpoint so the rating renders on reload; this endpoint is the qualitative
 * capture point only.
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
	 */
	const EVENT_NAME = 'hey_woo_feedback';

	/**
	 * Maximum characters retained from a merchant comment.
	 *
	 * Hard-caps free-text so a paste-bomb cannot inflate Tracks payloads or
	 * the stored conversation. UTF-8 aware via mb_substr.
	 */
	const COMMENT_MAX_LENGTH = 1000;

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
							'minLength'         => 1,
						),
						'message_id'      => array(
							'type'     => 'integer',
							'required' => true,
							'minimum'  => 0,
						),
						'rating'          => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'up', 'down' ),
						),
						'comment'         => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
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
	 * @return \WP_REST_Response
	 */
	public function record_feedback( \WP_REST_Request $request ) {
		$conversation_id = (string) $request->get_param( 'conversation_id' );
		$message_id      = (int) $request->get_param( 'message_id' );
		$rating          = (string) $request->get_param( 'rating' );
		$comment         = (string) $request->get_param( 'comment' );

		$comment = function_exists( 'mb_substr' )
			? mb_substr( $comment, 0, self::COMMENT_MAX_LENGTH )
			: substr( $comment, 0, self::COMMENT_MAX_LENGTH );

		$has_comment = '' !== trim( $comment );

		TelemetryHandler::record(
			self::EVENT_NAME,
			array(
				'event'           => self::EVENT_NAME,
				'conversation_id' => $conversation_id,
				'message_id'      => $message_id,
				'rating'          => $rating,
				'has_comment'     => $has_comment ? 'yes' : 'no',
				'comment_length'  => strlen( $comment ),
				'comment'         => $comment,
			)
		);

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}
}
