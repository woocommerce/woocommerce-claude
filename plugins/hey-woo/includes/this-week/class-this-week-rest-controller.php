<?php
/**
 * REST controller for the "This Week" home surface.
 *
 * Routes:
 *   GET  /hey-woo/v1/this-week/signals          — List persisted signals.
 *   POST /hey-woo/v1/this-week/run              — Run the runner now (synchronous in PR 1).
 *   POST /hey-woo/v1/this-week/signals/dismiss  — Dismiss a signal by slug.
 *   POST /hey-woo/v1/this-week/signals/snooze   — Snooze a signal until a Unix timestamp.
 *
 * @package WooCommerce\HeyWoo\ThisWeek
 */

namespace WooCommerce\HeyWoo\ThisWeek;

defined( 'ABSPATH' ) || exit;

/**
 * Register and handle This Week REST routes.
 */
class ThisWeekRestController {

	/**
	 * REST namespace, shared with the rest of Hey Woo.
	 */
	const NAMESPACE = 'hey-woo/v1';

	/**
	 * Default snooze window when the caller does not pass an explicit timestamp.
	 */
	const DEFAULT_SNOOZE_SECONDS = DAY_IN_SECONDS * 3;

	/**
	 * Register the rest_api_init hook.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/this-week/signals',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_signals' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/this-week/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'skip_ai' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/this-week/signals/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_signal' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/this-week/signals/snooze',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'snooze_signal' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'slug'          => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'snoozed_until' => array(
						'type'     => 'integer',
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * GET handler — return all unresolved signals plus metadata.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_signals() {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'signals' => SignalStore::unresolved(),
			)
		);
	}

	/**
	 * POST handler — trigger a synchronous runner pass.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function run( $request ) {
		$skip_ai = (bool) $request->get_param( 'skip_ai' );
		$result  = ( new SignalRunner() )->run( array( 'skip_ai' => $skip_ai ) );

		return rest_ensure_response(
			array(
				'status'   => 'ok',
				'signals'  => SignalStore::unresolved(),
				'detected' => $result['detected'],
				'skipped'  => $result['skipped'],
				'errors'   => $result['errors'],
			)
		);
	}

	/**
	 * POST handler — dismiss a signal by slug.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function dismiss_signal( $request ) {
		$slug = (string) $request->get_param( 'slug' );

		if ( '' === $slug ) {
			return new \WP_Error(
				'hey_woo_invalid_signal_slug',
				__( 'A signal slug is required.', 'hey-woo' ),
				array( 'status' => 400 )
			);
		}

		$dismissed = SignalStore::dismiss( $slug );

		if ( ! $dismissed ) {
			return new \WP_Error(
				'hey_woo_signal_not_found',
				__( 'That signal could not be dismissed.', 'hey-woo' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'signals' => SignalStore::unresolved(),
			)
		);
	}

	/**
	 * POST handler — snooze a signal until a Unix timestamp.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function snooze_signal( $request ) {
		$slug          = (string) $request->get_param( 'slug' );
		$snoozed_until = (int) $request->get_param( 'snoozed_until' );

		if ( '' === $slug ) {
			return new \WP_Error(
				'hey_woo_invalid_signal_slug',
				__( 'A signal slug is required.', 'hey-woo' ),
				array( 'status' => 400 )
			);
		}

		if ( $snoozed_until <= time() ) {
			$snoozed_until = time() + self::DEFAULT_SNOOZE_SECONDS;
		}

		$snoozed = SignalStore::snooze( $slug, $snoozed_until );

		if ( ! $snoozed ) {
			return new \WP_Error(
				'hey_woo_signal_not_found',
				__( 'That signal could not be snoozed.', 'hey-woo' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'status'        => 'ok',
				'signals'       => SignalStore::unresolved(),
				'snoozed_until' => $snoozed_until,
			)
		);
	}

	/**
	 * Permission check — require manage_woocommerce.
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
}
