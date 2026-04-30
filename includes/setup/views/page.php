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
 * @var bool                                                                $mcp_enabled  Whether the Woo MCP feature flag is on.
 * @var bool                                                                $site_https   Whether home_url() is https.
 * @var string                                                              $endpoint_url Full Woo MCP endpoint URL.
 * @var string                                                              $notice_code  Status flash from query string.
 * @var array{credential:string,key_id:int,permissions:string}|null         $key_state    Provisioned key, or null if not yet created.
 * @package HeyWoo
 */

defined( 'ABSPATH' ) || exit;

use HeyWoo\Setup\SetupPage;

$credential    = $key_state['credential'] ?? '';
$permissions   = $key_state['permissions'] ?? 'read';
$is_read_write = 'read_write' === $permissions;

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
	'key_regenerated'     => array( 'success', __( 'API key regenerated. Re-download the MCPB file for Claude Desktop.', 'hey-woo' ) ),
	'key_failed'          => array( 'error', __( 'Could not provision the API key. Check the error log.', 'hey-woo' ) ),
	'permissions_updated' => array( 'success', __( 'API key access level updated.', 'hey-woo' ) ),
);

$enable_url           = SetupPage::action_url( SetupPage::ACTION_ENABLE_MCP );
$download_url         = SetupPage::action_url( SetupPage::ACTION_DOWNLOAD );
$regen_url            = SetupPage::action_url( SetupPage::ACTION_REGEN_KEY );
$scope_read_url       = SetupPage::action_url( SetupPage::ACTION_SET_PERMS, array( 'permissions' => 'read' ) );
$scope_read_write_url = SetupPage::action_url( SetupPage::ACTION_SET_PERMS, array( 'permissions' => 'read_write' ) );
?>
<div class="hey-woo-setup">

	<?php if ( isset( $notices[ $notice_code ] ) ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notices[ $notice_code ][0] ); ?> is-dismissible">
			<p><?php echo esc_html( $notices[ $notice_code ][1] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $mcp_enabled ) : ?>
		<div class="hey-woo-setup__topbar hey-woo-setup__topbar--warn">
			<div>
				<strong><?php esc_html_e( 'WooCommerce MCP integration is off.', 'hey-woo' ); ?></strong>
				<span><?php esc_html_e( 'Hey Woo needs it on to expose tools at /wp-json/woocommerce/mcp. Turn it on to continue.', 'hey-woo' ); ?></span>
			</div>
			<a class="button button-primary" href="<?php echo esc_url( $enable_url ); ?>">
				<?php esc_html_e( 'Enable WooCommerce MCP integration', 'hey-woo' ); ?>
			</a>
		</div>
	<?php endif; ?>

	<?php if ( ! $site_https ) : ?>
		<div class="hey-woo-setup__topbar hey-woo-setup__topbar--info">
			<div>
				<strong><?php esc_html_e( 'This store is on HTTP.', 'hey-woo' ); ?></strong>
				<span><?php esc_html_e( 'WooCommerce MCP requires HTTPS by default. Local-dev sites can opt in via the woocommerce_mcp_allow_insecure_transport filter.', 'hey-woo' ); ?></span>
			</div>
		</div>
	<?php endif; ?>

	<?php /* Section 1 — Grant access (scope). */ ?>
	<section class="hey-woo-setup__row">
		<header class="hey-woo-setup__row-label">
			<h2><?php esc_html_e( 'Grant access', 'hey-woo' ); ?></h2>
			<p>
				<?php esc_html_e( 'Hey Woo creates a single WooCommerce REST API key for Claude. Choose how much it can do — you can change this later from this page, and switching access level updates the existing key in place (no re-install needed).', 'hey-woo' ); ?>
			</p>
		</header>
		<div class="hey-woo-setup__row-content">
			<div class="hey-woo-setup__card">
				<h3><?php esc_html_e( 'Generate an API key and choose permissions', 'hey-woo' ); ?></h3>

				<a
					class="hey-woo-setup__radio-card<?php echo $is_read_write ? '' : ' is-active'; ?>"
					href="<?php echo esc_url( $scope_read_url ); ?>"
					aria-pressed="<?php echo $is_read_write ? 'false' : 'true'; ?>"
				>
					<span class="hey-woo-setup__radio-indicator" aria-hidden="true"></span>
					<span class="hey-woo-setup__radio-body">
						<span class="hey-woo-setup__radio-title">
							<strong><?php esc_html_e( 'Read only', 'hey-woo' ); ?></strong>
							<span class="hey-woo-setup__badge"><?php esc_html_e( 'Recommended', 'hey-woo' ); ?></span>
						</span>
						<span class="hey-woo-setup__radio-desc">
							<?php esc_html_e( "View store data, run analytics, and get readiness recommendations. Claude can answer questions but can't change anything in your store.", 'hey-woo' ); ?>
						</span>
					</span>
				</a>

				<a
					class="hey-woo-setup__radio-card<?php echo $is_read_write ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $scope_read_write_url ); ?>"
					aria-pressed="<?php echo $is_read_write ? 'true' : 'false'; ?>"
				>
					<span class="hey-woo-setup__radio-indicator" aria-hidden="true"></span>
					<span class="hey-woo-setup__radio-body">
						<span class="hey-woo-setup__radio-title">
							<strong><?php esc_html_e( 'Read + Write', 'hey-woo' ); ?></strong>
							<span class="hey-woo-setup__badge hey-woo-setup__badge--alt"><?php esc_html_e( 'Expanded', 'hey-woo' ); ?></span>
						</span>
						<span class="hey-woo-setup__radio-desc">
							<?php esc_html_e( 'Everything in Read, plus letting Claude create or edit products and orders.', 'hey-woo' ); ?>
						</span>
					</span>
				</a>

				<?php if ( null !== $key_state ) : ?>
					<p class="hey-woo-setup__keymeta">
						<?php
						printf(
							/* translators: 1: key description label, 2: current scope. */
							esc_html__( 'API key: %1$s (%2$s)', 'hey-woo' ),
							'<code>' . esc_html__( 'Hey Woo MCP — Claude Desktop', 'hey-woo' ) . '</code>',
							esc_html( $is_read_write ? __( 'Read + Write', 'hey-woo' ) : __( 'Read', 'hey-woo' ) )
						);
						?>
						<span aria-hidden="true">·</span>
						<a
							href="<?php echo esc_url( $regen_url ); ?>"
							onclick="return confirm('<?php echo esc_js( __( 'Regenerate the API key? Any installed Claude Desktop bundle will stop working until you re-download and re-install it.', 'hey-woo' ) ); ?>');"
						>
							<?php esc_html_e( 'Regenerate', 'hey-woo' ); ?>
						</a>
					</p>
				<?php else : ?>
					<p class="hey-woo-setup__keymeta">
						<em><?php esc_html_e( 'The API key is provisioned automatically when you first download a bundle or copy a snippet below.', 'hey-woo' ); ?></em>
					</p>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<?php /* Section 2 — Setup (Claude only for v1). */ ?>
	<section class="hey-woo-setup__row">
		<header class="hey-woo-setup__row-label">
			<h2><?php esc_html_e( 'Setup', 'hey-woo' ); ?></h2>
			<p>
				<?php esc_html_e( "Pick the easy path if you use Claude Desktop. The manual snippets work for Claude Code today; we'll add more clients in future versions.", 'hey-woo' ); ?>
			</p>
		</header>
		<div class="hey-woo-setup__row-content">
			<div class="hey-woo-setup__card hey-woo-setup__setup-card">
				<h3><?php esc_html_e( 'Setup (Claude only)', 'hey-woo' ); ?></h3>

				<?php /* Quick setup — Claude Desktop. */ ?>
				<div class="hey-woo-setup__panel">
					<h4><?php esc_html_e( 'Quick setup — Claude Desktop', 'hey-woo' ); ?></h4>
					<ol class="hey-woo-setup__steps">
						<li>
							<?php
							printf(
								/* translators: %s: link to Claude Desktop download page. */
								esc_html__( 'Install Claude Desktop if you don\'t already have it (free at %s).', 'hey-woo' ),
								'<a href="https://claude.ai/download" target="_blank" rel="noopener">claude.ai/download</a>'
							);
							?>
						</li>
						<li><?php esc_html_e( 'Click Download MCPB file below.', 'hey-woo' ); ?></li>
						<li><?php esc_html_e( 'Double-click the downloaded file. Claude Desktop registers Hey Woo automatically.', 'hey-woo' ); ?></li>
					</ol>

					<?php if ( $mcp_enabled ) : ?>
						<a class="button button-primary button-hero" href="<?php echo esc_url( $download_url ); ?>">
							<?php esc_html_e( 'Download MCPB file', 'hey-woo' ); ?>
						</a>
						<p class="hey-woo-setup__prereq">
							<strong><?php esc_html_e( 'Prerequisite:', 'hey-woo' ); ?></strong>
							<?php
							printf(
								/* translators: %s: link to nodejs.org. */
								esc_html__( 'Node.js 18 or later. Install from %s if you don\'t have it.', 'hey-woo' ),
								'<a href="https://nodejs.org/" target="_blank" rel="noopener">nodejs.org</a>'
							);
							?>
						</p>
						<p class="hey-woo-setup__warn-block">
							<?php esc_html_e( 'The MCPB file contains an API key for this store. Don\'t share it. If it leaks, click Regenerate above to revoke instantly.', 'hey-woo' ); ?>
						</p>
					<?php else : ?>
						<p class="hey-woo-setup__blocked">
							<?php esc_html_e( 'Enable WooCommerce MCP integration in the banner above to unlock the download.', 'hey-woo' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<div class="hey-woo-setup__divider" aria-hidden="true"><span><?php esc_html_e( 'OR', 'hey-woo' ); ?></span></div>

				<?php /* Manual setup — Claude Code one-liner + Claude Desktop JSON. */ ?>
				<div class="hey-woo-setup__panel">
					<h4><?php esc_html_e( 'Manual setup', 'hey-woo' ); ?></h4>

					<?php if ( null === $key_state ) : ?>
						<p class="hey-woo-setup__blocked">
							<?php esc_html_e( 'Enable WooCommerce MCP integration above to see the configuration with your API key filled in.', 'hey-woo' ); ?>
						</p>
					<?php else : ?>

						<div class="hey-woo-setup__credential">
							<label><?php esc_html_e( 'Your API key (used in the configurations below):', 'hey-woo' ); ?></label>
							<div class="hey-woo-setup__copy-row">
								<code data-hey-woo-credential><?php echo esc_html( $credential ); ?></code>
								<button type="button" class="button hey-woo-setup__copy" data-hey-woo-copy-target="credential">
									<?php esc_html_e( 'Copy', 'hey-woo' ); ?>
								</button>
							</div>
						</div>

						<h5><?php esc_html_e( 'Claude Code — one command:', 'hey-woo' ); ?></h5>
						<div class="hey-woo-setup__copy-row hey-woo-setup__copy-row--block">
							<pre data-hey-woo-cli><code><?php echo esc_html( $claude_code_command ); ?></code></pre>
							<button type="button" class="button hey-woo-setup__copy" data-hey-woo-copy-target="cli">
								<?php esc_html_e( 'Copy', 'hey-woo' ); ?>
							</button>
						</div>

						<h5><?php esc_html_e( 'Claude Desktop — claude_desktop_config.json:', 'hey-woo' ); ?></h5>
						<div class="hey-woo-setup__copy-row hey-woo-setup__copy-row--block">
							<pre data-hey-woo-json><code><?php echo esc_html( $json_snippet ); ?></code></pre>
							<button type="button" class="button hey-woo-setup__copy" data-hey-woo-copy-target="json">
								<?php esc_html_e( 'Copy', 'hey-woo' ); ?>
							</button>
						</div>

					<?php endif; ?>
				</div>
			</div>
		</div>
	</section>

</div>
