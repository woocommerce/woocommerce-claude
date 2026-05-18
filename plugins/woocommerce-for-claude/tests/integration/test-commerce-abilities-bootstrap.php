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
	 * Hey Woo should work from a monorepo checkout even before Composer has
	 * populated plugins/hey-woo/vendor.
	 */
	public function test_hey_woo_loads_shared_package_from_monorepo_source_without_vendor() {
		$repo_root          = dirname( __DIR__, 4 );
		$plugin_file        = $repo_root . '/plugins/hey-woo/hey-woo.php';
		$package_source_dir = $repo_root . '/php-packages/commerce-abilities/src';
		$temp_root          = trailingslashit( sys_get_temp_dir() ) . 'hey-woo-commerce-abilities-' . uniqid();
		$fake_plugin_dir    = $temp_root . '/plugins/hey-woo/';
		$package_parent_dir = $temp_root . '/php-packages/commerce-abilities';
		$package_link       = $package_parent_dir . '/src';

		wp_mkdir_p( $fake_plugin_dir );
		wp_mkdir_p( $package_parent_dir );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.symlink_symlink -- Local temporary probe symlink points at the monorepo package source.
		if ( ! symlink( $package_source_dir, $package_link ) ) {
			$this->remove_hey_woo_probe_tree( $temp_root, $package_link );
			$this->markTestSkipped( 'Could not create temporary commerce-abilities source symlink.' );
		}

		$script     = $this->build_hey_woo_source_fallback_probe( $plugin_file, $fake_plugin_dir );
		$probe_file = tempnam( sys_get_temp_dir(), 'hey-woo-commerce-abilities-bootstrap-' );

		file_put_contents( $probe_file, $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local temporary probe script for an isolated PHP process.

		$output = array();
		$status = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated PHP process exercises plugin bootstrap without a vendored package directory.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probe_file ), $output, $status );

		unlink( $probe_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove local temporary probe script.
		$this->remove_hey_woo_probe_tree( $temp_root, $package_link );

		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}

	/**
	 * Hey Woo should register its own store, catalogue, product, and readiness
	 * abilities so the admin chat does not depend on WooCommerce for Claude.
	 */
	public function test_hey_woo_registers_own_store_and_readiness_abilities() {
		$repo_root   = dirname( __DIR__, 4 );
		$plugin_file = $repo_root . '/plugins/hey-woo/hey-woo.php';
		$script      = $this->build_hey_woo_ability_registration_probe( $plugin_file );
		$probe_file  = tempnam( sys_get_temp_dir(), 'hey-woo-ability-registration-' );

		file_put_contents( $probe_file, $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local temporary probe script for an isolated PHP process.

		$output = array();
		$status = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Isolated PHP process exercises Hey Woo ability registration without loading WooCommerce for Claude.
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $probe_file ), $output, $status );

		unlink( $probe_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove local temporary probe script.

		$this->assertSame( 0, $status, implode( "\n", $output ) );
	}

	/**
	 * WooCommerce for Claude should keep its public ability IDs while sharing
	 * store/readiness implementation with Hey Woo through commerce-abilities.
	 */
	public function test_woocommerce_claude_store_wrappers_use_shared_implementation() {
		$this->assertContains(
			\WooCommerce\CommerceAbilities\Abilities\Store\GetReadinessScoreAbilityTrait::class,
			class_uses( \WooCommerce\Claude\Abilities\GetReadinessScoreAbility::class ),
			'WooCommerce for Claude readiness ability should use the shared implementation trait.'
		);

		$this->assertContains(
			\WooCommerce\CommerceAbilities\Abilities\Store\SuggestImprovementsAbilityTrait::class,
			class_uses( \WooCommerce\Claude\Abilities\SuggestImprovementsAbility::class ),
			'WooCommerce for Claude improvement ability should use the shared implementation trait.'
		);

		$this->assertTrue(
			is_subclass_of( \WooCommerce\Claude\API\ReadinessController::class, \WooCommerce\CommerceAbilities\API\AbstractReadinessController::class ),
			'WooCommerce for Claude readiness REST controller should wrap the shared controller base.'
		);

		$this->assertTrue(
			is_subclass_of( \WooCommerce\Claude\Scoring\ScoringEngine::class, \WooCommerce\CommerceAbilities\Scoring\ScoringEngine::class ),
			'WooCommerce for Claude scoring engine should wrap the shared scoring engine.'
		);
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

	if ( ! class_exists( "WooCommerce\\\\CommerceAbilities\\\\Store\\\\StoreKnowledge" ) ) {
		fwrite( STDERR, "store knowledge service was not autoloadable\n" );
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

	/**
	 * Build an isolated PHP script that proves Hey Woo can load the shared
	 * package from the monorepo source path when its own vendor directory is
	 * absent.
	 *
	 * @param string $plugin_file     Absolute Hey Woo bootstrap path.
	 * @param string $fake_plugin_dir Fake plugin directory with no vendor copy.
	 * @return string
	 */
	private function build_hey_woo_source_fallback_probe( $plugin_file, $fake_plugin_dir ) {
		$plugin_file_literal     = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $plugin_file ) . "'";
		$fake_plugin_dir_literal = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $fake_plugin_dir ) . "'";

		return '<?php
namespace {
	define( "ABSPATH", __DIR__ . "/" );
	$GLOBALS["wc_probe_hooks"] = array();
	$GLOBALS["hey_woo_probe_plugin_dir"] = ' . $fake_plugin_dir_literal . ';

	function plugin_dir_path( $file ) {
		return $GLOBALS["hey_woo_probe_plugin_dir"];
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

	function add_filter( $hook_name, $callback ) {
		add_action( $hook_name, $callback );
	}

	require ' . $plugin_file_literal . ';

	if ( ! class_exists( "WooCommerce\\\\CommerceAbilities\\\\Loader" ) ) {
		fwrite( STDERR, "commerce-abilities loader did not load from source fallback\n" );
		exit( 1 );
	}

	if ( ! class_exists( "WooCommerce\\\\CommerceAbilities\\\\Abilities\\\\AnalyticsBootstrap" ) ) {
		fwrite( STDERR, "analytics bootstrap did not load from source fallback\n" );
		exit( 1 );
	}

	if ( ! class_exists( "WooCommerce\\\\CommerceAbilities\\\\Store\\\\StoreKnowledge" ) ) {
		fwrite( STDERR, "store knowledge service did not load from source fallback\n" );
		exit( 1 );
	}

	if ( ! trait_exists( "WooCommerce\\\\CommerceAbilities\\\\Abilities\\\\Store\\\\GetReadinessScoreAbilityTrait" ) ) {
		fwrite( STDERR, "readiness score trait did not load from source fallback\n" );
		exit( 1 );
	}

	hey_woo_init_commerce_abilities();

	$analytics_callback = array( "WooCommerce\\\\CommerceAbilities\\\\Abilities\\\\AnalyticsBootstrap", "register_abilities" );
	if ( false === has_action( "wp_abilities_api_init", $analytics_callback ) ) {
		fwrite( STDERR, "Hey Woo did not register analytics hooks from source fallback\n" );
		exit( 1 );
	}

	exit( 0 );
}
';
	}

	/**
	 * Build an isolated PHP script that proves Hey Woo registers its own
	 * non-analytics ability IDs.
	 *
	 * @param string $plugin_file Absolute Hey Woo bootstrap path.
	 * @return string
	 */
	private function build_hey_woo_ability_registration_probe( $plugin_file ) {
		$plugin_file_literal = "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $plugin_file ) . "'";

		return '<?php
namespace {
	define( "ABSPATH", __DIR__ . "/" );
	$GLOBALS["wc_probe_hooks"] = array();
	$GLOBALS["wc_probe_abilities"] = array();
	$GLOBALS["wc_probe_categories"] = array();

	function plugin_dir_path( $file ) {
		return dirname( $file ) . "/";
	}

	function __( $text, $domain = null ) {
		unset( $domain );
		return $text;
	}

	function add_action( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $priority, $accepted_args );
		$GLOBALS["wc_probe_hooks"][ $hook_name ][] = $callback;
	}

	function add_filter( $hook_name, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $priority, $accepted_args );
		add_action( $hook_name, $callback );
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

	function do_action( $hook_name = "", ...$args ) {
		unset( $hook_name, $args );
	}

	function wp_register_ability_category( $slug, $args ) {
		$GLOBALS["wc_probe_categories"][ $slug ] = $args;
	}

	function wp_register_ability( $name, $args ) {
		$GLOBALS["wc_probe_abilities"][ $name ] = $args;
	}

	require ' . $plugin_file_literal . ';

	hey_woo_load_runtime_files();
	hey_woo_register_providers();
	\WooCommerce\HeyWoo\Abilities\AbilitiesBootstrap::register_category();
	\WooCommerce\HeyWoo\Abilities\AbilitiesBootstrap::register_abilities();

	$expected = array(
		"hey-woo/get-store-profile",
		"hey-woo/search-products",
		"hey-woo/get-product-details",
		"hey-woo/get-readiness-score",
		"hey-woo/get-recommendations",
		"hey-woo/suggest-improvements",
	);

	foreach ( $expected as $ability_id ) {
		if ( ! isset( $GLOBALS["wc_probe_abilities"][ $ability_id ] ) ) {
			fwrite( STDERR, "Hey Woo did not register " . $ability_id . "\n" );
			exit( 1 );
		}
	}

	$traits = class_uses( "WooCommerce\\\\HeyWoo\\\\Abilities\\\\GetReadinessScoreAbility" );
	if ( ! in_array( "WooCommerce\\\\CommerceAbilities\\\\Abilities\\\\Store\\\\GetReadinessScoreAbilityTrait", $traits, true ) ) {
		fwrite( STDERR, "Hey Woo readiness ability is not using the shared implementation trait\n" );
		exit( 1 );
	}

	$traits = class_uses( "WooCommerce\\\\HeyWoo\\\\Abilities\\\\SuggestImprovementsAbility" );
	if ( ! in_array( "WooCommerce\\\\CommerceAbilities\\\\Abilities\\\\Store\\\\SuggestImprovementsAbilityTrait", $traits, true ) ) {
		fwrite( STDERR, "Hey Woo improvement ability is not using the shared implementation trait\n" );
		exit( 1 );
	}

	if ( ! is_subclass_of( "WooCommerce\\\\HeyWoo\\\\API\\\\ReadinessController", "WooCommerce\\\\CommerceAbilities\\\\API\\\\AbstractReadinessController" ) ) {
		fwrite( STDERR, "Hey Woo readiness controller is not wrapping the shared controller base\n" );
		exit( 1 );
	}

	if ( ! isset( $GLOBALS["wc_probe_categories"]["hey-woo"] ) ) {
		fwrite( STDERR, "Hey Woo did not register its ability category\n" );
		exit( 1 );
	}

	$map = \WooCommerce\HeyWoo\Difm\DifmRestController::get_tool_ability_map();
	foreach ( array_slice( $expected, 0, 6 ) as $ability_id ) {
		if ( ! in_array( $ability_id, $map, true ) ) {
			fwrite( STDERR, "DIFM tool map does not use " . $ability_id . "\n" );
			exit( 1 );
		}
	}

	exit( 0 );
}
';
	}

	/**
	 * Remove the temporary fake Hey Woo checkout used by the isolated probe.
	 *
	 * @param string $temp_root    Temporary root directory.
	 * @param string $package_link Temporary package source symlink.
	 */
	private function remove_hey_woo_probe_tree( $temp_root, $package_link ) {
		if ( is_link( $package_link ) ) {
			unlink( $package_link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove local temporary probe symlink.
		}

		$directories = array(
			$temp_root . '/php-packages/commerce-abilities',
			$temp_root . '/php-packages',
			$temp_root . '/plugins/hey-woo',
			$temp_root . '/plugins',
			$temp_root,
		);

		foreach ( $directories as $directory ) {
			if ( is_dir( $directory ) ) {
				rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove local temporary probe directory.
			}
		}
	}
}
