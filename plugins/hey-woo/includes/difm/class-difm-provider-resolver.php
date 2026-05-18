<?php
/**
 * AI provider resolution for AI Insights.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the configured AI provider into a DIFM client.
 */
class DifmProviderResolver {

	/**
	 * Provider selection option.
	 */
	const PROVIDER_OPTION = 'hey_woo_difm_provider';

	/**
	 * Provider selection values.
	 *
	 * `wordpress_ai` exists only to normalise saved values from the earlier
	 * hybrid provider selector.
	 */
	const PROVIDER_AUTO         = 'auto';
	const PROVIDER_WORDPRESS_AI = 'wordpress_ai';
	const PROVIDER_ANTHROPIC    = 'anthropic';

	/**
	 * Return all supported provider option values.
	 *
	 * @return array<int,string>
	 */
	public static function provider_values() {
		if ( DifmProviderEnvironment::is_connector_mode() ) {
			return WordPressAiClientAdapter::get_configured_provider_ids();
		}

		return array(
			self::PROVIDER_AUTO,
			self::PROVIDER_ANTHROPIC,
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

		if ( DifmProviderEnvironment::is_connector_mode() ) {
			$configured_provider_ids = WordPressAiClientAdapter::get_configured_provider_ids();

			if ( in_array( $provider, $configured_provider_ids, true ) ) {
				return $provider;
			}

			$default_provider_id = WordPressAiClientAdapter::get_default_provider_id();
			return '' !== $default_provider_id ? $default_provider_id : self::PROVIDER_AUTO;
		}

		return self::PROVIDER_ANTHROPIC === $provider ? self::PROVIDER_ANTHROPIC : self::PROVIDER_AUTO;
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
		$filtered_client = apply_filters( 'hey_woo_difm_ai_client', null, $selected_provider );
		if ( $filtered_client instanceof DifmAiClientInterface ) {
			return $filtered_client;
		}

		if ( DifmProviderEnvironment::is_connector_mode() ) {
			return $this->resolve_connector_client( $selected_provider );
		}

		return $this->resolve_legacy_client();
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
		$filtered_client = apply_filters( 'hey_woo_difm_ai_client', null, $selected_provider );
		if ( $filtered_client instanceof DifmAiClientInterface ) {
			return true;
		}

		if ( DifmProviderEnvironment::is_connector_mode() ) {
			return WordPressAiClientAdapter::has_api_key();
		}

		return AnthropicClient::has_api_key();
	}

	/**
	 * Return a compact provider status map for settings/admin UI.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_provider_statuses() {
		if ( ! DifmProviderEnvironment::is_connector_mode() ) {
			return array(
				self::PROVIDER_ANTHROPIC => array(
					'label'      => self::provider_label( self::PROVIDER_ANTHROPIC ),
					'available'  => true,
					'configured' => AnthropicClient::has_api_key(),
					'status'     => AnthropicClient::has_api_key() ? 'configured' : 'unconfigured',
				),
			);
		}

		$statuses = array();
		foreach ( WordPressAiClientAdapter::get_configured_provider_ids() as $provider_id ) {
			$statuses[ $provider_id ] = array(
				'label'      => self::provider_label( $provider_id ),
				'available'  => true,
				'configured' => true,
				'status'     => 'configured',
			);
		}

		return $statuses;
	}

	/**
	 * Return a human-readable provider label.
	 *
	 * @param string $provider Provider value.
	 * @return string
	 */
	public static function provider_label( $provider ) {
		if ( DifmProviderEnvironment::is_connector_mode() && ! in_array( $provider, array( self::PROVIDER_AUTO, self::PROVIDER_WORDPRESS_AI ), true ) ) {
			return WordPressAiClientAdapter::get_provider_label( $provider );
		}

		switch ( $provider ) {
			case self::PROVIDER_WORDPRESS_AI:
				return __( 'WordPress AI connectors', 'hey-woo' );
			case self::PROVIDER_ANTHROPIC:
				return __( 'Anthropic', 'hey-woo' );
			case self::PROVIDER_AUTO:
			default:
				return __( 'Auto', 'hey-woo' );
		}
	}

	/**
	 * Resolve legacy direct-Anthropic mode.
	 *
	 * @return DifmAiClientInterface|\WP_Error
	 */
	private function resolve_legacy_client() {
		if ( AnthropicClient::has_api_key() ) {
			return new AnthropicClient();
		}

		return new \WP_Error(
			'no_ai_provider',
			__( 'No AI provider is configured.', 'hey-woo' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Resolve strict WordPress connector mode.
	 *
	 * @param string $provider_id Native WordPress AI provider ID.
	 * @return DifmAiClientInterface|\WP_Error
	 */
	private function resolve_connector_client( $provider_id ) {
		if ( ! WordPressAiClientAdapter::is_supported() ) {
			return new \WP_Error(
				'wordpress_ai_unavailable',
				__( 'WordPress AI connectors are not available on this site.', 'hey-woo' ),
				array( 'status' => 400 )
			);
		}

		$configured_provider_ids = WordPressAiClientAdapter::get_configured_provider_ids();
		if ( empty( $configured_provider_ids ) ) {
			return new \WP_Error(
				'no_ai_provider',
				__( 'No WordPress AI provider connector is configured.', 'hey-woo' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $provider_id, $configured_provider_ids, true ) ) {
			$provider_id = WordPressAiClientAdapter::get_default_provider_id();
		}

		if ( '' === $provider_id ) {
			return new \WP_Error(
				'no_ai_provider',
				__( 'No WordPress AI provider connector is configured.', 'hey-woo' ),
				array( 'status' => 400 )
			);
		}

		return new WordPressAiClientAdapter( $provider_id );
	}
}
