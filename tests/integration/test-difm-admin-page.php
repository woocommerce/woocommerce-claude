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
		$this->reset_boot_menu_items();
	}

	/**
	 * Reset global state after each test.
	 */
	public function tear_down() {
		delete_option( SettingsPage::DIFM_API_KEY_OPTION );
		delete_option( SettingsPage::LEGACY_DIFM_API_KEY_OPTION );
		$this->remove_ai_insights_submenu();
		$this->reset_boot_menu_items();

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
	 * The boot sidebar menu item follows the same no-key gate.
	 */
	public function test_boot_sidebar_menu_item_is_not_registered_without_api_key() {
		$this->admin_page_with_boot_menu_capture()->on_init();

		$this->assertFalse( $this->boot_menu_contains_id( 'ai-insights' ) );
	}

	/**
	 * The boot sidebar menu item appears once a key is configured.
	 */
	public function test_boot_sidebar_menu_item_is_registered_with_api_key() {
		update_option( SettingsPage::DIFM_API_KEY_OPTION, 'sk-ant-test', 'no' );

		$this->admin_page_with_boot_menu_capture()->on_init();

		$this->assertTrue( $this->boot_menu_contains_id( 'ai-insights' ) );
	}

	/**
	 * Return an admin page test double that records boot menu registration.
	 *
	 * @return DifmAdminPage
	 */
	private function admin_page_with_boot_menu_capture() {
		return new class() extends DifmAdminPage {

			/**
			 * Record the boot menu item registration.
			 *
			 * @return void
			 */
			protected function register_boot_menu_item() {
				global $wcai_woocommerce_claude_insights_menu_items;

				$wcai_woocommerce_claude_insights_menu_items[] = array(
					'id'    => 'ai-insights',
					'label' => 'AI Insights',
					'to'    => '/',
				);
			}
		};
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

	/**
	 * Reset the generated boot menu registry for this page.
	 *
	 * @return void
	 */
	private function reset_boot_menu_items() {
		global $wcai_woocommerce_claude_insights_menu_items;

		$wcai_woocommerce_claude_insights_menu_items = array();
	}

	/**
	 * Whether the generated boot menu registry contains an item ID.
	 *
	 * @param string $id Menu item ID.
	 * @return bool
	 */
	private function boot_menu_contains_id( $id ) {
		global $wcai_woocommerce_claude_insights_menu_items;

		if ( ! is_array( $wcai_woocommerce_claude_insights_menu_items ) ) {
			return false;
		}

		foreach ( $wcai_woocommerce_claude_insights_menu_items as $item ) {
			if ( isset( $item['id'] ) && $id === $item['id'] ) {
				return true;
			}
		}

		return false;
	}
}
