<?php
/**
 * Manages the auto-created WooCommerce REST API key used by Hey Woo's
 * Claude Desktop setup flow.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * Find-or-create / rotate / revoke a single Hey-Woo-owned WooCommerce
 * REST API key. The cleartext credential (ck_*:cs_*) is persisted in a
 * non-autoloaded WP option because it has to be embedded into every
 * generated .mcpb bundle and surfaced on the setup page; WC only stores
 * the consumer key as a hash, so we can't reconstruct the cleartext
 * after creation.
 */
class RestApiKey {

	/**
	 * Option storing the joined `ck_xxx:cs_xxx` credential.
	 */
	const OPTION_CREDENTIAL = 'hey_woo_setup_api_credential';

	/**
	 * Option storing the woocommerce_api_keys.key_id row tied to the
	 * credential above. Kept separately so revocation can target the row
	 * directly without parsing the credential.
	 */
	const OPTION_KEY_ID = 'hey_woo_setup_api_key_id';

	/**
	 * Description written into woocommerce_api_keys.description so the
	 * key is recognisable in WC admin. Also used as the orphan-cleanup
	 * key — every row with this exact description is treated as
	 * Hey-Woo-owned and revoked together.
	 */
	const KEY_DESCRIPTION = 'Hey Woo MCP — Claude Desktop';

	/**
	 * Transient name used as a short-lived mutex around create() so
	 * two concurrent requests can't each insert a key row and have
	 * one of them lost in the option-write race.
	 */
	const PROVISIONING_LOCK = 'hey_woo_setup_provisioning';

	/**
	 * Maximum mutex hold time, in seconds. Long enough to cover one
	 * slow DB insert + option write; short enough that a crashed
	 * request can't deadlock future provisioning indefinitely.
	 */
	const PROVISIONING_LOCK_TTL = 30;

	/**
	 * Return the stored credential (creating one if none exists).
	 *
	 * @param string $permissions WC permissions value: 'read' | 'write' | 'read_write'.
	 * @return array{credential:string,key_id:int,permissions:string}|\WP_Error
	 */
	public function get_or_create( $permissions = 'read' ) {
		$credential = get_option( self::OPTION_CREDENTIAL, '' );
		$key_id     = (int) get_option( self::OPTION_KEY_ID, 0 );

		if ( '' !== $credential && $key_id > 0 && $this->key_exists( $key_id ) ) {
			return array(
				'credential'  => $credential,
				'key_id'      => $key_id,
				'permissions' => $this->get_stored_permissions( $key_id ),
			);
		}

		// Stale state: option set but row gone. Clear and recreate.
		if ( '' !== $credential || $key_id > 0 ) {
			$this->clear_options();
		}

		return $this->create( $permissions );
	}

	/**
	 * Revoke the existing key (if any) and issue a fresh one. Returns
	 * the same shape as get_or_create().
	 *
	 * @param string $permissions WC permissions value.
	 * @return array{credential:string,key_id:int,permissions:string}|\WP_Error
	 */
	public function regenerate( $permissions = 'read' ) {
		$this->revoke();
		return $this->create( $permissions );
	}

