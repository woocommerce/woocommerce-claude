<?php
/**
 * Hey Woo setup page template.
 *
 * Rendered by HeyWoo\Setup\SetupPage::render(). Expects the following
 * variables in scope:
 *
 * @var bool                                                                $mcp_enabled    Whether the Woo MCP feature flag is on.
 * @var bool                                                                $site_https     Whether home_url() is https.
 * @var string                                                              $endpoint_url   Full Woo MCP endpoint URL.
 * @var string                                                              $current_client Selected client tab ('claude-code', 'claude-desktop-manual', 'cursor', 'generic').
 * @var string                                                              $notice_code    Status flash from query string.
 * @var array{credential:string,key_id:int,permissions:string}|null         $key_state      Provisioned key, or null if not yet created.
 * @package HeyWoo
 */

defined( 'ABSPATH' ) || exit;

use HeyWoo\Setup\SetupPage;

$store_host     = wp_parse_url( home_url(), PHP_URL_HOST );
$store_host     = is_string( $store_host ) ? $store_host : '';
$credential     = $key_state['credential'] ?? '';
$permissions    = $key_state['permissions'] ?? 'read';
$is_read_write  = 'read_write' === $permissions;
$client_options = array(
	'claude-code'           => __( 'Claude Code', 'hey-woo' ),
	'claude-desktop-manual' => __( 'Claude Desktop (manual JSON)', 'hey-woo' ),
	'cursor'                => __( 'Cursor', 'hey-woo' ),
	'generic'               => __( 'Other / generic', 'hey-woo' ),
);
if ( ! isset( $client_options[ $current_client ] ) ) {
	$current_client = 'claude-code';
}

