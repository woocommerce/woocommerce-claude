# Hey Woo

Bring-your-own-key WooCommerce assistant plugin scaffold.

This package is intentionally small for the packaging spike: it proves that a
second WordPress plugin can live in this monorepo, install the shared
`woocommerce/commerce-abilities` package with Composer's path repository, boot
the package without relying on Composer's generated runtime autoloader, and ship
as `hey-woo.zip`.

The BYOK admin experience still lives in WooCommerce for Claude until a later,
separate extraction PR moves that product logic.

