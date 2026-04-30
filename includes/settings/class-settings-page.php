<?php
/**
 * WooCommerce Settings tab for Hey Woo.
 *
 * @package HeyWoo
 */

namespace HeyWoo\Settings;

use HeyWoo\Abilities\AbilitiesBootstrap;
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
 * - "preferences" — the telemetry and customer-PII checkboxes,
 *   rendered through WC's standard settings field API.
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
	 * shows the standard WC settings form for telemetry + privacy.
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
	 * Settings fields per section. The setup section returns an empty
	 * array because it's rendered via SetupPage::render_setup_view()
	 * in output() below; only the preferences section uses WC's
	 * settings field API.
	 *
	 * @param string $current_section Section ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_settings_for_section( $current_section ) {
		if ( 'preferences' === $current_section ) {
			return $this->get_preferences_settings();
		}
		return array();
	}

	/**
	 * Render the active section. Setup is rendered as a custom view
	 * (it has download buttons + nonced links rather than a settings
	 * form), so we bypass the parent's standard field renderer for
	 * that section.
	 */
	public function output() {
		global $current_section;

		if ( 'preferences' === $current_section ) {
			parent::output();
			return;
		}

		// Setup view — rendered inside WC's outer <form id="mainform">.
		// SetupPage uses nonced GET links rather than nested <form>s
		// to keep the HTML valid.
		SetupPage::render_setup_view();
	}

	/**
	 * The preferences section's setting definitions — telemetry +
	 * customer-PII toggle, preserved unchanged from the previous
	 * single-section layout.
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
			array(
				'type'  => 'title',
				'title' => __( 'Customer-level privacy', 'hey-woo' ),
				'id'    => 'hey_woo_privacy_section',
				'desc'  => __( 'Controls whether AI analytics tools return real names and emails for customer-level rows, or pseudonymised IDs only. Default is pseudonymised — flip on when you\'re deliberately chaining an email/CRM connector that needs real customer details.', 'hey-woo' ),
			),
			array(
				'type'    => 'checkbox',
				'id'      => AbilitiesBootstrap::OPTION_ALLOW_CUSTOMER_PII,
				'title'   => __( 'Allow customer-level PII in AI responses', 'hey-woo' ),
				'desc'    => __( 'When on, customer rows include real first_name / last_name / email fields. When off, rows return pseudonymised `Customer #N` identifiers.', 'hey-woo' ),
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'hey_woo_privacy_section',
			),
		);
	}
}
