<?php
/**
 * WooCommerce Settings tab for WooCommerce for Claude.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Settings;

use WooCommerce\Claude\Setup\SetupPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "WooCommerce for Claude" tab in WooCommerce > Settings.
 *
 * The tab has a single section — the Connect-to-Claude setup view, which
 * also hosts the "share usage data" toggle. Returning an empty section
 * map suppresses WC's sub-section nav so the tab renders the setup view
 * straight away.
 */
class SettingsPage extends \WC_Settings_Page {

	/**
	 * Register the tab and wire up WC settings hooks.
	 */
	public function __construct() {
		$this->id    = 'woocommerce-claude';
		$this->label = __( 'WooCommerce for Claude', 'woocommerce-claude' );
		parent::__construct();
	}

	/**
	 * Single-section tab — no sub-nav.
	 *
	 * @return array<string,string>
	 */
	public function get_sections() {
		return array();
	}

	/**
	 * The default section has no WC settings fields — the page renders a
	 * custom view via output() below.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
		return array();
	}

	/**
	 * Render the setup view directly. SetupPage uses nonced GET links
	 * rather than nested <form>s to keep the HTML valid inside WC's
	 * outer <form id="mainform">.
	 */
	public function output() {
		SetupPage::render_setup_view();
	}
}
