<?php
/**
 * REST controller for Hey Woo conversation persistence.
 *
 * Routes:
 *   GET  /hey-woo/v1/difm/conversations — Return stored conversations for the current user.
 *   POST /hey-woo/v1/difm/conversations — Create or update a conversation (upsert by ID).
 *
 * Conversations are stored as a JSON-encoded array in user meta under the key
 * `hey_woo_conversations`, capped at MAX_CONVERSATIONS entries
 * ordered by most-recently-updated first.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and handles the conversation persistence REST routes.
 *
 * PHP 7.4 compatible — no union types, no match, no enums.
 */
class DifmConversationsController {

	/**
	 * REST namespace — matches the chat controller.
	 */
	const NAMESPACE = 'hey-woo/v1';

	/**
	 * Conversations route.
	 */
	const CONVERSATIONS_ROUTE = '/difm/conversations';

	/**
	 * User meta key for stored conversations.
	 */
	const USER_META_KEY = 'hey_woo_conversations';

	/**
	 * Maximum number of conversations kept per user.
	 */
	const MAX_CONVERSATIONS = 5;

	/**
	 * Register REST route hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register conversations REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			self::CONVERSATIONS_ROUTE,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_conversations' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_conversation' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => array(
						'id'        => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'title'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'messages'  => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array(
								'type' => 'object',
							),
						),
						'updatedAt' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * GET handler — return stored conversations for the current user.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_conversations() {
		return rest_ensure_response( self::get_recent_conversations( get_current_user_id() ) );
	}

	/**
	 * POST handler — upsert a conversation by ID, keeping at most MAX_CONVERSATIONS.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function save_conversation( $request ) {
		$user_id         = get_current_user_id();
		$raw_body        = $request->get_body();
		$raw_body_params = array();
		$messages        = $request['messages'];

		if ( is_string( $raw_body ) && '' !== $raw_body ) {
			$decoded_body = json_decode( $raw_body, true );
			if ( is_array( $decoded_body ) ) {
				$raw_body_params = $decoded_body;
			}
		}

		if ( is_array( $raw_body_params ) && array_key_exists( 'messages', $raw_body_params ) ) {
			$messages = $raw_body_params['messages'];
		}

		$incoming = array(
			'id'        => $request['id'],
			'title'     => $request['title'],
			'messages'  => $messages,
			'updatedAt' => (int) $request['updatedAt'],
		);

		$conversations = self::get_recent_conversations( $user_id );

		// Remove existing entry with same ID (upsert).
		$conversations = array_values(
			array_filter(
				$conversations,
				function ( $conv ) use ( $incoming ) {
					return $conv['id'] !== $incoming['id'];
				}
			)
		);

		// Prepend the updated conversation.
		array_unshift( $conversations, $incoming );

		// Sort by updatedAt descending, keep most recent MAX_CONVERSATIONS.
		usort(
			$conversations,
			function ( $a, $b ) {
				return $b['updatedAt'] - $a['updatedAt'];
			}
		);
		$conversations = array_slice( $conversations, 0, self::MAX_CONVERSATIONS );

		update_user_meta( $user_id, self::USER_META_KEY, wp_slash( $conversations ) );

		return rest_ensure_response( array( 'status' => 'ok' ) );
	}

	/**
	 * Permission check — require manage_woocommerce capability.
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
	 * Read recent conversations from user meta — safe to call from PHP page setup.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array
	 */
	public static function get_recent_conversations( $user_id ) {
		$conversations = get_user_meta( $user_id, self::USER_META_KEY, true );
		if ( ! is_array( $conversations ) ) {
			return array();
		}

		return array_slice( $conversations, 0, self::MAX_CONVERSATIONS );
	}
}
