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
 * WooCommerce for Claude owns the external MCP connection setup. WordPress-admin
 * chat is owned by Hey Woo, so this settings screen deliberately avoids
 * Anthropic key fields, chat routes, and Ask Claude calls to action.
 */
class SettingsPage extends \WC_Settings_Page {

	/**
	 * Register the tab and wire up WC settings hooks.
	 */
	public function __construct() {
		$this->id    = 'woocommerce-claude';
		$this->label = __( 'WooCommerce for Claude', 'woocommerce-claude' );
		parent::__construct();

		add_action( 'woocommerce_admin_field_woocommerce_claude_telemetry', array( $this, 'render_telemetry_field' ) );
		add_action( 'woocommerce_settings_save_woocommerce-claude', array( $this, 'save_telemetry_option' ) );
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
	 * The default setup screen is custom-rendered, so there are no fields here.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
		return array();
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

		$this->render_setup_overview();
	}

	/**
	 * Render the external Claude-app setup overview.
	 *
	 * @return void
	 */
	private function render_setup_overview() {
		$external_state   = ( new RestApiKey() )->existing_state();
		$has_external_key = null !== $external_state;
		$has_external_use = $has_external_key && 0 < (int) get_option( RestApiKey::OPTION_LAST_SEEN, 0 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only setup flash.
		$setup_notice_code = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : '';

		$connected_label  = __( 'Connected', 'woocommerce-claude' );
		$ready_label      = __( 'Ready', 'woocommerce-claude' );
		$not_set_up_label = __( 'Not set up', 'woocommerce-claude' );
		$external_open    = '' === $setup_notice_code ? '' : 'open';
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
				<p><?php esc_html_e( 'Connect Claude Desktop, Claude Code, or another MCP-compatible app to this store.', 'woocommerce-claude' ); ?></p>
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
		</div>
		<?php
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
}
