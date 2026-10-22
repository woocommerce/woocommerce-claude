<?php
/**
 * MCP Adapter availability detection and the setup-screen notice.
 *
 * WooCommerce 11.3 stops bundling the WordPress MCP adapter, so the
 * `/wp-json/woocommerce-claude/mcp` endpoint only exists while the MCP
 * Adapter plugin is active. These tests pin how the plugin tells the
 * three cases apart and what the merchant is shown for each.
 *
 * The fully "missing" case (no adapter classes at all) can't be simulated
 * in-process, because the adapter classes are already loaded; it is
 * covered by manual testing against WooCommerce 11.3 without the plugin.
 * The "bundled" notice is exercised by passing the source explicitly.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Plugin;
use WooCommerce\Claude\Setup\SetupPage;

/**
 * Integration tests for MCP Adapter detection.
 */
class Test_MCP_Adapter_Availability extends WP_UnitTestCase {

	/**
	 * Snapshot of the `wp_mcp_init` action count, restored after each test.
	 *
	 * @var int|null
	 */
	private $saved_wp_mcp_init = null;

	/**
	 * Snapshot `wp_mcp_init` so tests can hide it.
	 */
	public function set_up() {
		parent::set_up();
		global $wp_actions;
		$this->saved_wp_mcp_init = $wp_actions['wp_mcp_init'] ?? null;
	}

	/**
	 * Restore `wp_mcp_init`.
	 */
	public function tear_down() {
		global $wp_actions;
		if ( null !== $this->saved_wp_mcp_init ) {
			$wp_actions['wp_mcp_init'] = $this->saved_wp_mcp_init; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the snapshot taken in set_up().
		}
		parent::tear_down();
	}

	/**
	 * Make the MCP Adapter plugin look inactive: its classes still resolve
	 * from its directory (as WooCommerce's autoloader cache can make them
	 * do right after deactivation), but its main file never fired
	 * `wp_mcp_init`.
	 */
	private function simulate_inactive_adapter_plugin() {
		global $wp_actions;
		unset( $wp_actions['wp_mcp_init'] );
	}

	/**
	 * The tests environment runs the MCP Adapter plugin, as a WooCommerce
	 * 11.3 store must.
	 */
	public function test_adapter_plugin_is_detected() {
		$this->assertSame( 'plugin', Plugin::mcp_adapter_source() );
	}

	/**
	 * No notice when the adapter plugin is active.
	 */
	public function test_no_notice_with_adapter_plugin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertNull( SetupPage::mcp_adapter_notice() );
	}

	/**
	 * Classes resolved from an inactive MCP Adapter plugin's directory are
	 * not a bundled copy: nothing must boot them, and the merchant is told
	 * the adapter is missing.
	 */
	public function test_inactive_adapter_plugin_copy_counts_as_missing() {
		$this->simulate_inactive_adapter_plugin();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 'missing', Plugin::mcp_adapter_source() );

		$notice = SetupPage::mcp_adapter_notice();

		$this->assertIsArray( $notice );
		$this->assertSame( 'error', $notice['kind'] );
	}

	/**
	 * A bundled copy (WooCommerce 10.x–11.2) still works today, so the
	 * notice is a warning ahead of the WooCommerce 11.3 update rather than
	 * an error.
	 */
	public function test_bundled_adapter_shows_upgrade_warning() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$notice = SetupPage::mcp_adapter_notice( 'bundled' );

		$this->assertIsArray( $notice );
		$this->assertSame( 'warning', $notice['kind'] );
		$this->assertStringContainsString( '11.3', $notice['title'] );
	}

	/**
	 * Only files inside the MCP Adapter plugin's directory count as the
	 * plugin's copy; WooCommerce's bundled library lives elsewhere.
	 */
	public function test_adapter_plugin_file_detection() {
		$plugin_dir = trailingslashit( WP_PLUGIN_DIR );

		$this->assertTrue( Plugin::is_adapter_plugin_file( $plugin_dir . 'mcp-adapter/includes/Core/McpAdapter.php' ) );
		$this->assertFalse( Plugin::is_adapter_plugin_file( $plugin_dir . 'woocommerce/vendor/wordpress/mcp-adapter/includes/Core/McpAdapter.php' ) );
		$this->assertFalse( Plugin::is_adapter_plugin_file( $plugin_dir . 'mcp-adapter-fork/includes/Core/McpAdapter.php' ), 'A sibling directory sharing the prefix is not the plugin.' );
	}

	/**
	 * An administrator gets a one-click install or activate link, with
	 * the core plugin-action nonce.
	 */
	public function test_administrator_gets_plugin_action_link() {
		$this->simulate_inactive_adapter_plugin();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin_id );
		}
		wp_set_current_user( $admin_id );

		$notice = SetupPage::mcp_adapter_notice();

		$this->assertNotSame( '', $notice['action_url'] );
		$this->assertContains(
			$notice['action_label'],
			array( 'Install MCP Adapter', 'Activate MCP Adapter' )
		);

		$query = array();
		// wp_nonce_url() returns an HTML-escaped URL for use in href.
		wp_parse_str( (string) wp_parse_url( html_entity_decode( $notice['action_url'] ), PHP_URL_QUERY ), $query );
		$this->assertArrayHasKey( '_wpnonce', $query );
	}

	/**
	 * A shop manager can open the setup screen but can't install or
	 * activate plugins, so they are told who can rather than shown a
	 * link that would fail.
	 */
	public function test_shop_manager_is_pointed_at_an_administrator() {
		$this->simulate_inactive_adapter_plugin();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$notice = SetupPage::mcp_adapter_notice();

		$this->assertSame( '', $notice['action_url'] );
		$this->assertStringContainsString(
			is_multisite() ? 'Ask a network administrator' : 'Ask a site administrator',
			$notice['body']
		);
	}

	/**
	 * On multisite, a site's own administrator can't install network
	 * plugins, so they are pointed at the network administrator rather
	 * than told to ask themselves.
	 */
	public function test_multisite_site_admin_is_pointed_at_network_administrator() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only (run with tests/phpunit/multisite.xml).' );
		}

		$this->simulate_inactive_adapter_plugin();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$notice = SetupPage::mcp_adapter_notice();

		if ( '' !== $notice['action_url'] ) {
			$this->markTestSkipped( 'This network lets site administrators activate the MCP Adapter plugin.' );
		}

		$this->assertStringContainsString( 'Ask a network administrator', $notice['body'] );
		$this->assertStringNotContainsString( 'site administrator', $notice['body'] );
	}
}
