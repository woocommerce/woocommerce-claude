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
	 * key is recognisable in WC admin.
	 */
	const KEY_DESCRIPTION = 'Hey Woo MCP — Claude Desktop';

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
	 * Delete the row in woocommerce_api_keys and clear the stored
	 * credential. Safe to call when no key exists.
	 */
	public function revoke() {
		$key_id = (int) get_option( self::OPTION_KEY_ID, 0 );
		if ( $key_id > 0 ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- targeted single-row revocation; no caching surface.
			$wpdb->delete(
				$wpdb->prefix . 'woocommerce_api_keys',
				array( 'key_id' => $key_id ),
				array( '%d' )
			);
		}
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
	 * credential locally. Returns a WP_Error if WC's hash helpers are
	 * missing (extremely unlikely on any supported WC version).
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

		// Use add_option with autoload=no so the credential never
		// joins the per-request alloptions cache.
		$this->clear_options();
		add_option( self::OPTION_CREDENTIAL, $credential, '', 'no' );
		add_option( self::OPTION_KEY_ID, $key_id, '', 'no' );

		return array(
			'credential'  => $credential,
			'key_id'      => $key_id,
			'permissions' => $permissions,
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
