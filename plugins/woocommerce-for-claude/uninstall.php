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
 * @package WooCommerce\Claude
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$table = $wpdb->prefix . 'woocommerce_api_keys';

// Skip the delete if the WC table is gone (Woo deactivated and uninstalled
// before us). Comparing against $wpdb->get_var() to avoid a fatal on a
// missing table.
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-shot uninstall, no caching surface.
if ( $exists === $table ) {
	// Description-scoped delete catches the tracked key plus any
	// orphans (e.g. from a lost-race concurrent insert in an earlier
	// version, or from a user manually editing the options).
	$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- description-scoped revocation on uninstall.
		$table,
		array( 'description' => 'WooCommerce for Claude — Claude Desktop' ),
		array( '%s' )
	);
}

delete_option( 'woocommerce_claude_setup_api_credential' );
delete_option( 'woocommerce_claude_setup_api_key_id' );

// Legacy: earlier versions of the plugin set this transient on
// activation to drive a post-activation admin notice. The notice has
// been removed but the transient may still be present from upgrades.
delete_transient( 'woocommerce_claude_show_setup_notice' );
