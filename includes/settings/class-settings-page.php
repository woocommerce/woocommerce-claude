<?php
/**
 * WooCommerce Settings tab for WooCommerce for Claude.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Settings;

use WooCommerce\Claude\Setup\RestApiKey;
use WooCommerce\Claude\Setup\SetupPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "WooCommerce for Claude" tab in WooCommerce > Settings.
 *
 * The default view renders a neutral setup overview. The Bring Your Own Key
 * field used by AI Insights, the external-access setup view, and plugin-level
 * settings live in their own sections.
 * The key field is deliberately custom-rendered so a stored Anthropic key is
 * never sent back to the browser.
 */
class SettingsPage extends \WC_Settings_Page {

	/**
	 * Option name that stores the merchant's Anthropic API key.
	 */
	const DIFM_API_KEY_OPTION = 'woocommerce_claude_anthropic_api_key';

	/**
	 * Fixed masked value rendered when a database key is configured.
	 */
	const DIFM_API_KEY_SENTINEL = '__HEY_WOO_KEY_CONFIGURED__';

	/**
	 * Field name for explicitly removing a saved Anthropic API key.
	 */
	const DIFM_API_KEY_CLEAR_FIELD = 'woocommerce_claude_anthropic_api_key_clear';

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
		add_action( 'woocommerce_admin_field_woocommerce_claude_telemetry', array( $this, 'render_telemetry_field' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::DIFM_API_KEY_OPTION, array( $this, 'sanitize_api_key_option' ), 10, 3 );
		add_action( 'woocommerce_settings_save_woocommerce-claude', array( $this, 'save_telemetry_option' ) );
		add_action( 'woocommerce_settings_save_woocommerce-claude', array( $this, 'validate_api_key_on_save' ) );
	}

	/**
	 * Sub-navigation sections for the WooCommerce for Claude tab.
	 *
	 * @return array<string,string>
	 */
	public function get_sections() {
		return array(
			''         => __( 'Setup', 'woocommerce-claude' ),
			'settings' => __( 'Settings', 'woocommerce-claude' ),
		);
	}

