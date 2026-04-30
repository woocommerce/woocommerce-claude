<?php
/**
 * Dev mu-plugin: allow the WooCommerce MCP server over plain HTTP.
 *
 * The MCP endpoint requires HTTPS by default. Local wp-env development
 * runs on HTTP, so this mu-plugin allows insecure transport so you can
 * test MCP calls against the local environment without a TLS cert.
 *
 * This file is automatically mounted into wp-content/mu-plugins/ by the
 * wp-env mappings in .wp-env.json. It is dev tooling only — never deploy
 * this to a production site.
 *
 * @package HeyWoo
 */

add_filter( 'woocommerce_mcp_allow_insecure_transport', '__return_true' );
