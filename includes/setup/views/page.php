<?php
/**
 * WooCommerce for Claude setup view, rendered inside WC Settings → WooCommerce for Claude (default
 * section). Outputs HTML directly inside WC's outer <form id="mainform">
 * wrapper, so this view contains *no* nested <form> elements: every
 * state-changing action is a `wp_nonce_url`-protected GET link.
 *
 * Two-step layout:
 *
 *   Step 1 — Generate a read-only API key (explicit Generate button,
 *            OR a summary line for the already-provisioned key with
 *            Regenerate / Disconnect).
 *   Step 2 — Configure in Claude (tabbed: Easy install / Manual setup).
 *
 * Step-2 actions (Download MCPB, copying snippets) require an existing
 * key — surfaced in the UI as a disabled state on the action buttons,
 * with backend gates as defence-in-depth.
 *
 * Expects the following variables in scope (prepared by
 * SetupPage::render_setup_view()):
 *
 * @var bool                                                                $site_https   Whether home_url() is https.
 * @var string                                                              $endpoint_url Full WooCommerce for Claude MCP endpoint URL.
 * @var string                                                              $notice_code  Status flash from query string.
 * @var array{credential:string,key_id:int,permissions:string}|null         $key_state    Provisioned key, or null if not yet created.
 * @var bool                                                                $is_owner     Whether the current user owns the provisioned key.
 * @var string                                                              $owner_display Display name of the key owner, when not the current user.
 * @package WooCommerce\Claude
 */

defined( 'ABSPATH' ) || exit;

use WooCommerce\Claude\Setup\RestApiKey;
use WooCommerce\Claude\Setup\SetupPage;

$credential       = $key_state['credential'] ?? '';
$has_key          = null !== $key_state;
$server_slug      = SetupPage::server_slug();
$remote_pkg       = SetupPage::REMOTE_PACKAGE;
$default_key_desc = RestApiKey::KEY_DESCRIPTION;

list( $api_username, $api_password ) = '' === $credential
	? array( 'ck_xxx', 'cs_xxx' )
	: RestApiKey::split_credential( $credential );