	/**
	 * The AI Insights section contains the Anthropic API key fields.
	 *
	 * The AI Insights section configures the Anthropic API key used for
	 * server-side AI calls. The setup wizard lives in the `setup` section and
	 * handles connecting Claude Desktop to the MCP server.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_ai_insights_section() {
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'AI Insights', 'woocommerce-claude' ),
				'id'    => 'woocommerce_claude_difm_section',
				'desc'  => $this->get_difm_section_description(),
			),
			array(
				'type'     => 'woocommerce_claude_api_key',
				'id'       => self::DIFM_API_KEY_OPTION,
				'title'    => __( 'Anthropic API Key', 'woocommerce-claude' ),
				'desc'     => __( 'Starts with <code>sk-ant-</code>. The real key is used only for server-side AI calls and is never rendered back into this page.', 'woocommerce-claude' ),
				'default'  => '',
				'autoload' => false,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'woocommerce_claude_difm_section',
			),
		);
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
	 * The AI Insights section renders the Anthropic API key field.
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
		$has_ai_key       = '' !== $this->get_api_key_constant_name() || '' !== $this->get_saved_api_key();
		$external_state   = ( new RestApiKey() )->existing_state();
		$has_external_key = null !== $external_state;
		$has_external_use = $has_external_key && 0 < (int) get_option( RestApiKey::OPTION_LAST_SEEN, 0 );

		$ai_insights_url  = admin_url( 'admin.php?page=woocommerce-claude-insights' );
		$show_ai_insights = $has_ai_key && $this->consume_ai_insights_saved_notice();
		$connected_label  = __( 'Connected', 'woocommerce-claude' );
		$ready_label      = __( 'Ready', 'woocommerce-claude' );
		$not_set_up_label = __( 'Not set up', 'woocommerce-claude' );
		$ai_open          = $has_ai_key ? '' : 'open';
		$external_open    = $has_external_key ? '' : 'open';
		$ai_status_label  = $has_ai_key ? $ready_label : $not_set_up_label;
		$ai_status_class  = $has_ai_key ? 'woocommerce-claude-setup__pill--ready' : 'woocommerce-claude-setup__pill--off';
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
				<h2><?php esc_html_e( 'Set up Claude for your store', 'woocommerce-claude' ); ?></h2>
				<p><?php esc_html_e( 'Connect Claude apps to this store, use Claude in WordPress admin, or enable both. Each option has its own setup and can be changed later.', 'woocommerce-claude' ); ?></p>
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
						<span class="woocommerce-claude-setup__accordion-description"><?php esc_html_e( 'Ask Claude about store performance, orders, customer trends, and products without leaving WooCommerce.', 'woocommerce-claude' ); ?></span>
					</span>
					<span class="woocommerce-claude-setup__pill <?php echo esc_attr( $ai_status_class ); ?>">
						<?php echo esc_html( $ai_status_label ); ?>
					</span>
				</summary>
				<div class="woocommerce-claude-setup__accordion-panel">
					<?php $this->render_ai_insights_setup_panel( $has_ai_key, $ai_insights_url, $show_ai_insights ); ?>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Render the AI Insights key setup controls inside the setup accordion.
	 *
	 * @param bool   $has_ai_key      Whether an Anthropic key is already configured.
	 * @param string $ai_insights_url URL for the AI Insights admin page.
	 * @param bool   $show_ai_insights_link Whether to show the AI Insights CTA.
	 * @return void
	 */
	private function render_ai_insights_setup_panel( $has_ai_key, $ai_insights_url, $show_ai_insights_link ) {
		?>
		<p class="woocommerce-claude-setup__card-lede"><?php echo wp_kses_post( $this->get_difm_section_description() ); ?></p>

		<?php if ( $has_ai_key ) : ?>
			<div class="woocommerce-claude-setup__banner woocommerce-claude-setup__banner--info">
				<span class="woocommerce-claude-setup__banner-icon" aria-hidden="true">
					<span class="dashicons dashicons-yes-alt"></span>
				</span>
				<div class="woocommerce-claude-setup__banner-body">
					<strong><?php esc_html_e( 'AI Insights is ready in WordPress admin.', 'woocommerce-claude' ); ?></strong>
					<p><?php esc_html_e( 'You can open the chat, replace the saved key, or remove it from this section.', 'woocommerce-claude' ); ?></p>
				</div>
			</div>
		<?php endif; ?>

		<table class="form-table woocommerce-claude-setup__embedded-form" role="presentation">
			<tbody>
				<?php
				$this->render_api_key_field(
					array(
						'id'    => self::DIFM_API_KEY_OPTION,
						'title' => __( 'Anthropic API Key', 'woocommerce-claude' ),
						'desc'  => __( 'Starts with <code>sk-ant-</code>. The real key is used only for server-side AI calls and is never rendered back into this page.', 'woocommerce-claude' ),
					)
				);
				?>
			</tbody>
		</table>

		<div class="woocommerce-claude-setup__accordion-actions">
			<button name="save" class="button button-primary woocommerce-save-button" type="submit" value="<?php esc_attr_e( 'Save changes', 'woocommerce-claude' ); ?>">
				<?php esc_html_e( 'Save changes', 'woocommerce-claude' ); ?>
			</button>
			<?php if ( $show_ai_insights_link ) : ?>
				<a class="button button-secondary" href="<?php echo esc_url( $ai_insights_url ); ?>">
					<?php esc_html_e( 'Open AI Insights', 'woocommerce-claude' ); ?>
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
		$constant_name = $this->get_api_key_constant_name();
		$requirement   = __( 'AI Insights requires WordPress 7.0 or later. On WordPress 6.9, install and activate the Gutenberg plugin.', 'woocommerce-claude' );

		if ( '' !== $constant_name ) {
			return sprintf(
				/* translators: %s: PHP constant name. */
				__( 'Your Anthropic API key is configured via the <code>%s</code> server constant. The field below is disabled; edit the constant in <code>wp-config.php</code> instead.', 'woocommerce-claude' ),
				esc_html( $constant_name )
			) . ' ' . $requirement;
		}

		return __( 'Paste your Anthropic API key to get AI-powered insights in your WooCommerce dashboard. Your key is stored in the WordPress database. For higher security, define <code>WOOCOMMERCE_CLAUDE_ANTHROPIC_KEY</code> in <code>wp-config.php</code> instead.', 'woocommerce-claude' ) . ' ' . $requirement;
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
	 * Render a masked API key field that never outputs the stored secret.
	 *
	 * @param array $value WooCommerce settings field definition.
	 * @return void
	 */
	public function render_api_key_field( $value ) {
		$has_constant = '' !== $this->get_api_key_constant_name();
		$stored_key   = $this->get_saved_api_key();
		$field_value  = ( ! $has_constant && '' !== $stored_key ) ? self::DIFM_API_KEY_SENTINEL : '';
		$field_id     = isset( $value['id'] ) ? $value['id'] : self::DIFM_API_KEY_OPTION;
		$field_name   = isset( $value['field_name'] ) ? $value['field_name'] : $field_id;
		$title        = isset( $value['title'] ) ? $value['title'] : '';
		$description  = isset( $value['desc'] ) ? $value['desc'] : '';
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
					placeholder="sk-ant-..."
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
							esc_html( $this->get_api_key_constant_name() )
						);
						?>
					</p>
				<?php elseif ( '' !== $stored_key ) : ?>
					<p class="description"><?php esc_html_e( 'A key is currently saved. Leave this field unchanged to keep it.', 'woocommerce-claude' ); ?></p>
					<label for="<?php echo esc_attr( self::DIFM_API_KEY_CLEAR_FIELD ); ?>">
						<input
							type="checkbox"
							name="<?php echo esc_attr( self::DIFM_API_KEY_CLEAR_FIELD ); ?>"
							id="<?php echo esc_attr( self::DIFM_API_KEY_CLEAR_FIELD ); ?>"
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
		unset( $value, $option );

		if ( '' !== $this->get_api_key_constant_name() ) {
			return null;
		}

		if ( $this->is_api_key_clear_requested() ) {
			delete_option( self::DIFM_API_KEY_OPTION );
			delete_option( self::LEGACY_DIFM_API_KEY_OPTION );
			$this->clear_ai_insights_saved_notice();
			return null;
		}

		$current_key   = $this->get_saved_api_key();
		$submitted_key = is_string( $raw_value ) ? trim( $raw_value ) : '';

		if ( '' === $submitted_key || self::DIFM_API_KEY_SENTINEL === $submitted_key ) {
			return '' === $current_key ? null : $current_key;
		}

		return $submitted_key;
	}

