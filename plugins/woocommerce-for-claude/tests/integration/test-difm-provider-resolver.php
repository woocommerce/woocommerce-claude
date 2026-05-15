<?php
/**
 * Integration tests for AI Insights provider resolution.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\AnthropicClient;
use WooCommerce\Claude\Difm\DifmAiClientInterface;
use WooCommerce\Claude\Difm\DifmProviderResolver;
use WooCommerce\Claude\Difm\WordPressAiClientAdapter;

/**
 * Tests for DifmProviderResolver.
 */
class Test_Difm_Provider_Resolver extends WP_UnitTestCase {

	/**
	 * Reset provider state.
	 */
	public function tear_down() {
		remove_all_filters( 'woocommerce_claude_difm_ai_client' );
		remove_all_filters( 'woocommerce_claude_difm_connector_mode' );
		remove_all_filters( 'woocommerce_claude_difm_wordpress_ai_supported' );
		remove_all_filters( 'woocommerce_claude_difm_wordpress_ai_has_credentials' );
		remove_all_filters( 'woocommerce_claude_difm_wordpress_ai_configured_provider_ids' );
		delete_option( DifmProviderResolver::PROVIDER_OPTION );
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		delete_option( 'hey_woo_anthropic_api_key' );
		delete_option( 'woocommerce_claude_options_migrated' );
		delete_option( 'woocommerce_claude_difm_provider_migrated' );
		parent::tear_down();
	}

	/**
	 * WP 6.9 mode ignores WordPress AI availability and uses direct Anthropic.
	 */
	public function test_legacy_mode_ignores_wordpress_ai_and_resolves_anthropic() {
		add_filter( 'woocommerce_claude_difm_connector_mode', '__return_false' );
		add_filter( 'woocommerce_claude_difm_wordpress_ai_supported', '__return_true' );
		add_filter(
			'woocommerce_claude_difm_wordpress_ai_configured_provider_ids',
			static function () {
				return array( 'openai' );
			}
		);
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );

