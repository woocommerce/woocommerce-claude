<?php
/**
 * Integration test — SetupPage::derive_server_slug uniqueness.
 *
 * Pins the contract that two stores with distinct identities get
 * distinct slugs, so installing or pasting their MCPB / mcpServers
 * configs into the same Claude Desktop doesn't collapse them onto a
 * single connector entry.
 *
 * The slug is used as:
 *   - the MCPB `manifest.name`
 *   - the manual `mcpServers` JSON key
 *   - the `claude mcp add <name>` server name
 *   - the downloaded `.mcpb` filename
 *
 * Collisions there mean Claude can silently authenticate against the
 * wrong tenant — a Read+Write key would let it mutate the wrong store.
 *
 * @package HeyWoo\Tests
 */

use HeyWoo\Setup\SetupPage;

/**
 * Tests for SetupPage::derive_server_slug.
 */
class Test_Setup_Server_Slug extends WP_UnitTestCase {

	/**
	 * Two stores on the same host but different paths
	 * (e.g. WP multisite subdirectory installs) must produce
	 * distinct slugs.
	 */
	public function test_distinct_slug_for_same_host_different_path() {
		$root   = SetupPage::derive_server_slug( 'https://example.com/wp-json/hey-woo/mcp' );
		$shop_a = SetupPage::derive_server_slug( 'https://example.com/shop-a/wp-json/hey-woo/mcp' );
		$shop_b = SetupPage::derive_server_slug( 'https://example.com/shop-b/wp-json/hey-woo/mcp' );

		$this->assertNotSame( $root, $shop_a, 'Root vs shop-a must differ.' );
		$this->assertNotSame( $root, $shop_b, 'Root vs shop-b must differ.' );
		$this->assertNotSame( $shop_a, $shop_b, 'shop-a vs shop-b must differ.' );
	}

	/**
	 * Same host, different ports (a common dev-environment shape:
	 * wp-env on :8888 and :8889, or two staging instances) must
	 * produce distinct slugs.
	 */
	public function test_distinct_slug_for_same_host_different_port() {
		$port_8888 = SetupPage::derive_server_slug( 'http://localhost:8888/wp-json/hey-woo/mcp' );
		$port_8889 = SetupPage::derive_server_slug( 'http://localhost:8889/wp-json/hey-woo/mcp' );

		$this->assertNotSame( $port_8888, $port_8889 );
	}

	/**
	 * Genuinely different hosts must of course produce distinct slugs.
	 */
	public function test_distinct_slug_for_different_hosts() {
		$a = SetupPage::derive_server_slug( 'https://store-a.example/wp-json/hey-woo/mcp' );
		$b = SetupPage::derive_server_slug( 'https://store-b.example/wp-json/hey-woo/mcp' );

		$this->assertNotSame( $a, $b );
	}

	/**
	 * Same URL → same slug, every time. Stability matters because
	 * the slug is also the bundle's manifest name — a slug change on
	 * the same store would orphan the previously-installed MCPB
	 * extension entry in Claude Desktop.
	 */
	public function test_slug_is_stable_for_same_url() {
		$url = 'https://example.com/wp-json/hey-woo/mcp';

		$this->assertSame(
			SetupPage::derive_server_slug( $url ),
			SetupPage::derive_server_slug( $url )
		);
	}

	/**
	 * The slug always starts with `hey-woo-` for namespacing, contains
	 * only lowercase + digits + hyphens, and includes a recognisable
	 * portion of the host so a user can tell two slugs apart at a
	 * glance.
	 */
	public function test_slug_shape() {
		$slug = SetupPage::derive_server_slug( 'https://example.com/wp-json/hey-woo/mcp' );

		$this->assertStringStartsWith( 'hey-woo-', $slug );
		$this->assertSame( 1, preg_match( '/^[a-z0-9\-]+$/', $slug ), 'Slug is sanitize_title-safe.' );
		$this->assertStringContainsString( 'example-com', $slug, 'Host portion remains visible for human recognition.' );
	}

	/**
	 * Empty / malformed input falls back to a safe constant rather
	 * than throwing or returning an unsafe value.
	 */
	public function test_slug_handles_empty_input() {
		$this->assertSame( 'hey-woo', SetupPage::derive_server_slug( '' ) );
	}
}
