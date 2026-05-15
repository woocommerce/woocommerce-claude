<?php
/**
 * Integration tests for AI Insights provider resolution.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\AnthropicClient;
use WooCommerce\Claude\Difm\DifmAiClientInterface;
use WooCommerce\Claude\Difm\DifmProviderResolver;
use WooCommerce\Claude\Difm\OpenAIResponsesClient;
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
		remove_all_filters( 'woocommerce_claude_difm_wordpress_ai_supported' );
		remove_all_filters( 'woocommerce_claude_difm_wordpress_ai_has_credentials' );
		delete_option( DifmProviderResolver::PROVIDER_OPTION );
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		delete_option( 'hey_woo_anthropic_api_key' );
		delete_option( OpenAIResponsesClient::API_KEY_OPTION );
		delete_option( 'woocommerce_claude_options_migrated' );
		delete_option( 'woocommerce_claude_difm_provider_migrated' );
		parent::tear_down();
	}

	/**
	 * Auto mode falls back to a direct Anthropic key.
	 */
	public function test_auto_mode_resolves_anthropic_when_anthropic_key_exists() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );

		$client = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( AnthropicClient::class, $client );
	}

	/**
	 * Auto mode falls back to a direct OpenAI key when Anthropic is absent.
	 */
	public function test_auto_mode_resolves_openai_when_only_openai_key_exists() {
		update_option( OpenAIResponsesClient::API_KEY_OPTION, 'sk-openai-test' );

		$client = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( OpenAIResponsesClient::class, $client );
	}

	/**
	 * Auto mode prefers WordPress AI connector credentials when available.
	 */
	public function test_auto_mode_prefers_wordpress_ai_when_available() {
		add_filter( 'woocommerce_claude_difm_wordpress_ai_supported', '__return_true' );
		add_filter( 'woocommerce_claude_difm_wordpress_ai_has_credentials', '__return_true' );
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		update_option( OpenAIResponsesClient::API_KEY_OPTION, 'sk-openai-test' );

		$client = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( WordPressAiClientAdapter::class, $client );
	}

	/**
	 * A pinned provider is honoured even when another provider has a key.
	 */
	public function test_pinned_provider_is_honoured() {
		update_option( DifmProviderResolver::PROVIDER_OPTION, DifmProviderResolver::PROVIDER_OPENAI );
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-test' );
		update_option( OpenAIResponsesClient::API_KEY_OPTION, 'sk-openai-test' );

		$client = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( OpenAIResponsesClient::class, $client );
	}

	/**
	 * No configured provider returns a controlled WP_Error.
	 */
	public function test_no_key_returns_provider_error() {
		$result = ( new DifmProviderResolver() )->resolve_client();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'no_ai_provider', $result->get_error_code() );
	}

	/**
	 * The upgrade migration pins existing Anthropic installs to Anthropic.
	 */
	public function test_upgrade_migration_pins_existing_anthropic_key() {
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
}
