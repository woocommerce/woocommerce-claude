<?php
/**
 * Integration tests for the WooCommerce for Claude settings page.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Settings\SettingsPage;
use WooCommerce\Claude\Setup\RestApiKey;
use WooCommerce\Claude\Setup\SetupPage;

/**
 * Tests for SettingsPage setup and preference handling.
 */
class Test_Settings_Page extends WP_UnitTestCase {

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

		delete_option( 'woocommerce_claude_anthropic_api_key' );
		delete_option( SetupPage::TELEMETRY_OPTION );
		( new RestApiKey() )->revoke();
		$_POST           = array();
		$current_section = '';
		unset( $_GET['notice'] );
		wp_set_current_user( 0 );

		if ( $this->settings_page ) {
			remove_action( 'woocommerce_admin_field_woocommerce_claude_telemetry', array( $this->settings_page, 'render_telemetry_field' ) );
			remove_action( 'woocommerce_settings_save_woocommerce-claude', array( $this->settings_page, 'save_telemetry_option' ) );
		}

		parent::tear_down();
	}

	/**
	 * The WooCommerce settings sub-navigation keeps setup and preferences visible.
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
	 * The default section renders external Claude-app setup without admin chat setup.
	 */
	public function test_default_section_renders_external_setup_only() {
		$this->set_admin_user();
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-stale', 'no' );

		$html = $this->render_default_output();

		$this->assertStringContainsString( 'Set up Claude for your store', $html );
		$this->assertStringContainsString( 'Connect Claude apps', $html );
		$this->assertStringContainsString( 'Step 1: Create a store connection key', $html );
		$this->assertStringContainsString( 'Step 3: Add guide workflows (optional)', $html );
		$this->assertStringContainsString( 'Download skills', $html );
		$this->assertStringContainsString( 'woocommerce-claude-setup__accordion', $html );
		$this->assertStringNotContainsString( 'Chat in WordPress admin', $html );
		$this->assertStringNotContainsString( 'Anthropic API Key', $html );
		$this->assertStringNotContainsString( 'Open Ask Claude', $html );
		$this->assertStringNotContainsString( 'type="password"', $html );
	}

	/**
	 * The old AI Insights section slug falls back to the external setup screen.
	 */
	public function test_old_ai_insights_section_slug_does_not_render_admin_chat_setup() {
		global $current_section;

		$this->set_admin_user();
		$current_section = 'ai-insights';

		$html = $this->render_default_output();

		$this->assertStringContainsString( 'Connect Claude apps', $html );
		$this->assertStringNotContainsString( 'Ask Claude is managed by Hey Woo', $html );
		$this->assertStringNotContainsString( 'Chat in WordPress admin', $html );
		$this->assertStringNotContainsString( 'Anthropic API Key', $html );
	}

	/**
	 * Setup action notices keep the external connection accordion open so the
	 * next step is visible after creating or rotating the store connection key.
	 */
	public function test_external_connection_notice_keeps_connection_accordion_open() {
		$this->set_admin_user();

		$state = ( new RestApiKey() )->get_or_create();
		$this->assertIsArray( $state );

		$_GET['notice'] = 'key_generated';
		$html           = $this->render_default_output();

		$this->assertMatchesRegularExpression(
			'/<details class="woocommerce-claude-setup__accordion" open>[\s\S]*Connect Claude apps[\s\S]*Download MCPB file[\s\S]*<\/details>/',
			$html
		);
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
	 * Render the settings fields to HTML.
	 *
	 * @param string $method_name Protected settings method to invoke.
	 * @return string
	 */
	private function render_settings_html( $method_name ) {
		ob_start();
		\WC_Admin_Settings::output_fields( $this->get_settings_fields( $method_name ) );
		return ob_get_clean();
	}

	/**
	 * Render the default settings output to HTML.
	 *
	 * @return string
	 */
	private function render_default_output() {
		ob_start();
		$this->settings_page()->output();
		return ob_get_clean();
	}

	/**
	 * Return settings fields from the requested protected settings method.
	 *
	 * @param string $method_name Protected settings method to invoke.
	 * @return array
	 */
	private function get_settings_fields( $method_name ) {
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
	 * Set the current user to an administrator who can manage WooCommerce.
	 *
	 * @return void
	 */
	private function set_admin_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );
	}
}