	/**
	 * Whether the explicit remove-key checkbox was submitted.
	 *
	 * @return bool
	 */
	private function is_api_key_clear_requested() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		$clear = isset( $_POST[ self::DIFM_API_KEY_CLEAR_FIELD ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::DIFM_API_KEY_CLEAR_FIELD ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return wc_string_to_bool( $clear );
	}

	/**
	 * Validate the Anthropic API key immediately after settings are saved.
	 *
	 * @return void
	 */
	public function validate_api_key_on_save() {
		if ( '' !== $this->get_api_key_constant_name() ) {
			return;
		}

		if ( $this->is_api_key_clear_requested() ) {
			delete_option( self::DIFM_API_KEY_OPTION );
			delete_option( self::LEGACY_DIFM_API_KEY_OPTION );
			$this->clear_ai_insights_saved_notice();
			return;
		}

		if ( ! $this->is_api_key_field_submitted() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API keys may contain characters that sanitizers would corrupt; validate with Anthropic instead.
		$submitted_key = isset( $_POST[ self::DIFM_API_KEY_OPTION ] ) && is_string( $_POST[ self::DIFM_API_KEY_OPTION ] )
			? trim( wp_unslash( $_POST[ self::DIFM_API_KEY_OPTION ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $submitted_key || self::DIFM_API_KEY_SENTINEL === $submitted_key ) {
			if ( '' !== $this->get_saved_api_key() ) {
				$this->mark_ai_insights_saved_notice();
			}
			return;
		}

		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';
		$result = \WooCommerce\Claude\Difm\AnthropicClient::validate_key( $submitted_key );

		if ( is_wp_error( $result ) ) {
			$auth_failure = in_array( $result->get_error_code(), array( 'invalid_key', 'empty_key' ), true );

			if ( $auth_failure ) {
				delete_option( self::DIFM_API_KEY_OPTION );
				$this->clear_ai_insights_saved_notice();

				\WC_Admin_Settings::add_error(
					sprintf(
						/* translators: %s: error message from Anthropic. */
						__( 'WooCommerce for Claude: Anthropic API key is invalid and has been removed — %s', 'woocommerce-claude' ),
						$result->get_error_message()
					)
				);
				return;
			} else {
				\WC_Admin_Settings::add_error(
					sprintf(
						/* translators: %s: error message. */
						__( 'WooCommerce for Claude: Anthropic API key was saved but could not be verified right now — %s', 'woocommerce-claude' ),
						$result->get_error_message()
					)
				);
			}
		}

		$this->mark_ai_insights_saved_notice();
	}

	/**
	 * Whether the Anthropic API key field was part of the submitted form.
	 *
	 * @return bool
	 */
	private function is_api_key_field_submitted() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		return isset( $_POST[ self::DIFM_API_KEY_OPTION ] );
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
	 * Return the server constant currently providing the Anthropic key.
	 *
	 * @return string Constant name, or empty string.
	 */
	private function get_api_key_constant_name() {
		if ( defined( self::DIFM_API_KEY_CONSTANT ) ) {
			return self::DIFM_API_KEY_CONSTANT;
		}

		if ( defined( self::LEGACY_DIFM_API_KEY_CONSTANT ) ) {
			return self::LEGACY_DIFM_API_KEY_CONSTANT;
		}

		return '';
	}

	/**
	 * Return the saved API key, including the pre-rename option fallback.
	 *
	 * @return string Saved API key, or empty string.
	 */
	private function get_saved_api_key() {
		$current_key = (string) get_option( self::DIFM_API_KEY_OPTION, '' );
		if ( '' !== $current_key ) {
			return $current_key;
		}

		return (string) get_option( self::LEGACY_DIFM_API_KEY_OPTION, '' );
	}
}
