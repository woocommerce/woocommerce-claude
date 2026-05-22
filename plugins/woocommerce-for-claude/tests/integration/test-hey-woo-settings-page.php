<?php
/**
 * Integration tests for the Hey Woo settings page.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\HeyWoo\Settings\SettingsPage as HeyWooSettingsPage;
use WooCommerce\HeyWoo\Telemetry\Handlers\TracksHandler as HeyWooTracksHandler;
use WooCommerce\HeyWoo\Telemetry\TelemetryHandler as HeyWooTelemetryHandler;

// Load the Hey Woo main plugin file so functions defined there (notably
// hey_woo_activate) are available to tests. Idempotent: top-level loaders use
// class_exists / file_exists guards so re-including is safe.
require_once WP_PLUGIN_DIR . '/hey-woo/hey-woo.php';

require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/interface-telemetry-handler.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/class-telemetry-handler.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/handlers/class-tracks-handler.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/telemetry/class-difm-ai-telemetry.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/interface-difm-ai-client.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-difm-provider-environment.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-wordpress-ai-client-adapter.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-difm-provider-resolver.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/difm/class-anthropic-client.php';
require_once WP_PLUGIN_DIR . '/hey-woo/includes/settings/class-settings-page.php';

/**
 * Tests for Hey Woo settings and telemetry preference handling.
 */
class Test_Hey_Woo_Settings_Page extends WP_UnitTestCase {

	/**
	 * Settings page instance under test.
	 *
	 * @var HeyWooSettingsPage|null
	 */
	private $settings_page = null;

	/**
	 * Tear down persisted options and hooks.
	 */
	public function tear_down() {
		global $current_section;

		delete_option( HeyWooSettingsPage::DIFM_API_KEY_OPTION );
		delete_option( HeyWooSettingsPage::DIFM_PROVIDER_OPTION );
		delete_option( HeyWooSettingsPage::TELEMETRY_OPTION );
		$_POST           = array();
		$current_section = '';

		if ( $this->settings_page ) {
			remove_action( 'woocommerce_admin_field_hey_woo_api_key', array( $this->settings_page, 'render_api_key_field' ) );
			remove_action( 'woocommerce_admin_field_hey_woo_telemetry', array( $this->settings_page, 'render_telemetry_field' ) );
			remove_action( 'woocommerce_admin_field_hey_woo_wp_ai_status', array( $this->settings_page, 'render_wordpress_ai_status_field' ) );
			remove_filter( 'woocommerce_admin_settings_sanitize_option_' . HeyWooSettingsPage::DIFM_PROVIDER_OPTION, array( $this->settings_page, 'sanitize_provider_option' ), 10 );
			remove_filter( 'woocommerce_admin_settings_sanitize_option_' . HeyWooSettingsPage::DIFM_API_KEY_OPTION, array( $this->settings_page, 'sanitize_api_key_option' ), 10 );
			remove_action( 'woocommerce_settings_save_hey-woo', array( $this->settings_page, 'migrate_anthropic_key_to_connector' ), 9 );
			remove_action( 'woocommerce_settings_save_hey-woo', array( $this->settings_page, 'save_telemetry_option' ) );
			remove_action( 'woocommerce_settings_save_hey-woo', array( $this->settings_page, 'validate_api_key_on_save' ) );
		}

		parent::tear_down();
	}

	/**
	 * The Hey Woo settings tab exposes setup and plugin-level settings sections.
	 */
	public function test_sections_include_setup_and_settings() {
		$this->assertSame(
			array(
				''         => 'Setup',
				'settings' => 'Settings',
			),
			$this->settings_page()->get_sections()
		);
	}

	/**
	 * Usage tracking belongs in Settings, not in the setup key section.
	 */
	public function test_default_section_does_not_render_usage_tracking() {
		$html = $this->render_settings_html();

		$this->assertStringNotContainsString( 'Usage tracking', $html );
		$this->assertStringNotContainsString( 'hey-woo-telemetry-optin', $html );
	}

	/**
	 * The Settings section renders the native WooCommerce usage-tracking checkbox.
	 */
	public function test_settings_section_renders_usage_tracking_toggle() {
		update_option( HeyWooSettingsPage::TELEMETRY_OPTION, 'yes' );

		$html = $this->render_settings_html( 'get_settings_for_settings_section' );

		$this->assertStringContainsString( 'Usage tracking', $html );
		$this->assertStringContainsString( 'id="hey-woo-telemetry-optin"', $html );
		$this->assertStringContainsString( 'name="' . HeyWooSettingsPage::TELEMETRY_OPTION . '"', $html );
		$this->assertStringContainsString( 'Share anonymised usage data to help improve Hey Woo', $html );
		$this->assertStringContainsString( 'Learn more about usage tracking.', $html );
		$this->assertStringContainsString( 'checked', $html );
	}

