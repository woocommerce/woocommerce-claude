<?php
/**
 * Integration tests — commerce-abilities bootstrap edge cases.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Tests for shared package bootstrap safety.
 */
class Test_Commerce_Abilities_Bootstrap extends WP_UnitTestCase {

	/**
	 * The plugin should not register analytics hooks at file load time, and it
	 * should still register the moved analytics hooks when an older no-op shared
	 * package loader class was loaded first.
	 */
	public function test_shared_analytics_hooks_wait_for_explicit_initialisation_and_survive_old_loader() {
		$plugin_file = dirname( __DIR__, 2 ) . '/woocommerce-claude.php';
		$script      = $this->build_bootstrap_probe( $plugin_file );
		$probe_file  = tempnam( sys_get_temp_dir(), 'wc-commerce-abilities-bootstrap-' );

		file_put_contents( $probe_file, $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local temporary probe script for an isolated PHP process.

		$output = array();
		$status = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated PHP process exercises plugin bootstrap before WordPress/WooCommerce are loaded.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probe_file ), $output, $status );

		unlink( $probe_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove local temporary probe script.

		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}

	/**
	 * Build an isolated PHP script that stubs enough WordPress functions to load
	 * the plugin file without booting WordPress.
	 *
	 * @param string $plugin_file Absolute plugin bootstrap path.
	 * @return string
	 */
	private function build_bootstrap_probe( $plugin_file ) {
		$plugin_file_literal = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $plugin_file ) . "'";

		return '<?php
namespace WooCommerce\CommerceAbilities {
	final class Loader {
		public static function init() {}
	}
}

namespace {
	define( "ABSPATH", __DIR__ . "/" );
	$GLOBALS["wc_probe_hooks"] = array();

	function plugin_dir_path( $file ) {
		return dirname( $file ) . "/";
	}

	function add_action( $hook_name, $callback ) {
		$GLOBALS["wc_probe_hooks"][ $hook_name ][] = $callback;
	}

	function has_action( $hook_name, $callback = false ) {
		if ( empty( $GLOBALS["wc_probe_hooks"][ $hook_name ] ) ) {
			return false;
		}
		if ( false === $callback ) {
			return true;
		}
		foreach ( $GLOBALS["wc_probe_hooks"][ $hook_name ] as $index => $registered ) {
			if ( $registered === $callback ) {
				return $index + 1;
			}
		}
		return false;
	}

	function register_activation_hook() {}
	function register_deactivation_hook() {}
	function get_option() {
		return false;
	}
	function update_option() {}

	require ' . $plugin_file_literal . ';

	$analytics_callback = array( "WooCommerce\\\\CommerceAbilities\\\\Abilities\\\\AnalyticsBootstrap", "register_abilities" );

	if ( false !== has_action( "wp_abilities_api_init", $analytics_callback ) ) {
		fwrite( STDERR, "analytics hooks registered during plugin file load\n" );
		exit( 1 );
	}

	woocommerce_claude_init_commerce_abilities();

	if ( ! class_exists( "WooCommerce\\\\CommerceAbilities\\\\Abilities\\\\AnalyticsBootstrap" ) ) {
		fwrite( STDERR, "analytics bootstrap class was not autoloadable\n" );
		exit( 1 );
	}

	if ( false === has_action( "wp_abilities_api_init", $analytics_callback ) ) {
		fwrite( STDERR, "analytics hooks were not registered after explicit initialisation\n" );
		exit( 1 );
	}

	exit( 0 );
}
';
	}
}
