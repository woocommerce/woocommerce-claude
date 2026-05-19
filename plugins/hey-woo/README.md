# Hey Woo

Bring-your-own-key WooCommerce assistant plugin.

Hey Woo owns the WordPress-admin chat experience for merchants who provide an
Anthropic API key. It installs the shared `woocommerce/commerce-abilities`
package with Composer's path repository, boots the shared analytics abilities,
and exposes the New chat screen plus its supporting REST endpoints from this
plugin. It also registers its own `hey-woo/*` store, product, catalogue, and
readiness wrappers, backed by the shared commerce-abilities implementation, so
the admin chat works without WooCommerce for Claude.

WooCommerce for Claude still owns the external MCP product. When that plugin is
also active, the two plugins run side by side but Hey Woo does not depend on its
ability namespace.

## Releases

Hey Woo is released from this monorepo. Source changes live under
`plugins/hey-woo/`; the root `pnpm run hey-woo-plugin-zip` command builds
`hey-woo.zip`, and the **Release Hey Woo** workflow publishes it with a
`hey-woo-v<version>` tag.