	/**
	 * Update the permissions on the existing key without rotating it.
	 * Used by the Read ↔ Read/Write toggle on the setup page.
	 *
	 * @param string $permissions WC permissions value.
	 * @return bool True if the row was updated, false if there's no key yet.
	 */
	public function set_permissions( $permissions ) {
		$key_id = (int) get_option( self::OPTION_KEY_ID, 0 );
		if ( $key_id <= 0 ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WC has no public helper for updating an API key row.
		$updated = $wpdb->update(
			$wpdb->prefix . 'woocommerce_api_keys',
			array( 'permissions' => $this->normalise_permissions( $permissions ) ),
			array( 'key_id' => $key_id ),
			array( '%s' ),
			array( '%d' )
		);
		return false !== $updated;
	}

	/**
	 * Revoke every Hey-Woo-owned key row and clear the stored credential.
	 *
	 * Deletes by description rather than by tracked key_id, so any
	 * orphans from a lost-race insert (or from a user manually
	 * clearing the option but not the row, or vice versa) are caught
	 * here too. Safe to call when no key exists.
	 */
	public function revoke() {
		$this->delete_owned_rows();
		$this->clear_options();
	}

	/**
	 * Whether a credential is currently provisioned.
	 *
	 * @return bool
	 */
	public function exists() {
		$key_id = (int) get_option( self::OPTION_KEY_ID, 0 );
		return $key_id > 0 && $this->key_exists( $key_id );
	}

	/**
	 * Insert a fresh row in woocommerce_api_keys and store the cleartext
	 * credential locally.
	 *
	 * Serialised by a transient mutex so two concurrent first-time
	 * requests can't each succeed and leave one of the rows orphaned
	 * (the option-write loser would point at one row while the
	 * other's row remained active and unrevoke-able). On entry: wait
	 * briefly for any in-flight provisioning to finish, then re-read
	 * the option state — if another request beat us to it, return
	 * their result rather than inserting a duplicate.
	 *
	 * @param string $permissions WC permissions value.
	 * @return array{credential:string,key_id:int,permissions:string}|\WP_Error
	 */
	private function create( $permissions ) {
		if ( ! function_exists( 'wc_rand_hash' ) || ! function_exists( 'wc_api_hash' ) ) {
			return new \WP_Error(
				'hey_woo_wc_helpers_missing',
				__( 'WooCommerce API helpers are unavailable. Make sure WooCommerce is active.', 'hey-woo' )
			);
		}

		$this->wait_for_provisioning_lock();
		set_transient( self::PROVISIONING_LOCK, 1, self::PROVISIONING_LOCK_TTL );

		try {
			// Re-read state inside the mutex — another request may
			// have finished provisioning while we were waiting.
			$existing_credential = get_option( self::OPTION_CREDENTIAL, '' );
			$existing_key_id     = (int) get_option( self::OPTION_KEY_ID, 0 );
			if ( '' !== $existing_credential && $existing_key_id > 0 && $this->key_exists( $existing_key_id ) ) {
				return array(
					'credential'  => $existing_credential,
					'key_id'      => $existing_key_id,
					'permissions' => $this->get_stored_permissions( $existing_key_id ),
				);
			}

			// Defensive: clear any orphans from previous failed
			// attempts before inserting a fresh row, so revoke() /
			// uninstall continue to fully clean up after this run.
			$this->delete_owned_rows();
			$this->clear_options();

			$permissions     = $this->normalise_permissions( $permissions );
			$consumer_key    = 'ck_' . wc_rand_hash();
			$consumer_secret = 'cs_' . wc_rand_hash();
			$user_id         = get_current_user_id();

			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- WC has no public helper for inserting an API key row.
			$inserted = $wpdb->insert(
				$wpdb->prefix . 'woocommerce_api_keys',
				array(
					'user_id'         => $user_id,
					'description'     => self::KEY_DESCRIPTION,
					'permissions'     => $permissions,
					'consumer_key'    => wc_api_hash( $consumer_key ),
					'consumer_secret' => $consumer_secret,
					'truncated_key'   => substr( $consumer_key, -7 ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);

			if ( false === $inserted ) {
				return new \WP_Error(
					'hey_woo_key_insert_failed',
					__( 'Could not create the WooCommerce REST API key.', 'hey-woo' )
				);
			}

			$key_id     = (int) $wpdb->insert_id;
			$credential = $consumer_key . ':' . $consumer_secret;

			// Use add_option with autoload=no so the credential
			// never joins the per-request alloptions cache.
			$cred_set = add_option( self::OPTION_CREDENTIAL, $credential, '', 'no' );
			$key_set  = add_option( self::OPTION_KEY_ID, $key_id, '', 'no' );

			if ( ! $cred_set || ! $key_set ) {
				// Couldn't persist locally — clean up the row we
				// just inserted so we don't leave an active credential
				// nothing tracks.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compensating delete on persist failure.
				$wpdb->delete(
					$wpdb->prefix . 'woocommerce_api_keys',
					array( 'key_id' => $key_id ),
					array( '%d' )
				);
				$this->clear_options();
				return new \WP_Error(
					'hey_woo_key_persist_failed',
					__( 'Could not store the API key locally.', 'hey-woo' )
				);
			}

			return array(
				'credential'  => $credential,
				'key_id'      => $key_id,
				'permissions' => $permissions,
			);
		} finally {
			delete_transient( self::PROVISIONING_LOCK );
		}
	}

	/**
	 * Busy-wait briefly while another request holds the provisioning
	 * mutex. Caps at PROVISIONING_LOCK_TTL × 10 iterations so a stuck
	 * lock never wedges this request indefinitely.
	 */
	private function wait_for_provisioning_lock() {
		// 100ms × 30 ≈ 3 seconds. The lock TTL is the real ceiling —
		// transients self-expire — so this is just to avoid spinning
		// past the natural timeout.
		$max_iterations = self::PROVISIONING_LOCK_TTL * 10;
		$i              = 0;
		while ( get_transient( self::PROVISIONING_LOCK ) && $i < $max_iterations ) {
			usleep( 100000 );
			++$i;
		}
	}

	/**
	 * Delete every woocommerce_api_keys row whose description matches
	 * Hey Woo's exact label. Catches orphans from concurrent inserts
	 * and cleans up after manual table edits in WC admin.
	 */
	private function delete_owned_rows() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- description-scoped revocation; no caching surface.
		$wpdb->delete(
			$wpdb->prefix . 'woocommerce_api_keys',
			array( 'description' => self::KEY_DESCRIPTION ),
			array( '%s' )
		);
	}

	/**
	 * Look up the permissions value stored on a given key row.
	 *
	 * @param int $key_id woocommerce_api_keys.key_id.
	 * @return string
	 */
	private function get_stored_permissions( $key_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- key_id cast to int; no caching surface for ad-hoc admin lookup.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT permissions FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d",
				$key_id
			)
		);
		return is_string( $value ) ? $value : 'read';
	}

	/**
	 * Whether a row with the given key_id still exists.
	 *
	 * @param int $key_id woocommerce_api_keys.key_id.
	 * @return bool
	 */
	private function key_exists( $key_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- key_id cast to int; no caching surface for ad-hoc admin lookup.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d",
				$key_id
			)
		);
		return null !== $found;
	}

	/**
	 * Coerce arbitrary input to one of WC's accepted permission values.
	 *
	 * @param string $permissions Caller-supplied value.
	 * @return string One of 'read' | 'write' | 'read_write'.
	 */
	private function normalise_permissions( $permissions ) {
		$allowed = array( 'read', 'write', 'read_write' );
		return in_array( $permissions, $allowed, true ) ? $permissions : 'read';
	}

	/**
	 * Remove both options, regardless of whether they exist.
	 */
	private function clear_options() {
		delete_option( self::OPTION_CREDENTIAL );
		delete_option( self::OPTION_KEY_ID );
	}
}
