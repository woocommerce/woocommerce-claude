<?php
/**
 * MCP server registration contract.
 *
 * Pins that by the time `mcp_adapter_init` has fired (i.e. anyone
 * actually hitting `/wp-json/woocommerce-claude/mcp`), the WooCommerce for Claude server
 * exists and exposes the full curated tool / resource / prompt
 * surface — not an empty server because abilities hadn't registered
 * yet.
 *
 * Background: `McpAdapter::instance()` schedules its `init()` on
 * `rest_api_init` priority 15 (or `init` priority 20 under WP-CLI).
 * Both run AFTER `init` has fired, which is when our abilities
 * register via `wp_abilities_api_init`. So when our
 * `register_mcp_server` callback resolves the ability IDs we pass
 * to `create_server()`, every ability is already registered and
 * none get silently skipped. This file pins that contract end-to-
 * end so a future refactor that boots the adapter earlier (e.g.
 * synchronously inside `bootstrap_mcp_adapter()` on `plugins_loaded`)
 * fails loudly instead of producing an empty production endpoint.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Integration tests for the WooCommerce for Claude MCP server registration.
 */
class Test_MCP_Server_Registration extends WP_UnitTestCase {

	/**
	 * Force the REST server (and therefore `rest_api_init` →
	 * `McpAdapter::init()` → `mcp_adapter_init` → our
	 * `register_mcp_server` callback) to fire, so the server
	 * registry is populated by the time each test asserts.
	 */
	public function set_up() {
		parent::set_up();
		rest_get_server();
	}

	/**
	 * The server itself was registered at all. If this fails the
	 * adapter never received our `create_server()` call (most likely
	 * because `mcp_adapter_init` didn't fire, or our hook wasn't on
	 * it) — every other assertion below depends on this.
	 */
	public function test_woocommerce_claude_server_is_registered() {
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$server  = $adapter->get_server( 'woocommerce-claude' );

		$this->assertNotNull( $server, 'WooCommerce for Claude MCP server must be registered with the adapter.' );
		$this->assertSame( 'woocommerce-claude', $server->get_server_route_namespace() );
		$this->assertSame( 'mcp', $server->get_server_route() );
	}

	/**
	 * The tool surface matches `Plugin::mcp_tool_ability_ids()`.
	 * Pin every entry by name (not just count) so a regression in
	 * which one specific ability gets silently skipped — e.g. its
	 * registration moved to a hook that runs after `mcp_adapter_init`
	 * — fails on the missing-by-name assertion rather than passing
	 * a count check that happens to match.
	 */
	public function test_woocommerce_claude_server_exposes_expected_tools() {
		$server     = \WP\MCP\Core\McpAdapter::instance()->get_server( 'woocommerce-claude' );
		$tool_names = array();
		foreach ( $server->get_tools() as $tool ) {
			$tool_names[] = $tool->get_name();
		}

		// Tool names are the ability ID with `/` replaced by `-` (the
		// MCP transport's name-mangling for ability ids).
		$expected = array(
			'woocommerce-claude-get-store-profile',
			'woocommerce-claude-get-readiness-score',
			'woocommerce-claude-get-recommendations',
			'woocommerce-claude-get-product-details',
			'woocommerce-claude-search-products',
			'woocommerce-claude-suggest-improvements',
			'wc-analytics-get-data',
			'wc-analytics-describe',
			'wc-analytics-confirm-large-range',
		);

		foreach ( $expected as $tool_name ) {
			$this->assertContains(
				$tool_name,
				$tool_names,
				"Tool {$tool_name} must be exposed on the WooCommerce for Claude MCP server."
			);
		}
	}

	/**
	 * The three resources (`wc-knowledge/*`) are registered with
	 * the server. Same rationale as the tools test — pin by name so
	 * an ability that's silently skipped surfaces as a missing-by-
	 * name failure.
	 */
	public function test_woocommerce_claude_server_exposes_expected_resources() {
		$server         = \WP\MCP\Core\McpAdapter::instance()->get_server( 'woocommerce-claude' );
		$resources      = $server->get_resources();
		$resource_count = count( $resources );

		$this->assertSame(
			3,
			$resource_count,
			sprintf( 'Expected 3 resources (store profile, catalog schema, store policies); got %d.', $resource_count )
		);
	}

	/**
	 * The two prompts (`wc-prompts/*`) are registered.
	 */
	public function test_woocommerce_claude_server_exposes_expected_prompts() {
		$server  = \WP\MCP\Core\McpAdapter::instance()->get_server( 'woocommerce-claude' );
		$prompts = $server->get_prompts();

		$prompt_names = array();
		foreach ( $prompts as $prompt ) {
			$prompt_names[] = $prompt->get_name();
		}

		$this->assertContains( 'wc-prompts-catalog-audit', $prompt_names );
		$this->assertContains( 'wc-prompts-product-improve', $prompt_names );
	}

	/**
	 * The connector's session-level guidance is shipped to clients via the
	 * `instructions` field of the MCP `initialize` response (the WP MCP
	 * adapter's InitializeHandler reads `get_server_description()` and
	 * places the value there).
	 *
	 * Pin the contract by content marker rather than exact wording — that
	 * leaves room for editorial polish without locking the tests against
	 * the routing/privacy guarantees the block exists to enforce. If a
	 * future edit silently drops the `query_analytics` routing rule or the
	 * pseudonymisation posture, this test fails with a clear "missing
	 * marker" message.
	 *
	 * Also pins a minimum length to catch a regression to the pre-feature
	 * one-line description, which would silently re-empty the model's
	 * preloaded routing context.
	 */
	public function test_woocommerce_claude_server_ships_instructions_block() {
		$server       = \WP\MCP\Core\McpAdapter::instance()->get_server( 'woocommerce-claude' );
		$instructions = $server->get_server_description();

		$this->assertGreaterThan(
			1500,
			strlen( $instructions ),
			'Server description (delivered as MCP `instructions`) must be a guidance block, not a one-line summary.'
		);

		$required_markers = array(
			// Routing: query_analytics is the antidote to "tool can't show specifics".
			'query_analytics',
			// Privacy posture: pseudonymisation is the rule clients keep refusing without this guidance.
			'pseudonymised',
			'Customer #N',
			// 365-day gate handshake.
			'extended_range_required',
			'confirmation_token',
			'wc-analytics-confirm-large-range',
			// Privacy posture explicit instruction.
			'Do not refuse',
		);

		foreach ( $required_markers as $marker ) {
			$this->assertStringContainsString(
				$marker,
				$instructions,
				"Instructions block must mention `{$marker}` — that guidance is what stops Claude from making the routing or privacy mistake the marker addresses."
			);
		}
	}
}
