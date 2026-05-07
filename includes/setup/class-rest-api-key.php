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
	 * Option name used as the provisioning mutex around create().
	 *
	 * `add_option()` is the WP-canonical atomic test-and-set: it
	 * relies on the unique constraint on `wp_options.option_name` and
	 * returns false if the option already exists. Two concurrent
	 * `add_option()` calls — one wins, one fails — so the lock is
	 * acquired (or denied) atomically without a check-then-set race.
	 */
	const PROVISIONING_LOCK = 'hey_woo_setup_provisioning_lock';

	/**
	 * Maximum lock hold time, in seconds. The lock value carries an
	 * `expires` timestamp; a request that finds an expired lock from
	 * a crashed predecessor will delete and re-acquire it. Long
	 * enough to cover a slow DB insert + option writes, short enough
	 * that a crash can't deadlock future provisioning indefinitely.
	 */
	const PROVISIONING_LOCK_TTL = 30;

	/**
	 * Maximum total time a single create() call will wait for an
	 * existing provisioning lock to clear, in seconds. Caps how long
	 * a queued admin double-click sits before timing out.
	 */
	const PROVISIONING_WAIT_LIMIT = 5;

	/**
	 * Return the stored credential (creating one if none exists).
	 *
	 * The `owner_user_id` field is the WP user the underlying WC API
	 * key is bound to (i.e., the user the key authenticates *as*).
	 * It's surfaced so the setup page can gate the credential UI on
	 * `current_user_id() === owner_user_id` — preventing a different
	 * admin from extracting another admin's bound credential.
	 *
	 * Hey Woo always provisions read-only keys. If the merchant later
	 * needs broader scope, they edit the key directly under
	 * WooCommerce → Settings → Advanced → REST API — which updates
	 * the same row in place (same credential, new permissions), so
	 * already-distributed bundles keep working with the new scope.
	 *
	 * @return array{credential:string,key_id:int,permissions:string,owner_user_id:int}|\WP_Error
	 */
	public function get_or_create() {
		$credential = get_option( self::OPTION_CREDENTIAL, '' );
		$key_id     = (int) get_option( self::OPTION_KEY_ID, 0 );

		if ( '' !== $credential && $key_id > 0 && $this->key_exists( $key_id ) ) {
			return array(
				'credential'    => $credential,
				'key_id'        => $key_id,
				'permissions'   => $this->get_stored_permissions( $key_id ),
				'owner_user_id' => $this->get_owner_user_id( $key_id ),
			);
		}

		// Stale state: option set but row gone. Clear and recreate.
		if ( '' !== $credential || $key_id > 0 ) {
			$this->clear_options();
		}

		return $this->create();
	}

	/**
	 * Revoke the existing key (if any) and issue a fresh read-only one.
	 * Returns the same shape as get_or_create().
	 *
	 * @return array{credential:string,key_id:int,permissions:string}|\WP_Error
	 */
	public function regenerate() {
		$this->revoke();
		return $this->create();
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
	 * Read-only snapshot of the current key state.
	 *
	 * Returns the same shape as get_or_create() if a Hey-Woo-owned
	 * key exists, or null otherwise. The credential authenticates
	 * against the standard WC REST API surface generally (not just
	 * /wp-json/hey-woo/mcp), so the page must be able to surface
	 * and revoke an existing key whether the merchant is mid-setup
	 * or arriving on the page after the fact — only
	 * revoke()/Disconnect/uninstall removes it.
	 *
	 * Side-effect-free: never provisions, never clears options.
	 * Caller (get_or_create or render_setup_view) decides whether to
	 * recover from any stale option state.
	 *
	 * @return array{credential:string,key_id:int,permissions:string,owner_user_id:int}|null
	 */
	public function existing_state() {
		$credential = get_option( self::OPTION_CREDENTIAL, '' );
		$key_id     = (int) get_option( self::OPTION_KEY_ID, 0 );

		if ( '' === $credential || $key_id <= 0 || ! $this->key_exists( $key_id ) ) {
			return null;
		}

		return array(
			'credential'    => $credential,
			'key_id'        => $key_id,
			'permissions'   => $this->get_stored_permissions( $key_id ),
			'owner_user_id' => $this->get_owner_user_id( $key_id ),
		);
	}

	/**
	 * Read-only lookup of the WP user_id the current key is bound to.
	 * Returns 0 when no key is provisioned. Side-effect-free, so safe
	 * to call from ownership-check paths that must not provision.
	 *
	 * @return int
	 */
	public function owner_user_id() {
		$key_id = (int) get_option( self::OPTION_KEY_ID, 0 );
		if ( $key_id <= 0 || ! $this->key_exists( $key_id ) ) {
			return 0;
		}
		return $this->get_owner_user_id( $key_id );
	}

	/**
	 * Insert a fresh read-only row in woocommerce_api_keys and store
	 * the cleartext credential locally.
	 *
	 * Serialised by a transient mutex so two concurrent first-time
	 * requests can't each succeed and leave one of the rows orphaned
	 * (the option-write loser would point at one row while the
	 * other's row remained active and unrevoke-able). On entry: wait
	 * briefly for any in-flight provisioning to finish, then re-read
	 * the option state — if another request beat us to it, return
	 * their result rather than inserting a duplicate.
	 *
	 * @return array{credential:string,key_id:int,permissions:string}|\WP_Error
	 */
	private function create() {
		if ( ! function_exists( 'wc_rand_hash' ) || ! function_exists( 'wc_api_hash' ) ) {
			return new \WP_Error(
				'hey_woo_wc_helpers_missing',
				__( 'WooCommerce API helpers are unavailable. Make sure WooCommerce is active.', 'hey-woo' )
			);
		}

		$lock_token = $this->acquire_provisioning_lock();
		if ( null === $lock_token ) {
			return new \WP_Error(
				'hey_woo_provisioning_busy',
				__( 'Another setup request is in progress. Please try again in a moment.', 'hey-woo' )
			);
		}

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
					'permissions'     => 'read',
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
				/*
				 * Compensating cleanup with two narrow guards:
				 *
				 * 1. Always delete the row we just inserted, scoped by
				 *    its key_id. This row is uniquely ours.
				 *
				 * 2. For each option write that succeeded, compare-and-
				 *    delete only when the stored value still matches
				 *    what we wrote. Avoids the failure mode flagged by
				 *    review: a slow predecessor whose lock expired
				 *    (TTL elapsed → reclaimed by a successor → successor
				 *    persisted its own options) coming back here and
				 *    clobbering the successor's options via an
				 *    unconditional clear_options(). With compare-and-
				 *    delete, the successor's options stay untouched
				 *    because the value column won't match.
				 */
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compensating delete on persist failure.
				$wpdb->delete(
					$wpdb->prefix . 'woocommerce_api_keys',
					array( 'key_id' => $key_id ),
					array( '%d' )
				);
				if ( $cred_set ) {
					$this->compare_and_delete_option( self::OPTION_CREDENTIAL, $credential );
				}
				if ( $key_set ) {
					$this->compare_and_delete_option( self::OPTION_KEY_ID, $key_id );
				}
				return new \WP_Error(
					'hey_woo_key_persist_failed',
					__( 'Could not store the API key locally.', 'hey-woo' )
				);
			}

			return array(
				'credential'    => $credential,
				'key_id'        => $key_id,
				'permissions'   => 'read',
				'owner_user_id' => (int) $user_id,
			);
		} finally {
			$this->release_provisioning_lock( $lock_token );
		}
	}

	/**
	 * Atomically acquire the provisioning lock. Returns a token to
	 * pass back to release_provisioning_lock(), or null if the lock
	 * couldn't be acquired within PROVISIONING_WAIT_LIMIT seconds.
	 *
	 * Uses `add_option()` which is atomic at the database level
	 * (unique constraint on option_name): two concurrent calls — one
	 * succeeds, one returns false. The return value of add_option()
	 * is the only race-safe signal.
	 *
	 * Stale locks (whose `expires` is in the past) are deleted and
	 * the loop re-tries on the next iteration. The token guards
	 * against deleting a fresh lock that was acquired after our
	 * stale-cleanup deletion.
	 *
	 * @return string|null Token, or null on timeout.
	 */
	private function acquire_provisioning_lock() {
		$token    = wp_generate_password( 24, false );
		$deadline = time() + self::PROVISIONING_WAIT_LIMIT;

		while ( time() < $deadline ) {
			$value = array(
				'token'   => $token,
				'expires' => time() + self::PROVISIONING_LOCK_TTL,
			);
			if ( add_option( self::PROVISIONING_LOCK, $value, '', 'no' ) ) {
				return $token;
			}

			// Existing lock — check if it's stale and reclaim it.
			//
			// CRUCIAL: the reclamation must be a compare-and-delete,
			// not an unconditional delete_option(). Two contenders
			// reading the same stale value would each call delete and
			// each acquire — the second's delete would clobber the
			// first's freshly-acquired lock. See reclaim_stale_lock()
			// for the conditional DELETE that closes that TOCTOU.
			$existing = get_option( self::PROVISIONING_LOCK );
			if ( is_array( $existing ) && (int) ( $existing['expires'] ?? 0 ) < time() ) {
				$this->reclaim_stale_lock( $existing );
				// Fall through to the next iteration's add_option().
			}

			usleep( 100000 ); // 100ms.
		}
		return null;
	}

	/**
	 * Release the provisioning lock. Only deletes the option if its
	 * stored token matches ours, so a slow request that times out
	 * (and whose lock another request reclaimed) can't accidentally
	 * release the new owner's lock.
	 *
	 * @param string $token The token returned by acquire_provisioning_lock().
	 */
	private function release_provisioning_lock( $token ) {
		$existing = get_option( self::PROVISIONING_LOCK );
		if ( is_array( $existing ) && ( $existing['token'] ?? '' ) === $token ) {
			delete_option( self::PROVISIONING_LOCK );
		}
	}

	/**
	 * Compare-and-delete an option: delete the row only when its
	 * `option_value` column still serializes to the value the caller
	 * just wrote. Used by the cleanup path in create() to avoid
	 * clobbering a successful concurrent provision's options when
	 * our own writes failed for any reason.
	 *
	 * @param string $option_name The wp_options.option_name to target.
	 * @param mixed  $expected    The value we believe we wrote.
	 * @return int Number of rows deleted (0 = value didn't match; 1 = removed).
	 */
	private function compare_and_delete_option( $option_name, $expected ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-delete on the wp_options unique key.
		$rows = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => maybe_serialize( $expected ),
			),
			array( '%s', '%s' )
		);
		if ( $rows > 0 ) {
			wp_cache_delete( $option_name, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return (int) $rows;
	}

	/**
	 * Compare-and-delete the provisioning lock row.
	 *
	 * Atomic stale-lock reclamation: deletes the lock row only if its
	 * `option_value` column still matches `maybe_serialize( $observed_value )`
	 * — the exact serialized value we read a moment ago. If another
	 * contender raced ahead and replaced the lock between our
	 * `get_option()` and this call, the value column won't match and
	 * the DELETE affects 0 rows; we leave their fresh lock alone.
	 *
	 * Without this guarantee, two contenders observing the same stale
	 * lock would each call `delete_option()` unconditionally, and one
	 * could clobber the other's freshly-acquired lock — re-introducing
	 * the orphan-credential race the lock is meant to prevent.
	 *
	 * @param array $observed_value The lock value as just read via get_option().
	 * @return int Number of rows deleted (0 = lost the race; 1 = reclaimed).
	 */
	private function reclaim_stale_lock( $observed_value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- compare-and-delete on the wp_options unique key; no caching surface for this lock row.
		$rows = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::PROVISIONING_LOCK,
				'option_value' => maybe_serialize( $observed_value ),
			),
			array( '%s', '%s' )
		);
		if ( $rows > 0 ) {
			// Mirror what delete_option() does so the next loop's
			// add_option()/get_option() sees the row's actually gone.
			wp_cache_delete( self::PROVISIONING_LOCK, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		return (int) $rows;
	}

	/**
	 * Delete every Hey-Woo-owned woocommerce_api_keys row.
	 *
	 * Two passes so an admin who renamed our key's description column
	 * directly in WC's REST API admin can't strand it:
	 *
	 *  1. Delete by tracked key_id (OPTION_KEY_ID). Authoritative when
	 *     the option pointer is intact — catches rows whose description
	 *     was edited in WC admin after we minted them.
	 *
	 *  2. Delete by canonical KEY_DESCRIPTION. Safety net for orphans
	 *     from concurrent inserts where the option pointer was never
	 *     persisted, or where it was cleared without the row being
	 *     removed. Always-canonical insert in create() keeps this
	 *     match reliable.
	 */
	private function delete_owned_rows() {
		global $wpdb;

		$tracked_key_id = (int) get_option( self::OPTION_KEY_ID, 0 );
		if ( $tracked_key_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- key_id-scoped revocation; no caching surface.
			$wpdb->delete(
				$wpdb->prefix . 'woocommerce_api_keys',
				array( 'key_id' => $tracked_key_id ),
				array( '%d' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- description-scoped orphan cleanup; no caching surface.
		$wpdb->delete(
			$wpdb->prefix . 'woocommerce_api_keys',
			array( 'description' => self::KEY_DESCRIPTION ),
			array( '%s' )
		);
	}

	/**
	 * Look up the WP user_id the WC API key is bound to. Returns 0
	 * if the row doesn't exist (the caller should treat that as
	 * "no key").
	 *
	 * @param int $key_id woocommerce_api_keys.key_id.
	 * @return int
	 */
	private function get_owner_user_id( $key_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- key_id cast to int; no caching surface for ad-hoc admin lookup.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d",
				$key_id
			)
		);
		return null === $value ? 0 : (int) $value;
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
	 * Remove both options, regardless of whether they exist.
	 */
	private function clear_options() {
		delete_option( self::OPTION_CREDENTIAL );
		delete_option( self::OPTION_KEY_ID );
	}
}
