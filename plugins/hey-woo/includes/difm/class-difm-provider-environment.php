<?php
/**
 * Runtime environment helpers for AI Insights providers.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Centralises the WordPress-version split between legacy keys and connectors.
 */
class DifmProviderEnvironment {

	/**
	 * First WordPress version that exposes the native connector flow.
	 */
	const CONNECTOR_MODE_VERSION = '7.0-alpha';

	/**
	 * Whether AI Insights should use native WordPress AI connectors.
	 *
	 * This is intentionally based on WordPress version rather than the
	 * presence of Gutenberg-provided AI functions.
	 *
	 * @return bool
	 */
	public static function is_connector_mode() {
		$is_connector_mode = version_compare( get_bloginfo( 'version' ), self::CONNECTOR_MODE_VERSION, '>=' );

		/**
		 * Filter connector-mode detection for tests and specialised dev setups.
		 *
		 * @since 0.5.0
		 *
		 * @param bool $is_connector_mode Whether WP 7.0 connector mode is active.
		 */
		return (bool) apply_filters( 'hey_woo_difm_connector_mode', $is_connector_mode );
	}

	/**
	 * Whether AI Insights should use the plugin-owned direct Anthropic client.
	 *
	 * @return bool
	 */
	public static function is_legacy_mode() {
		return ! self::is_connector_mode();
	}

	/**
	 * Return the native connector settings URL.
	 *
	 * @return string
	 */
	public static function connectors_url() {
		return admin_url( 'options-connectors.php' );
	}

	/**
	 * Return the connector setting name for a provider.
	 *
	 * @param string $provider_id Native AI provider ID.
	 * @return string Connector setting option name, or empty string.
	 */
	public static function get_connector_setting_name( $provider_id ) {
		$provider_id  = sanitize_key( $provider_id );
		$setting_name = '';

		if ( '' !== $provider_id && function_exists( 'wp_get_connector' ) ) {
			$connector = wp_get_connector( $provider_id );
			if ( is_array( $connector ) && isset( $connector['authentication'] ) && is_array( $connector['authentication'] ) ) {
				$setting_name = isset( $connector['authentication']['setting_name'] ) && is_string( $connector['authentication']['setting_name'] )
					? $connector['authentication']['setting_name']
					: '';
			}
		}

		/**
		 * Filter connector setting names for tests and provider extensions.
		 *
		 * @since 0.5.0
		 *
		 * @param string $setting_name Connector setting option name.
		 * @param string $provider_id  Native AI provider ID.
		 */
		return (string) apply_filters( 'hey_woo_difm_connector_setting_name', $setting_name, $provider_id );
	}

	/**
	 * Return where a connector key currently comes from.
	 *
	 * @param string $provider_id Native AI provider ID.
	 * @return string env|constant|database|none
	 */
	public static function get_connector_api_key_source( $provider_id ) {
		$provider_id = sanitize_key( $provider_id );

		if ( '' !== $provider_id && function_exists( '_wp_connectors_get_api_key_source' ) ) {
			$source = _wp_connectors_get_api_key_source( $provider_id );
			if ( is_string( $source ) && '' !== $source && 'none' !== $source ) {
				return $source;
			}
		}

		$setting_name = self::get_connector_setting_name( $provider_id );
		if ( '' !== $setting_name && '' !== (string) get_option( $setting_name, '' ) ) {
			return 'database';
		}

		$constant_name = self::get_default_connector_constant_name( $provider_id );
		if ( '' !== $constant_name && defined( $constant_name ) && '' !== (string) constant( $constant_name ) ) {
			return 'constant';
		}

		if ( '' !== $constant_name && '' !== (string) getenv( $constant_name ) ) {
			return 'env';
		}

		return 'none';
	}

