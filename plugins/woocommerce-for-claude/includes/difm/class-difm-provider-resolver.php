<?php
/**
 * AI provider resolution for AI Insights.
 *
 * @package WooCommerce\Claude\Difm
 */

namespace WooCommerce\Claude\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the configured AI provider into a DIFM client.
 */
class DifmProviderResolver {

	/**
	 * Provider selection option.
	 */
	const PROVIDER_OPTION = 'woocommerce_claude_difm_provider';

	/**
	 * Provider selection values.
	 */
	const PROVIDER_AUTO         = 'auto';
	const PROVIDER_WORDPRESS_AI = 'wordpress_ai';
	const PROVIDER_ANTHROPIC    = 'anthropic';
	const PROVIDER_OPENAI       = 'openai';

	/**
	 * Return all supported provider option values.
	 *
	 * @return array<int,string>
	 */
	public static function provider_values() {
		return array(
			self::PROVIDER_AUTO,
			self::PROVIDER_WORDPRESS_AI,
			self::PROVIDER_ANTHROPIC,
			self::PROVIDER_OPENAI,
		);
	}

	/**
	 * Return the saved provider selection.
	 *
	 * @return string
	 */
	public static function get_selected_provider() {
		return self::normalise_provider( get_option( self::PROVIDER_OPTION, self::PROVIDER_AUTO ) );
	}

	/**
	 * Sanitise a provider option value.
	 *
	 * @param mixed $provider Provider value.
	 * @return string
	 */
	public static function normalise_provider( $provider ) {
		$provider = is_string( $provider ) ? sanitize_key( $provider ) : self::PROVIDER_AUTO;

		return in_array( $provider, self::provider_values(), true ) ? $provider : self::PROVIDER_AUTO;
	}

	/**
	 * Resolve the active client.
	 *
	 * @return DifmAiClientInterface|\WP_Error
	 */
	public function resolve_client() {
		$selected_provider = self::get_selected_provider();

		/**
		 * Allow tests and advanced integrations to provide the DIFM AI client.
		 *
		 * Returning null leaves normal provider resolution in place.
		 *
		 * @since 0.5.0
		 *
		 * @param DifmAiClientInterface|null $client            Client override.
		 * @param string                     $selected_provider Saved provider setting.
		 */
		$filtered_client = apply_filters( 'woocommerce_claude_difm_ai_client', null, $selected_provider );
		if ( $filtered_client instanceof DifmAiClientInterface ) {
			return $filtered_client;
		}

		if ( self::PROVIDER_AUTO === $selected_provider ) {
			return $this->resolve_auto_client();
		}

		return $this->resolve_pinned_client( $selected_provider );
	}

	/**
	 * Whether any provider is configured enough to show/use AI Insights.
	 *
	 * @return bool
	 */
	public function has_configured_provider() {
		$selected_provider = self::get_selected_provider();

		/**
		 * Allow tests and advanced integrations to report a configured DIFM AI client.
		 *
		 * @since 0.5.0
		 *
		 * @param DifmAiClientInterface|null $client            Client override.
		 * @param string                     $selected_provider Saved provider setting.
		 */
		$filtered_client = apply_filters( 'woocommerce_claude_difm_ai_client', null, $selected_provider );
		if ( $filtered_client instanceof DifmAiClientInterface ) {
			return true;
		}

		if ( self::PROVIDER_AUTO === $selected_provider ) {
			return WordPressAiClientAdapter::has_api_key()
				|| AnthropicClient::has_api_key()
				|| OpenAIResponsesClient::has_api_key();
		}

		if ( self::PROVIDER_WORDPRESS_AI === $selected_provider ) {
			return WordPressAiClientAdapter::has_api_key();
		}

		if ( self::PROVIDER_ANTHROPIC === $selected_provider ) {
			return AnthropicClient::has_api_key();
		}

		if ( self::PROVIDER_OPENAI === $selected_provider ) {
			return OpenAIResponsesClient::has_api_key();
		}

		return false;
	}

