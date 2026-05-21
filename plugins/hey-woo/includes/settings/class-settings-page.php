<?php
/**
 * WooCommerce Settings tab for Hey Woo.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Settings;

use WooCommerce\HeyWoo\Difm\AnthropicClient;
use WooCommerce\HeyWoo\Difm\DifmProviderEnvironment;
use WooCommerce\HeyWoo\Difm\DifmProviderResolver;
use WooCommerce\HeyWoo\Difm\WordPressAiClientAdapter;
use WooCommerce\HeyWoo\ThisWeek\Notifications\ThisWeekSettings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "Hey Woo" tab in WooCommerce > Settings.
 *
 * Provider key fields are custom-rendered so stored API keys are never sent
 * back to the browser.
 */
class SettingsPage extends \WC_Settings_Page {

	/**
	 * Option name that stores the selected AI provider.
	 */
	const DIFM_PROVIDER_OPTION = 'hey_woo_difm_provider';

	/**
	 * Option name that stores the merchant's Anthropic API key.
	 */
	const DIFM_API_KEY_OPTION = 'hey_woo_anthropic_api_key';

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
	const DIFM_API_KEY_CLEAR_FIELD = 'hey_woo_anthropic_api_key_clear';

	/**
	 * Field name for moving a saved Anthropic DB key into the native connector.
	 */
	const ANTHROPIC_CONNECTOR_MIGRATE_FIELD = 'hey_woo_migrate_anthropic_to_connector';

	/**
	 * Field name for removing a saved local Anthropic DB key after connector setup.
	 */
	const ANTHROPIC_CONNECTOR_REMOVE_LOCAL_FIELD = 'hey_woo_remove_local_anthropic_key';

	/**
	 * Server constant name for the Anthropic key.
	 */
	const DIFM_API_KEY_CONSTANT = 'HEY_WOO_ANTHROPIC_KEY';

	/**
	 * Option name that stores the merchant's anonymised usage tracking choice.
	 */
	const TELEMETRY_OPTION = 'hey_woo_telemetry_enabled';

	/**
	 * Register the tab and wire up WC settings hooks.
	 */
	public function __construct() {
		$this->id    = 'hey-woo';
		$this->label = __( 'Hey Woo', 'hey-woo' );
		parent::__construct();

		add_action( 'woocommerce_admin_field_hey_woo_api_key', array( $this, 'render_api_key_field' ) );
		add_action( 'woocommerce_admin_field_hey_woo_telemetry', array( $this, 'render_telemetry_field' ) );
		add_action( 'woocommerce_admin_field_hey_woo_wp_ai_status', array( $this, 'render_wordpress_ai_status_field' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::DIFM_PROVIDER_OPTION, array( $this, 'sanitize_provider_option' ), 10, 3 );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . self::DIFM_API_KEY_OPTION, array( $this, 'sanitize_api_key_option' ), 10, 3 );
		add_action( 'woocommerce_settings_save_hey-woo', array( $this, 'migrate_anthropic_key_to_connector' ), 9 );
		add_action( 'woocommerce_settings_save_hey-woo', array( $this, 'save_telemetry_option' ) );
		add_action( 'woocommerce_settings_save_hey-woo', array( $this, 'validate_api_key_on_save' ) );
	}

	/**
	 * Sub-navigation sections for the Hey Woo tab.
	 *
	 * @return array<string,string>
	 */
	public function get_sections() {
		return array(
			''         => __( 'Setup', 'hey-woo' ),
			'settings' => __( 'Settings', 'hey-woo' ),
		);
	}