$json_snippet = wp_json_encode(
	array(
		'mcpServers' => array(
			'hey-woo' => array(
				'command' => 'npx',
				'args'    => array( '-y', '@automattic/mcp-wordpress-remote@latest' ),
				'env'     => array(
					'WP_API_URL'     => $endpoint_url,
					'CUSTOM_HEADERS' => wp_json_encode(
						array( 'X-MCP-API-Key' => '' === $credential ? 'ck_xxx:cs_xxx' : $credential ),
						JSON_UNESCAPED_SLASHES
					),
				),
			),
		),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);

$claude_code_command = sprintf(
	"claude mcp add hey-woo \\\n  --env WP_API_URL=%s \\\n  --env CUSTOM_HEADERS='%s' \\\n  -- npx -y @automattic/mcp-wordpress-remote@latest",
	$endpoint_url,
	wp_json_encode(
		array( 'X-MCP-API-Key' => '' === $credential ? 'ck_xxx:cs_xxx' : $credential ),
		JSON_UNESCAPED_SLASHES
	)
);

$notices = array(
	'mcp_enabled'         => array( 'success', __( 'WooCommerce MCP integration enabled.', 'hey-woo' ) ),
	'mcp_required'        => array( 'error', __( 'Enable WooCommerce MCP integration first.', 'hey-woo' ) ),
	'key_regenerated'     => array( 'success', __( 'API key regenerated. Re-download the bundle for Claude Desktop.', 'hey-woo' ) ),
	'key_failed'          => array( 'error', __( 'Could not provision the API key. Check the error log.', 'hey-woo' ) ),
	'permissions_updated' => array( 'success', __( 'API key scope updated.', 'hey-woo' ) ),
);
?>
<div class="wrap hey-woo-setup">
	<h1><?php esc_html_e( 'Connect Hey Woo to Claude', 'hey-woo' ); ?></h1>

	<?php if ( isset( $notices[ $notice_code ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice_code ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $notices[ $notice_code ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="hey-woo-setup__intro">
		<?php esc_html_e( "Pick the easy path if you use Claude Desktop — one click and you're done. If you use a different MCP client, copy the configuration from the manual section below.", 'hey-woo' ); ?>
	</p>

	<div class="hey-woo-setup__status">
		<h2><?php esc_html_e( 'Status', 'hey-woo' ); ?></h2>
		<ul>
			<li>
				<strong><?php esc_html_e( 'Site URL:', 'hey-woo' ); ?></strong>
				<code><?php echo esc_html( $endpoint_url ); ?></code>
				<?php if ( ! $site_https ) : ?>
					<span class="hey-woo-setup__warn">
						<?php esc_html_e( 'HTTP only — fine for local development, but Claude Desktop needs the WooCommerce MCP "allow insecure transport" filter for the connection to succeed.', 'hey-woo' ); ?>
					</span>
				<?php endif; ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'WooCommerce MCP integration:', 'hey-woo' ); ?></strong>
				<?php if ( $mcp_enabled ) : ?>
					<span class="hey-woo-setup__ok"><?php esc_html_e( 'Enabled', 'hey-woo' ); ?></span>
				<?php else : ?>
					<span class="hey-woo-setup__warn"><?php esc_html_e( 'Disabled', 'hey-woo' ); ?></span>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hey-woo-setup__inline-form">
						<?php wp_nonce_field( SetupPage::ACTION_ENABLE_MCP ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( SetupPage::ACTION_ENABLE_MCP ); ?>" />
						<button type="submit" class="button button-secondary">
							<?php esc_html_e( 'Enable WooCommerce MCP integration', 'hey-woo' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'API key:', 'hey-woo' ); ?></strong>
				<?php if ( null === $key_state ) : ?>
					<em><?php esc_html_e( 'Will be created automatically when you Connect.', 'hey-woo' ); ?></em>
				<?php else : ?>
					<code>Hey Woo MCP — Claude Desktop</code>
					(<?php echo esc_html( $is_read_write ? __( 'Read + Write', 'hey-woo' ) : __( 'Read', 'hey-woo' ) ); ?>)
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hey-woo-setup__inline-form">
						<?php wp_nonce_field( SetupPage::ACTION_REGEN_KEY ); ?>
						<input type="hidden" name="action" value="<?php echo esc_attr( SetupPage::ACTION_REGEN_KEY ); ?>" />
						<button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( 'Regenerate the API key? Any installed Claude Desktop bundle will stop working until you re-download and re-install it.', 'hey-woo' ) ); ?>');">
							<?php esc_html_e( 'Regenerate', 'hey-woo' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</li>
		</ul>
	</div>

	<div class="hey-woo-setup__cards">

		<div class="hey-woo-setup__card hey-woo-setup__card--quick">
			<h2><?php esc_html_e( '1. Quick Setup — Claude Desktop', 'hey-woo' ); ?></h2>
			<p><?php esc_html_e( 'Download a one-click bundle. Double-click the file and Claude Desktop registers Hey Woo for you.', 'hey-woo' ); ?></p>
			<p class="hey-woo-setup__prereq">
				<strong><?php esc_html_e( 'Prerequisite:', 'hey-woo' ); ?></strong>
				<?php
				printf(
					/* translators: %s: link to nodejs.org. */
					esc_html__( 'Node.js 18 or later. If you don\'t have it, install from %s.', 'hey-woo' ),
					'<a href="https://nodejs.org/" target="_blank" rel="noopener">nodejs.org</a>'
				);
				?>
			</p>

			<?php if ( $mcp_enabled ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( SetupPage::ACTION_DOWNLOAD ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( SetupPage::ACTION_DOWNLOAD ); ?>" />
					<button type="submit" class="button button-primary button-hero">
						<?php esc_html_e( 'Download Hey Woo for Claude Desktop', 'hey-woo' ); ?>
					</button>
				</form>
				<p class="hey-woo-setup__warn-block">
					<?php esc_html_e( 'The bundle contains an API key for this store. Don\'t share the file. If it leaks, click Regenerate above to revoke it.', 'hey-woo' ); ?>
				</p>
			<?php else : ?>
				<p class="hey-woo-setup__blocked">
					<?php esc_html_e( 'Enable WooCommerce MCP integration above to unlock the download.', 'hey-woo' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<div class="hey-woo-setup__card hey-woo-setup__card--manual">
			<h2><?php esc_html_e( '2. Manual Setup — other MCP clients', 'hey-woo' ); ?></h2>
			<p><?php esc_html_e( 'Pick your client and copy the configuration into its MCP settings.', 'hey-woo' ); ?></p>

			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="hey-woo-setup__client-picker">
				<input type="hidden" name="page" value="<?php echo esc_attr( SetupPage::PAGE_SLUG ); ?>" />
				<label for="hey-woo-client-select"><?php esc_html_e( 'MCP client:', 'hey-woo' ); ?></label>
				<select id="hey-woo-client-select" name="client" data-hey-woo-client>
					<?php foreach ( $client_options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_client, $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<noscript>
					<button type="submit" class="button"><?php esc_html_e( 'Update', 'hey-woo' ); ?></button>
				</noscript>
			</form>

			<?php if ( null === $key_state ) : ?>
				<p class="hey-woo-setup__blocked">
					<?php esc_html_e( 'Enable WooCommerce MCP integration above to see the configuration with your API key filled in.', 'hey-woo' ); ?>
				</p>
			<?php else : ?>

				<div class="hey-woo-setup__credential">
					<label><?php esc_html_e( 'Your API key (used in the configuration below):', 'hey-woo' ); ?></label>
					<div class="hey-woo-setup__copy-row">
						<code data-hey-woo-credential><?php echo esc_html( $credential ); ?></code>
						<button type="button" class="button hey-woo-setup__copy" data-hey-woo-copy-target="credential">
							<?php esc_html_e( 'Copy', 'hey-woo' ); ?>
						</button>
					</div>
				</div>

				<?php if ( 'claude-code' === $current_client ) : ?>
					<h3><?php esc_html_e( 'Claude Code', 'hey-woo' ); ?></h3>
					<p><?php esc_html_e( 'Run this in your terminal:', 'hey-woo' ); ?></p>
					<div class="hey-woo-setup__copy-row hey-woo-setup__copy-row--block">
						<pre data-hey-woo-cli><code><?php echo esc_html( $claude_code_command ); ?></code></pre>
						<button type="button" class="button hey-woo-setup__copy" data-hey-woo-copy-target="cli">
							<?php esc_html_e( 'Copy', 'hey-woo' ); ?>
						</button>
					</div>
				<?php else : ?>
					<h3>
						<?php
						switch ( $current_client ) {
							case 'claude-desktop-manual':
								esc_html_e( 'Claude Desktop — claude_desktop_config.json', 'hey-woo' );
								break;
							case 'cursor':
								esc_html_e( 'Cursor — ~/.cursor/mcp.json', 'hey-woo' );
								break;
							default:
								esc_html_e( 'Generic MCP configuration', 'hey-woo' );
						}
						?>
					</h3>
					<p><?php esc_html_e( 'Copy this JSON into your client\'s MCP settings file:', 'hey-woo' ); ?></p>
					<div class="hey-woo-setup__copy-row hey-woo-setup__copy-row--block">
						<pre data-hey-woo-json><code><?php echo esc_html( $json_snippet ); ?></code></pre>
						<button type="button" class="button hey-woo-setup__copy" data-hey-woo-copy-target="json">
							<?php esc_html_e( 'Copy', 'hey-woo' ); ?>
						</button>
					</div>
				<?php endif; ?>

			<?php endif; ?>
		</div>

		<div class="hey-woo-setup__card hey-woo-setup__card--scope">
			<h2><?php esc_html_e( 'API key scope', 'hey-woo' ); ?></h2>
			<p>
				<?php esc_html_e( 'Hey Woo defaults to a Read-only key, which is enough for the analytics, readiness, and product-search tools. Switch to Read + Write if you also want Claude to be able to create or edit products and orders.', 'hey-woo' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hey-woo-setup__scope-form">
				<?php wp_nonce_field( SetupPage::ACTION_SET_PERMS ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( SetupPage::ACTION_SET_PERMS ); ?>" />
				<label>
					<input type="radio" name="permissions" value="read" <?php checked( ! $is_read_write ); ?> />
					<?php esc_html_e( 'Read (recommended)', 'hey-woo' ); ?>
				</label>
				<label>
					<input type="radio" name="permissions" value="read_write" <?php checked( $is_read_write ); ?> />
					<?php esc_html_e( 'Read + Write — let Claude create and edit products / orders', 'hey-woo' ); ?>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Update scope', 'hey-woo' ); ?></button>
			</form>
		</div>

		<div class="hey-woo-setup__card hey-woo-setup__card--after">
			<h2><?php esc_html_e( 'After setup, ask Claude', 'hey-woo' ); ?></h2>
			<ul>
				<li><?php esc_html_e( '"How did my store do this week?"', 'hey-woo' ); ?></li>
				<li><?php esc_html_e( '"What\'s my AI readiness score and top recommendations?"', 'hey-woo' ); ?></li>
				<li><?php esc_html_e( '"Run a full catalog audit."', 'hey-woo' ); ?></li>
				<li><?php esc_html_e( '"Which channels are bringing in new customers?"', 'hey-woo' ); ?></li>
				<li><?php esc_html_e( '"Are my coupons working?"', 'hey-woo' ); ?></li>
			</ul>
			<p>
				<?php
				printf(
					/* translators: %s: link to README. */
					esc_html__( 'Full tool list and example questions live in the %s.', 'hey-woo' ),
					'<a href="https://github.com/woocommerce/hey-woo#readme" target="_blank" rel="noopener">README</a>'
				);
				?>
			</p>
		</div>

	</div>
</div>
