<?php
/**
 * Uninstall handler.
 *
 * Runs when the plugin is deleted from the WP admin. Revokes the
 * auto-created WooCommerce REST API key (if any) and clears the options
 * and transients owned by the setup flow. Other plugin options
 * (telemetry preference, etc.) are intentionally left in place — they
 * survive a deactivate-and-reinstall cycle.
 *
 * @package HeyWoo
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$key_id = (int) get_option( 'hey_woo_setup_api_key_id', 0 );
if ( $key_id > 0 ) {
	$table = $wpdb->prefix . 'woocommerce_api_keys';
	// Skip the delete if the WC table is gone (Woo deactivated and uninstalled
	// before us). Comparing against $wpdb->get_var() to avoid a fatal on
	// missing table.
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-shot uninstall, no caching surface.
	if ( $exists === $table ) {
		$wpdb->delete( $table, array( 'key_id' => $key_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- targeted single-row revocation on uninstall.
	}
}

delete_option( 'hey_woo_setup_api_credential' );
delete_option( 'hey_woo_setup_api_key_id' );

// Legacy: earlier versions of the plugin set this transient on
// activation to drive a post-activation admin notice. The notice has
// been removed but the transient may still be present from upgrades.
delete_transient( 'hey_woo_show_setup_notice' );
