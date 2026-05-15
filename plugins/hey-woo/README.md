# Hey Woo

Bring-your-own-key WooCommerce assistant plugin.

Hey Woo owns the WordPress-admin chat experience for merchants who provide an
Anthropic API key. It installs the shared `woocommerce/commerce-abilities`
package with Composer's path repository, boots the shared analytics abilities,
and exposes the Ask Claude screen plus its supporting REST endpoints from this
plugin.

WooCommerce for Claude still owns the external MCP product. When that plugin is
also active, Hey Woo can use its product and readiness abilities in the chat
tool bridge; otherwise the admin chat runs with the shared analytics tools.
