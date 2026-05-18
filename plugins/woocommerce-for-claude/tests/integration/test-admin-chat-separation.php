<?php
/**
 * Integration tests for keeping WP-admin chat out of WooCommerce for Claude.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Tests for the admin-chat product boundary.
 */
class Test_Admin_Chat_Separation extends WP_UnitTestCase {

	/**
	 * Reset old chat-key and menu state before each test.
	 */
	public function set_up() {
		parent::set_up();

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_userdata( $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->remove_admin_chat_submenu();
	}

	/**
	 * Reset global state after each test.
	 */
	public function tear_down() {
		delete_option( 'woocommerce_claude_anthropic_api_key' );
		$this->remove_admin_chat_submenu();

		parent::tear_down();
	}

	/**
	 * A stale Anthropic key from earlier releases must not create an admin chat menu.
	 */
	public function test_stale_chat_key_does_not_register_admin_chat_menu() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-stale', 'no' );

		$this->assertFalse( class_exists( 'WooCommerce\\Claude\\Difm\\DifmAdminPage', false ) );
		$this->assertNull( $this->admin_menu_callback_for_slug( 'woocommerce-claude-insights' ) );
		$this->assertFalse( $this->submenu_contains_slug( 'woocommerce-claude-insights' ) );
	}

	/**
	 * WooCommerce for Claude must not register the old admin-chat REST routes.
	 */
	public function test_admin_chat_rest_routes_are_not_registered() {
		update_option( 'woocommerce_claude_anthropic_api_key', 'sk-ant-stale', 'no' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayNotHasKey( '/woocommerce-claude/v1/difm/chat', $routes );
		$this->assertArrayNotHasKey( '/woocommerce-claude/v1/difm/conversations', $routes );
	}

	/**
	 * Remove the old admin-chat submenu entry from the global WP admin menu.
	 *
	 * @return void
	 */
	private function remove_admin_chat_submenu() {
		global $submenu;

		if ( ! isset( $submenu['woocommerce'] ) || ! is_array( $submenu['woocommerce'] ) ) {
			return;
		}

		$submenu['woocommerce'] = array_values(
			array_filter(
				$submenu['woocommerce'],
				static function ( $item ) {
					return ! isset( $item[2] ) || 'woocommerce-claude-insights' !== $item[2];
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
	 * Return an admin_menu callback that mentions the given menu slug.
	 *
	 * @param string $slug Menu slug.
	 * @return mixed|null Matching callback, or null when absent.
	 */
	private function admin_menu_callback_for_slug( $slug ) {
		global $wp_filter;

		if ( ! isset( $wp_filter['admin_menu'] ) || ! is_object( $wp_filter['admin_menu'] ) ) {
			return null;
		}

		foreach ( $wp_filter['admin_menu']->callbacks as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$function = isset( $callback['function'] ) ? $callback['function'] : null;
				if ( is_array( $function ) && isset( $function[0], $function[1] ) ) {
					$object_or_class = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
					$signature       = $object_or_class . '::' . (string) $function[1];
					if ( false !== strpos( $signature, $slug ) || false !== strpos( $signature, 'WooCommerce\\Claude\\Difm' ) ) {
						return $function;
					}
				}
			}
		}

		return null;
	}
}
