<?php
/**
 * Integration tests for the DIFM settings field.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Settings\SettingsPage;
use WooCommerce\Claude\Setup\SetupPage;

/**
 * Tests for SettingsPage DIFM API-key handling.
 */
class Test_Difm_Settings_Page extends WP_UnitTestCase {

	/**
	 * Settings page instance under test.
	 *
	 * @var SettingsPage|null
	 */
	private $settings_page = null;

	/**
	 * Tear down persisted options and hooks.
	 */
	public function tear_down() {
		global $current_section;

		remove_all_filters( 'pre_http_request' );
		delete_option( SettingsPage::DIFM_API_KEY_OPTION );
		delete_option( SetupPage::TELEMETRY_OPTION );
		$_POST           = array();
		$current_section = '';

		if ( $this->settings_page ) {
			remove_action( 'woocommerce_admin_field_woocommerce_claude_api_key', array( $this->settings_page, 'render_api_key_field' ) );
			remove_action( 'woocommerce_admin_field_woocommerce_claude_telemetry', array( $this->settings_page, 'render_telemetry_field' ) );
			remove_filter( 'woocommerce_admin_settings_sanitize_option_' . SettingsPage::DIFM_API_KEY_OPTION, array( $this->settings_page, 'sanitize_api_key_option' ), 10 );
			remove_action( 'woocommerce_settings_save_woocommerce-claude', array( $this->settings_page, 'save_telemetry_option' ) );
			remove_action( 'woocommerce_settings_save_woocommerce-claude', array( $this->settings_page, 'validate_api_key_on_save' ) );
		}

		parent::tear_down();
	}

	/**
	 * A saved key is never rendered back to the admin HTML.
	 */
	public function test_saved_api_key_is_masked_in_rendered_settings_html() {
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-real-secret', 'no' );

		$html = $this->render_settings_html();

		$this->assertStringNotContainsString( 'sk-ant-real-secret', $html );
		$this->assertStringContainsString( SettingsPage::DIFM_API_KEY_SENTINEL, $html );
		$this->assertStringContainsString( 'type="password"', $html );
	}

	/**
	 * The WooCommerce settings sub-navigation keeps AI, DIY, and preferences separate.
	 */
	public function test_sections_include_ai_insights_diy_and_settings() {
		$this->assertSame(
			array(
				''         => 'AI Insights',
				'setup'    => 'DIY',
				'settings' => 'Settings',
			),
			$this->settings_page()->get_sections()
		);
	}

	/**
	 * Usage tracking belongs in Settings, not in the AI Insights key section.
	 */
	public function test_ai_insights_section_does_not_render_usage_tracking() {
		$html = $this->render_settings_html();

		$this->assertStringNotContainsString( 'Usage tracking', $html );
		$this->assertStringNotContainsString( 'woocommerce-claude-telemetry-optin', $html );
	}

	/**
	 * The Settings section renders the native WooCommerce usage-tracking checkbox.
	 */
	public function test_settings_section_renders_usage_tracking_toggle() {
		update_option( SetupPage::TELEMETRY_OPTION, 'yes' );

		$html = $this->render_settings_html( 'get_settings_for_settings_section' );

		$this->assertStringContainsString( 'Usage tracking', $html );
		$this->assertStringContainsString( 'id="woocommerce-claude-telemetry-optin"', $html );
		$this->assertStringContainsString( 'name="' . SetupPage::TELEMETRY_OPTION . '"', $html );
		$this->assertStringContainsString( 'Share anonymised usage data to help improve WooCommerce for Claude', $html );
		$this->assertStringContainsString( 'Learn more about usage tracking.', $html );
		$this->assertStringContainsString( 'checked', $html );
	}

	/**
	 * Saving another section must not treat the missing checkbox as an opt-out.
	 */
	public function test_usage_tracking_save_is_scoped_to_settings_section() {
		global $current_section;

		update_option( SetupPage::TELEMETRY_OPTION, 'yes' );

		$current_section = '';
		$_POST           = array();
		$this->settings_page()->save_telemetry_option();

		$this->assertSame( 'yes', get_option( SetupPage::TELEMETRY_OPTION ) );

		$current_section = 'settings';
		$_POST           = array();
		$this->settings_page()->save_telemetry_option();

		$this->assertSame( 'no', get_option( SetupPage::TELEMETRY_OPTION ) );

		$_POST = array(
			SetupPage::TELEMETRY_OPTION => 'yes',
		);
		$this->settings_page()->save_telemetry_option();

		$this->assertSame( 'yes', get_option( SetupPage::TELEMETRY_OPTION ) );
	}

