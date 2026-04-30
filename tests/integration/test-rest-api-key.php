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
use HeyWoo\Setup\SetupPage;

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
		delete_option( RestApiKey::PROVISIONING_LOCK );
		delete_option( SetupPage::FEATURE_FLAG_OPTION );
	}

	/**
	 * Same teardown — explicit even though WP_UnitTestCase rolls back.
	 */
	public function tear_down() {
		$this->wipe_owned_rows();
		delete_option( RestApiKey::OPTION_CREDENTIAL );
		delete_option( RestApiKey::OPTION_KEY_ID );
		delete_option( RestApiKey::PROVISIONING_LOCK );
		delete_option( SetupPage::FEATURE_FLAG_OPTION );
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
	 * Direct test of the atomic semantic the new lock relies on:
	 * `add_option()` returns false when the option already exists.
	 * Without that guarantee, two concurrent first-run requests could
	 * both pass through `create()` and produce duplicate rows.
	 *
	 * This test pins the WP-core contract so a regression in WP (or
	 * a bad refactor of the lock helper that switches to e.g.
	 * `update_option`) breaks loudly.
	 */
	public function test_provisioning_lock_uses_atomic_add_option() {
		$first  = add_option(
			RestApiKey::PROVISIONING_LOCK,
			array(
				'token'   => 'A',
				'expires' => time() + 30,
			),
			'',
			'no'
		);
		$second = add_option(
			RestApiKey::PROVISIONING_LOCK,
			array(
				'token'   => 'B',
				'expires' => time() + 30,
			),
			'',
			'no'
		);

		$this->assertTrue( $first, 'First add_option() acquires the lock.' );
		$this->assertFalse( $second, 'Second add_option() must fail — option already exists.' );

		$stored = get_option( RestApiKey::PROVISIONING_LOCK );
		$this->assertSame( 'A', $stored['token'], 'Lock retains the first acquirer\'s token.' );
	}

	/**
	 * If a request finds the provisioning lock already held by another
	 * request that has *not* yet finished, get_or_create() must not
	 * insert a second row. Simulate "another request holds the lock"
	 * by pre-acquiring it manually with a long expiry, then call
	 * get_or_create() and assert the call times out with WP_Error
	 * rather than racing past the lock and inserting.
	 */
	public function test_get_or_create_aborts_when_lock_is_held_by_another_request() {
		add_option(
			RestApiKey::PROVISIONING_LOCK,
			array(
				'token'   => 'other-request',
				'expires' => time() + 60,
			),
			'',
			'no'
		);

		$helper = new RestApiKey();
		$result = $helper->get_or_create( 'read' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'hey_woo_provisioning_busy', $result->get_error_code() );
		$this->assertSame( 0, $this->count_owned_rows(), 'No row was inserted while the lock was held.' );
	}

	/**
	 * Stale-lock TOCTOU regression test. The dangerous interleaving:
	 *
	 *   1. Lock row A is stale.
	 *   2. Contenders X and Y both `get_option()` and read A's value.
	 *   3. X reclaims (deletes) A and acquires a fresh lock B with
	 *      its own token.
	 *   4. Y, still acting on its stale read of A, attempts to
	 *      reclaim — this MUST NOT delete B.
	 *
	 * The fix is a compare-and-delete: reclaim_stale_lock() only
	 * deletes the row when option_value still matches the serialized
	 * value the caller observed. Test the contract directly via
	 * Reflection so a refactor that switches back to an unconditional
	 * delete_option breaks loudly.
	 */
	public function test_reclaim_stale_lock_compare_and_delete_protects_fresh_lock() {
		$stale_value = array(
			'token'   => 'crashed-predecessor',
			'expires' => time() - 60,
		);
		add_option( RestApiKey::PROVISIONING_LOCK, $stale_value, '', 'no' );

		$helper  = new RestApiKey();
		$reclaim = new ReflectionMethod( $helper, 'reclaim_stale_lock' );
		$reclaim->setAccessible( true );

		// Contender X reclaims — value matches → 1 row deleted.
		$x_deleted = $reclaim->invoke( $helper, $stale_value );
		$this->assertSame( 1, $x_deleted, 'X successfully reclaimed the stale lock.' );
		$this->assertFalse( get_option( RestApiKey::PROVISIONING_LOCK ), 'Lock row gone after X reclaims.' );

		// Contender X acquires a fresh lock with its own token.
		$x_fresh = array(
			'token'   => 'contender-X',
			'expires' => time() + 30,
		);
		$this->assertTrue(
			add_option( RestApiKey::PROVISIONING_LOCK, $x_fresh, '', 'no' ),
			'X acquires fresh lock after reclamation.'
		);

		// Contender Y, still holding its stale read of the lock,
		// attempts the same reclamation. Compare-and-delete must NOT
		// match X's fresh value — 0 rows deleted, X's lock untouched.
		$y_deleted = $reclaim->invoke( $helper, $stale_value );
		$this->assertSame(
			0,
			$y_deleted,
			'Y did not delete X\'s fresh lock — TOCTOU closed.'
		);

		// X's fresh lock is intact and still owned by X.
		$current = get_option( RestApiKey::PROVISIONING_LOCK );
		$this->assertIsArray( $current );
		$this->assertSame( 'contender-X', $current['token'] );
	}

	/**
	 * A stale lock (one whose expires timestamp is in the past — the
	 * predecessor crashed without releasing) must not deadlock new
	 * provisioning requests forever. The acquire helper detects the
	 * stale lock, deletes it, and the next iteration acquires.
	 */
	public function test_stale_lock_is_reclaimed() {
		add_option(
			RestApiKey::PROVISIONING_LOCK,
			array(
				'token'   => 'crashed-request',
				'expires' => time() - 60,
			),
			'',
			'no'
		);

		$helper = new RestApiKey();
		$state  = $helper->get_or_create( 'read' );

		$this->assertIsArray( $state );
		$this->assertSame( 1, $this->count_owned_rows() );
		// Lock should have been released after our successful provision.
		$this->assertFalse( get_option( RestApiKey::PROVISIONING_LOCK ) );
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
	 * The security-critical scope-change contract:
	 *
	 *   Read → Read+Write must rotate the credential.
	 *
	 * Otherwise an already-distributed Read-only `.mcpb` (or pasted
	 * snippet) silently gains write access when the merchant flips
	 * the toggle, which is the explicit attack model — a contractor
	 * who was given a read-only bundle should not be granted
	 * product/order mutation by a UI click on the merchant's side.
	 */
	public function test_scope_escalation_rotates_credential() {
		$helper     = new RestApiKey();
		$initial    = $helper->get_or_create( 'read' );
		$old_creds  = $initial['credential'];
		$old_key_id = $initial['key_id'];

		$result = $helper->set_permissions( 'read_write' );

		$this->assertSame( RestApiKey::SCOPE_ROTATED, $result, 'Escalation reports SCOPE_ROTATED.' );

		$post = $helper->get_or_create( 'read' ); // returns current state, not 'read'.
		$this->assertNotSame( $old_creds, $post['credential'], 'Credential rotated on escalation.' );
		$this->assertNotSame( $old_key_id, $post['key_id'], 'Key row replaced on escalation.' );
		$this->assertSame( 'read_write', $post['permissions'] );
		$this->assertSame( 1, $this->count_owned_rows(), 'Exactly one row after rotation.' );
	}

	/**
	 * Read+Write → Read should NOT rotate — narrowing scope is safe
	 * to apply in place. Existing bundles continue to authenticate
	 * with the same credential, just losing write tools.
	 */
	public function test_scope_downgrade_is_in_place() {
		$helper = new RestApiKey();
		$helper->get_or_create( 'read_write' );
		$initial    = $helper->get_or_create( 'read' ); // returns the existing read_write key state.
		$old_creds  = $initial['credential'];
		$old_key_id = $initial['key_id'];

		$result = $helper->set_permissions( 'read' );

		$this->assertSame( RestApiKey::SCOPE_DOWNGRADED, $result, 'Downgrade reports SCOPE_DOWNGRADED.' );

		$post = $helper->get_or_create( 'read' );
		$this->assertSame( $old_creds, $post['credential'], 'Credential preserved on downgrade.' );
		$this->assertSame( $old_key_id, $post['key_id'], 'Same row, narrowed permissions.' );
		$this->assertSame( 'read', $post['permissions'] );
	}

	/**
	 * Setting the scope to its current value is a noop — no rotation,
	 * no UPDATE, distinct return code so the UI flash can be
	 * suppressed if desired.
	 */
	public function test_scope_noop_when_already_at_requested_value() {
		$helper = new RestApiKey();
		$helper->get_or_create( 'read' );

		$this->assertSame( RestApiKey::SCOPE_NOOP, $helper->set_permissions( 'read' ) );
	}

	/**
	 * `owner_user_id()` returns the WP user the WC key row is bound
	 * to. The setup-page view uses this to gate credential disclosure
	 * to other admins; a regression here would let a shop manager
	 * see / use a credential bound to an admin's user_id.
	 */
	public function test_owner_user_id_returns_the_bound_wp_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$helper = new RestApiKey();
		$helper->get_or_create( 'read' );

		$this->assertSame( $user_id, $helper->owner_user_id() );
	}

	/**
	 * No key → owner_user_id() returns 0. Callers must treat that as
	 * "no current owner" rather than "owned by user 0".
	 */
	public function test_owner_user_id_returns_zero_when_no_key() {
		$helper = new RestApiKey();
		$this->assertSame( 0, $helper->owner_user_id() );
	}

	/**
	 * Read-only snapshot via existing_state() — never provisions
	 * and never clears option state. Pin both directions: returns
	 * the row when a key is provisioned, returns null when not.
	 *
	 * The setup view depends on this method to surface a key that
	 * remains active even after the WC MCP feature flag is turned
	 * off (the dangerous middle state where the credential can still
	 * authenticate against the standard WC REST surface).
	 */
	public function test_existing_state_returns_state_or_null_without_side_effects() {
		$helper = new RestApiKey();

		$this->assertNull( $helper->existing_state(), 'No key → null.' );
		$this->assertSame( 0, $this->count_owned_rows(), 'No row was provisioned by the lookup.' );

		$helper->get_or_create( 'read' );
		$state = $helper->existing_state();

		$this->assertIsArray( $state );
		$this->assertArrayHasKey( 'credential', $state );
		$this->assertArrayHasKey( 'key_id', $state );
		$this->assertArrayHasKey( 'permissions', $state );
		$this->assertArrayHasKey( 'owner_user_id', $state );
		$this->assertSame( 'read', $state['permissions'] );
	}

	/**
	 * SetupPage::prepare_download_state() — extracted from
	 * handle_download() — must reject when the post-provision owner
	 * differs from the user who passed the initial gate. Closes the
	 * concurrent-regenerate TOCTOU: contender X passes
	 * require_key_owner, contender Y rotates the credential, X's
	 * get_or_create returns Y's credential. Without this re-check,
	 * X would receive a bundle bound to Y's user_id.
	 *
	 * Simulate the race by mutating the row's user_id between the
	 * provision and the precondition check, then calling the
	 * extracted helper with the original user as the expected owner.
	 */
	public function test_prepare_download_state_refuses_when_ownership_changed_mid_request() {
		update_option( 'woocommerce_feature_mcp_integration_enabled', 'yes' );

		$x_user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$y_user = self::factory()->user->create( array( 'role' => 'administrator' ) );

		wp_set_current_user( $x_user );
		$helper = new RestApiKey();
		$helper->get_or_create( 'read' );
		$state = $helper->existing_state();
		$this->assertSame( $x_user, $state['owner_user_id'] );

		// Simulate a concurrent regenerate by mutating the row's
		// user_id directly — the actual race would call regenerate()
		// from another request, but the resulting row state is the same.
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'woocommerce_api_keys',
			array( 'user_id' => $y_user ),
			array( 'key_id' => $state['key_id'] ),
			array( '%d' ),
			array( '%d' )
		);

		// X is still the current user, but the key now belongs to Y.
		$result = SetupPage::prepare_download_state( $x_user );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ownership_changed', $result->get_error_code() );
	}

	/**
	 * Refuses with `mcp_required` when the WC MCP feature flag is
	 * off, even if a key already exists.
	 * The bundle would point at an endpoint that returns 404, so
	 * downloading it would just hand out a credential with no
	 * working delivery path — the merchant should re-enable first.
	 */
	public function test_prepare_download_state_refuses_when_mcp_disabled() {
		update_option( 'woocommerce_feature_mcp_integration_enabled', 'no' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = SetupPage::prepare_download_state( $user_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_required', $result->get_error_code() );
	}

	/**
	 * Returns the full key state on the happy path, with
	 * owner_user_id matching the caller.
	 */
	public function test_prepare_download_state_returns_state_on_happy_path() {
		update_option( 'woocommerce_feature_mcp_integration_enabled', 'yes' );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$result = SetupPage::prepare_download_state( $user_id );

		$this->assertIsArray( $result );
		$this->assertSame( $user_id, $result['owner_user_id'] );
		$this->assertNotEmpty( $result['credential'] );
	}

	/**
	 * Deactivating the plugin must revoke the auto-created WC API
	 * key. Without that, a merchant who deactivates Hey Woo to
	 * disconnect Claude leaves the credential alive — the WC core
	 * MCP server (and WC REST surfaces generally) keep
	 * authenticating it because they don't depend on Hey Woo. A
	 * regression here means deactivation appears to disconnect but
	 * doesn't actually close the access path.
	 */
	public function test_deactivation_revokes_the_api_key() {
		$helper = new RestApiKey();
		$helper->get_or_create( 'read' );
		$this->assertSame( 1, $this->count_owned_rows() );

		// phpcs:disable WooCommerce.Commenting.CommentHooks -- this isn't a hook definition, it's a synthetic firing of the deactivation hook to test the behavior the plugin's register_deactivation_hook() registers.
		do_action( 'deactivate_' . plugin_basename( HEY_WOO_PLUGIN_FILE ) );
		// phpcs:enable WooCommerce.Commenting.CommentHooks

		$this->assertSame( 0, $this->count_owned_rows(), 'Deactivation revokes the WC API key row.' );
		$this->assertFalse( get_option( RestApiKey::OPTION_CREDENTIAL ) );
		$this->assertFalse( get_option( RestApiKey::OPTION_KEY_ID ) );
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