		$client = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( AnthropicClient::class, $client );
	}

	/**
	 * WP 7.0 connector mode ignores direct Anthropic keys until migrated.
	 */
	public function test_connector_mode_ignores_direct_anthropic_key_until_migrated() {
		add_filter( 'woocommerce_claude_difm_connector_mode', '__return_true' );
		add_filter( 'woocommerce_claude_difm_wordpress_ai_supported', '__return_true' );
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );

		$result = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_ai_provider', $result->get_error_code() );
	}

	/**
	 * WP 7.0 connector mode resolves the selected configured connector provider.
	 */
	public function test_connector_mode_resolves_selected_configured_provider() {
		add_filter( 'woocommerce_claude_difm_connector_mode', '__return_true' );
		add_filter( 'woocommerce_claude_difm_wordpress_ai_supported', '__return_true' );
		add_filter(
			'woocommerce_claude_difm_wordpress_ai_configured_provider_ids',
			static function () {
				return array( 'anthropic', 'openai' );
			}
		);
		update_option( DifmProviderResolver::PROVIDER_OPTION, 'openai' );

		$client = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( WordPressAiClientAdapter::class, $client );
		$this->assertSame( 'openai', $client->get_provider_id() );
	}

	/**
	 * Empty tool-use input is replayed as null args so WP's Anthropic connector sends `{}`.
	 */
	public function test_wordpress_ai_adapter_replays_empty_tool_input_as_null_args() {
		$this->register_fake_wp_ai_history_dtos();

		$adapter = new WordPressAiClientAdapter( 'anthropic' );
		$method  = new \ReflectionMethod( $adapter, 'build_history' );
		$method->setAccessible( true );

		$history = $method->invoke(
			$adapter,
			array(
				array(
					'role'    => 'assistant',
					'content' => array(
						array(
							'type' => 'text',
							'text' => 'I will check that.',
						),
						array(
							'type'  => 'tool_use',
							'id'    => 'toolu_readiness',
							'name'  => 'get_readiness_score',
							'input' => array(),
						),
					),
				),
				array(
					'role'    => 'user',
					'content' => 'Continue.',
				),
			)
		);

		$this->assertCount( 1, $history );
		$parts         = $history[0]->getParts();
		$function_call = $parts[1]->getFunctionCall();
		$this->assertNull( $function_call->getArgs() );
	}

	/**
	 * Tool result turns stay in ordered history immediately after tool_use turns.
	 */
	public function test_wordpress_ai_adapter_replays_tool_result_turns_in_ordered_history() {
		$this->register_fake_wp_ai_history_dtos();

		$adapter = new WordPressAiClientAdapter( 'anthropic' );
		$method  = new \ReflectionMethod( $adapter, 'build_history' );
		$method->setAccessible( true );

		$history = $method->invoke(
			$adapter,
			array(
				array(
					'role'    => 'user',
					'content' => 'What is the readiness of my store?',
				),
				array(
					'role'    => 'assistant',
					'content' => array(
						array(
							'type'  => 'tool_use',
							'id'    => 'toolu_readiness',
							'name'  => 'get_readiness_score',
							'input' => array(),
						),
					),
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'        => 'tool_result',
							'tool_use_id' => 'toolu_readiness',
							'content'     => '{"overall_score":82}',
						),
					),
				),
			)
		);

		$this->assertCount( 3, $history );
		$parts             = $history[2]->getParts();
		$function_response = $parts[0]->getFunctionResponse();
		$this->assertSame( 'toolu_readiness', $function_response->getId() );
		$this->assertSame( array( 'overall_score' => 82 ), $function_response->getResponse() );
	}

	/**
	 * Legacy connector option values normalise to a configured provider.
	 */
	public function test_connector_mode_normalises_legacy_values_to_configured_provider() {
		add_filter( 'woocommerce_claude_difm_connector_mode', '__return_true' );
		add_filter( 'woocommerce_claude_difm_wordpress_ai_supported', '__return_true' );
		add_filter(
			'woocommerce_claude_difm_wordpress_ai_configured_provider_ids',
			static function () {
				return array( 'google', 'anthropic' );
			}
		);

		update_option( DifmProviderResolver::PROVIDER_OPTION, DifmProviderResolver::PROVIDER_AUTO );

		$this->assertSame( 'anthropic', DifmProviderResolver::get_selected_provider() );

		update_option( DifmProviderResolver::PROVIDER_OPTION, DifmProviderResolver::PROVIDER_WORDPRESS_AI );

		$client = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( WordPressAiClientAdapter::class, $client );
		$this->assertSame( 'anthropic', $client->get_provider_id() );
	}

	/**
	 * Unknown legacy direct values fall back to direct-Anthropic auto mode.
	 */
	public function test_legacy_unknown_provider_value_falls_back_to_direct_anthropic() {
		add_filter( 'woocommerce_claude_difm_connector_mode', '__return_false' );
		update_option( DifmProviderResolver::PROVIDER_OPTION, 'openai' );
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );

		$client = ( new DifmProviderResolver() )->resolve_client();

		$this->assertSame( DifmProviderResolver::PROVIDER_AUTO, DifmProviderResolver::get_selected_provider() );
		$this->assertInstanceOf( AnthropicClient::class, $client );
	}

	/**
	 * No configured provider returns a controlled WP_Error in legacy mode.
	 */
	public function test_no_key_returns_provider_error() {
		add_filter( 'woocommerce_claude_difm_connector_mode', '__return_false' );

		$result = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_ai_provider', $result->get_error_code() );
	}

	/**
	 * The upgrade migration pins existing Anthropic installs to Anthropic.
	 */
	public function test_upgrade_migration_pins_existing_anthropic_key() {
		add_filter( 'woocommerce_claude_difm_connector_mode', '__return_false' );
		delete_option( DifmProviderResolver::PROVIDER_OPTION );
		delete_option( 'woocommerce_claude_options_migrated' );
		delete_option( 'woocommerce_claude_difm_provider_migrated' );
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-existing' );

		woocommerce_claude_migrate_legacy_options();

		$this->assertSame( DifmProviderResolver::PROVIDER_ANTHROPIC, get_option( DifmProviderResolver::PROVIDER_OPTION ) );
	}

	/**
	 * The filter can provide a fake client for controller tests.
	 */
	public function test_client_filter_can_override_resolution() {
		add_filter(
			'woocommerce_claude_difm_ai_client',
			static function () {
				return new class() implements DifmAiClientInterface {
					/**
					 * Send a message.
					 *
					 * @param array  $messages   Messages.
					 * @param string $system     System prompt.
					 * @param array  $tools      Tools.
					 * @param int    $max_tokens Max tokens.
					 * @param array  $context    Context.
					 * @return array
					 */
					public function messages( array $messages, $system = '', array $tools = array(), $max_tokens = 4096, array $context = array() ) {
						unset( $messages, $system, $tools, $max_tokens, $context );
						return array(
							'type'        => 'message',
							'content'     => array(),
							'stop_reason' => 'end_turn',
							'provider'    => 'fake',
							'model'       => 'fake',
						);
					}
				};
			}
		);

		$client = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( DifmAiClientInterface::class, $client );
	}

	/**
	 * Register tiny fake WP AI DTOs for history-conversion tests.
	 *
	 * @return void
	 */
	private function register_fake_wp_ai_history_dtos() {
		require_once __DIR__ . '/class-wp-ai-test-dto-double.php';

		if (
			class_exists( 'WordPress\\AiClient\\Tools\\DTO\\FunctionCall' )
			&& ! is_a( 'WordPress\\AiClient\\Tools\\DTO\\FunctionCall', WooCommerce_Claude_Test_Wp_Ai_Dto_Double::class, true )
		) {
			$this->markTestSkipped( 'Native WP AI Client DTOs are already loaded.' );
		}

		if ( ! class_exists( 'WordPress\\AiClient\\Tools\\DTO\\FunctionCall' ) ) {
			class_alias( WooCommerce_Claude_Test_Wp_Ai_Dto_Double::class, 'WordPress\\AiClient\\Tools\\DTO\\FunctionCall' );
			class_alias( WooCommerce_Claude_Test_Wp_Ai_Dto_Double::class, 'WordPress\\AiClient\\Tools\\DTO\\FunctionResponse' );
			class_alias( WooCommerce_Claude_Test_Wp_Ai_Dto_Double::class, 'WordPress\\AiClient\\Messages\\DTO\\MessagePart' );
			class_alias( WooCommerce_Claude_Test_Wp_Ai_Dto_Double::class, 'WordPress\\AiClient\\Messages\\DTO\\ModelMessage' );
			class_alias( WooCommerce_Claude_Test_Wp_Ai_Dto_Double::class, 'WordPress\\AiClient\\Messages\\DTO\\UserMessage' );
		}
	}
}