	/**
	 * Blank submissions preserve an existing key.
	 */
	public function test_blank_save_preserves_existing_key() {
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-existing', 'no' );

		$this->save_settings(
			array(
				SettingsPage::DIFM_API_KEY_OPTION => '',
			)
		);

		$this->assertSame( 'sk-ant-existing', get_option( SettingsPage::DIFM_API_KEY_OPTION ) );
	}

	/**
	 * Sentinel submissions preserve an existing key.
	 */
	public function test_sentinel_save_preserves_existing_key() {
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-existing', 'no' );

		$this->save_settings(
			array(
				SettingsPage::DIFM_API_KEY_OPTION => SettingsPage::DIFM_API_KEY_SENTINEL,
			)
		);

		$this->assertSame( 'sk-ant-existing', get_option( SettingsPage::DIFM_API_KEY_OPTION ) );
	}

	/**
	 * The explicit clear checkbox removes the saved key.
	 */
	public function test_clear_checkbox_removes_saved_key() {
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-existing', 'no' );

		$this->save_settings(
			array(
				SettingsPage::DIFM_API_KEY_OPTION      => SettingsPage::DIFM_API_KEY_SENTINEL,
				SettingsPage::DIFM_API_KEY_CLEAR_FIELD => 'yes',
			)
		);

		$this->assertFalse( get_option( SettingsPage::DIFM_API_KEY_OPTION, false ) );
	}

	/**
	 * New keys are saved with autoload disabled.
	 */
	public function test_new_key_is_saved_with_autoload_disabled() {
		$this->save_settings(
			array(
				SettingsPage::DIFM_API_KEY_OPTION => 'sk-ant-new-key',
			)
		);

		$this->assertSame( 'sk-ant-new-key', get_option( SettingsPage::DIFM_API_KEY_OPTION ) );
		$this->assertContains( $this->get_option_autoload_value( SettingsPage::DIFM_API_KEY_OPTION ), array( 'no', 'off', 'auto-off' ) );
	}

	/**
	 * Invalid keys submitted through settings save are removed after validation.
	 */
	public function test_invalid_key_is_removed_on_settings_validation() {
		$this->save_settings(
			array(
				SettingsPage::DIFM_API_KEY_OPTION => 'sk-ant-bad-key',
			)
		);

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'response' => array(
						'code'    => 401,
						'message' => 'Unauthorized',
					),
					'body'     => wp_json_encode(
						array(
							'type'  => 'error',
							'error' => array( 'message' => 'Invalid API key.' ),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);

		$this->settings_page()->validate_api_key_on_save();

		$this->assertFalse( get_option( SettingsPage::DIFM_API_KEY_OPTION, false ) );
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
	 * Save settings using WooCommerce's settings helper.
	 *
	 * @param array $data Posted settings data.
	 * @return void
	 */
	private function save_settings( array $data ) {
		$_POST = $data;
		\WC_Admin_Settings::save_fields( $this->get_settings_fields(), $data );
	}

	/**
	 * Return settings fields from the requested protected settings method.
	 *
	 * @param string $method_name Protected settings method to invoke.
	 * @return array
	 */
	private function get_settings_fields( $method_name = 'get_settings_for_default_section' ) {
		$method = new \ReflectionMethod( $this->settings_page(), $method_name );
		$method->setAccessible( true );
		return $method->invoke( $this->settings_page() );
	}

	/**
	 * Return the SettingsPage instance.
	 *
	 * @return SettingsPage
	 */
	private function settings_page() {
		if ( ! $this->settings_page ) {
			$this->settings_page = new SettingsPage();
		}

		return $this->settings_page;
	}

	/**
	 * Read an option's autoload value from the database.
	 *
	 * @param string $option_name Option name.
	 * @return string|null
	 */
	private function get_option_autoload_value( $option_name ) {
		global $wpdb;

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				$option_name
			)
		);
	}
}
