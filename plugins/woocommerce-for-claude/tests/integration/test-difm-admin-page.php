<?php
/**
 * Integration tests for the AI Insights admin menu.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\DifmAdminPage;
use WooCommerce\Claude\Settings\SettingsPage;
use WooCommerce\Claude\Setup\RestApiKey;

/**
 * Tests for conditional AI Insights navigation registration.
 */
class Test_Difm_Admin_Page extends WP_UnitTestCase {

	/**
	 * Test-only filter for the generated boot runtime requirement.
	 */
	const RUNTIME_FILTER = 'woocommerce_claude_difm_has_required_runtime';

	/**
	 * Reset API-key and menu state before each test.
	 */
	public function set_up() {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_userdata( $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		delete_option( SettingsPage::DIFM_API_KEY_OPTION );
		delete_option( 'hey_woo_anthropic_api_key' );
		( new RestApiKey() )->revoke();
		$this->remove_ai_insights_submenu();
		add_filter( self::RUNTIME_FILTER, '__return_true' );
	}

	/**
	 * Reset global state after each test.
	 */
	public function tear_down() {
		delete_option( SettingsPage::DIFM_API_KEY_OPTION );
		delete_option( 'hey_woo_anthropic_api_key' );
		( new RestApiKey() )->revoke();
		$this->remove_ai_insights_submenu();
		remove_all_filters( self::RUNTIME_FILTER );

		parent::tear_down();
	}

	/**
	 * The WooCommerce submenu is hidden until a key is configured.
	 */
	public function test_ai_insights_submenu_is_not_registered_without_api_key() {
		( new DifmAdminPage() )->add_menu_page();

		$this->assertFalse( $this->submenu_contains_slug( DifmAdminPage::MENU_SLUG ) );
	}

	/**
	 * Connecting Claude apps uses a WooCommerce REST API key, but that is not
	 * enough to make the WordPress-admin AI Insights chat usable.
	 */
	public function test_ai_insights_submenu_is_not_registered_with_only_store_connection_key() {
		$state = ( new RestApiKey() )->get_or_create();
		$this->assertIsArray( $state );

		( new DifmAdminPage() )->add_menu_page();

		$this->assertFalse( $this->submenu_contains_slug( DifmAdminPage::MENU_SLUG ) );
	}

	/**
	 * The WooCommerce submenu is registered once a key is configured.
	 */
	public function test_ai_insights_submenu_is_registered_with_api_key() {
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-test', 'no' );

		( new DifmAdminPage() )->add_menu_page();

		$this->assertTrue( $this->submenu_contains_slug( DifmAdminPage::MENU_SLUG ) );
		$this->assertSame( 'Ask Claude', $this->submenu_label_for_slug( DifmAdminPage::MENU_SLUG ) );
	}

	/**
	 * The WooCommerce submenu is hidden until the boot runtime is available.
	 */
	public function test_ai_insights_submenu_is_not_registered_without_required_runtime() {
		remove_all_filters( self::RUNTIME_FILTER );
		add_filter( self::RUNTIME_FILTER, '__return_false' );
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-test', 'no' );

		( new DifmAdminPage() )->add_menu_page();

		$this->assertFalse( $this->submenu_contains_slug( DifmAdminPage::MENU_SLUG ) );
	}

	/**
	 * A configured key with no boot runtime shows the merchant-facing requirement.
	 */
	public function test_missing_runtime_notice_is_rendered_with_api_key() {
		remove_all_filters( self::RUNTIME_FILTER );
		add_filter( self::RUNTIME_FILTER, '__return_false' );
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-test', 'no' );

		ob_start();
		( new DifmAdminPage() )->render_missing_runtime_notice();
		$notice = ob_get_clean();

		$this->assertStringContainsString( 'Ask Claude requires Gutenberg or WordPress 7.0', $notice );
	}

	/**
	 * Remove the AI Insights submenu entry from the global WP admin menu.
	 *
	 * @return void
	 */
	private function remove_ai_insights_submenu() {
		global $submenu;

		if ( ! isset( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
			return;
		}

		$submenu['woocommerce'] = array_values(
			array_filter(
				$submenu['woocommerce'],
				static function ( $item ) {
					return ! isset( $item[2] ) || DifmAdminPage::MENU_SLUG !== $item[2];
				}
			)
		);
	}

	/**
	 * Whether the WooCommerce submenu contains the given slug.
	 *
	 * @param string $slug Menu slug.
	 * @return bool
	 */
	private function submenu_contains_slug( $slug ) {
		return null !== $this->submenu_label_for_slug( $slug );
	}

	/**
	 * Return the menu label for a WooCommerce submenu slug.
	 *
	 * @param string $slug Menu slug.
	 * @return string|null Menu label, or null when the slug is absent.
	 */
	private function submenu_label_for_slug( $slug ) {
		global $submenu;

		if ( ! isset( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
			return null;
		}

		foreach ( $submenu['woocommerce'] as $item ) {
			if ( isset( $item[2] ) && $slug === $item[2] ) {
				return isset( $item[0] ) ? (string) $item[0] : '';
			}
		}

		return null;
	}
}
