<?php
/**
 * Integration test — MCPB manifest contract.
 *
 * Pins the store connection bundle fields Claude Desktop uses to launch
 * the same proxy/auth shape that worked in the 0.4.2 release line.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Setup\McpbBundle;
use WooCommerce\Claude\Setup\SetupPage;

require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/setup/class-mcpb-bundle.php';

/**
 * Tests for the generated .mcpb manifest.
 */
class Test_Mcpb_Bundle extends WP_UnitTestCase {

	/**
	 * The bundle should keep using the vetted proxy package and Basic auth
	 * env vars. This is the external install contract used by the working
	 * 0.4.2 release, even though the repo is now a monorepo internally.
	 */
	public function test_manifest_uses_pinned_proxy_and_basic_auth_env() {
		$bundle   = new McpbBundle(
			'https://example.com/wp-json/woocommerce-claude/mcp',
			'ck_test:cs_test',
			'0.4.3'
		);
		$manifest = $bundle->manifest();
		$config   = $manifest['server']['mcp_config'];

		$this->assertSame( McpbBundle::MANIFEST_VERSION, $manifest['manifest_version'] );
		$this->assertArrayNotHasKey( 'tools_generated', $manifest );
		$this->assertArrayNotHasKey( 'prompts_generated', $manifest );
		$this->assertSame( 'npx', $config['command'] );
		$this->assertSame( array( '-y', SetupPage::REMOTE_PACKAGE ), $config['args'] );
		$this->assertSame( 'https://example.com/wp-json/woocommerce-claude/mcp', $config['env']['WP_API_URL'] );
		$this->assertSame( 'ck_test', $config['env']['WP_API_USERNAME'] );
		$this->assertSame( 'cs_test', $config['env']['WP_API_PASSWORD'] );
		$this->assertArrayNotHasKey( 'CUSTOM_HEADERS', $config['env'] );
	}
}