	/**
	 * Return a native connector's human-readable label.
	 *
	 * @param string $provider_id Native AI provider ID.
	 * @return string
	 */
	public static function get_connector_label( $provider_id ) {
		$provider_id = sanitize_key( $provider_id );
		$label       = '';

		if ( '' !== $provider_id && function_exists( 'wp_get_connector' ) ) {
			$connector = wp_get_connector( $provider_id );
			if ( is_array( $connector ) ) {
				foreach ( array( 'label', 'name', 'title' ) as $label_key ) {
					if ( isset( $connector[ $label_key ] ) && is_string( $connector[ $label_key ] ) && '' !== $connector[ $label_key ] ) {
						$label = $connector[ $label_key ];
						break;
					}
				}
			}
		}

		if ( '' === $label ) {
			$known_labels = array(
				'anthropic' => __( 'Anthropic', 'hey-woo' ),
				'openai'    => __( 'OpenAI', 'hey-woo' ),
				'google'    => __( 'Google', 'hey-woo' ),
			);

			$label = isset( $known_labels[ $provider_id ] )
				? $known_labels[ $provider_id ]
				: ucwords( str_replace( array( '-', '_' ), ' ', $provider_id ) );
		}

		/**
		 * Filter connector labels for tests and provider extensions.
		 *
		 * @since 0.5.0
		 *
		 * @param string $label       Connector label.
		 * @param string $provider_id Native AI provider ID.
		 */
		return (string) apply_filters( 'hey_woo_difm_connector_label', $label, $provider_id );
	}

	/**
	 * Set a connector API key option and mirror it into the runtime registry.
	 *
	 * @param string $provider_id Native AI provider ID.
	 * @param string $api_key     API key.
	 * @return bool Whether the option was updated.
	 */
	public static function set_connector_api_key( $provider_id, $api_key ) {
		$provider_id  = sanitize_key( $provider_id );
		$setting_name = self::get_connector_setting_name( $provider_id );
		$api_key      = is_string( $api_key ) ? trim( $api_key ) : '';

		if ( '' === $provider_id || '' === $setting_name || '' === $api_key ) {
			return false;
		}

		$updated = update_option( $setting_name, $api_key, 'no' );
		self::set_connector_registry_api_key( $provider_id, $api_key );

		return (bool) $updated || (string) get_option( $setting_name, '' ) === $api_key;
	}

	/**
	 * Return the conventional native connector constant name for a provider.
	 *
	 * @param string $provider_id Native AI provider ID.
	 * @return string
	 */
	private static function get_default_connector_constant_name( $provider_id ) {
		$provider_id = sanitize_key( $provider_id );

		if ( '' === $provider_id ) {
			return '';
		}

		return strtoupper( str_replace( '-', '_', $provider_id ) ) . '_API_KEY';
	}

	/**
	 * Mirror a newly saved DB key into the current request's AI Client registry.
	 *
	 * WordPress passes connector keys to the registry during init. Migration
	 * happens later in a settings request, so mirror it here for immediate use.
	 *
	 * @param string $provider_id Native AI provider ID.
	 * @param string $api_key     API key.
	 * @return void
	 */
	private static function set_connector_registry_api_key( $provider_id, $api_key ) {
		$registry_class = 'WordPress\\AiClient\\AiClient';
		$auth_class     = 'WordPress\\AiClient\\Providers\\Http\\DTO\\ApiKeyRequestAuthentication';

		if (
			! class_exists( $registry_class )
			|| ! method_exists( $registry_class, 'defaultRegistry' )
			|| ! class_exists( $auth_class )
		) {
			return;
		}

		try {
			$registry = $registry_class::defaultRegistry();
			if ( ! is_object( $registry ) || ! method_exists( $registry, 'hasProvider' ) || ! method_exists( $registry, 'getProvider' ) ) {
				return;
			}

			if ( ! $registry->hasProvider( $provider_id ) ) {
				return;
			}

			$provider = $registry->getProvider( $provider_id );
			if ( is_object( $provider ) && method_exists( $provider, 'setRequestAuthentication' ) ) {
				$provider->setRequestAuthentication( new $auth_class( $api_key ) );
			}
		} catch ( \Throwable $e ) {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->warning(
					sprintf(
						/* translators: 1: AI provider ID, 2: exception message. */
						__( 'Hey Woo could not refresh the %1$s AI connector registry credentials after settings save: %2$s', 'hey-woo' ),
						$provider_id,
						$e->getMessage()
					),
					array( 'source' => 'hey-woo' )
				);
			}
			return;
		}
	}
}
