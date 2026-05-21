<?php
/**
 * REST controller for Hey Woo conversation persistence.
 *
 * Routes:
 *   GET  /hey-woo/v1/difm/conversations — Return stored conversations for the current user.
 *   POST /hey-woo/v1/difm/conversations — Create or update a conversation (upsert by ID).
 *   DELETE /hey-woo/v1/difm/conversations — Delete one or more stored conversations.
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
	const MAX_CONVERSATIONS = 50;

	/**
	 * Prefix for the per-user MySQL advisory lock taken around the read →
	 * stale-check → write sequence in {@see save_conversation()}.
	 *
	 * Lock keys are limited to 64 chars in MySQL 5.7+; this prefix plus a 64-bit
	 * user id stays well within that bound.
	 */
	const LOCK_KEY_PREFIX = 'hey_woo_conv:';

	/**
	 * GET_LOCK acquisition timeout, in seconds.
	 *
	 * Short on purpose: under contention we'd rather return 503 quickly than
	 * tie up a PHP-FPM worker. Overridable via the `hey_woo_conversation_lock_timeout`
	 * filter (tests use 0 for fast contention assertions).
	 */
	const LOCK_TIMEOUT_SECONDS = 2;

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
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_conversations' ),
					'permission_callback' => array( $this, 'check_permission' ),
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
	 * The read → stale-check → write sequence runs under a per-user MySQL
	 * advisory lock so two concurrent PHP-FPM workers cannot interleave their
	 * SELECT/UPDATE pairs and let an older write land last. See
	 * {@see with_user_lock()} for the lock contract.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
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

		if ( is_array( $raw_body_params ) && isset( $raw_body_params['type'] ) ) {
			$type = sanitize_key( (string) $raw_body_params['type'] );
			if ( in_array( $type, array( 'chat', 'workflow' ), true ) ) {
				$incoming['type'] = $type;
			}
		}

		if ( is_array( $raw_body_params ) && isset( $raw_body_params['workflowRun'] ) && is_array( $raw_body_params['workflowRun'] ) ) {
			$incoming['workflowRun'] = self::sanitize_workflow_run_meta( $raw_body_params['workflowRun'] );
		}

		return $this->with_user_lock(
			$user_id,
			function () use ( $user_id, $incoming ) {
				$conversations = self::get_recent_conversations( $user_id );

				// Stale-write guard: if a stored entry already has a newer updatedAt for
				// this id, reject — otherwise a slow, older POST could overwrite a faster,
				// newer one. The enclosing lock makes this guard atomic with the write.
				foreach ( $conversations as $existing ) {
					if ( ! isset( $existing['id'] ) || $existing['id'] !== $incoming['id'] ) {
						continue;
					}
					$stored_updated_at = isset( $existing['updatedAt'] ) ? (int) $existing['updatedAt'] : 0;
					if ( $incoming['updatedAt'] < $stored_updated_at ) {
						return new \WP_Error(
							'hey_woo_stale_conversation_write',
							__( 'A newer version of this conversation is already stored.', 'hey-woo' ),
							array(
								'status'     => 409,
								'storedAt'   => $stored_updated_at,
								'incomingAt' => $incoming['updatedAt'],
							)
						);
					}
					break;
				}

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
		);
	}

	/**
	 * Run a callback under a per-user MySQL advisory lock.
	 *
	 * The lock is keyed on the user id so saves for one user never block another
	 * user's saves. On timeout we surface a 503 so the client can retry — the
	 * client-side save chain in `useConversations.ts` already coalesces same-id
	 * saves, so 503s in practice indicate genuine multi-tab or multi-consumer
	 * contention rather than a self-collision.
	 *
	 * This is the only place in the plugin that uses GET_LOCK; the choice is
	 * documented here because it sets a precedent. Alternatives considered:
	 * per-conversation rows (much larger change) and Redis-backed locks (extra
	 * infra dependency). GET_LOCK is connection-scoped, releases automatically
	 * when the connection dies, and is portable across the MySQL/MariaDB
	 * versions WordPress already supports.
	 *
	 * @param int      $user_id  WordPress user id; namespaces the lock.
	 * @param callable $callback No-arg callback whose return value is propagated.
	 * @return mixed The callback's return value, or a WP_Error if the lock
	 *               cannot be acquired within the configured timeout.
	 */
	private function with_user_lock( $user_id, callable $callback ) {
		global $wpdb;

		$key = self::LOCK_KEY_PREFIX . (int) $user_id;
		/**
		 * Filter the GET_LOCK acquisition timeout for conversation writes.
		 *
		 * @since 0.5.0
		 *
		 * @param int $timeout_seconds Default {@see self::LOCK_TIMEOUT_SECONDS}.
		 */
		$timeout = (int) apply_filters( 'hey_woo_conversation_lock_timeout', self::LOCK_TIMEOUT_SECONDS );
		if ( $timeout < 0 ) {
			$timeout = 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK is a session-scoped advisory lock; caching would defeat the purpose.
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, $timeout ) );

		if ( '1' !== (string) $acquired ) {
			return new \WP_Error(
				'hey_woo_conversation_lock_timeout',
				__( 'The conversation is being saved by another request. Retry in a moment.', 'hey-woo' ),
				array( 'status' => 503 )
			);
		}

		try {
			return $callback();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See acquire site above; RELEASE_LOCK must mirror it.
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	/**
	 * Sanitize workflow-run metadata before storing it in user meta.
	 *
	 * @param array<string,mixed> $meta Raw workflow metadata.
	 * @return array<string,mixed>
	 */
	private static function sanitize_workflow_run_meta( array $meta ) {
		$status = isset( $meta['status'] ) ? sanitize_key( (string) $meta['status'] ) : 'running';
		if ( ! in_array( $status, array( 'running', 'complete', 'error' ), true ) ) {
			$status = 'running';
		}

		return array(
			'slug'              => isset( $meta['slug'] ) ? sanitize_key( (string) $meta['slug'] ) : '',
			'label'             => isset( $meta['label'] ) ? sanitize_text_field( (string) $meta['label'] ) : '',
			'status'            => $status,
			'runMode'           => isset( $meta['runMode'] ) ? sanitize_key( (string) $meta['runMode'] ) : 'now',
			'period'            => isset( $meta['period'] ) ? sanitize_key( (string) $meta['period'] ) : '',
			'periodLabel'       => isset( $meta['periodLabel'] ) ? sanitize_text_field( (string) $meta['periodLabel'] ) : '',
			'compare'           => ! empty( $meta['compare'] ),
			'actionCards'       => ! empty( $meta['actionCards'] ),
			'adminNotification' => ! empty( $meta['adminNotification'] ),
			'startedAt'         => isset( $meta['startedAt'] ) ? (int) $meta['startedAt'] : 0,
			'scheduleLabel'     => isset( $meta['scheduleLabel'] ) ? sanitize_text_field( (string) $meta['scheduleLabel'] ) : '',
			'completedAt'       => isset( $meta['completedAt'] ) ? (int) $meta['completedAt'] : 0,
			'errorMessage'      => isset( $meta['errorMessage'] ) ? sanitize_text_field( (string) $meta['errorMessage'] ) : '',
		);
	}

	/**
	 * DELETE handler — remove one or more conversations by ID.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_conversations( $request ) {
		$user_id = get_current_user_id();
		$ids     = $request->get_param( 'ids' );

		if ( ! is_array( $ids ) ) {
			$raw_body = $request->get_body();
			if ( is_string( $raw_body ) && '' !== $raw_body ) {
				$decoded_body = json_decode( $raw_body, true );
				if ( is_array( $decoded_body ) && isset( $decoded_body['ids'] ) ) {
					$ids = $decoded_body['ids'];
				}
			}
		}

		if ( is_string( $ids ) ) {
			$ids = array( $ids );
		}

		if ( ! is_array( $ids ) ) {
			return new \WP_Error(
				'hey_woo_missing_conversation_ids',
				__( 'Select at least one conversation to delete.', 'hey-woo' ),
				array( 'status' => 400 )
			);
		}

		$ids = array_values(
			array_filter(
				array_unique(
					array_map(
						function ( $id ) {
							return sanitize_text_field( (string) $id );
						},
						$ids
					)
				)
			)
		);

		if ( empty( $ids ) ) {
			return new \WP_Error(
				'hey_woo_missing_conversation_ids',
				__( 'Select at least one conversation to delete.', 'hey-woo' ),
				array( 'status' => 400 )
			);
		}

		$conversations = self::get_recent_conversations( $user_id );
		$conversations = array_values(
			array_filter(
				$conversations,
				function ( $conversation ) use ( $ids ) {
					return ! isset( $conversation['id'] ) || ! in_array( $conversation['id'], $ids, true );
				}
			)
		);

		update_user_meta( $user_id, self::USER_META_KEY, wp_slash( $conversations ) );

		return rest_ensure_response(
			array(
				'status'        => 'ok',
				'conversations' => $conversations,
			)
		);
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
