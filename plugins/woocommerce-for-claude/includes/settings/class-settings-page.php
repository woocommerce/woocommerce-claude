<?php
/**
 * WooCommerce Settings tab for WooCommerce for Claude.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Settings;

use WooCommerce\Claude\Difm\AnthropicClient;
use WooCommerce\Claude\Difm\DifmProviderEnvironment;
use WooCommerce\Claude\Difm\DifmProviderResolver;
use WooCommerce\Claude\Difm\WordPressAiClientAdapter;
use WooCommerce\Claude\Setup\RestApiKey;
use WooCommerce\Claude\Setup\SetupPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "WooCommerce for Claude" tab in WooCommerce > Settings.
 *
 * The default view renders a neutral setup overview. The Bring Your Own Key
 * field used by AI Insights, the external-access setup view, and plugin-level
 * settings live in their own sections.
 * Provider key fields are deliberately custom-rendered so stored API keys are
 * never sent back to the browser.
 */
class SettingsPage extends \WC_Settings_Page {

	/**
	 * Option name that stores the selected AI provider.
	 */
	const DIFM_PROVIDER_OPTION = 'woocommerce_claude_difm_provider';

	/**
	 * Option name that stores the merchant's Anthropic API key.
	 */
	const DIFM_API_KEY_OPTION = 'woocommerce_claude_anthropic_api_key';

	/**
	 * Back-compatible alias for the Anthropic key option.
	 */
	const ANTHROPIC_API_KEY_OPTION = self::DIFM_API_KEY_OPTION;

	/**
	 * Fixed masked value rendered when a database key is configured.
	 */
	const DIFM_API_KEY_SENTINEL = '__HEY_WOO_KEY_CONFIGURED__';

	/**
	 * Field name for explicitly removing a saved Anthropic API key.
	 */
	const DIFM_API_KEY_CLEAR_FIELD = 'woocommerce_claude_anthropic_api_key_clear';

	/**
	 * Field name for moving a saved Anthropic DB key into the native connector.
	 */
	const ANTHROPIC_CONNECTOR_MIGRATE_FIELD = 'woocommerce_claude_migrate_anthropic_to_connector';

	/**
	 * Field name for removing a saved local Anthropic DB key after connector setup.
	 */
	const ANTHROPIC_CONNECTOR_REMOVE_LOCAL_FIELD = 'woocommerce_claude_remove_local_anthropic_key';

	/**
	 * Legacy option name from the pre-rename branch.
	 */
	const LEGACY_DIFM_API_KEY_OPTION = 'hey_woo_anthropic_api_key';

	/**
	 * Server constant name for the Anthropic key.
	 */
	const DIFM_API_KEY_CONSTANT = 'WOOCOMMERCE_CLAUDE_ANTHROPIC_KEY';

	/**
	 * Legacy server constant name from the pre-rename branch.
	 */
	const LEGACY_DIFM_API_KEY_CONSTANT = 'HEY_WOO_ANTHROPIC_KEY';

	/**
	 * User-meta flag used to show the AI Insights CTA immediately after saving.
	 */
	const AI_INSIGHTS_SAVED_META = 'woocommerce_claude_ai_insights_just_saved';

