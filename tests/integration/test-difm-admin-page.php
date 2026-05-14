<?php
/**
 * Integration tests for the AI Insights admin menu.
 *
 * @package WooCommerce\Claude\Tests
 */

use WooCommerce\Claude\Difm\DifmAdminPage;
use WooCommerce\Claude\Settings\SettingsPage;

/**
 * Tests for conditional AI Insights navigation registration.
 */
class Test_Difm_Admin_Page extends WP_UnitTestCase {

	/**
	 * Reset API-key and menu state before each test.
	 */
	public function set_up() {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		delete_option( SettingsPage::DIFM_API_KEY_OPTION );
		delete_option( SettingsPage::LEGACY_DIFM_API_KEY_OPTION );
		$this->remove_ai_insights_submenu();
	}

	/**
	 * Reset global state after each test.
	 */
	public function tear_down() {
		delete_option( SettingsPage::DIFM_API_KEY_OPTION );
		delete_option( SettingsPage::LEGACY_DIFM_API_KEY_OPTION );
		$this->remove_ai_insights_submenu();

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
	 * The WooCommerce submenu is registered once a key is configured.
	 */
	public function test_ai_insights_submenu_is_registered_with_api_key() {
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-test', 'no' );

		( new DifmAdminPage() )->add_menu_page();

		$this->assertTrue( $this->submenu_contains_slug( DifmAdminPage::MENU_SLUG ) );
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
		global $submenu;

		if ( ! isset( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
			return false;
		}

		foreach ( $submenu['woocommerce'] as $item ) {
			if ( isset( $item[2] ) && $slug === $item[2] ) {
				return true;
			}
		}

		return false;
	}
}
