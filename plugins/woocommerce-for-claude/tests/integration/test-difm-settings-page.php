<?php
/**
 * Integration tests for the DIFM settings field.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Settings\SettingsPage;
use WooCommerce\Claude\Difm\DifmProviderResolver;
use WooCommerce\Claude\Setup\RestApiKey;
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
		remove_all_filters( 'woocommerce_claude_difm_connector_mode' );
		remove_all_filters( 'woocommerce_claude_difm_connector_setting_name' );
		remove_all_filters( 'woocommerce_claude_difm_anthropic_key_constant_name' );
		remove_all_filters( 'woocommerce_claude_difm_wordpress_ai_supported' );
		remove_all_filters( 'woocommerce_claude_difm_wordpress_ai_configured_provider_ids' );
		delete_option( SettingsPage::DIFM_API_KEY_OPTION );
		delete_option( SettingsPage::LEGACY_DIFM_API_KEY_OPTION );
		delete_option( 'connectors_ai_anthropic_api_key' );
		delete_option( 'connectors_ai_openai_api_key' );
		delete_option( SettingsPage::DIFM_PROVIDER_OPTION );
		delete_option( 'woocommerce_claude_difm_provider_migrated' );
		delete_option( SetupPage::TELEMETRY_OPTION );
		update_option(
			'active_plugins',
			array_values( array_diff( (array) get_option( 'active_plugins', array() ), array( 'hey-woo/hey-woo.php' ) ) )
		);
		( new RestApiKey() )->revoke();
		$_POST           = array();
		$current_section = '';
		unset( $_GET['notice'] );
		wp_set_current_user( 0 );

		if ( $this->settings_page ) {
			remove_action( 'woocommerce_admin_field_woocommerce_claude_api_key', array( $this->settings_page, 'render_api_key_field' ) );
			remove_action( 'woocommerce_admin_field_woocommerce_claude_wp_ai_status', array( $this->settings_page, 'render_wordpress_ai_status_field' ) );
			remove_action( 'woocommerce_admin_field_woocommerce_claude_telemetry', array( $this->settings_page, 'render_telemetry_field' ) );
			remove_filter( 'woocommerce_admin_settings_sanitize_option_' . SettingsPage::DIFM_PROVIDER_OPTION, array( $this->settings_page, 'sanitize_provider_option' ), 10 );
			remove_filter( 'woocommerce_admin_settings_sanitize_option_' . SettingsPage::DIFM_API_KEY_OPTION, array( $this->settings_page, 'sanitize_api_key_option' ), 10 );
			remove_action( 'woocommerce_settings_save_woocommerce-claude', array( $this->settings_page, 'save_telemetry_option' ) );
			remove_action( 'woocommerce_settings_save_woocommerce-claude', array( $this->settings_page, 'migrate_anthropic_key_to_connector' ), 9 );
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
	 * The WooCommerce settings sub-navigation keeps only consolidated setup and preferences visible.
	 */
	public function test_sections_include_setup_and_settings() {
		$this->assertSame(
			array(
				''            => 'Setup',
				'ai-insights' => 'AI provider',
				'settings'    => 'Settings',
			),
			$this->settings_page()->get_sections()
		);
	}

	/**
	 * The default section renders the consolidated accordion setup overview.
	 */
	public function test_default_section_renders_setup_overview() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		ob_start();
		$this->settings_page()->output();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Set up AI for your store', $html );
		$this->assertStringContainsString( 'Chat in WordPress admin', $html );
		$this->assertStringContainsString( 'Connect Claude apps', $html );
		$this->assertLessThan( strpos( $html, 'Chat in WordPress admin' ), strpos( $html, 'Connect Claude apps' ) );
		$this->assertStringContainsString( 'Anthropic API key', $html );
		$this->assertStringNotContainsString( 'OpenAI API key', $html );
		$this->assertStringContainsString( 'Step 1: Create a store connection key', $html );
		$this->assertStringContainsString( 'Step 3: Add guide workflows (optional)', $html );
		$this->assertStringContainsString( 'Download skills', $html );
		$this->assertStringContainsString( 'woocommerce-claude-setup__accordion', $html );
		$this->assertStringNotContainsString( 'Optional: Add guided workflows', $html );
		$this->assertStringNotContainsString( '<details class="woocommerce-claude-setup__accordion" open>', $html );
		$this->assertStringNotContainsString( 'Download Claude workflow skills', $html );
		$this->assertStringNotContainsString( 'You can set up one or both options.', $html );
	}

	/**
	 * Hey Woo owns the WordPress-admin chat setup when both plugins are active.
	 */
	public function test_default_section_hides_admin_chat_setup_when_hey_woo_is_active() {
		global $current_section;

		$this->mark_hey_woo_active();

		$html = $this->render_default_output();

		$this->assertStringContainsString( 'Ask AI in WordPress admin is managed by Hey Woo', $html );
		$this->assertStringContainsString( 'Connect Claude apps', $html );
		$this->assertStringNotContainsString( 'Chat in WordPress admin', $html );
		$this->assertStringNotContainsString( 'Anthropic API Key', $html );

		$current_section = 'ai-insights';
		$html            = $this->render_default_output();

		$this->assertStringContainsString( 'Ask AI is managed by Hey Woo', $html );
		$this->assertStringNotContainsString( 'type="password"', $html );
	}

	/**
	 * The Ask AI CTA is a post-save affordance, not a persistent second action.
	 */
	public function test_open_ai_insights_button_only_renders_after_saving_key_form() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-existing', 'no' );

		$html = $this->render_default_output();
		$this->assertStringNotContainsString( 'Open Ask AI', $html );

		$_POST = array(
			SettingsPage::DIFM_API_KEY_OPTION => SettingsPage::DIFM_API_KEY_SENTINEL,
		);
		$this->settings_page()->validate_api_key_on_save();

		$html = $this->render_default_output();
		$this->assertStringContainsString( 'Open Ask AI', $html );
		$this->assertMatchesRegularExpression(
			'/<details class="woocommerce-claude-setup__accordion" open>[\s\S]*Chat in WordPress admin[\s\S]*Open Ask AI[\s\S]*<\/details>/',
			$html
		);

		$html = $this->render_default_output();
		$this->assertStringNotContainsString( 'Open Ask AI', $html );
	}

	/**
	 * Setup action notices keep the external connection accordion open so the
	 * next step is visible after creating or rotating the store connection key.
	 */
	public function test_external_connection_notice_keeps_connection_accordion_open() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( WP_User::class, $user );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

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
	 * Usage tracking belongs in Settings, not in the AI Insights key section.
	 */
	public function test_ai_insights_section_does_not_render_usage_tracking() {
		$html = $this->render_settings_html( 'get_settings_for_ai_insights_section' );

		$this->assertStringNotContainsString( 'Usage tracking', $html );
		$this->assertStringNotContainsString( 'woocommerce-claude-telemetry-optin', $html );
	}

	/**
	 * Legacy mode renders only the direct Anthropic key field.
	 */
	public function test_legacy_mode_renders_direct_anthropic_key_only() {
		$html = $this->render_settings_html( 'get_settings_for_ai_insights_section' );

		$this->assertStringContainsString( 'Anthropic API key', $html );
		$this->assertStringContainsString( 'name="' . SettingsPage::DIFM_API_KEY_OPTION . '"', $html );
		$this->assertStringNotContainsString( 'name="' . SettingsPage::DIFM_PROVIDER_OPTION . '"', $html );
		$this->assertStringNotContainsString( 'Open Settings &gt; Connectors', $html );
	}

	/**
	 * Connector mode points merchants to the connector screen when no provider is connected.
	 */
	public function test_connector_mode_renders_connector_cta_and_no_direct_key_field() {
		$this->enable_connector_mode( array() );

		$html = $this->render_settings_html( 'get_settings_for_ai_insights_section' );

		$this->assertStringContainsString( 'Open Settings &gt; Connectors', $html );
		$this->assertStringContainsString( 'No WordPress AI provider is connected yet', $html );
		$this->assertStringNotContainsString( 'name="' . SettingsPage::DIFM_API_KEY_OPTION . '"', $html );
		$this->assertStringNotContainsString( 'Anthropic API key', $html );
	}

	/**
	 * Connector mode renders a selector containing only connected providers.
	 */
	public function test_connector_mode_renders_provider_selector_for_multiple_connected_providers() {
		$this->enable_connector_mode( array( 'anthropic', 'openai' ) );

		$html = $this->render_settings_html( 'get_settings_for_ai_insights_section' );

		$this->assertStringContainsString( 'name="' . SettingsPage::DIFM_PROVIDER_OPTION . '"', $html );
		$this->assertStringContainsString( 'value="anthropic"', $html );
		$this->assertStringContainsString( 'Anthropic', $html );
		$this->assertStringContainsString( 'value="openai"', $html );
		$this->assertStringContainsString( 'OpenAI', $html );
		$this->assertStringNotContainsString( 'value="auto"', $html );
		$this->assertStringNotContainsString( 'value="wordpress_ai"', $html );
		$this->assertStringNotContainsString( 'name="' . SettingsPage::DIFM_API_KEY_OPTION . '"', $html );
	}

	/**
	 * Migration card appears only when a database-stored local Anthropic key exists.
	 */
	public function test_connector_mode_renders_migration_card_for_database_key() {
		$this->enable_connector_mode( array() );
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-existing', 'no' );

		$html = $this->render_settings_html( 'get_settings_for_ai_insights_section' );

		$this->assertStringContainsString( 'Move saved Anthropic key to WordPress connectors', $html );
		$this->assertStringContainsString( 'name="' . SettingsPage::ANTHROPIC_CONNECTOR_MIGRATE_FIELD . '"', $html );
		$this->assertStringNotContainsString( 'name="' . SettingsPage::DIFM_API_KEY_OPTION . '"', $html );
	}

	/**
	 * A manually configured connector lets merchants remove the stale local key.
	 */
	public function test_connector_mode_renders_remove_local_key_action_when_connector_exists() {
		$this->enable_connector_mode( array( 'anthropic' ) );
		$this->mock_connector_setting_name();
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-existing', 'no' );
		update_option( 'connectors_ai_anthropic_api_key', 'sk-ant-native', 'no' );

		$html = $this->render_settings_html( 'get_settings_for_ai_insights_section' );

		$this->assertStringContainsString( 'Remove legacy local Anthropic key', $html );
		$this->assertStringContainsString( 'name="' . SettingsPage::ANTHROPIC_CONNECTOR_REMOVE_LOCAL_FIELD . '"', $html );
		$this->assertStringNotContainsString( 'name="' . SettingsPage::ANTHROPIC_CONNECTOR_MIGRATE_FIELD . '"', $html );
	}

	/**
	 * Constant-backed legacy keys show instructions and no copy action.
	 */
	public function test_connector_mode_renders_constant_instructions_without_copy_action() {
		$this->enable_connector_mode( array() );
		add_filter(
			'woocommerce_claude_difm_anthropic_key_constant_name',
			static function () {
				return SettingsPage::DIFM_API_KEY_CONSTANT;
			}
		);

		$html = $this->render_settings_html( 'get_settings_for_ai_insights_section' );

		$this->assertStringContainsString( 'ANTHROPIC_API_KEY', $html );
		$this->assertStringContainsString( 'Open Settings &gt; Connectors', $html );
		$this->assertStringNotContainsString( 'name="' . SettingsPage::ANTHROPIC_CONNECTOR_MIGRATE_FIELD . '"', $html );
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
	 * Legacy mode saves only direct-Anthropic selections.
	 */
	public function test_legacy_provider_option_is_sanitised() {
		$this->assertSame(
			DifmProviderResolver::PROVIDER_ANTHROPIC,
			$this->settings_page()->sanitize_provider_option( null, array(), DifmProviderResolver::PROVIDER_ANTHROPIC )
		);

		$this->assertSame(
			DifmProviderResolver::PROVIDER_AUTO,
			$this->settings_page()->sanitize_provider_option( null, array(), 'openai' )
		);
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
	 * Successful migration writes the native connector key and removes local keys.
	 */
	public function test_anthropic_key_migration_writes_connector_key_and_deletes_local_keys() {
		$this->enable_connector_mode( array( 'anthropic' ) );
		$this->mock_connector_setting_name();
		$this->mock_anthropic_validation_response( 200 );
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-existing', 'no' );
		update_option( SettingsPage::LEGACY_DIFM_API_KEY_OPTION, 'sk-ant-legacy', 'no' );

		$_POST = array(
			SettingsPage::ANTHROPIC_CONNECTOR_MIGRATE_FIELD => 'yes',
		);
		$this->settings_page()->migrate_anthropic_key_to_connector();

		$this->assertSame( 'sk-ant-existing', get_option( 'connectors_ai_anthropic_api_key' ) );
		$this->assertFalse( get_option( SettingsPage::DIFM_API_KEY_OPTION, false ) );
		$this->assertFalse( get_option( SettingsPage::LEGACY_DIFM_API_KEY_OPTION, false ) );
		$this->assertSame( 'anthropic', get_option( SettingsPage::DIFM_PROVIDER_OPTION ) );
	}

	/**
	 * Invalid keys are not moved or deleted.
	 */
	public function test_anthropic_key_migration_keeps_local_key_when_validation_fails() {
		$this->enable_connector_mode( array( 'anthropic' ) );
		$this->mock_connector_setting_name();
		$this->mock_anthropic_validation_response( 401 );
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-bad', 'no' );

		$_POST = array(
			SettingsPage::ANTHROPIC_CONNECTOR_MIGRATE_FIELD => 'yes',
		);
		$this->settings_page()->migrate_anthropic_key_to_connector();

		$this->assertSame( 'sk-ant-bad', get_option( SettingsPage::DIFM_API_KEY_OPTION ) );
		$this->assertFalse( get_option( 'connectors_ai_anthropic_api_key', false ) );
	}

	/**
	 * Migration does not overwrite an existing native connector key.
	 */
	public function test_anthropic_key_migration_does_not_overwrite_existing_connector_key() {
		$this->enable_connector_mode( array( 'anthropic' ) );
		$this->mock_connector_setting_name();
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-new', 'no' );
		update_option( 'connectors_ai_anthropic_api_key', 'sk-ant-native', 'no' );

		$_POST = array(
			SettingsPage::ANTHROPIC_CONNECTOR_MIGRATE_FIELD => 'yes',
		);
		$this->settings_page()->migrate_anthropic_key_to_connector();

		$this->assertSame( 'sk-ant-native', get_option( 'connectors_ai_anthropic_api_key' ) );
		$this->assertSame( 'sk-ant-new', get_option( SettingsPage::DIFM_API_KEY_OPTION ) );
	}

	/**
	 * Remove-local action deletes only the legacy database key.
	 */
	public function test_remove_local_key_action_deletes_local_key_and_keeps_connector_key() {
		$this->enable_connector_mode( array( 'anthropic' ) );
		$this->mock_connector_setting_name();
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-local', 'no' );
		update_option( SettingsPage::LEGACY_DIFM_API_KEY_OPTION, 'sk-ant-legacy', 'no' );
		update_option( 'connectors_ai_anthropic_api_key', 'sk-ant-native', 'no' );

		$_POST = array(
			SettingsPage::ANTHROPIC_CONNECTOR_REMOVE_LOCAL_FIELD => 'yes',
		);
		$this->settings_page()->migrate_anthropic_key_to_connector();

		$this->assertSame( 'sk-ant-native', get_option( 'connectors_ai_anthropic_api_key' ) );
		$this->assertFalse( get_option( SettingsPage::DIFM_API_KEY_OPTION, false ) );
		$this->assertFalse( get_option( SettingsPage::LEGACY_DIFM_API_KEY_OPTION, false ) );
		$this->assertSame( 'anthropic', get_option( SettingsPage::DIFM_PROVIDER_OPTION ) );
	}

	/**
	 * Enable WP 7 connector mode with a controlled configured-provider list.
	 *
	 * @param array<int,string> $configured_provider_ids Configured provider IDs.
	 * @return void
	 */
	private function enable_connector_mode( array $configured_provider_ids ) {
		add_filter( 'woocommerce_claude_difm_connector_mode', '__return_true' );
		add_filter( 'woocommerce_claude_difm_wordpress_ai_supported', '__return_true' );
		add_filter(
			'woocommerce_claude_difm_wordpress_ai_configured_provider_ids',
			static function () use ( $configured_provider_ids ) {
				return $configured_provider_ids;
			}
		);
	}

	/**
	 * Mock the native Anthropic connector option name.
	 *
	 * @return void
	 */
	private function mock_connector_setting_name() {
		add_filter(
			'woocommerce_claude_difm_connector_setting_name',
			static function ( $setting_name, $provider_id ) {
				return 'anthropic' === $provider_id ? 'connectors_ai_anthropic_api_key' : $setting_name;
			},
			10,
			2
		);
	}

	/**
	 * Mock Anthropic API-key validation.
	 *
	 * @param int $status_code HTTP status code.
	 * @return void
	 */
	private function mock_anthropic_validation_response( $status_code ) {
		add_filter(
			'pre_http_request',
			static function () use ( $status_code ) {
				return array(
					'response' => array(
						'code'    => $status_code,
						'message' => 200 === $status_code ? 'OK' : 'Unauthorized',
					),
					'body'     => wp_json_encode(
						array(
							'type'  => 200 === $status_code ? 'message' : 'error',
							'error' => array( 'message' => 'Invalid API key.' ),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);
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
	 * Mark Hey Woo as active in the isolated test options table.
	 *
	 * @return void
	 */
	private function mark_hey_woo_active() {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		if ( ! in_array( 'hey-woo/hey-woo.php', $active_plugins, true ) ) {
			$active_plugins[] = 'hey-woo/hey-woo.php';
		}

		update_option( 'active_plugins', $active_plugins );
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
