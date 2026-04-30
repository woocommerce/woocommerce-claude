<?php
/**
 * WooCommerce Settings tab for Hey Woo.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Settings;

use HeyWoo\Setup\SetupPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Hey Woo" tab in WooCommerce > Settings.
 *
 * The tab has two sections:
 *
 * - "" (default) — the Connect-to-Claude setup view, rendered by
 *   SetupPage::render_setup_view(). Setup is the first thing a new
 *   user wants, so it sits at the default URL.
 * - "preferences" — the telemetry checkbox, rendered through WC's
 *   standard settings field API.
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
	 * Sub-section navigation. Default ("") shows Setup; "preferences"
	 * shows the standard WC settings form for telemetry.
	 *
	 * @return array<string,string>
	 */
	public function get_sections() {
		return array(
			''            => __( 'Setup', 'hey-woo' ),
			'preferences' => __( 'Preferences', 'hey-woo' ),
		);
	}

	/**
	 * Default (Setup) section has no WC settings fields — it's
	 * rendered as a custom view by output() below. WC's final
	 * `get_settings_for_section()` dispatcher delegates here when
	 * `$current_section === ''`.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
		return array();
	}

	/**
	 * Telemetry fields for the "preferences" section. Discovered
	 * automatically by WC's final dispatcher via the
	 * `get_settings_for_<section>_section` naming convention.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_preferences_section() {
		return $this->get_preferences_settings();
	}

	/**
	 * Render the active section. The Setup (default) section has no
	 * WC settings fields, so we render the custom view directly;
	 * Preferences hands off to WC's standard field renderer.
	 */
	public function output() {
		global $current_section;

		if ( '' === $current_section || 'setup' === $current_section ) {
			// Setup view — rendered inside WC's outer <form id="mainform">.
			// SetupPage uses nonced GET links rather than nested <form>s
			// to keep the HTML valid.
			SetupPage::render_setup_view();
			return;
		}

		parent::output();
	}

	/**
	 * The preferences section's setting definitions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_preferences_settings() {
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
