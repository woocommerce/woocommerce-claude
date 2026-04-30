<?php
/**
 * Integration test — RestApiKey provisioning & revocation invariants.
 *
 * Pins the security-sensitive contract:
 *
 *   "After revoke()/uninstall, every Hey-Woo-owned row in
 *    woocommerce_api_keys is gone — even ones the local options
 *    didn't track."
 *
 * That invariant is what protects merchants from a lost-race orphan:
 * if two concurrent first-time provisions both insert a key but only
 * one wins the option write, the loser's row would otherwise stay
 * authenticating forever. Description-scoped revocation cleans up
 * after both. A regression here is a credential leak — an orphaned
 * `ck_…:cs_…` row that no UI surface knows about and no Regenerate
 * click revokes.
 *
 * @package HeyWoo\Tests
 */

use HeyWoo\Setup\RestApiKey;

/**
 * Integration tests for HeyWoo\Setup\RestApiKey.
 */
class Test_Rest_Api_Key extends WP_UnitTestCase {

	/**
	 * Reset the underlying state before each test.
	 */
	public function set_up() {
		parent::set_up();
		$this->wipe_owned_rows();
		delete_option( RestApiKey::OPTION_CREDENTIAL );
		delete_option( RestApiKey::OPTION_KEY_ID );
		delete_transient( RestApiKey::PROVISIONING_LOCK );
	}

	/**
	 * Same teardown — explicit even though WP_UnitTestCase rolls back.
	 */
	public function tear_down() {
		$this->wipe_owned_rows();
		delete_option( RestApiKey::OPTION_CREDENTIAL );
		delete_option( RestApiKey::OPTION_KEY_ID );
		delete_transient( RestApiKey::PROVISIONING_LOCK );
		parent::tear_down();
	}

	/**
	 * Happy path — first call provisions one row and persists the
	 * credential and key_id; the row is discoverable in the WC table.
	 */
	public function test_get_or_create_provisions_a_single_key() {
		$helper = new RestApiKey();
		$state  = $helper->get_or_create( 'read' );

		$this->assertIsArray( $state );
		$this->assertSame( 1, $this->count_owned_rows(), 'Exactly one Hey-Woo-owned row should exist.' );
		$this->assertNotEmpty( $state['credential'] );
		$this->assertSame( 1, preg_match( '/^ck_[a-f0-9]+:cs_[a-f0-9]+$/', $state['credential'] ), 'Credential follows ck_…:cs_… shape.' );
		$this->assertSame( 'read', $state['permissions'] );
		$this->assertSame( $state['key_id'], (int) get_option( RestApiKey::OPTION_KEY_ID ) );
		$this->assertSame( $state['credential'], get_option( RestApiKey::OPTION_CREDENTIAL ) );
	}

	/**
	 * Second call returns the same credential — idempotent. No new
	 * row is inserted.
	 */
	public function test_get_or_create_is_idempotent() {
		$helper = new RestApiKey();
		$first  = $helper->get_or_create( 'read' );
		$second = $helper->get_or_create( 'read' );

		$this->assertSame( $first['credential'], $second['credential'] );
		$this->assertSame( $first['key_id'], $second['key_id'] );
		$this->assertSame( 1, $this->count_owned_rows() );
	}

	/**
	 * Revoke removes the tracked row AND clears local options.
	 */
	public function test_revoke_removes_the_tracked_row() {
		$helper = new RestApiKey();
		$helper->get_or_create( 'read' );
		$this->assertSame( 1, $this->count_owned_rows() );

		$helper->revoke();

		$this->assertSame( 0, $this->count_owned_rows(), 'Row removed.' );
		$this->assertFalse( get_option( RestApiKey::OPTION_CREDENTIAL ) );
		$this->assertFalse( get_option( RestApiKey::OPTION_KEY_ID ) );
	}

	/**
	 * THE invariant: revoke() must remove every row matching the
	 * Hey-Woo description, including ones the local options never
	 * tracked (the lost-race orphan case).
	 *
	 * Setup:
	 *  - Provision a key normally → row A, options point at A.
	 *  - Manually insert row B with the same description, *not*
	 *    referenced by any local option. This is the shape of an
	 *    orphan from a concurrent first-time provision.
	 *
	 * Expectation:
	 *  - revoke() leaves zero matching rows. The orphan B is gone too.
	 */
	public function test_revoke_cleans_up_orphan_rows_from_lost_race() {
		$helper = new RestApiKey();
		$helper->get_or_create( 'read' );
		$this->assertSame( 1, $this->count_owned_rows() );

		$orphan_id = $this->insert_orphan_row();
		$this->assertGreaterThan( 0, $orphan_id );
		$this->assertSame( 2, $this->count_owned_rows(), 'Two rows present (tracked + orphan).' );
		$this->assertNotSame( $orphan_id, (int) get_option( RestApiKey::OPTION_KEY_ID ), 'Orphan is not the tracked key.' );

		$helper->revoke();

		$this->assertSame( 0, $this->count_owned_rows(), 'Orphan was deleted along with the tracked row.' );
		$this->assertFalse( get_option( RestApiKey::OPTION_KEY_ID ) );
	}

	/**
	 * Regenerate also clears orphans before issuing a new key, so the
	 * post-regenerate state contains exactly one row.
	 */
	public function test_regenerate_clears_orphans_and_issues_one_fresh_row() {
		$helper = new RestApiKey();
		$helper->get_or_create( 'read' );
		$this->insert_orphan_row();
		$this->insert_orphan_row();
		$this->assertSame( 3, $this->count_owned_rows() );

		$state = $helper->regenerate( 'read_write' );

		$this->assertIsArray( $state );
		$this->assertSame( 1, $this->count_owned_rows(), 'Exactly one row after regenerate.' );
		$this->assertSame( 'read_write', $state['permissions'] );
		$this->assertSame( $state['credential'], get_option( RestApiKey::OPTION_CREDENTIAL ) );
		$this->assertSame( $state['key_id'], (int) get_option( RestApiKey::OPTION_KEY_ID ) );
	}

	/**
	 * Count rows in WC's API key table whose description matches the
	 * Hey Woo label. Used by every assertion above.
	 *
	 * @return int
	 */
	private function count_owned_rows() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.SlowDBQuery -- test fixture; no caching surface.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_api_keys WHERE description = %s",
				RestApiKey::KEY_DESCRIPTION
			)
		);
	}

	/**
	 * Wipe every Hey-Woo-owned row. set_up / tear_down hygiene.
	 */
	private function wipe_owned_rows() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture; no caching surface.
		$wpdb->delete(
			$wpdb->prefix . 'woocommerce_api_keys',
			array( 'description' => RestApiKey::KEY_DESCRIPTION ),
			array( '%s' )
		);
	}

	/**
	 * Insert a Hey-Woo-described row directly via $wpdb, bypassing
	 * the helper's mutex and option-write. Mimics the orphan that a
	 * lost-race concurrent provision would leave behind.
	 *
	 * @return int Inserted row's key_id.
	 */
	private function insert_orphan_row() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test fixture; no caching surface.
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => 1,
				'description'     => RestApiKey::KEY_DESCRIPTION,
				'permissions'     => 'read',
				'consumer_key'    => wc_api_hash( 'ck_orphan_' . wp_generate_password( 16, false ) ),
				'consumer_secret' => 'cs_orphan_' . wp_generate_password( 16, false ),
				'truncated_key'   => 'orphanX',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}
}
