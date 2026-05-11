<?php
/**
 * WooCommerce Settings tab for WooCommerce for Claude.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Settings;

use WooCommerce\Claude\Setup\SetupPage;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "WooCommerce for Claude" tab in WooCommerce > Settings.
 *
 * The default view renders the Connect-to-Claude setup screen first, then the
 * Bring Your Own Key field used by AI Insights. The key field is deliberately
 * custom-rendered so a stored Anthropic key is never sent back to the browser.
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
	 * Field name for explicitly removing a saved DIFM API key.
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
	 * Register the tab and wire up WC settings hooks.
	 */
	public function __construct() {
		$this->id    = 'woocommerce-claude';
		$this->label = __( 'WooCommerce for Claude', 'woocommerce-claude' );
		parent::__construct();

		add_action( 'woocommerce_admin_field_woocommerce_claude_api_key', array( $this, 'render_api_key_field' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::DIFM_API_KEY_OPTION, array( $this, 'sanitize_api_key_option' ), 10, 3 );
		add_action( 'woocommerce_settings_save_woocommerce-claude', array( $this, 'validate_api_key_on_save' ) );
	}

	/**
	 * Sub-navigation sections for the WooCommerce for Claude tab.
	 *
	 * @return array<string,string>
	 */
	public function get_sections() {
		return array(
			''      => __( 'AI Insights', 'woocommerce-claude' ),
			'setup' => __( 'Setup', 'woocommerce-claude' ),
		);
	}

	/**
	 * The default section contains the AI Insights key fields.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
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
	 * Render the current section.
	 *
	 * The Setup section shows the Claude Desktop connection wizard and hides
	 * WC's Save button (no form fields, all actions are link-based).
	 * The default AI Insights section renders the Anthropic API key field.
	 */
	public function output() {
		global $current_section;

		if ( 'setup' === $current_section ) {
			SetupPage::render_setup_view();
			return;
		}

		\WC_Admin_Settings::output_fields( $this->get_settings_for_default_section() );
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

		if ( '' !== $constant_name ) {
			return sprintf(
				/* translators: %s: PHP constant name. */
				__( 'Your Anthropic API key is configured via the <code>%s</code> server constant. The field below is disabled; edit the constant in <code>wp-config.php</code> instead.', 'woocommerce-claude' ),
				esc_html( $constant_name )
			);
		}

		return __( 'Paste your Anthropic API key to get AI-powered insights in your WooCommerce dashboard. Your key is stored in the WordPress database. For higher security, define <code>WOOCOMMERCE_CLAUDE_ANTHROPIC_KEY</code> in <code>wp-config.php</code> instead.', 'woocommerce-claude' );
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
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API keys may contain characters that sanitizers would corrupt; validate with Anthropic instead.
		$submitted_key = isset( $_POST[ self::DIFM_API_KEY_OPTION ] ) && is_string( $_POST[ self::DIFM_API_KEY_OPTION ] )
			? trim( wp_unslash( $_POST[ self::DIFM_API_KEY_OPTION ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $submitted_key || self::DIFM_API_KEY_SENTINEL === $submitted_key ) {
			return;
		}

		require_once WOOCOMMERCE_CLAUDE_PLUGIN_DIR . 'includes/difm/class-anthropic-client.php';
		$result = \WooCommerce\Claude\Difm\AnthropicClient::validate_key( $submitted_key );

		if ( is_wp_error( $result ) ) {
			$auth_failure = in_array( $result->get_error_code(), array( 'invalid_key', 'empty_key' ), true );

			if ( $auth_failure ) {
				delete_option( self::DIFM_API_KEY_OPTION );

				\WC_Admin_Settings::add_error(
					sprintf(
						/* translators: %s: error message from Anthropic. */
						__( 'WooCommerce for Claude: Anthropic API key is invalid and has been removed — %s', 'woocommerce-claude' ),
						$result->get_error_message()
					)
				);
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
