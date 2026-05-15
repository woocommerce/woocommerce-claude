<?php
/**
 * Interface that all knowledge providers must implement.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Knowledge;

defined( 'ABSPATH' ) || exit;

interface KnowledgeProvider {

	/**
	 * Unique provider ID (e.g. 'store-profile', 'catalog').
	 *
	 * @return string
	 */
	public function get_id();

	/**
	 * Human-readable label for the provider.
	 *
	 * @return string
	 */
	public function get_label();

	/**
	 * Whether this provider's data source is available.
	 *
	 * @return bool
	 */
	public function is_available();

	/**
	 * Get structured knowledge data.
	 *
	 * @param array $args Optional arguments for filtering/pagination.
	 * @return array
	 */
	public function get_data( $args = array() );
}