$json_snippet = wp_json_encode(
	array(
		'mcpServers' => array(
			$server_slug => array(
				'command' => 'npx',
				'args'    => array( '-y', $remote_pkg ),
				'env'     => array(
					'WP_API_URL'      => $endpoint_url,
					'WP_API_USERNAME' => $api_username,
					'WP_API_PASSWORD' => $api_password,
				),
			),
		),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
);

$claude_code_command = sprintf(
	"claude mcp add %s \\\n  --env WP_API_URL=%s \\\n  --env WP_API_USERNAME=%s \\\n  --env WP_API_PASSWORD=%s \\\n  -- npx -y %s",
	$server_slug,
	$endpoint_url,
	$api_username,
	$api_password,
	$remote_pkg
);

$notices = array(
	'key_generated'     => array( 'success', __( 'API key generated and ready to use with Claude.', 'woocommerce-claude' ) ),
	'key_exists'        => array( 'info', __( 'An API key already exists. Use Regenerate to rotate it.', 'woocommerce-claude' ) ),
	'key_required'      => array( 'error', __( 'Generate an API key in Step 1 before continuing.', 'woocommerce-claude' ) ),
	'key_regenerated'   => array( 'success', __( 'API key regenerated. Re-download the MCPB file for Claude Desktop.', 'woocommerce-claude' ) ),
	'key_failed'        => array( 'error', __( 'Could not provision the API key. Check the error log.', 'woocommerce-claude' ) ),
	'disconnected'      => array( 'info', __( 'API key revoked and the connection torn down. Any installed Claude Desktop bundle has stopped authenticating.', 'woocommerce-claude' ) ),
	'ownership_changed' => array( 'error', __( 'The API key was rotated by another admin while your action was in flight. Refresh the page and try again.', 'woocommerce-claude' ) ),
);

$download_url   = SetupPage::action_url( SetupPage::ACTION_DOWNLOAD );
$regen_url      = SetupPage::action_url( SetupPage::ACTION_REGEN_KEY );
$disconnect_url = SetupPage::action_url( SetupPage::ACTION_DISCONNECT );
$generate_url   = SetupPage::action_url( SetupPage::ACTION_GENERATE_KEY );

// Deep link to WC's REST API key list — used for "broaden permissions"
// guidance. When a key already exists, link straight to its edit form
// via &edit-key=<id> so the merchant lands on the row's permissions
// dropdown without having to scroll the list.
$wc_keys_url     = admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys' );
$wc_key_edit_url = $has_key
	? add_query_arg( 'edit-key', (int) $key_state['key_id'], $wc_keys_url )
	: $wc_keys_url;

$can_generate          = ! $has_key;
$can_use_step2_actions = $has_key;
?>
<div class="woocommerce-claude-setup">

	<?php
	if ( isset( $notices[ $notice_code ] ) ) :
		$notice_kind = $notices[ $notice_code ][0];
		$notice_text = $notices[ $notice_code ][1];
		// Map success/info/error to a banner colour. Success uses the
		// info treatment so the flash matches the "API key revoked"
		// state shown in the design — green is reserved for the
		// inline form-saved confirmation WC emits elsewhere.
		$banner_modifier = 'error' === $notice_kind ? 'error' : 'info';
		?>
		<div class="woocommerce-claude-setup__banner woocommerce-claude-setup__banner--<?php echo esc_attr( $banner_modifier ); ?> is-flash" role="status">
			<span class="woocommerce-claude-setup__banner-icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
					<circle cx="10" cy="10" r="8.25" stroke="currentColor" stroke-width="1.5"/>
					<path d="M10 9V14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
					<circle cx="10" cy="6.25" r="0.95" fill="currentColor"/>
				</svg>
			</span>
			<div class="woocommerce-claude-setup__banner-body">
				<p><?php echo esc_html( $notice_text ); ?></p>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( ! $site_https ) : ?>
		<div class="woocommerce-claude-setup__banner woocommerce-claude-setup__banner--info">
			<span class="woocommerce-claude-setup__banner-icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
					<circle cx="10" cy="10" r="8.25" stroke="currentColor" stroke-width="1.5"/>
					<path d="M10 9V14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
					<circle cx="10" cy="6.25" r="0.95" fill="currentColor"/>
				</svg>
			</span>
			<div class="woocommerce-claude-setup__banner-body">
				<strong><?php esc_html_e( 'This store is on HTTP.', 'woocommerce-claude' ); ?></strong>
				<p><?php esc_html_e( 'Production stores should use HTTPS so credentials and tool responses are not transmitted in clear text.', 'woocommerce-claude' ); ?></p>
			</div>
		</div>
	<?php endif; ?>

	<?php if ( $has_key && ! $is_owner ) : ?>

		<?php /* Non-owner view — set up by another admin. */ ?>
		<section class="woocommerce-claude-setup__card">
			<h2 class="woocommerce-claude-setup__card-title"><?php esc_html_e( 'Provisioned by another admin', 'woocommerce-claude' ); ?></h2>
			<p class="woocommerce-claude-setup__card-lede">
				<?php
				printf(
					/* translators: %s: display name of the admin who provisioned the key. */
					esc_html__( 'The WooCommerce for Claude API key was created by %s. WooCommerce REST API keys authenticate as the user who created them, so the credential is only shown to that admin.', 'woocommerce-claude' ),
					'<strong>' . esc_html( $owner_display ) . '</strong>'
				);
				?>
			</p>
			<p><?php esc_html_e( 'You have two options:', 'woocommerce-claude' ); ?></p>
			<ol class="woocommerce-claude-setup__steps">
				<li>
					<?php
					printf(
						/* translators: %s: display name of the admin who provisioned the key. */
						esc_html__( 'Sign in as %s — the existing Claude Desktop bundle and configurations they distributed continue to work without change.', 'woocommerce-claude' ),
						esc_html( $owner_display )
					);
					?>
				</li>
				<li><?php esc_html_e( "Click Regenerate below — this revokes the existing key, issues a fresh one bound to your user, and invalidates every bundle / configuration that's already been distributed. They'll need to re-download from this page.", 'woocommerce-claude' ); ?></li>
			</ol>
			<div class="woocommerce-claude-setup__actions">
				<a
					class="button button-secondary"
					href="<?php echo esc_url( $regen_url ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Regenerate the API key and re-bind it to your user? Any installed Claude Desktop bundle and pasted configuration will stop working until re-downloaded / re-pasted.', 'woocommerce-claude' ) ); ?>');"
				>
					<?php esc_html_e( 'Regenerate and re-bind to me', 'woocommerce-claude' ); ?>
				</a>
			</div>
		</section>

	<?php else : ?>

		<?php /* Step 1 — Generate API key (form OR summary line). */ ?>
		<section class="woocommerce-claude-setup__card">
			<?php if ( ! $has_key ) : ?>
				<h2 class="woocommerce-claude-setup__card-title"><?php esc_html_e( 'Step 1: Generate an API key', 'woocommerce-claude' ); ?></h2>
			<?php endif; ?>

			<?php if ( $has_key ) : ?>

				<div class="woocommerce-claude-setup__keyrow">
					<div class="woocommerce-claude-setup__keyrow-meta">
						<div class="woocommerce-claude-setup__keyrow-label">
							<span class="woocommerce-claude-setup__field-label"><?php esc_html_e( 'API KEY', 'woocommerce-claude' ); ?></span>
							<span class="woocommerce-claude-setup__pill woocommerce-claude-setup__pill--live">
								<?php esc_html_e( 'LIVE', 'woocommerce-claude' ); ?>
							</span>
						</div>
						<p class="woocommerce-claude-setup__keyrow-name">
							<?php
							printf(
								/* translators: %s: key description label. */
								esc_html__( 'API key: %s', 'woocommerce-claude' ),
								esc_html( $default_key_desc )
							);
							?>
						</p>
					</div>
					<div class="woocommerce-claude-setup__keyrow-actions">
						<a class="woocommerce-claude-setup__textlink" href="<?php echo esc_url( $wc_key_edit_url ); ?>">
							<?php esc_html_e( 'Permissions', 'woocommerce-claude' ); ?>
						</a>
						<a
							class="woocommerce-claude-setup__textlink"
							href="<?php echo esc_url( $regen_url ); ?>"
							onclick="return confirm('<?php echo esc_js( __( 'Regenerate the API key? Any installed Claude Desktop bundle will stop working until you re-download and re-install it.', 'woocommerce-claude' ) ); ?>');"
						>
							<?php esc_html_e( 'Regenerate', 'woocommerce-claude' ); ?>
						</a>
						<a
							class="woocommerce-claude-setup__textlink"
							href="<?php echo esc_url( $disconnect_url ); ?>"
							onclick="return confirm('<?php echo esc_js( __( 'Disconnect WooCommerce for Claude and revoke the API key? Any installed bundle stops authenticating immediately and the credential is removed from WooCommerce.', 'woocommerce-claude' ) ); ?>');"
						>
							<?php esc_html_e( 'Disconnect', 'woocommerce-claude' ); ?>
						</a>
					</div>
				</div>
				<p class="woocommerce-claude-setup__keyrow-help">
					<?php esc_html_e( 'To broaden access, click Permissions and pick Read/Write — Write alone disables reads. If you have shared the MCPB file, click Regenerate first so older copies stop authenticating before the new scope takes effect.', 'woocommerce-claude' ); ?>
				</p>

			<?php else : ?>

				<p class="woocommerce-claude-setup__card-lede">
					<?php
					printf(
						wp_kses(
							/* translators: 1: canonical key description label. 2: link to WC's REST API key list. */
							__( 'A read-only WooCommerce REST API key labelled %1$s will be created for Claude. If you later want Claude to make changes, change its scope to Read/Write under %2$s.', 'woocommerce-claude' ),
							array(
								'code' => array(),
								'a'    => array( 'href' => array() ),
							)
						),
						'<code>' . esc_html( $default_key_desc ) . '</code>',
						'<a href="' . esc_url( $wc_keys_url ) . '">' . esc_html__( 'WooCommerce → Settings → Advanced → REST API', 'woocommerce-claude' ) . '</a>'
					);
					?>
				</p>

				<div class="woocommerce-claude-setup__actions">
					<?php if ( $can_generate ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $generate_url ); ?>">
							<?php esc_html_e( 'Generate key', 'woocommerce-claude' ); ?>
						</a>
					<?php else : ?>
						<button type="button" class="button button-primary" disabled>
							<?php esc_html_e( 'Generate key', 'woocommerce-claude' ); ?>
						</button>
					<?php endif; ?>
				</div>

			<?php endif; ?>
		</section>

		<?php /* Step 2 — Configure in Claude (tabs). */ ?>
		<section class="woocommerce-claude-setup__card">
			<h2 class="woocommerce-claude-setup__card-title"><?php esc_html_e( 'Step 2: Configure in Claude', 'woocommerce-claude' ); ?></h2>
			<p class="woocommerce-claude-setup__card-lede">
				<?php esc_html_e( "Choose how you'd prefer to set up WooCommerce for Claude.", 'woocommerce-claude' ); ?>
			</p>

			<div class="woocommerce-claude-setup__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Configure in Claude', 'woocommerce-claude' ); ?>">
				<button
					type="button"
					role="tab"
					id="woocommerce-claude-tab-easy"
					class="woocommerce-claude-setup__tab is-active"
					aria-selected="true"
					aria-controls="woocommerce-claude-panel-easy"
					data-woocommerce-claude-tab="easy"
				>
					<?php esc_html_e( 'Easy install', 'woocommerce-claude' ); ?>
				</button>
				<button
					type="button"
					role="tab"
					id="woocommerce-claude-tab-manual"
					class="woocommerce-claude-setup__tab"
					aria-selected="false"
					aria-controls="woocommerce-claude-panel-manual"
					tabindex="-1"
					data-woocommerce-claude-tab="manual"
				>
					<?php esc_html_e( 'Manual setup', 'woocommerce-claude' ); ?>
				</button>
			</div>

			<?php /* Easy install panel. */ ?>
			<div
				role="tabpanel"
				id="woocommerce-claude-panel-easy"
				class="woocommerce-claude-setup__tabpanel"
				aria-labelledby="woocommerce-claude-tab-easy"
			>
				<ol class="woocommerce-claude-setup__steps">
					<li>
						<?php
						printf(
							/* translators: %s: link to Claude Desktop download page. */
							esc_html__( 'Install Claude Desktop if you don\'t already have it (free at %s).', 'woocommerce-claude' ),
							'<a href="https://claude.ai/download" target="_blank" rel="noopener">claude.ai/download</a>'
						);
						?>
					</li>
					<li><?php esc_html_e( 'Click Download MCPB file below.', 'woocommerce-claude' ); ?></li>
					<li><?php esc_html_e( 'Double-click the downloaded file and enable WooCommerce for Claude.', 'woocommerce-claude' ); ?></li>
					<li><?php esc_html_e( 'Restart Claude Desktop for the changes to take effect.', 'woocommerce-claude' ); ?></li>
				</ol>

				<p class="woocommerce-claude-setup__prereq">
					<strong><?php esc_html_e( 'Prerequisite:', 'woocommerce-claude' ); ?></strong>
					<?php
					printf(
						/* translators: %s: link to nodejs.org. */
						esc_html__( 'Node.js 18 or later. Install from %s if you don\'t have it.', 'woocommerce-claude' ),
						'<a href="https://nodejs.org/" target="_blank" rel="noopener">nodejs.org</a>'
					);
					?>
				</p>

				<?php if ( $can_use_step2_actions ) : ?>
					<p class="woocommerce-claude-setup__warn-block">
						<?php esc_html_e( 'The MCPB file contains an API key for this store. Don\'t share it. If it leaks, click Regenerate above to revoke instantly.', 'woocommerce-claude' ); ?>
					</p>
				<?php endif; ?>

				<div class="woocommerce-claude-setup__actions">
					<?php if ( $can_use_step2_actions ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( $download_url ); ?>">
							<?php esc_html_e( 'Download MCPB file', 'woocommerce-claude' ); ?>
						</a>
					<?php else : ?>
						<button type="button" class="button button-primary" disabled>
							<?php esc_html_e( 'Download MCPB file', 'woocommerce-claude' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<?php /* Manual setup panel. */ ?>
			<div
				role="tabpanel"
				id="woocommerce-claude-panel-manual"
				class="woocommerce-claude-setup__tabpanel"
				aria-labelledby="woocommerce-claude-tab-manual"
				hidden
			>
				<p class="woocommerce-claude-setup__card-lede">
					<?php esc_html_e( "The manual snippets work for Claude Code today; we'll add more clients in future versions.", 'woocommerce-claude' ); ?>
				</p>

				<?php if ( ! $can_use_step2_actions ) : ?>
					<p class="woocommerce-claude-setup__blocked">
						<?php esc_html_e( 'Generate an API key in Step 1 above to see the snippets with your credential filled in.', 'woocommerce-claude' ); ?>
					</p>
				<?php else : ?>

					<div class="woocommerce-claude-setup__field">
						<span class="woocommerce-claude-setup__field-label"><?php esc_html_e( 'TERMINAL', 'woocommerce-claude' ); ?></span>
						<div class="woocommerce-claude-setup__codeblock">
							<button type="button" class="woocommerce-claude-setup__copy" data-woocommerce-claude-copy-target="cli" aria-label="<?php esc_attr_e( 'Copy terminal command', 'woocommerce-claude' ); ?>">
								<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
									<rect x="4" y="4" width="9" height="10" rx="1" stroke="currentColor" stroke-width="1.3"/>
									<path d="M3 11H2.5C2.22 11 2 10.78 2 10.5V2.5C2 2.22 2.22 2 2.5 2H10.5C10.78 2 11 2.22 11 2.5V3" stroke="currentColor" stroke-width="1.3"/>
								</svg>
							</button>
							<pre data-woocommerce-claude-cli><code><?php echo esc_html( $claude_code_command ); ?></code></pre>
						</div>
					</div>

					<div class="woocommerce-claude-setup__field">
						<span class="woocommerce-claude-setup__field-label"><?php esc_html_e( 'CONFIG FILE', 'woocommerce-claude' ); ?></span>
						<div class="woocommerce-claude-setup__codeblock">
							<button type="button" class="woocommerce-claude-setup__copy" data-woocommerce-claude-copy-target="json" aria-label="<?php esc_attr_e( 'Copy config file', 'woocommerce-claude' ); ?>">
								<svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
									<rect x="4" y="4" width="9" height="10" rx="1" stroke="currentColor" stroke-width="1.3"/>
									<path d="M3 11H2.5C2.22 11 2 10.78 2 10.5V2.5C2 2.22 2.22 2 2.5 2H10.5C10.78 2 11 2.22 11 2.5V3" stroke="currentColor" stroke-width="1.3"/>
								</svg>
							</button>
							<pre data-woocommerce-claude-json><code><?php echo esc_html( $json_snippet ); ?></code></pre>
						</div>
					</div>

				<?php endif; ?>
			</div>
		</section>

		<?php
		/*
		 * "Try it out" card — only relevant once the connection is live
		 * ($has_key, captured by $can_use_step2_actions).
		 * Three starter questions the merchant can paste straight into
		 * Claude, plus a link to a longer set of guide questions in the
		 * repo. Intentionally avoids referencing MCP slash commands —
		 * the picker UX varies across clients and a "paste this question"
		 * pathway works on every Claude surface.
		 */
		if ( $can_use_step2_actions ) :
			$starter_prompts = array(
				__( 'Walk me through my store — what it sells, who buys, and what to look at this week.', 'woocommerce-claude' ),
				__( 'Revenue is down vs. last period — find the cause.', 'woocommerce-claude' ),
				__( 'Pick my 5 worst-scoring products and draft rewrites.', 'woocommerce-claude' ),
			);
			$guides_url      = 'https://github.com/woocommerce/woocommerce-claude/blob/trunk/docs/prompt-guides.md';
			?>
			<section class="woocommerce-claude-setup__card">
				<h2 class="woocommerce-claude-setup__card-title"><?php esc_html_e( 'Try it out', 'woocommerce-claude' ); ?></h2>
				<p class="woocommerce-claude-setup__card-lede">
					<?php esc_html_e( 'Three starter questions you can ask Claude about your store. Open Claude, paste one in, and WooCommerce for Claude will pull the data and write the answer.', 'woocommerce-claude' ); ?>
				</p>

				<ul class="woocommerce-claude-setup__prompts">
					<?php foreach ( $starter_prompts as $question ) : ?>
						<li class="woocommerce-claude-setup__prompt">
							<?php echo esc_html( $question ); ?>
						</li>
					<?php endforeach; ?>
				</ul>

				<p class="woocommerce-claude-setup__prompt-footer">
					<?php
					printf(
						wp_kses(
							/* translators: %s: URL to the prompt-guides.md doc on GitHub. */
							__( 'Want more ideas? See the <a href="%s" target="_blank" rel="noopener">prompt guides</a> in the repo for a longer list of questions you can ask, organised by what you\'re trying to learn.', 'woocommerce-claude' ),
							array(
								'a' => array(
									'href'   => array(),
									'target' => array(),
									'rel'    => array(),
								),
							)
						),
						esc_url( $guides_url )
					);
					?>
				</p>
			</section>
		<?php endif; /* end Try it out card */ ?>

	<?php endif; /* end non-owner gate */ ?>

</div>
