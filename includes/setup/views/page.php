<?php
/**
 * Hey Woo setup view, rendered inside WC Settings → Hey Woo (default
 * section). Outputs HTML directly inside WC's outer <form id="mainform">
 * wrapper, so this view contains *no* nested <form> elements: every
 * state-changing action is a `wp_nonce_url`-protected GET link.
 *
 * Expects the following variables in scope (prepared by
 * SetupPage::render_setup_view()):
 *
 * @var bool                                                                $mcp_enabled    Whether the Woo MCP feature flag is on.
 * @var bool                                                                $site_https     Whether home_url() is https.
 * @var string                                                              $endpoint_url   Full Woo MCP endpoint URL.
 * @var string                                                              $current_client Selected client tab.
 * @var string                                                              $notice_code    Status flash from query string.
 * @var array{credential:string,key_id:int,permissions:string}|null         $key_state      Provisioned key, or null if not yet created.
 * @package HeyWoo
 */

defined( 'ABSPATH' ) || exit;

use HeyWoo\Setup\SetupPage;

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

$enable_url           = SetupPage::action_url( SetupPage::ACTION_ENABLE_MCP );
$download_url         = SetupPage::action_url( SetupPage::ACTION_DOWNLOAD );
$regen_url            = SetupPage::action_url( SetupPage::ACTION_REGEN_KEY );
$scope_read_url       = SetupPage::action_url( SetupPage::ACTION_SET_PERMS, array( 'permissions' => 'read' ) );
$scope_read_write_url = SetupPage::action_url( SetupPage::ACTION_SET_PERMS, array( 'permissions' => 'read_write' ) );
?>
<div class="hey-woo-setup">

	<p class="hey-woo-setup__intro">
		<?php esc_html_e( "Pick the easy path if you use Claude Desktop — one click and you're done. If you use a different MCP client, copy the configuration from the manual section.", 'hey-woo' ); ?>
	</p>

	<?php if ( isset( $notices[ $notice_code ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice_code ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $notices[ $notice_code ][1] ); ?></p>
		</div>
	<?php endif; ?>

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
					<a class="button button-secondary hey-woo-setup__inline-action" href="<?php echo esc_url( $enable_url ); ?>">
						<?php esc_html_e( 'Enable WooCommerce MCP integration', 'hey-woo' ); ?>
					</a>
				<?php endif; ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'API key:', 'hey-woo' ); ?></strong>
				<?php if ( null === $key_state ) : ?>
					<em><?php esc_html_e( 'Will be created automatically when you Connect.', 'hey-woo' ); ?></em>
				<?php else : ?>
					<code>Hey Woo MCP — Claude Desktop</code>
					(<?php echo esc_html( $is_read_write ? __( 'Read + Write', 'hey-woo' ) : __( 'Read', 'hey-woo' ) ); ?>)
					<a
						class="hey-woo-setup__inline-link"
						href="<?php echo esc_url( $regen_url ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Regenerate the API key? Any installed Claude Desktop bundle will stop working until you re-download and re-install it.', 'hey-woo' ) ); ?>');"
					>
						<?php esc_html_e( 'Regenerate', 'hey-woo' ); ?>
					</a>
				<?php endif; ?>
			</li>
		</ul>
	</div>

	<div class="hey-woo-setup__cards">

		<?php /* 1. Access level — decide first, deliver second. */ ?>
		<div class="hey-woo-setup__card hey-woo-setup__card--scope">
			<h2><?php esc_html_e( '1. Access level', 'hey-woo' ); ?></h2>
			<p class="hey-woo-setup__card-lede">
				<?php esc_html_e( 'What can the AI do? Default is Read — enough for analytics, readiness, and product look-ups. Switch later from this same page.', 'hey-woo' ); ?>
			</p>
			<div class="hey-woo-setup__toggle" role="group" aria-label="<?php esc_attr_e( 'API key access level', 'hey-woo' ); ?>">
				<a
					class="hey-woo-setup__toggle-option<?php echo $is_read_write ? '' : ' is-active'; ?>"
					href="<?php echo esc_url( $scope_read_url ); ?>"
					aria-pressed="<?php echo $is_read_write ? 'false' : 'true'; ?>"
				>
					<strong><?php esc_html_e( 'Read', 'hey-woo' ); ?></strong>
					<span><?php esc_html_e( '"How is my store doing?" workflows.', 'hey-woo' ); ?></span>
				</a>
				<a
					class="hey-woo-setup__toggle-option<?php echo $is_read_write ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $scope_read_write_url ); ?>"
					aria-pressed="<?php echo $is_read_write ? 'true' : 'false'; ?>"
				>
					<strong><?php esc_html_e( 'Read + Write', 'hey-woo' ); ?></strong>
					<span><?php esc_html_e( 'Also lets Claude create / edit products and orders.', 'hey-woo' ); ?></span>
				</a>
			</div>
			<?php if ( null !== $key_state ) : ?>
				<p class="hey-woo-setup__hint">
					<?php esc_html_e( 'Switching scope updates the existing key — already-installed bundles keep working with the new permission set.', 'hey-woo' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<?php /* 2. Claude Desktop — one-click bundle. */ ?>
		<div class="hey-woo-setup__card hey-woo-setup__card--quick">
			<h2><?php esc_html_e( '2. Quick Setup — Claude Desktop', 'hey-woo' ); ?></h2>
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
				<a class="button button-primary button-hero" href="<?php echo esc_url( $download_url ); ?>">
					<?php esc_html_e( 'Download Hey Woo for Claude Desktop', 'hey-woo' ); ?>
				</a>
				<p class="hey-woo-setup__warn-block">
					<?php esc_html_e( 'The bundle contains an API key for this store. Don\'t share the file. If it leaks, click Regenerate above to revoke it.', 'hey-woo' ); ?>
				</p>
			<?php else : ?>
				<p class="hey-woo-setup__blocked">
					<?php esc_html_e( 'Enable WooCommerce MCP integration above to unlock the download.', 'hey-woo' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<?php /* 3. Other clients — manual JSON / claude mcp add. */ ?>
		<div class="hey-woo-setup__card hey-woo-setup__card--manual">
			<h2><?php esc_html_e( '3. Manual Setup — other MCP clients', 'hey-woo' ); ?></h2>
			<p><?php esc_html_e( 'Pick your client and copy the configuration into its MCP settings.', 'hey-woo' ); ?></p>

			<div class="hey-woo-setup__client-picker">
				<label for="hey-woo-client-select"><?php esc_html_e( 'MCP client:', 'hey-woo' ); ?></label>
				<select id="hey-woo-client-select" data-hey-woo-client>
					<?php foreach ( $client_options as $value => $label ) : ?>
						<option
							value="<?php echo esc_attr( $value ); ?>"
							data-hey-woo-client-url="<?php echo esc_attr( SetupPage::url( array( 'client' => $value ) ) ); ?>"
							<?php selected( $current_client, $value ); ?>
						>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

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

		<?php /* 4. Success state — what to ask once it's hooked up. */ ?>
		<div class="hey-woo-setup__card hey-woo-setup__card--after">
			<h2><?php esc_html_e( '4. After setup, ask Claude', 'hey-woo' ); ?></h2>
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