	/**
	 * Settings fields for the default section.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_default_section() {
		$settings = array(
			array(
				'type'  => 'title',
				'title' => __( 'AI provider', 'hey-woo' ),
				'id'    => 'hey_woo_difm_section',
				'desc'  => $this->get_difm_section_description(),
			),
		);

		if ( DifmProviderEnvironment::is_connector_mode() ) {
			$provider_options = $this->get_provider_options();
			if ( 1 < count( $provider_options ) ) {
				$settings[] = array(
					'type'     => 'select',
					'id'       => self::DIFM_PROVIDER_OPTION,
					'title'    => __( 'AI provider', 'hey-woo' ),
					'desc'     => __( 'Choose one of the AI providers already connected in Settings > Connectors.', 'hey-woo' ),
					'default'  => WordPressAiClientAdapter::get_default_provider_id(),
					'options'  => $provider_options,
					'autoload' => false,
				);
			}

			$settings[] = array(
				'type'  => 'hey_woo_wp_ai_status',
				'id'    => 'hey_woo_wordpress_ai_status',
				'title' => __( 'WordPress AI connectors', 'hey-woo' ),
			);
		} else {
			$settings[] = array(
				'type'     => 'hey_woo_api_key',
				'id'       => self::ANTHROPIC_API_KEY_OPTION,
				'title'    => __( 'Anthropic API key', 'hey-woo' ),
				'desc'     => __( 'Starts with <code>sk-ant-</code>. Used by the direct Anthropic server-side client and never rendered back into this page.', 'hey-woo' ),
				'provider' => DifmProviderResolver::PROVIDER_ANTHROPIC,
				'default'  => '',
				'autoload' => false,
			);
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => 'hey_woo_difm_section',
		);

		if ( function_exists( 'hey_woo_today_enabled' ) && hey_woo_today_enabled() ) {
			$settings[] = array(
				'type'  => 'title',
				'title' => __( 'This Week monitoring', 'hey-woo' ),
				'id'    => 'hey_woo_this_week_section',
				'desc'  => __( 'Hey Woo refreshes the This Week feed once a day in your store timezone and can send a weekly summary of unresolved signals to the WooCommerce admin email.', 'hey-woo' ),
			);

			$settings[] = array(
				'type'     => 'checkbox',
				'id'       => ThisWeekSettings::OPTION_ENABLED,
				'title'    => __( 'Enable monitoring', 'hey-woo' ),
				'desc'     => __( 'Refresh the This Week signal feed automatically once a day.', 'hey-woo' ),
				'default'  => 'yes',
				'autoload' => true,
			);

			$settings[] = array(
				'type'     => 'checkbox',
				'id'       => ThisWeekSettings::OPTION_DIGEST_ENABLED,
				'title'    => __( 'Send weekly email digest', 'hey-woo' ),
				'desc'     => __( 'Email a short summary of unresolved signals to the WooCommerce admin email. Skipped silently when nothing material is detected.', 'hey-woo' ),
				'default'  => 'yes',
				'autoload' => true,
			);

			$settings[] = array(
				'type'     => 'select',
				'id'       => ThisWeekSettings::OPTION_DIGEST_DAY,
				'title'    => __( 'Digest day', 'hey-woo' ),
				'default'  => ThisWeekSettings::DEFAULT_DIGEST_DAY,
				'options'  => array(
					'monday'    => __( 'Monday', 'hey-woo' ),
					'tuesday'   => __( 'Tuesday', 'hey-woo' ),
					'wednesday' => __( 'Wednesday', 'hey-woo' ),
					'thursday'  => __( 'Thursday', 'hey-woo' ),
					'friday'    => __( 'Friday', 'hey-woo' ),
					'saturday'  => __( 'Saturday', 'hey-woo' ),
					'sunday'    => __( 'Sunday', 'hey-woo' ),
				),
				'autoload' => true,
			);

			$settings[] = array(
				'type'              => 'text',
				'id'                => ThisWeekSettings::OPTION_DIGEST_TIME,
				'title'             => __( 'Digest time', 'hey-woo' ),
				'desc'              => __( 'Store-local 24-hour time, formatted as HH:MM.', 'hey-woo' ),
				'default'           => ThisWeekSettings::DEFAULT_DIGEST_TIME,
				'placeholder'       => '09:00',
				'autoload'          => true,
				'custom_attributes' => array(
					'type'    => 'time',
					'pattern' => '[0-2][0-9]:[0-5][0-9]',
				),
			);

			$settings[] = array(
				'type' => 'sectionend',
				'id'   => 'hey_woo_this_week_section',
			);
		}

		return $settings;
	}

	/**
	 * Settings fields for plugin-level preferences.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function get_settings_for_settings_section() {
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Settings', 'hey-woo' ),
				'id'    => 'hey_woo_settings_section',
				'desc'  => __( 'Manage Hey Woo preferences that are not tied to a specific Claude connection.', 'hey-woo' ),
			),
			array(
				'type'  => 'hey_woo_telemetry',
				'id'    => self::TELEMETRY_OPTION,
				'title' => __( 'Usage tracking', 'hey-woo' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'hey_woo_settings_section',
			),
		);
	}

	/**
	 * Render the current section.
	 *
	 * @return void
	 */
	public function output() {
		global $current_section;

		if ( 'settings' === $current_section ) {
			\WC_Admin_Settings::output_fields( $this->get_settings_for_settings_section() );
			return;
		}

		\WC_Admin_Settings::output_fields( $this->get_settings_for_default_section() );
	}

