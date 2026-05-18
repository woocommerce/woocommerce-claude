<?php
/**
 * Knowledge Registry — singleton that collects data from all providers.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton registry that collects knowledge providers and dispatches reads.
 */
class KnowledgeRegistry {

	/**
	 * Singleton instances keyed by consumer ID.
	 *
	 * @var array<string, KnowledgeRegistry>
	 */
	private static $instances = array();

	/**
	 * Consumer ID for this registry instance.
	 *
	 * @var string
	 */
	private $consumer;

	/**
	 * Registered knowledge providers, keyed by provider ID.
	 *
	 * @var KnowledgeProvider[]
	 */
	private $providers = array();

	/**
	 * Return the singleton instance, constructing it on first access.
	 *
	 * @param string $consumer Consumer ID.
	 * @return KnowledgeRegistry
	 */
	public static function instance( $consumer = 'woocommerce-claude' ) {
		$consumer = self::normalise_consumer( $consumer );

		if ( ! isset( self::$instances[ $consumer ] ) ) {
			self::$instances[ $consumer ] = new self( $consumer );
		}

		return self::$instances[ $consumer ];
	}

	/**
	 * Private constructor — use instance().
	 *
	 * @param string $consumer Consumer ID.
	 */
	private function __construct( $consumer ) {
		$this->consumer = $consumer;
	}

	/**
	 * Return this registry's consumer ID.
	 *
	 * @return string
	 */
	public function get_consumer() {
		return $this->consumer;
	}

	/**
	 * Register a knowledge provider.
	 *
	 * @param KnowledgeProvider $provider Provider instance.
	 */
	public function register( KnowledgeProvider $provider ) {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get a specific provider.
	 *
	 * @param string $id Provider ID.
	 * @return KnowledgeProvider|null
	 */
	public function get_provider( $id ) {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * Get data from a specific provider.
	 *
	 * @param string $id   Provider ID.
	 * @param array  $args Optional arguments.
	 * @return array|null
	 */
	public function get_knowledge( $id, $args = array() ) {
		$provider = $this->get_provider( $id );
		if ( ! $provider || ! $provider->is_available() ) {
			return null;
		}
		return $provider->get_data( $args );
	}

	/**
	 * Get all registered providers and their availability.
	 *
	 * @return array
	 */
	public function get_providers_status() {
		$status = array();
		foreach ( $this->providers as $id => $provider ) {
			$status[ $id ] = array(
				'label'     => $provider->get_label(),
				'available' => $provider->is_available(),
			);
		}
		return $status;
	}

	/**
	 * Get all available knowledge in one call.
	 *
	 * @return array Keyed by provider ID.
	 */
	public function get_all_knowledge() {
		$data = array();
		foreach ( $this->providers as $id => $provider ) {
			if ( $provider->is_available() ) {
				$data[ $id ] = $provider->get_data();
			}
		}
		return $data;
	}

	/**
	 * Normalise a consumer ID for registry lookup.
	 *
	 * @param string $consumer Consumer ID.
	 * @return string
	 */
	private static function normalise_consumer( $consumer ) {
		$consumer = is_string( $consumer ) ? strtolower( trim( $consumer ) ) : '';
		$consumer = preg_replace( '/[^a-z0-9_-]+/', '-', $consumer );
		$consumer = trim( (string) $consumer, '-' );

		return '' === $consumer ? 'woocommerce-claude' : $consumer;
	}
}
