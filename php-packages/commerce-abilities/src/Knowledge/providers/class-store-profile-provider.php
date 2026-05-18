<?php
/**
 * Store Profile Knowledge Provider.
 *
 * Exposes store identity, configuration, payment gateways, and shipping zones
 * as structured knowledge for AI consumption.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities\Knowledge\Providers;

use WooCommerce\CommerceAbilities\Knowledge\KnowledgeProvider;

defined( 'ABSPATH' ) || exit;

/**
 * Knowledge provider for store identity, locale, address, payments, shipping, tax, and feature flags.
 */
class StoreProfileProvider implements KnowledgeProvider {

	/**
	 * Unique provider ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'store-profile';
	}

	/**
	 * Human-readable label for the provider.
	 *
	 * @return string
	 */
	public function get_label() {
		return 'Store Profile';
	}

	/**
	 * Whether this provider's data source is available.
	 *
	 * @return bool
	 */
	public function is_available() {
		return true;
	}

	/**
	 * Assemble the full store profile payload.
	 *
	 * @param array $args Optional arguments (unused).
	 * @return array
	 */
	public function get_data( $args = array() ) {
		return array(
			'identity'        => $this->get_identity(),
			'locale'          => $this->get_locale(),
			'address'         => $this->get_address(),
			'payment_methods' => $this->get_payment_methods(),
			'shipping_zones'  => $this->get_shipping_zones(),
			'tax'             => $this->get_tax_config(),
			'features'        => $this->get_active_features(),
		);
	}

	/**
	 * Site name, description, URL, admin email, and platform versions.
	 *
	 * @return array
	 */
	private function get_identity() {
		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'admin_email' => get_option( 'admin_email' ),
			'wc_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
			'wp_version'  => get_bloginfo( 'version' ),
		);
	}

	/**
	 * Currency, formatting, locale, timezone, and unit settings.
	 *
	 * @return array
	 */
	private function get_locale() {
		return array(
			'currency'          => get_woocommerce_currency(),
			'currency_symbol'   => get_woocommerce_currency_symbol(),
			'currency_position' => get_option( 'woocommerce_currency_pos', 'left' ),
			'thousand_sep'      => get_option( 'woocommerce_price_thousand_sep', ',' ),
			'decimal_sep'       => get_option( 'woocommerce_price_decimal_sep', '.' ),
			'num_decimals'      => absint( get_option( 'woocommerce_price_num_decimals', 2 ) ),
			'locale'            => get_locale(),
			'timezone'          => wp_timezone_string(),
			'weight_unit'       => get_option( 'woocommerce_weight_unit', 'kg' ),
			'dimension_unit'    => get_option( 'woocommerce_dimension_unit', 'cm' ),
		);
	}

	/**
	 * Configured store address.
	 *
	 * @return array
	 */
	private function get_address() {
		return array(
			'address_1' => get_option( 'woocommerce_store_address', '' ),
			'address_2' => get_option( 'woocommerce_store_address_2', '' ),
			'city'      => get_option( 'woocommerce_store_city', '' ),
			'postcode'  => get_option( 'woocommerce_store_postcode', '' ),
			'country'   => WC()->countries->get_base_country(),
			'state'     => WC()->countries->get_base_state(),
		);
	}

	/**
	 * Available payment gateways with id, title, description, and enabled state.
	 *
	 * @return array
	 */
	private function get_payment_methods() {
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$methods  = array();

		foreach ( $gateways as $gateway ) {
			$methods[] = array(
				'id'          => $gateway->id,
				'title'       => $gateway->get_title(),
				'description' => $gateway->get_description(),
				'enabled'     => $gateway->is_available(),
			);
		}

		return $methods;
	}

	/**
	 * Configured shipping zones with their methods and locations.
	 *
	 * @return array
	 */
	private function get_shipping_zones() {
		$zones  = \WC_Shipping_Zones::get_zones();
		$result = array();

		foreach ( $zones as $zone_data ) {
			$zone    = new \WC_Shipping_Zone( $zone_data['id'] );
			$methods = array();

			foreach ( $zone->get_shipping_methods() as $method ) {
				$methods[] = array(
					'id'      => $method->id,
					'title'   => $method->get_title(),
					'enabled' => $method->is_enabled(),
				);
			}

			$result[] = array(
				'id'        => $zone_data['id'],
				'name'      => $zone_data['zone_name'],
				'locations' => $zone_data['formatted_zone_location'] ?? '',
				'methods'   => $methods,
			);
		}

		return $result;
	}

	/**
	 * Tax-related configuration flags and display preferences.
	 *
	 * @return array
	 */
	private function get_tax_config() {
		return array(
			'enabled'          => wc_tax_enabled(),
			'calc_taxes'       => get_option( 'woocommerce_calc_taxes', 'no' ),
			'prices_include'   => get_option( 'woocommerce_prices_include_tax', 'no' ),
			'tax_display_shop' => get_option( 'woocommerce_tax_display_shop', 'excl' ),
			'tax_display_cart' => get_option( 'woocommerce_tax_display_cart', 'excl' ),
		);
	}

	/**
	 * Booleans for HPOS, block checkout, coupons, reviews, guest checkout, and signup.
	 *
	 * @return array
	 */
	private function get_active_features() {
		return array(
			'hpos_enabled'         => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
				&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
			'block_checkout'       => has_block( 'woocommerce/checkout' ) || 'yes' === get_option( 'woocommerce_blocks_use_blockified_checkout', 'no' ),
			'coupons_enabled'      => wc_coupons_enabled(),
			'reviews_enabled'      => 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ),
			'guest_checkout'       => 'yes' === get_option( 'woocommerce_enable_guest_checkout', 'yes' ),
			'registration_enabled' => 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' ),
		);
	}
}