	/**
	 * Return the description for the Hey Woo settings section.
	 *
	 * @return string
	 */
	private function get_difm_section_description() {
		$anthropic_constant = $this->get_api_key_constant_name( DifmProviderResolver::PROVIDER_ANTHROPIC );

		if ( DifmProviderEnvironment::is_connector_mode() ) {
			if ( '' !== $anthropic_constant ) {
				return sprintf(
					/* translators: %s: PHP constant name. */
					__( 'A direct Anthropic key is configured via the <code>%s</code> server constant. WordPress 7.0 uses native AI connectors for Hey Woo, so server-managed keys should be configured through the native <code>ANTHROPIC_API_KEY</code> connector constant or in Settings > Connectors.', 'hey-woo' ),
					esc_html( $anthropic_constant )
				);
			}

			return __( 'Hey Woo uses native WordPress AI providers configured in Settings > Connectors. Connect Anthropic, OpenAI, Google, or another provider there, then choose from the connected providers here.', 'hey-woo' );
		}

		if ( '' !== $anthropic_constant ) {
			return sprintf(
				/* translators: %s: PHP constant name. */
				__( 'A direct Anthropic key is configured via the <code>%s</code> server constant. The matching field below is disabled; edit the constant in <code>wp-config.php</code> instead. WordPress 7.0 or later uses native WordPress AI connectors instead.', 'hey-woo' ),
				esc_html( $anthropic_constant )
			);
		}

		return __( 'On this WordPress version, Hey Woo uses the direct Anthropic client. Enter an Anthropic API key below, or define <code>HEY_WOO_ANTHROPIC_KEY</code> in <code>wp-config.php</code> for a server-managed key. WordPress 7.0 or later uses native WordPress AI connectors instead.', 'hey-woo' );
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
			DifmProviderResolver::PROVIDER_ANTHROPIC => __( 'Anthropic', 'hey-woo' ),
		);
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
			<td class="forminp forminp-hey-woo-wp-ai-status">
				<?php if ( empty( $provider_options ) ) : ?>
					<p><?php esc_html_e( 'No WordPress AI provider is connected yet. Add Anthropic, OpenAI, Google, or another AI provider in Settings > Connectors.', 'hey-woo' ); ?></p>
					<p>
						<a class="button" href="<?php echo esc_url( $connectors_url ); ?>">
							<?php esc_html_e( 'Open Settings > Connectors', 'hey-woo' ); ?>
						</a>
					</p>
				<?php elseif ( 1 === count( $provider_options ) ) : ?>
					<?php $active_provider_label = (string) reset( $provider_options ); ?>
					<p>
						<?php
						printf(
							/* translators: %s: AI provider label. */
							esc_html__( 'Using %s from Settings > Connectors.', 'hey-woo' ),
							esc_html( $active_provider_label )
						);
						?>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'Choose between the AI providers already connected in Settings > Connectors.', 'hey-woo' ); ?></p>
				<?php endif; ?>
				<?php if ( ( new DifmProviderResolver() )->has_configured_provider() ) : ?>
					<p>
						<a class="button" href="<?php echo esc_url( $this->get_hey_woo_url() ); ?>">
							<?php esc_html_e( 'Open Hey Woo', 'hey-woo' ); ?>
						</a>
					</p>
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
			<div class="hey-woo-connector-migration">
				<?php if ( 'none' === $connector_source ) : ?>
					<p><strong><?php esc_html_e( 'Move saved Anthropic key to WordPress connectors', 'hey-woo' ); ?></strong></p>
					<p><?php esc_html_e( 'A legacy Anthropic key is still saved locally. Move it to the native Anthropic connector so Hey Woo can use the WordPress 7.0 provider flow, then remove the local copy.', 'hey-woo' ); ?></p>
					<p>
						<button
							type="submit"
							name="<?php echo esc_attr( self::ANTHROPIC_CONNECTOR_MIGRATE_FIELD ); ?>"
							value="yes"
							class="button"
						>
							<?php esc_html_e( 'Move key to connector', 'hey-woo' ); ?>
						</button>
					</p>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'Remove legacy local Anthropic key', 'hey-woo' ); ?></strong></p>
					<p><?php esc_html_e( 'The native Anthropic connector is already configured. Remove the old database copy so the connector is the only stored key used by Hey Woo.', 'hey-woo' ); ?></p>
					<p>
						<button
							type="submit"
							name="<?php echo esc_attr( self::ANTHROPIC_CONNECTOR_REMOVE_LOCAL_FIELD ); ?>"
							value="yes"
							class="button"
						>
							<?php esc_html_e( 'Remove legacy local key', 'hey-woo' ); ?>
						</button>
					</p>
				<?php endif; ?>
			</div>
			<?php
			return;
		}

		if ( '' !== $anthropic_constant ) {
			?>
			<div class="hey-woo-connector-migration">
				<p><strong><?php esc_html_e( 'Server-managed Anthropic key detected', 'hey-woo' ); ?></strong></p>
				<p>
					<?php
					printf(
						/* translators: 1: old constant name, 2: native constant name. */
						esc_html__( 'The direct Anthropic key comes from %1$s, so Hey Woo will not copy it into the database. Configure the native %2$s constant or add the key in Settings > Connectors.', 'hey-woo' ),
						esc_html( $anthropic_constant ),
						'ANTHROPIC_API_KEY'
					);
					?>
				</p>
				<p>
					<a class="button" href="<?php echo esc_url( $connectors_url ); ?>">
						<?php esc_html_e( 'Open Settings > Connectors', 'hey-woo' ); ?>
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
		$telemetry_enabled = 'yes' === get_option( self::TELEMETRY_OPTION, 'no' );
		$title             = isset( $value['title'] ) ? $value['title'] : '';
		?>
		<tr>
			<th scope="row" class="titledesc"><?php echo esc_html( $title ); ?></th>
			<td class="forminp forminp-checkbox">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo esc_html( $title ); ?></span></legend>
					<label for="hey-woo-telemetry-optin">
						<input
							id="hey-woo-telemetry-optin"
							name="<?php echo esc_attr( self::TELEMETRY_OPTION ); ?>"
							type="checkbox"
							value="yes"
							<?php checked( $telemetry_enabled ); ?>
						/>
						<?php esc_html_e( 'Share anonymised usage data to help improve Hey Woo', 'hey-woo' ); ?>
					</label>
					<p class="description">
						<?php
						printf(
							wp_kses(
								/* translators: %s: link to WooCommerce's usage tracking page. */
								__( 'You can opt out at any time. %s', 'hey-woo' ),
								array(
									'a' => array(
										'href'   => array(),
										'target' => array(),
										'rel'    => array(),
									),
								)
							),
							'<a href="https://woocommerce.com/usage-tracking/" target="_blank" rel="noopener">' . esc_html__( 'Learn more about usage tracking.', 'hey-woo' ) . '</a>'
						);
						?>
					</p>
				</fieldset>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the telemetry opt-in when Hey Woo settings are saved.
	 *
	 * WC does not know about our custom field type, and unchecked checkboxes
	 * are absent from the request, so handle the option explicitly.
	 *
	 * @return void
	 */
	public function save_telemetry_option() {
		global $current_section;

		if ( 'settings' !== $current_section ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC settings save handles the nonce.
		$enabled = isset( $_POST[ self::TELEMETRY_OPTION ] )
			&& 'yes' === sanitize_key( wp_unslash( $_POST[ self::TELEMETRY_OPTION ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		update_option( self::TELEMETRY_OPTION, $enabled ? 'yes' : 'no' );
	}

	/**
	 * Opt fresh installs into anonymised usage tracking by default.
	 *
	 * Existing installs keep their stored preference across reactivation.
	 *
	 * @return void
	 */
	public static function maybe_set_default_telemetry_option() {
		if ( false === get_option( self::TELEMETRY_OPTION, false ) ) {
			update_option( self::TELEMETRY_OPTION, 'yes' );
		}
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
			<td class="forminp forminp-hey-woo-api-key">
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
							esc_html__( 'This key is managed by the %s server constant.', 'hey-woo' ),
							esc_html( $constant )
						);
						?>
					</p>
				<?php elseif ( '' !== $stored_key ) : ?>
					<p class="description"><?php esc_html_e( 'A key is currently saved. Leave this field unchanged to keep it.', 'hey-woo' ); ?></p>
					<label for="<?php echo esc_attr( $clear_field ); ?>">
						<input
							type="checkbox"
							name="<?php echo esc_attr( $clear_field ); ?>"
							id="<?php echo esc_attr( $clear_field ); ?>"
							value="yes"
						/>
						<?php esc_html_e( 'Remove saved key', 'hey-woo' ); ?>
					</label>
					<?php if ( '' !== $this->get_hey_woo_url() ) : ?>
						<p>
							<a class="button" href="<?php echo esc_url( $this->get_hey_woo_url() ); ?>">
								<?php esc_html_e( 'Open Hey Woo', 'hey-woo' ); ?>
							</a>
						</p>
					<?php endif; ?>
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
			\WC_Admin_Settings::add_error( __( 'Hey Woo: no saved Anthropic key was found to move.', 'hey-woo' ) );
			return;
		}

		$connector_setting_name = DifmProviderEnvironment::get_connector_setting_name( DifmProviderResolver::PROVIDER_ANTHROPIC );
		if ( '' === $connector_setting_name ) {
			\WC_Admin_Settings::add_error( __( 'Hey Woo: the native Anthropic connector is not available yet. Install or activate the Anthropic provider connector, then try again.', 'hey-woo' ) );
			return;
		}

		$connector_source = DifmProviderEnvironment::get_connector_api_key_source( DifmProviderResolver::PROVIDER_ANTHROPIC );
		if ( 'none' !== $connector_source ) {
			\WC_Admin_Settings::add_error( __( 'Hey Woo: the native Anthropic connector already has a key, so the legacy key was left in place and not overwritten.', 'hey-woo' ) );
			return;
		}

		$result = AnthropicClient::validate_key( $database_key );
		if ( is_wp_error( $result ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: validation error message. */
					__( 'Hey Woo: the saved Anthropic key could not be moved because it failed validation - %s', 'hey-woo' ),
					$result->get_error_message()
				)
			);
			return;
		}

		if ( ! DifmProviderEnvironment::set_connector_api_key( DifmProviderResolver::PROVIDER_ANTHROPIC, $database_key ) ) {
			\WC_Admin_Settings::add_error( __( 'Hey Woo: the Anthropic connector key could not be saved. The legacy key was left in place.', 'hey-woo' ) );
			return;
		}

		update_option( self::DIFM_PROVIDER_OPTION, DifmProviderResolver::PROVIDER_ANTHROPIC, 'no' );
		$this->delete_api_key_option( DifmProviderResolver::PROVIDER_ANTHROPIC );
		\WC_Admin_Settings::add_message( __( 'Hey Woo: Anthropic key moved to Settings > Connectors and the legacy local key was removed.', 'hey-woo' ) );
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
			\WC_Admin_Settings::add_error( __( 'Hey Woo: no legacy local Anthropic key was found to remove.', 'hey-woo' ) );
			return;
		}

		$connector_source = DifmProviderEnvironment::get_connector_api_key_source( DifmProviderResolver::PROVIDER_ANTHROPIC );
		if ( 'none' === $connector_source ) {
			\WC_Admin_Settings::add_error( __( 'Hey Woo: connect Anthropic in Settings > Connectors before removing the legacy local key.', 'hey-woo' ) );
			return;
		}

		$this->delete_api_key_option( DifmProviderResolver::PROVIDER_ANTHROPIC );
		update_option( self::DIFM_PROVIDER_OPTION, DifmProviderResolver::PROVIDER_ANTHROPIC, 'no' );
		\WC_Admin_Settings::add_message( __( 'Hey Woo: legacy local Anthropic key removed. Hey Woo will use the native Anthropic connector.', 'hey-woo' ) );
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

		if ( ! $ai_provider_form_submitted || DifmProviderEnvironment::is_connector_mode() ) {
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
							__( 'Hey Woo: %1$s API key is invalid and has been removed - %2$s', 'hey-woo' ),
							DifmProviderResolver::provider_label( $provider ),
							$result->get_error_message()
						)
					);
					continue;
				}

				\WC_Admin_Settings::add_error(
					sprintf(
						/* translators: 1: provider name, 2: error message. */
						__( 'Hey Woo: %1$s API key was saved but could not be verified right now - %2$s', 'hey-woo' ),
						DifmProviderResolver::provider_label( $provider ),
						$result->get_error_message()
					)
				);
			}
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

		/**
		 * Filter the detected direct Anthropic key constant for tests.
		 *
		 * @since 0.5.0
		 *
		 * @param string $constant_name Constant name, or empty string.
		 */
		return (string) apply_filters( 'hey_woo_difm_anthropic_key_constant_name', $constant_name );
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

		return '';
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
	 * Delete a direct API key option.
	 *
	 * @param string $provider Provider value.
	 * @return void
	 */
	private function delete_api_key_option( $provider ) {
		unset( $provider );

		delete_option( self::ANTHROPIC_API_KEY_OPTION );
	}

	/**
	 * Return the Hey Woo admin URL when the app page is available.
	 *
	 * @return string
	 */
	private function get_hey_woo_url() {
		if ( ! ( new DifmProviderResolver() )->has_configured_provider() ) {
			return '';
		}

		return admin_url( 'admin.php?page=hey-woo-insights' );
	}
}
