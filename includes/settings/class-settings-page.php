<?php
/**
 * WooCommerce Settings tab for Hey Woo.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Hey Woo" tab in WooCommerce > Settings.
 */
class SettingsPage extends \WC_Settings_Page {

	/**
	 * Option name that stores whether usage telemetry is enabled.
	 */
	const TELEMETRY_ENABLED_OPTION = 'hey_woo_telemetry_enabled';

	/**
	 * Register the tab and wire up WC settings hooks.
	 */
	public function __construct() {
		$this->id    = 'hey-woo';
		$this->label = __( 'Hey Woo', 'hey-woo' );
		parent::__construct();
	}

	/**
	 * Return the settings fields for the default (only) section.
	 *
	 * @return array WC settings field definitions.
	 */
	protected function get_settings_for_default_section() {
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Usage Telemetry', 'hey-woo' ),
				'id'    => 'hey_woo_telemetry_section',
				'desc'  => __( 'When enabled, Hey Woo sends anonymised usage data to help us improve the product. No personal data, customer names, order details, or financial figures are ever shared — only aggregate metrics such as which analytics tools are used and how quickly they respond. This is used solely to prioritise improvements and fix performance issues.', 'hey-woo' ),
			),
			array(
				'type'    => 'checkbox',
				'id'      => self::TELEMETRY_ENABLED_OPTION,
				'title'   => __( 'Enable telemetry', 'hey-woo' ),
				'desc'    => __( 'Share anonymised usage data with the Hey Woo team.', 'hey-woo' ),
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'hey_woo_telemetry_section',
			),
		);
	}
}
