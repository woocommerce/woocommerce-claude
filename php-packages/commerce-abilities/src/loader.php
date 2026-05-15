<?php
/**
 * Shared commerce abilities loader.
 *
 * @package WooCommerce\CommerceAbilities
 */

namespace WooCommerce\CommerceAbilities;

defined( 'ABSPATH' ) || exit;

/**
 * Idempotent package loader.
 */
final class Loader {

	/**
	 * Whether the loader has already been initialised in this request.
	 *
	 * @var bool
	 */
	private static $loaded = false;

	/**
	 * Initialise the shared package once.
	 */
	public static function init() {
		if ( self::$loaded ) {
			return;
		}

		self::$loaded = true;

		self::register_hooks();
	}

	/**
	 * Register shared analytics hooks once.
	 */
	public static function register_hooks() {
		if ( ! function_exists( 'add_action' ) ) {
			return;
		}

		self::add_action_once( 'wp_abilities_api_categories_init', array( Abilities\AnalyticsBootstrap::class, 'register_category' ) );
		self::add_action_once( 'wp_abilities_api_init', array( Abilities\AnalyticsBootstrap::class, 'register_abilities' ) );
	}

	/**
	 * Add a WordPress action only when the exact callback is not registered.
	 *
	 * @param string $hook_name Hook name.
	 * @param array  $callback  Static method callback.
	 */
	private static function add_action_once( $hook_name, $callback ) {
		if ( function_exists( 'has_action' ) && false !== has_action( $hook_name, $callback ) ) {
			return;
		}

		add_action( $hook_name, $callback );
	}
}