	/**
	 * Register the tab and wire up WC settings hooks.
	 */
	public function __construct() {
		$this->id    = 'woocommerce-claude';
		$this->label = __( 'WooCommerce for Claude', 'woocommerce-claude' );
		parent::__construct();

		add_action( 'woocommerce_admin_field_woocommerce_claude_api_key', array( $this, 'render_api_key_field' ) );
		add_action( 'woocommerce_admin_field_woocommerce_claude_wp_ai_status', array( $this, 'render_wordpress_ai_status_field' ) );
		add_action( 'woocommerce_admin_field_woocommerce_claude_telemetry', array( $this, 'render_telemetry_field' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::DIFM_PROVIDER_OPTION, array( $this, 'sanitize_provider_option' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::DIFM_API_KEY_OPTION, array( $this, 'sanitize_api_key_option' ), 10, 3 );
		add_action( 'woocommerce_settings_save_woocommerce-claude', array( $this, 'save_telemetry_option' ) );
		add_action( 'woocommerce_settings_save_woocommerce-claude', array( $this, 'migrate_anthropic_key_to_connector' ), 9 );
		add_action( 'woocommerce_settings_save_woocommerce-claude', array( $this, 'validate_api_key_on_save' ) );
	}

	/**
	 * Sub-navigation sections for the WooCommerce for Claude tab.
	 *
	 * @return array<string,string>
	 */
	public function get_sections() {
		return array(
			''            => __( 'Setup', 'woocommerce-claude' ),
			'ai-insights' => __( 'AI provider', 'woocommerce-claude' ),
			'settings'    => __( 'Settings', 'woocommerce-claude' ),
		);
	}

	/**
	 * The AI Insights section contains AI provider configuration.
	 *
	 * The setup wizard lives in the `setup` section and handles connecting
	 * Claude Desktop/Code to the MCP server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_ai_insights_section() {
		$settings = array(
			array(
				'type'  => 'title',
				'title' => __( 'AI provider', 'woocommerce-claude' ),
				'id'    => 'woocommerce_claude_difm_section',
				'desc'  => $this->get_difm_section_description(),
			),
		);

		if ( DifmProviderEnvironment::is_connector_mode() ) {
			$provider_options = $this->get_provider_options();
			if ( 1 < count( $provider_options ) ) {
				$settings[] = array(
					'type'     => 'select',
					'id'       => self::DIFM_PROVIDER_OPTION,
					'title'    => __( 'AI provider', 'woocommerce-claude' ),
					'desc'     => __( 'Choose one of the AI providers already connected in Settings > Connectors.', 'woocommerce-claude' ),
					'default'  => WordPressAiClientAdapter::get_default_provider_id(),
					'options'  => $provider_options,
					'autoload' => false,
				);
			}

			$settings[] = array(
				'type'  => 'woocommerce_claude_wp_ai_status',
				'id'    => 'woocommerce_claude_wordpress_ai_status',
				'title' => __( 'WordPress AI connectors', 'woocommerce-claude' ),
			);
		} else {
			$settings[] = array(
				'type'     => 'woocommerce_claude_api_key',
				'id'       => self::ANTHROPIC_API_KEY_OPTION,
				'title'    => __( 'Anthropic API key', 'woocommerce-claude' ),
				'desc'     => __( 'Starts with <code>sk-ant-</code>. Used by the direct Anthropic server-side client and never rendered back into this page.', 'woocommerce-claude' ),
				'provider' => DifmProviderResolver::PROVIDER_ANTHROPIC,
				'default'  => '',
				'autoload' => false,
			);
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'woocommerce_claude_difm_section',
		);

		return $settings;
	}

	/**
	 * Back-compatibility shim for callers that still ask WC for the default
	 * section's settings. The default screen is now custom-rendered, but the
	 * AI Insights field list remains available here so legacy save flows keep
	 * preserving an existing key instead of treating a missing field as removal.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
		return $this->get_settings_for_ai_insights_section();
	}

	/**
	 * The Settings section contains plugin-level preferences.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_settings_section() {
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Settings', 'woocommerce-claude' ),
				'id'    => 'woocommerce_claude_settings_section',
				'desc'  => __( 'Manage WooCommerce for Claude preferences that are not tied to a specific Claude connection.', 'woocommerce-claude' ),
			),
			array(
				'type'  => 'woocommerce_claude_telemetry',
				'id'    => SetupPage::TELEMETRY_OPTION,
				'title' => __( 'Usage tracking', 'woocommerce-claude' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'woocommerce_claude_settings_section',
			),
		);
	}

	/**
	 * Render the current section.
	 *
	 * The default section shows a neutral setup overview and hides WC's Save
	 * button (no form fields). The external-access section shows the Claude app
	 * connection wizard. The Settings section renders plugin-level preferences.
	 * The AI Insights section renders AI provider fields.
	 */
	public function output() {
		global $current_section;

		if ( 'settings' === $current_section ) {
			\WC_Admin_Settings::output_fields( $this->get_settings_for_settings_section() );
			return;
		}

		if ( 'setup' === $current_section ) {
			SetupPage::render_setup_view();
			return;
		}

		if ( 'ai-insights' === $current_section ) {
			\WC_Admin_Settings::output_fields( $this->get_settings_for_ai_insights_section() );
			return;
		}

		$this->render_setup_overview();
	}

	/**
	 * Render the neutral setup overview with the two setup surfaces embedded
	 * as stacked accordions.
	 *
	 * @return void
	 */
	private function render_setup_overview() {
		$resolver         = new DifmProviderResolver();
		$has_ai_provider  = $resolver->has_configured_provider();
		$external_state   = ( new RestApiKey() )->existing_state();
		$has_external_key = null !== $external_state;
		$has_external_use = $has_external_key && 0 < (int) get_option( RestApiKey::OPTION_LAST_SEEN, 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only setup flash.
		$setup_notice_code = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		$ai_insights_url  = admin_url( 'admin.php?page=woocommerce-claude-insights' );
		$show_ai_insights = $has_ai_provider && $this->consume_ai_insights_saved_notice();
		$connected_label  = __( 'Connected', 'woocommerce-claude' );
		$ready_label      = __( 'Ready', 'woocommerce-claude' );
		$not_set_up_label = __( 'Not set up', 'woocommerce-claude' );
		$ai_open          = $show_ai_insights ? 'open' : '';
		$external_open    = '' === $setup_notice_code ? '' : 'open';
		$ai_status_label  = $has_ai_provider ? $ready_label : $not_set_up_label;
		$ai_status_class  = $has_ai_provider ? 'woocommerce-claude-setup__pill--ready' : 'woocommerce-claude-setup__pill--off';
		if ( $has_external_use ) {
			$external_status_label = $connected_label;
			$external_status_class = 'woocommerce-claude-setup__pill--live';
		} elseif ( $has_external_key ) {
			$external_status_label = $ready_label;
			$external_status_class = 'woocommerce-claude-setup__pill--ready';
		} else {
			$external_status_label = $not_set_up_label;
			$external_status_class = 'woocommerce-claude-setup__pill--off';
		}
		?>
		<div class="woocommerce-claude-setup woocommerce-claude-setup--overview">
			<section class="woocommerce-claude-setup__intro">
				<h2><?php esc_html_e( 'Set up AI for your store', 'woocommerce-claude' ); ?></h2>
				<p><?php esc_html_e( 'Connect Claude apps to this store, use AI in WordPress admin, or enable both. Each option has its own setup and can be changed later.', 'woocommerce-claude' ); ?></p>
			</section>

			<details class="woocommerce-claude-setup__accordion" <?php echo esc_attr( $external_open ); ?>>
				<summary class="woocommerce-claude-setup__accordion-summary">
					<span class="woocommerce-claude-setup__option-icon dashicons dashicons-desktop" aria-hidden="true"></span>
					<span class="woocommerce-claude-setup__accordion-text">
						<span class="woocommerce-claude-setup__accordion-title"><?php esc_html_e( 'Connect Claude apps', 'woocommerce-claude' ); ?></span>
						<span class="woocommerce-claude-setup__accordion-description"><?php esc_html_e( 'Use Claude Desktop, Claude Code, or another MCP-compatible app with this store.', 'woocommerce-claude' ); ?></span>
					</span>
					<span class="woocommerce-claude-setup__pill <?php echo esc_attr( $external_status_class ); ?>">
						<?php echo esc_html( $external_status_label ); ?>
					</span>
				</summary>
				<div class="woocommerce-claude-setup__accordion-panel">
					<?php SetupPage::render_setup_view( true ); ?>
				</div>
			</details>

			<details class="woocommerce-claude-setup__accordion" <?php echo esc_attr( $ai_open ); ?>>
				<summary class="woocommerce-claude-setup__accordion-summary">
					<span class="woocommerce-claude-setup__option-icon dashicons dashicons-format-chat" aria-hidden="true"></span>
					<span class="woocommerce-claude-setup__accordion-text">
						<span class="woocommerce-claude-setup__accordion-title"><?php esc_html_e( 'Chat in WordPress admin', 'woocommerce-claude' ); ?></span>
						<span class="woocommerce-claude-setup__accordion-description"><?php esc_html_e( 'Ask AI about store performance, orders, customer trends, and products without leaving WooCommerce.', 'woocommerce-claude' ); ?></span>
					</span>
					<span class="woocommerce-claude-setup__pill <?php echo esc_attr( $ai_status_class ); ?>">
						<?php echo esc_html( $ai_status_label ); ?>
					</span>
				</summary>
				<div class="woocommerce-claude-setup__accordion-panel">
					<?php $this->render_ai_insights_setup_panel( $has_ai_provider, $ai_insights_url, $show_ai_insights ); ?>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Render the AI Insights key setup controls inside the setup accordion.
	 *
	 * @param bool   $has_ai_provider Whether an AI provider is already configured.
	 * @param string $ai_insights_url URL for the AI Insights admin page.
	 * @param bool   $show_ai_insights_link Whether to show the AI Insights CTA.
	 * @return void
	 */
	private function render_ai_insights_setup_panel( $has_ai_provider, $ai_insights_url, $show_ai_insights_link ) {
		?>
		<p class="woocommerce-claude-setup__card-lede"><?php echo wp_kses_post( $this->get_difm_section_description() ); ?></p>

		<?php if ( $has_ai_provider ) : ?>
			<div class="woocommerce-claude-setup__banner woocommerce-claude-setup__banner--info">
				<span class="woocommerce-claude-setup__banner-icon" aria-hidden="true">
					<span class="dashicons dashicons-yes-alt"></span>
				</span>
				<div class="woocommerce-claude-setup__banner-body">
					<strong><?php esc_html_e( 'Ask AI is ready in WordPress admin.', 'woocommerce-claude' ); ?></strong>
					<p><?php esc_html_e( 'You can open the chat or manage the active AI provider from this section.', 'woocommerce-claude' ); ?></p>
				</div>
			</div>
		<?php endif; ?>

		<table class="form-table woocommerce-claude-setup__embedded-form" role="presentation">
			<tbody>
				<?php
				if ( DifmProviderEnvironment::is_connector_mode() ) {
					if ( 1 < count( $this->get_provider_options() ) ) {
						$this->render_provider_select_field(
							array(
								'id'    => self::DIFM_PROVIDER_OPTION,
								'title' => __( 'AI provider', 'woocommerce-claude' ),
								'desc'  => __( 'Choose one of the AI providers already connected in Settings > Connectors.', 'woocommerce-claude' ),
							)
						);
					}

					$this->render_wordpress_ai_status_field(
						array(
							'title' => __( 'WordPress AI connectors', 'woocommerce-claude' ),
						)
					);
				} else {
					$this->render_api_key_field(
						array(
							'id'       => self::ANTHROPIC_API_KEY_OPTION,
							'title'    => __( 'Anthropic API key', 'woocommerce-claude' ),
							'desc'     => __( 'Starts with <code>sk-ant-</code>. Used by the direct Anthropic server-side client and never rendered back into this page.', 'woocommerce-claude' ),
							'provider' => DifmProviderResolver::PROVIDER_ANTHROPIC,
						)
					);
				}
				?>
			</tbody>
		</table>

		<div class="woocommerce-claude-setup__accordion-actions">
			<button name="save" class="button button-primary woocommerce-save-button" type="submit" value="<?php esc_attr_e( 'Save changes', 'woocommerce-claude' ); ?>">
				<?php esc_html_e( 'Save changes', 'woocommerce-claude' ); ?>
			</button>
			<?php if ( $show_ai_insights_link ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( $ai_insights_url ); ?>">
					<?php esc_html_e( 'Open Ask AI', 'woocommerce-claude' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Return the description for the DIFM section.
	 *
	 * Shows a notice when the key is configured via a PHP constant.
	 *
	 * @return string
	 */
	private function get_difm_section_description() {
		$anthropic_constant = $this->get_api_key_constant_name( DifmProviderResolver::PROVIDER_ANTHROPIC );

		if ( DifmProviderEnvironment::is_connector_mode() ) {
			if ( '' !== $anthropic_constant ) {
				return sprintf(
					/* translators: %s: PHP constant name. */
					__( 'A direct Anthropic key is configured via the <code>%s</code> server constant. WordPress 7.0 uses native AI connectors for Ask AI, so server-managed keys should be configured through the native <code>ANTHROPIC_API_KEY</code> connector constant or in Settings > Connectors.', 'woocommerce-claude' ),
					esc_html( $anthropic_constant )
				);
			}

			return __( 'Ask AI uses native WordPress AI providers configured in Settings > Connectors. Connect Anthropic, OpenAI, Google, or another provider there, then choose from the connected providers here.', 'woocommerce-claude' );
		}

		if ( '' !== $anthropic_constant ) {
			return sprintf(
				/* translators: %s: PHP constant name. */
				__( 'A direct Anthropic key is configured via the <code>%s</code> server constant. The matching field below is disabled; edit the constant in <code>wp-config.php</code> instead. WordPress 7.0 or later uses native WordPress AI connectors instead.', 'woocommerce-claude' ),
				esc_html( $anthropic_constant )
			);
		}

		return __( 'On this WordPress version, Ask AI uses the direct Anthropic client. Enter an Anthropic API key below, or define <code>WOOCOMMERCE_CLAUDE_ANTHROPIC_KEY</code> in <code>wp-config.php</code> for a server-managed key. WordPress 7.0 or later uses native WordPress AI connectors instead.', 'woocommerce-claude' );
	}

	/**
	 * Return provider select options.
	 *
	 * @return array<string,string>
	 */
	private function get_provider_options() {
		if ( DifmProviderEnvironment::is_connector_mode() ) {
			return WordPressAiClientAdapter::get_configured_provider_options();
		}

		return array(
			DifmProviderResolver::PROVIDER_ANTHROPIC => __( 'Anthropic', 'woocommerce-claude' ),
		);
	}

	/**
	 * Render the provider select row inside the embedded setup form.
	 *
	 * @param array $value Field definition.
	 * @return void
	 */
	private function render_provider_select_field( $value ) {
		$field_id    = isset( $value['id'] ) ? $value['id'] : self::DIFM_PROVIDER_OPTION;
		$title       = isset( $value['title'] ) ? $value['title'] : '';
		$description = isset( $value['desc'] ) ? $value['desc'] : '';
		$selected    = DifmProviderResolver::get_selected_provider();
		?>
		<tr>
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $title ); ?></label>
			</th>
			<td class="forminp forminp-select">
				<select name="<?php echo esc_attr( $field_id ); ?>" id="<?php echo esc_attr( $field_id ); ?>" class="wc-enhanced-select">
					<?php foreach ( $this->get_provider_options() as $value_key => $label ) : ?>
						<option value="<?php echo esc_attr( $value_key ); ?>" <?php selected( $selected, $value_key ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render WordPress AI connector availability/status.
	 *
	 * @param array $value Field definition.
	 * @return void
	 */
	public function render_wordpress_ai_status_field( $value ) {
		$title            = isset( $value['title'] ) ? $value['title'] : '';
		$provider_options = WordPressAiClientAdapter::get_configured_provider_options();
		$connectors_url   = DifmProviderEnvironment::connectors_url();
		?>
		<tr>
			<th scope="row" class="titledesc"><?php echo esc_html( $title ); ?></th>
			<td class="forminp forminp-woocommerce-claude-wp-ai-status">
				<?php if ( empty( $provider_options ) ) : ?>
					<p><?php esc_html_e( 'No WordPress AI provider is connected yet. Add Anthropic, OpenAI, Google, or another AI provider in Settings > Connectors.', 'woocommerce-claude' ); ?></p>
					<p>
						<a class="button" href="<?php echo esc_url( $connectors_url ); ?>">
							<?php esc_html_e( 'Open Settings > Connectors', 'woocommerce-claude' ); ?>
						</a>
					</p>
				<?php elseif ( 1 === count( $provider_options ) ) : ?>
					<?php $active_provider_label = (string) reset( $provider_options ); ?>
					<p>
						<?php
						printf(
							/* translators: %s: AI provider label. */
							esc_html__( 'Using %s from Settings > Connectors.', 'woocommerce-claude' ),
							esc_html( $active_provider_label )
						);
						?>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'Choose between the AI providers already connected in Settings > Connectors.', 'woocommerce-claude' ); ?></p>
				<?php endif; ?>
				<?php $this->render_anthropic_connector_migration_card(); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the legacy Anthropic-key migration card for WP 7 connector mode.
	 *
	 * @return void
	 */
	private function render_anthropic_connector_migration_card() {
		$database_key       = $this->get_saved_database_api_key( DifmProviderResolver::PROVIDER_ANTHROPIC );
		$anthropic_constant = $this->get_api_key_constant_name( DifmProviderResolver::PROVIDER_ANTHROPIC );
		$connectors_url     = DifmProviderEnvironment::connectors_url();
		$connector_source   = DifmProviderEnvironment::get_connector_api_key_source( DifmProviderResolver::PROVIDER_ANTHROPIC );

		if ( '' !== $database_key ) {
			?>
			<div class="woocommerce-claude-connector-migration">
				<?php if ( 'none' === $connector_source ) : ?>
					<p><strong><?php esc_html_e( 'Move saved Anthropic key to WordPress connectors', 'woocommerce-claude' ); ?></strong></p>
					<p><?php esc_html_e( 'A legacy Anthropic key is still saved by WooCommerce for Claude. Move it to the native Anthropic connector so Ask AI can use the WordPress 7.0 provider flow, then remove the local copy.', 'woocommerce-claude' ); ?></p>
					<p>
						<button
							type="submit"
							name="<?php echo esc_attr( self::ANTHROPIC_CONNECTOR_MIGRATE_FIELD ); ?>"
							value="yes"
							class="button"
						>
							<?php esc_html_e( 'Move key to connector', 'woocommerce-claude' ); ?>
						</button>
					</p>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'Remove legacy local Anthropic key', 'woocommerce-claude' ); ?></strong></p>
					<p><?php esc_html_e( 'The native Anthropic connector is already configured. Remove the old WooCommerce for Claude database copy so the connector is the only stored key used by Ask AI.', 'woocommerce-claude' ); ?></p>
					<p>
						<button
							type="submit"
							name="<?php echo esc_attr( self::ANTHROPIC_CONNECTOR_REMOVE_LOCAL_FIELD ); ?>"
							value="yes"
							class="button"
						>
							<?php esc_html_e( 'Remove legacy local key', 'woocommerce-claude' ); ?>
						</button>
					</p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		if ( '' !== $anthropic_constant ) {
			?>
			<div class="woocommerce-claude-connector-migration">
				<p><strong><?php esc_html_e( 'Server-managed Anthropic key detected', 'woocommerce-claude' ); ?></strong></p>
				<p>
					<?php
					printf(
						/* translators: 1: old constant name, 2: native constant name. */
						esc_html__( 'The direct Anthropic key comes from %1$s, so WooCommerce for Claude will not copy it into the database. Configure the native %2$s constant or add the key in Settings > Connectors.', 'woocommerce-claude' ),
						esc_html( $anthropic_constant ),
						'ANTHROPIC_API_KEY'
					);
					?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( $connectors_url ); ?>">
						<?php esc_html_e( 'Open Settings > Connectors', 'woocommerce-claude' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Render the anonymised-usage-data opt-in as a native WC-style checkbox row.
	 *
	 * @param array $value WooCommerce settings field definition.
	 * @return void
	 */
	public function render_telemetry_field( $value ) {
		$telemetry_enabled = 'yes' === get_option( SetupPage::TELEMETRY_OPTION, 'no' );
		$title             = isset( $value['title'] ) ? $value['title'] : '';
		?>
		<tr>
			<th scope="row" class="titledesc"><?php echo esc_html( $title ); ?></th>
			<td class="forminp forminp-checkbox">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo esc_html( $title ); ?></span></legend>
					<label for="woocommerce-claude-telemetry-optin">
						<input
							id="woocommerce-claude-telemetry-optin"
							name="<?php echo esc_attr( SetupPage::TELEMETRY_OPTION ); ?>"
							type="checkbox"
							value="yes"
							<?php checked( $telemetry_enabled ); ?>
						/>
						<?php esc_html_e( 'Share anonymised usage data to help improve WooCommerce for Claude', 'woocommerce-claude' ); ?>
					</label>
					<p class="description">
						<?php
						printf(
							wp_kses(
								/* translators: %s: link to WooCommerce's usage tracking page. */
								__( 'You can opt out at any time. %s', 'woocommerce-claude' ),
								array(
									'a' => array(
										'href'   => array(),
										'target' => array(),
										'rel'    => array(),
									),
								)
							),
							'<a href="https://woocommerce.com/usage-tracking/" target="_blank" rel="noopener">' . esc_html__( 'Learn more about usage tracking.', 'woocommerce-claude' ) . '</a>'
						);
						?>
					</p>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the telemetry opt-in when WooCommerce for Claude settings are saved.
	 *
	 * A standard HTML checkbox sends its value only when checked; absence means
	 * unchecked. WC doesn't know about our custom field type, so we handle the
	 * save explicitly here.
	 *
	 * @return void
	 */
	public function save_telemetry_option() {
		global $current_section;

		if ( 'settings' !== $current_section ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		$enabled = isset( $_POST[ SetupPage::TELEMETRY_OPTION ] ) && 'yes' === sanitize_key( $_POST[ SetupPage::TELEMETRY_OPTION ] );
		update_option( SetupPage::TELEMETRY_OPTION, $enabled ? 'yes' : 'no' );
	}

	/**
	 * Sanitise the AI provider option.
	 *
	 * @param mixed $value     Value already sanitised by WooCommerce.
	 * @param array $option    Field definition.
	 * @param mixed $raw_value Raw posted value.
	 * @return string
	 */
	public function sanitize_provider_option( $value, $option, $raw_value ) {
		unset( $value, $option );

		return DifmProviderResolver::normalise_provider( $raw_value );
	}

	/**
	 * Render a masked API key field that never outputs the stored secret.
	 *
	 * @param array $value WooCommerce settings field definition.
	 * @return void
	 */
	public function render_api_key_field( $value ) {
		$provider     = isset( $value['provider'] ) ? DifmProviderResolver::normalise_provider( $value['provider'] ) : DifmProviderResolver::PROVIDER_ANTHROPIC;
		$field_id     = isset( $value['id'] ) ? $value['id'] : $this->api_key_option_for_provider( $provider );
		$field_name   = isset( $value['field_name'] ) ? $value['field_name'] : $field_id;
		$title        = isset( $value['title'] ) ? $value['title'] : '';
		$description  = isset( $value['desc'] ) ? $value['desc'] : '';
		$placeholder  = isset( $value['placeholder'] ) ? (string) $value['placeholder'] : 'sk-ant-...';
		$constant     = $this->get_api_key_constant_name( $provider );
		$has_constant = '' !== $constant;
		$stored_key   = $this->get_saved_api_key( $provider );
		$field_value  = ( ! $has_constant && '' !== $stored_key ) ? self::DIFM_API_KEY_SENTINEL : '';
		$clear_field  = $this->api_key_clear_field_for_provider( $provider );
		?>
		<tr>
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $title ); ?></label>
			</th>
			<td class="forminp forminp-woocommerce-claude-api-key">
				<input
					name="<?php echo esc_attr( $field_name ); ?>"
					id="<?php echo esc_attr( $field_id ); ?>"
					type="password"
					value="<?php echo esc_attr( $field_value ); ?>"
					class="regular-input"
					placeholder="<?php echo esc_attr( $placeholder ); ?>"
					autocomplete="new-password"
					<?php disabled( $has_constant ); ?>
				/>
				<?php if ( '' !== $description ) : ?>
					<p class="description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $has_constant ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: PHP constant name. */
							esc_html__( 'This key is managed by the %s server constant.', 'woocommerce-claude' ),
							esc_html( $constant )
						);
						?>
					</p>
				<?php elseif ( '' !== $stored_key ) : ?>
					<p class="description"><?php esc_html_e( 'A key is currently saved. Leave this field unchanged to keep it.', 'woocommerce-claude' ); ?></p>
					<label for="<?php echo esc_attr( $clear_field ); ?>">
						<input
							type="checkbox"
							name="<?php echo esc_attr( $clear_field ); ?>"
							id="<?php echo esc_attr( $clear_field ); ?>"
							value="yes"
						/>
						<?php esc_html_e( 'Remove saved key', 'woocommerce-claude' ); ?>
					</label>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Sanitise the masked API key field.
	 *
	 * Blank and sentinel submissions preserve the current key. The clear
	 * checkbox deletes the option. When the server constant is defined, the
	 * database option is not touched.
	 *
	 * @param mixed $value     Value already sanitised by WooCommerce.
	 * @param array $option    Field definition.
	 * @param mixed $raw_value Raw posted value, already unslashed by WooCommerce.
	 * @return string|null Value to save, or null to skip updating the option.
	 */
	public function sanitize_api_key_option( $value, $option, $raw_value ) {
		unset( $value );

		if ( DifmProviderEnvironment::is_connector_mode() ) {
			return null;
		}

		$option_id = isset( $option['id'] ) ? (string) $option['id'] : self::ANTHROPIC_API_KEY_OPTION;
		$provider  = $this->provider_for_api_key_option( $option_id );

		if ( '' !== $this->get_api_key_constant_name( $provider ) ) {
			return null;
		}

		if ( $this->is_api_key_clear_requested( $provider ) ) {
			$this->delete_api_key_option( $provider );
			if ( ! ( new DifmProviderResolver() )->has_configured_provider() ) {
				$this->clear_ai_insights_saved_notice();
			}
			return null;
		}

		$current_key   = $this->get_saved_api_key( $provider );
		$submitted_key = is_string( $raw_value ) ? trim( $raw_value ) : '';

		if ( '' === $submitted_key || self::DIFM_API_KEY_SENTINEL === $submitted_key ) {
			return '' === $current_key ? null : $current_key;
		}

		return $submitted_key;
	}

	/**
	 * Whether the explicit remove-key checkbox was submitted.
	 *
	 * @param string $provider Provider value.
	 * @return bool
	 */
	private function is_api_key_clear_requested( $provider = DifmProviderResolver::PROVIDER_ANTHROPIC ) {
		$field = $this->api_key_clear_field_for_provider( $provider );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		$clear = isset( $_POST[ $field ] )
			? sanitize_text_field( wp_unslash( $_POST[ $field ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return wc_string_to_bool( $clear );
	}

	/**
	 * Move a saved local Anthropic DB key into the native Anthropic connector.
	 *
	 * @return void
	 */
	public function migrate_anthropic_key_to_connector() {
		if ( ! DifmProviderEnvironment::is_connector_mode() ) {
			return;
		}

		if ( $this->is_anthropic_connector_remove_local_requested() ) {
			$this->remove_local_anthropic_key_after_connector_setup();
			return;
		}

		if ( ! $this->is_anthropic_connector_migration_requested() ) {
			return;
		}

		$database_key = $this->get_saved_database_api_key( DifmProviderResolver::PROVIDER_ANTHROPIC );
		if ( '' === $database_key ) {
			\WC_Admin_Settings::add_error( __( 'WooCommerce for Claude: no saved Anthropic key was found to move.', 'woocommerce-claude' ) );
			return;
		}

		$connector_setting_name = DifmProviderEnvironment::get_connector_setting_name( DifmProviderResolver::PROVIDER_ANTHROPIC );
		if ( '' === $connector_setting_name ) {
			\WC_Admin_Settings::add_error( __( 'WooCommerce for Claude: the native Anthropic connector is not available yet. Install or activate the Anthropic provider connector, then try again.', 'woocommerce-claude' ) );
			return;
		}

		$connector_source = DifmProviderEnvironment::get_connector_api_key_source( DifmProviderResolver::PROVIDER_ANTHROPIC );
		if ( 'none' !== $connector_source ) {
			\WC_Admin_Settings::add_error( __( 'WooCommerce for Claude: the native Anthropic connector already has a key, so the legacy key was left in place and not overwritten.', 'woocommerce-claude' ) );
			return;
		}

		$result = AnthropicClient::validate_key( $database_key );
		if ( is_wp_error( $result ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: validation error message. */
					__( 'WooCommerce for Claude: the saved Anthropic key could not be moved because it failed validation - %s', 'woocommerce-claude' ),
					$result->get_error_message()
				)
			);
			return;
		}

		if ( ! DifmProviderEnvironment::set_connector_api_key( DifmProviderResolver::PROVIDER_ANTHROPIC, $database_key ) ) {
			\WC_Admin_Settings::add_error( __( 'WooCommerce for Claude: the Anthropic connector key could not be saved. The legacy key was left in place.', 'woocommerce-claude' ) );
			return;
		}

		update_option( self::DIFM_PROVIDER_OPTION, DifmProviderResolver::PROVIDER_ANTHROPIC, 'no' );
		$this->delete_api_key_option( DifmProviderResolver::PROVIDER_ANTHROPIC );
		$this->mark_ai_insights_saved_notice();
		\WC_Admin_Settings::add_message( __( 'WooCommerce for Claude: Anthropic key moved to Settings > Connectors and the legacy local key was removed.', 'woocommerce-claude' ) );
	}

	/**
	 * Whether the connector migration button was submitted.
	 *
	 * @return bool
	 */
	private function is_anthropic_connector_migration_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		return isset( $_POST[ self::ANTHROPIC_CONNECTOR_MIGRATE_FIELD ] );
	}

	/**
	 * Whether the remove-local-key button was submitted.
	 *
	 * @return bool
	 */
	private function is_anthropic_connector_remove_local_requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		return isset( $_POST[ self::ANTHROPIC_CONNECTOR_REMOVE_LOCAL_FIELD ] );
	}

	/**
	 * Remove a stale local Anthropic key once the native connector is configured.
	 *
	 * @return void
	 */
	private function remove_local_anthropic_key_after_connector_setup() {
		$database_key = $this->get_saved_database_api_key( DifmProviderResolver::PROVIDER_ANTHROPIC );
		if ( '' === $database_key ) {
			\WC_Admin_Settings::add_error( __( 'WooCommerce for Claude: no legacy local Anthropic key was found to remove.', 'woocommerce-claude' ) );
			return;
		}

		$connector_source = DifmProviderEnvironment::get_connector_api_key_source( DifmProviderResolver::PROVIDER_ANTHROPIC );
		if ( 'none' === $connector_source ) {
			\WC_Admin_Settings::add_error( __( 'WooCommerce for Claude: connect Anthropic in Settings > Connectors before removing the legacy local key.', 'woocommerce-claude' ) );
			return;
		}

		$this->delete_api_key_option( DifmProviderResolver::PROVIDER_ANTHROPIC );
		update_option( self::DIFM_PROVIDER_OPTION, DifmProviderResolver::PROVIDER_ANTHROPIC, 'no' );
		$this->mark_ai_insights_saved_notice();
		\WC_Admin_Settings::add_message( __( 'WooCommerce for Claude: legacy local Anthropic key removed. Ask AI will use the native Anthropic connector.', 'woocommerce-claude' ) );
	}

	/**
	 * Validate direct API keys immediately after settings are saved.
	 *
	 * @return void
	 */
	public function validate_api_key_on_save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		$ai_provider_form_submitted = isset( $_POST[ self::DIFM_PROVIDER_OPTION ] )
			|| isset( $_POST[ self::ANTHROPIC_API_KEY_OPTION ] )
			|| isset( $_POST[ self::DIFM_API_KEY_CLEAR_FIELD ] )
			|| isset( $_POST[ self::ANTHROPIC_CONNECTOR_MIGRATE_FIELD ] )
			|| isset( $_POST[ self::ANTHROPIC_CONNECTOR_REMOVE_LOCAL_FIELD ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $ai_provider_form_submitted ) {
			return;
		}

		if ( DifmProviderEnvironment::is_connector_mode() ) {
			if ( ( new DifmProviderResolver() )->has_configured_provider() ) {
				$this->mark_ai_insights_saved_notice();
			} else {
				$this->clear_ai_insights_saved_notice();
			}
			return;
		}

		foreach ( array( DifmProviderResolver::PROVIDER_ANTHROPIC ) as $provider ) {
			if ( '' !== $this->get_api_key_constant_name( $provider ) ) {
				continue;
			}

			if ( $this->is_api_key_clear_requested( $provider ) ) {
				$this->delete_api_key_option( $provider );
				continue;
			}

			if ( ! $this->is_api_key_field_submitted( $provider ) ) {
				continue;
			}

			$submitted_key = $this->get_submitted_api_key( $provider );
			if ( '' === $submitted_key || self::DIFM_API_KEY_SENTINEL === $submitted_key ) {
				continue;
			}

			$result = AnthropicClient::validate_key( $submitted_key );

			if ( is_wp_error( $result ) ) {
				$auth_failure = in_array( $result->get_error_code(), array( 'invalid_key', 'empty_key' ), true );

				if ( $auth_failure ) {
					$this->delete_api_key_option( $provider );

					\WC_Admin_Settings::add_error(
						sprintf(
							/* translators: 1: provider name, 2: error message. */
							__( 'WooCommerce for Claude: %1$s API key is invalid and has been removed - %2$s', 'woocommerce-claude' ),
							DifmProviderResolver::provider_label( $provider ),
							$result->get_error_message()
						)
					);
					continue;
				}

				\WC_Admin_Settings::add_error(
					sprintf(
						/* translators: 1: provider name, 2: error message. */
						__( 'WooCommerce for Claude: %1$s API key was saved but could not be verified right now - %2$s', 'woocommerce-claude' ),
						DifmProviderResolver::provider_label( $provider ),
						$result->get_error_message()
					)
				);
			}
		}

		if ( ( new DifmProviderResolver() )->has_configured_provider() ) {
			$this->mark_ai_insights_saved_notice();
		} else {
			$this->clear_ai_insights_saved_notice();
		}
	}

	/**
	 * Whether a direct API key field was part of the submitted form.
	 *
	 * @param string $provider Provider value.
	 * @return bool
	 */
	private function is_api_key_field_submitted( $provider = DifmProviderResolver::PROVIDER_ANTHROPIC ) {
		$field = $this->api_key_option_for_provider( $provider );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		return isset( $_POST[ $field ] );
	}

	/**
	 * Return a submitted direct API key without corrupting provider-specific characters.
	 *
	 * @param string $provider Provider value.
	 * @return string
	 */
	private function get_submitted_api_key( $provider ) {
		$field = $this->api_key_option_for_provider( $provider );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API keys may contain characters that sanitizers would corrupt; validate with the provider instead.
		return isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] )
			? trim( wp_unslash( $_POST[ $field ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Mark the current user as having just saved the AI Insights key form.
	 *
	 * @return void
	 */
	private function mark_ai_insights_saved_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		update_user_meta( $user_id, self::AI_INSIGHTS_SAVED_META, (string) time() );
	}

	/**
	 * Clear the post-save AI Insights CTA flag for the current user.
	 *
	 * @return void
	 */
	private function clear_ai_insights_saved_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		delete_user_meta( $user_id, self::AI_INSIGHTS_SAVED_META );
	}

	/**
	 * Consume the post-save AI Insights CTA flag for the current user.
	 *
	 * @return bool
	 */
	private function consume_ai_insights_saved_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		$show_notice = '' !== (string) get_user_meta( $user_id, self::AI_INSIGHTS_SAVED_META, true );
		if ( $show_notice ) {
			delete_user_meta( $user_id, self::AI_INSIGHTS_SAVED_META );
		}

		return $show_notice;
	}

	/**
	 * Return the server constant currently providing a direct provider key.
	 *
	 * @param string $provider Provider value.
	 * @return string Constant name, or empty string.
	 */
	private function get_api_key_constant_name( $provider = DifmProviderResolver::PROVIDER_ANTHROPIC ) {
		unset( $provider );

		$constant_name = '';
		if ( defined( self::DIFM_API_KEY_CONSTANT ) ) {
			$constant_name = self::DIFM_API_KEY_CONSTANT;
		}

		if ( '' === $constant_name && defined( self::LEGACY_DIFM_API_KEY_CONSTANT ) ) {
			$constant_name = self::LEGACY_DIFM_API_KEY_CONSTANT;
		}

		/**
		 * Filter the detected direct Anthropic key constant for tests.
		 *
		 * @since 0.5.0
		 *
		 * @param string $constant_name Constant name, or empty string.
		 */
		return (string) apply_filters( 'woocommerce_claude_difm_anthropic_key_constant_name', $constant_name );
	}

	/**
	 * Return the saved direct API key.
	 *
	 * @param string $provider Provider value.
	 * @return string Saved API key, or empty string.
	 */
	private function get_saved_api_key( $provider = DifmProviderResolver::PROVIDER_ANTHROPIC ) {
		unset( $provider );

		$constant = $this->get_api_key_constant_name( DifmProviderResolver::PROVIDER_ANTHROPIC );
		if ( '' !== $constant && defined( $constant ) ) {
			return (string) constant( $constant );
		}

		return $this->get_saved_database_api_key( DifmProviderResolver::PROVIDER_ANTHROPIC );
	}

	/**
	 * Return the saved direct API key from database options only.
	 *
	 * @param string $provider Provider value.
	 * @return string Saved API key, or empty string.
	 */
	private function get_saved_database_api_key( $provider = DifmProviderResolver::PROVIDER_ANTHROPIC ) {
		unset( $provider );

		$current_key = (string) get_option( self::ANTHROPIC_API_KEY_OPTION, '' );
		if ( '' !== $current_key ) {
			return $current_key;
		}

		return (string) get_option( self::LEGACY_DIFM_API_KEY_OPTION, '' );
	}

	/**
	 * Return the direct API key option for a provider.
	 *
	 * @param string $provider Provider value.
	 * @return string Option name.
	 */
	private function api_key_option_for_provider( $provider ) {
		unset( $provider );

		return self::ANTHROPIC_API_KEY_OPTION;
	}

	/**
	 * Return the clear checkbox field for a provider.
	 *
	 * @param string $provider Provider value.
	 * @return string Field name.
	 */
	private function api_key_clear_field_for_provider( $provider ) {
		unset( $provider );

		return self::DIFM_API_KEY_CLEAR_FIELD;
	}

	/**
	 * Determine provider from a direct API key option.
	 *
	 * @param string $option Option name.
	 * @return string Provider value.
	 */
	private function provider_for_api_key_option( $option ) {
		unset( $option );

		return DifmProviderResolver::PROVIDER_ANTHROPIC;
	}

	/**
	 * Delete a direct API key option and any legacy alias.
	 *
	 * @param string $provider Provider value.
	 * @return void
	 */
	private function delete_api_key_option( $provider ) {
		unset( $provider );

		delete_option( self::ANTHROPIC_API_KEY_OPTION );
		delete_option( self::LEGACY_DIFM_API_KEY_OPTION );
	}
}