	/**
	 * Return a compact provider status map for settings/admin UI.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_provider_statuses() {
		return array(
			self::PROVIDER_WORDPRESS_AI => array(
				'label'      => self::provider_label( self::PROVIDER_WORDPRESS_AI ),
				'available'  => WordPressAiClientAdapter::is_supported(),
				'configured' => WordPressAiClientAdapter::has_api_key(),
				'status'     => WordPressAiClientAdapter::get_status(),
			),
			self::PROVIDER_ANTHROPIC    => array(
				'label'      => self::provider_label( self::PROVIDER_ANTHROPIC ),
				'available'  => true,
				'configured' => AnthropicClient::has_api_key(),
				'status'     => AnthropicClient::has_api_key() ? 'configured' : 'unconfigured',
			),
			self::PROVIDER_OPENAI       => array(
				'label'      => self::provider_label( self::PROVIDER_OPENAI ),
				'available'  => true,
				'configured' => OpenAIResponsesClient::has_api_key(),
				'status'     => OpenAIResponsesClient::has_api_key() ? 'configured' : 'unconfigured',
			),
		);
	}

	/**
	 * Return a human-readable provider label.
	 *
	 * @param string $provider Provider value.
	 * @return string
	 */
	public static function provider_label( $provider ) {
		switch ( $provider ) {
			case self::PROVIDER_WORDPRESS_AI:
				return __( 'WordPress AI connectors', 'woocommerce-claude' );
			case self::PROVIDER_ANTHROPIC:
				return __( 'Anthropic', 'woocommerce-claude' );
			case self::PROVIDER_OPENAI:
				return __( 'OpenAI', 'woocommerce-claude' );
			case self::PROVIDER_AUTO:
			default:
				return __( 'Auto', 'woocommerce-claude' );
		}
	}

	/**
	 * Resolve auto mode.
	 *
	 * Priority for new installs:
	 *   1. WordPress AI/Core AI Client with configured connector credentials.
	 *   2. Plugin-owned Anthropic BYOK.
	 *   3. Plugin-owned OpenAI BYOK.
	 *
	 * Existing Anthropic installs are migrated to pinned Anthropic mode.
	 *
	 * @return DifmAiClientInterface|\WP_Error
	 */
	private function resolve_auto_client() {
		if ( WordPressAiClientAdapter::has_api_key() ) {
			return new WordPressAiClientAdapter();
		}

		if ( AnthropicClient::has_api_key() ) {
			return new AnthropicClient();
		}

		if ( OpenAIResponsesClient::has_api_key() ) {
			return new OpenAIResponsesClient();
		}

		return new \WP_Error(
			'no_ai_provider',
			__( 'No AI provider is configured.', 'woocommerce-claude' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Resolve a pinned provider.
	 *
	 * @param string $provider Provider value.
	 * @return DifmAiClientInterface|\WP_Error
	 */
	private function resolve_pinned_client( $provider ) {
		switch ( $provider ) {
			case self::PROVIDER_WORDPRESS_AI:
				if ( WordPressAiClientAdapter::has_api_key() ) {
					return new WordPressAiClientAdapter();
				}
				return new \WP_Error(
					'no_ai_provider',
					__( 'No WordPress AI provider connector is configured.', 'woocommerce-claude' ),
					array( 'status' => 400 )
				);

			case self::PROVIDER_ANTHROPIC:
				if ( AnthropicClient::has_api_key() ) {
					return new AnthropicClient();
				}
				return new \WP_Error(
					'no_ai_provider',
					__( 'No Anthropic API key is configured.', 'woocommerce-claude' ),
					array( 'status' => 400 )
				);

			case self::PROVIDER_OPENAI:
				if ( OpenAIResponsesClient::has_api_key() ) {
					return new OpenAIResponsesClient();
				}
				return new \WP_Error(
					'no_ai_provider',
					__( 'No OpenAI API key is configured.', 'woocommerce-claude' ),
					array( 'status' => 400 )
				);
		}

		return new \WP_Error(
			'unknown_ai_provider',
			__( 'The selected AI provider is not supported.', 'woocommerce-claude' ),
			array( 'status' => 400 )
		);
	}
}