	/**
	 * Saving another section must not treat the missing checkbox as an opt-out.
	 */
	public function test_usage_tracking_save_is_scoped_to_settings_section() {
		global $current_section;

		update_option( HeyWooSettingsPage::TELEMETRY_OPTION, 'yes' );

		$current_section = '';
		$_POST           = array();
		$this->settings_page()->save_telemetry_option();

		$this->assertSame( 'yes', get_option( HeyWooSettingsPage::TELEMETRY_OPTION ) );

		$current_section = 'settings';
		$_POST           = array();
		$this->settings_page()->save_telemetry_option();

		$this->assertSame( 'no', get_option( HeyWooSettingsPage::TELEMETRY_OPTION ) );

		$_POST = array(
			HeyWooSettingsPage::TELEMETRY_OPTION => 'yes',
		);
		$this->settings_page()->save_telemetry_option();

		$this->assertSame( 'yes', get_option( HeyWooSettingsPage::TELEMETRY_OPTION ) );
	}

	/**
	 * TracksHandler is on by default and stays off only on explicit opt-out.
	 *
	 * The read-default in maybe_add_tracks_handler() is 'yes' so missing
	 * options behave like the activation default. Storing 'no' is the only
	 * way to keep the handler out of the registry.
	 */
	public function test_tracks_handler_is_gated_by_usage_tracking_option() {
		delete_option( HeyWooSettingsPage::TELEMETRY_OPTION );

		$default_handlers = HeyWooTelemetryHandler::maybe_add_tracks_handler( array() );
		$this->assertCount( 1, $default_handlers );
		$this->assertInstanceOf( HeyWooTracksHandler::class, $default_handlers[0] );

		update_option( HeyWooSettingsPage::TELEMETRY_OPTION, 'no' );

		$this->assertSame( array(), HeyWooTelemetryHandler::maybe_add_tracks_handler( array() ) );

		update_option( HeyWooSettingsPage::TELEMETRY_OPTION, 'yes' );

		$handlers = HeyWooTelemetryHandler::maybe_add_tracks_handler( array() );

		$this->assertCount( 1, $handlers );
		$this->assertInstanceOf( HeyWooTracksHandler::class, $handlers[0] );
	}

	/**
	 * Fresh installs default on without overwriting an existing preference.
	 *
	 * Drives the activation hook directly so the inlined default-telemetry
	 * write in hey_woo_activate() stays covered. The hook is the only caller
	 * that should ever set this default — settings-page render code reads the
	 * option but never seeds it.
	 */
	public function test_activation_default_sets_missing_usage_tracking_preference_only() {
		delete_option( HeyWooSettingsPage::TELEMETRY_OPTION );

		hey_woo_activate();

		$this->assertSame( 'yes', get_option( HeyWooSettingsPage::TELEMETRY_OPTION ) );

		update_option( HeyWooSettingsPage::TELEMETRY_OPTION, 'no' );

		hey_woo_activate();

		$this->assertSame( 'no', get_option( HeyWooSettingsPage::TELEMETRY_OPTION ) );

		update_option( HeyWooSettingsPage::TELEMETRY_OPTION, 'yes' );

		hey_woo_activate();

		$this->assertSame( 'yes', get_option( HeyWooSettingsPage::TELEMETRY_OPTION ) );
	}

	/**
	 * Render the settings fields to HTML.
	 *
	 * @param string $method_name Protected settings method to invoke.
	 * @return string
	 */
	private function render_settings_html( $method_name = 'get_settings_for_default_section' ) {
		ob_start();
		\WC_Admin_Settings::output_fields( $this->get_settings_fields( $method_name ) );
		return ob_get_clean();
	}

	/**
	 * Return settings fields from the requested protected settings method.
	 *
	 * @param string $method_name Protected settings method to invoke.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_settings_fields( $method_name = 'get_settings_for_default_section' ) {
		$method = new ReflectionMethod( $this->settings_page(), $method_name );
		$method->setAccessible( true );
		return $method->invoke( $this->settings_page() );
	}

	/**
	 * Return the settings page under test.
	 *
	 * @return HeyWooSettingsPage
	 */
	private function settings_page() {
		if ( ! $this->settings_page ) {
			$this->settings_page = new HeyWooSettingsPage();
		}

		return $this->settings_page;
	}
}
