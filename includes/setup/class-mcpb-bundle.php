<?php
/**
 * Builds the .mcpb (Claude Desktop bundle) that wraps mcp-wordpress-remote
 * with this store's URL and REST API credential pre-filled.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Setup;

defined( 'ABSPATH' ) || exit;

/**
 * .mcpb bundle generator.
 *
 * The bundle ships nothing of substance — its job is purely to wrap a
 * pre-configured `npx -y @automattic/mcp-wordpress-remote@latest`
 * invocation so a double-click in Claude Desktop installs the store
 * with no copy-paste. The credential is embedded directly in
 * `mcp_config.env` (option A in the design discussion); revoke /
 * regenerate from the setup page if a bundle file leaks.
 */
class McpbBundle {

	/**
	 * MCPB manifest schema version this bundle conforms to.
	 *
	 * @see https://github.com/anthropics/mcpb/blob/main/MANIFEST.md
	 */
	const MANIFEST_VERSION = '0.3';

	/**
	 * Minimum Node.js the bundled npx invocation requires.
	 */
	const NODE_MIN_VERSION = '>=18.0.0';

	/**
	 * The fully-qualified WooCommerce for Claude MCP endpoint, e.g.
	 * https://example.com/wp-json/woocommerce-claude/mcp.
	 *
	 * @var string
	 */
	private $endpoint_url;

	/**
	 * The joined `ck_xxx:cs_xxx` credential. Split into username/password
	 * at manifest-build time and sent as Basic Auth via the proxy's
	 * native `WP_API_USERNAME` / `WP_API_PASSWORD` env vars.
	 *
	 * @var string
	 */
	private $api_credential;

	/**
	 * Plugin version, used as the bundle's version.
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Construct the bundle generator with all data baked into the manifest.
	 *
	 * @param string $endpoint_url   Full WooCommerce for Claude MCP endpoint URL.
	 * @param string $api_credential `ck_xxx:cs_xxx` joined credential.
	 * @param string $plugin_version Plugin version (e.g. '0.1.0').
	 */
	public function __construct( $endpoint_url, $api_credential, $plugin_version ) {
		$this->endpoint_url   = $endpoint_url;
		$this->api_credential = $api_credential;
		$this->plugin_version = $plugin_version;
	}

	/**
	 * Build the manifest.json contents as a PHP array.
	 *
	 * @return array
	 */
	public function manifest() {
		list( $username, $password ) = RestApiKey::split_credential( $this->api_credential );

		$host = wp_parse_url( $this->endpoint_url, PHP_URL_HOST );
		$host = is_string( $host ) ? $host : 'this store';

		return array(
			'manifest_version' => self::MANIFEST_VERSION,
			// Per-store identity so two WooCommerce for Claude stores installed in the
			// same Claude Desktop don't overwrite one another's
			// extension entries. SetupPage::server_slug() derives this
			// from the host (e.g. `woocommerce-claude-example-com`).
			'name'             => SetupPage::server_slug(),
			'version'          => $this->plugin_version,
			'description'      => sprintf(
				/* translators: %s: store hostname. */
				__( 'Connect %s to Claude Desktop via WooCommerce for Claude.', 'woocommerce-claude' ),
				$host
			),
			'author'           => array(
				'name' => 'Automattic',
				'url'  => 'https://woocommerce.com/',
			),
			'server'           => array(
				'type'        => 'node',
				'entry_point' => 'server/index.js',
				'mcp_config'  => array(
					'command' => 'npx',
					'args'    => array(
						'-y',
						// Pinned version — see SetupPage::REMOTE_PACKAGE.
						SetupPage::REMOTE_PACKAGE,
					),
					'env'     => array(
						'WP_API_URL'      => $this->endpoint_url,
						'WP_API_USERNAME' => $username,
						'WP_API_PASSWORD' => $password,
					),
				),
			),
			'compatibility'    => array(
				'runtimes' => array(
					'node' => self::NODE_MIN_VERSION,
				),
			),
		);
	}


	/**
	 * Generate the bundle and stream it to the browser as a download.
	 * Sends headers, writes the zip body, and exits the request.
	 *
	 * @param string $filename Suggested filename for the download.
	 * @return void
	 */
	public function stream( $filename = 'woocommerce-claude.mcpb' ) {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			wp_die(
				esc_html__( 'PHP ZipArchive is unavailable on this server, so the bundle cannot be generated. Use the Manual Setup option instead.', 'woocommerce-claude' ),
				esc_html__( 'Bundle unavailable', 'woocommerce-claude' ),
				array( 'response' => 500 )
			);
		}

		$manifest_json = wp_json_encode( $this->manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( false === $manifest_json ) {
			wp_die(
				esc_html__( 'Could not encode the bundle manifest.', 'woocommerce-claude' ),
				esc_html__( 'Bundle error', 'woocommerce-claude' ),
				array( 'response' => 500 )
			);
		}

		$tmp = wp_tempnam( $filename );
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $tmp, \ZipArchive::OVERWRITE | \ZipArchive::CREATE ) ) {
			wp_delete_file( $tmp );
			wp_die(
				esc_html__( 'Could not open the bundle for writing.', 'woocommerce-claude' ),
				esc_html__( 'Bundle error', 'woocommerce-claude' ),
				array( 'response' => 500 )
			);
		}

		$zip->addFromString( 'manifest.json', $manifest_json );

		// Placeholder entry point. Required by the manifest schema, but
		// never actually executed: mcp_config.command (npx) takes
		// precedence at runtime.
		$zip->addFromString(
			'server/index.js',
			"// WooCommerce for Claude MCPB placeholder — the real server is launched\n"
			. "// via mcp_config.command in manifest.json (npx fetches\n"
			. "// @automattic/mcp-wordpress-remote at install time).\n"
		);

		$zip->close();

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a binary download; WP_Filesystem returns the body in memory which defeats streaming.
		wp_delete_file( $tmp );
		exit;
	}
}
