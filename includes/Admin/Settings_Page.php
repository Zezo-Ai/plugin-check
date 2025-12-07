<?php
/**
 * Class WordPress\Plugin_Check\Admin\Settings_Page
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Admin;

use WP_Error;

/**
 * Class to handle the Settings page for Plugin Check.
 *
 * @since 1.8.0
 */
final class Settings_Page {

	/**
	 * Option group name.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const OPTION_GROUP = 'plugin_check_settings';

	/**
	 * Option name.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const OPTION_NAME = 'plugin_check_settings';

	/**
	 * Page slug.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const PAGE_SLUG = 'plugin-check-settings';

	/**
	 * Admin page hook suffix.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	protected $hook_suffix = '';

	/**
	 * Registers WordPress hooks for the settings page.
	 *
	 * @since 1.8.0
	 */
	public function add_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Adds the settings page under the Settings menu.
	 *
	 * @since 1.8.0
	 */
	public function add_page() {
		$this->hook_suffix = add_submenu_page(
			'options-general.php',
			__( 'Plugin Check', 'plugin-check' ),
			__( 'Plugin Check', 'plugin-check' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Registers settings and settings fields.
	 *
	 * @since 1.8.0
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(
					'ai_provider'         => '',
					'ai_api_key'          => '',
					'ai_model'            => '',
					'ai_severity_errors'   => 7,
					'ai_severity_warnings' => 6,
				),
			)
		);

		add_settings_section(
			'ai_settings_section',
			__( 'AI Integration', 'plugin-check' ),
			array( $this, 'render_ai_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'ai_provider',
			__( 'AI Provider', 'plugin-check' ),
			array( $this, 'render_provider_field' ),
			self::PAGE_SLUG,
			'ai_settings_section',
			array(
				'label_for' => 'ai_provider',
			)
		);

		add_settings_field(
			'ai_api_key',
			__( 'API Key / Credentials', 'plugin-check' ),
			array( $this, 'render_api_key_field' ),
			self::PAGE_SLUG,
			'ai_settings_section',
			array(
				'label_for' => 'ai_api_key',
			)
		);

		add_settings_field(
			'ai_model',
			__( 'AI Model', 'plugin-check' ),
			array( $this, 'render_model_field' ),
			self::PAGE_SLUG,
			'ai_settings_section',
			array(
				'label_for' => 'ai_model',
			)
		);

		add_settings_section(
			'ai_severity_section',
			__( 'Severity Threshold', 'plugin-check' ),
			array( $this, 'render_severity_section_description' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'ai_severity_errors',
			__( 'Errors', 'plugin-check' ),
			array( $this, 'render_severity_errors_field' ),
			self::PAGE_SLUG,
			'ai_severity_section',
			array(
				'label_for' => 'ai_severity_errors',
			)
		);

		add_settings_field(
			'ai_severity_warnings',
			__( 'Warnings', 'plugin-check' ),
			array( $this, 'render_severity_warnings_field' ),
			self::PAGE_SLUG,
			'ai_severity_section',
			array(
				'label_for' => 'ai_severity_warnings',
			)
		);
	}

	/**
	 * Renders the AI settings section description.
	 *
	 * @since 1.8.0
	 */
	public function render_ai_section_description() {
		?>
		<p>
			<?php esc_html_e( 'Configure AI integration settings for false positive detection. Select your AI provider, enter your credentials, and choose the model to use for analysis.', 'plugin-check' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the severity section description.
	 *
	 * @since 1.8.0
	 */
	public function render_severity_section_description() {
		?>
		<p>
			<?php esc_html_e( 'Set the minimum severity level (1-10) to be analyzed by AI.', 'plugin-check' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the provider field.
	 *
	 * @since 1.8.0
	 *
	 * @param array $args Field arguments.
	 */
	public function render_provider_field( $args ) {
		$settings  = get_option( self::OPTION_NAME, array() );
		$value     = isset( $settings['ai_provider'] ) ? esc_attr( $settings['ai_provider'] ) : '';
		$providers = $this->get_available_providers();
		?>
		<select
			id="<?php echo esc_attr( $args['label_for'] ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[ai_provider]' ); ?>"
			class="regular-text"
		>
			<option value=""><?php esc_html_e( '-- Select Provider --', 'plugin-check' ); ?></option>
			<?php foreach ( $providers as $provider_key => $provider_label ) : ?>
				<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $value, $provider_key ); ?>>
					<?php echo esc_html( $provider_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Select the AI service provider you want to use for analysis.', 'plugin-check' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the API key field.
	 *
	 * @since 1.8.0
	 *
	 * @param array $args Field arguments.
	 */
	public function render_api_key_field( $args ) {
		$settings = get_option( self::OPTION_NAME, array() );
		$provider = isset( $settings['ai_provider'] ) ? esc_attr( $settings['ai_provider'] ) : '';
		$has_key  = isset( $settings['ai_api_key'] ) && ! empty( $settings['ai_api_key'] );
		?>
		<input
			type="password"
			id="<?php echo esc_attr( $args['label_for'] ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[ai_api_key]' ); ?>"
			value=""
			class="regular-text"
			placeholder="<?php echo $has_key ? esc_attr__( 'Leave blank to keep current key, or enter new key', 'plugin-check' ) : esc_attr__( 'Enter your API key', 'plugin-check' ); ?>"
			autocomplete="new-password"
			<?php echo empty( $provider ) ? 'disabled' : ''; ?>
		/>
		<?php if ( $has_key ) : ?>
			<p class="description" style="color: #46b450;">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'API key is currently set. Leave blank to keep it unchanged.', 'plugin-check' ); ?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php
			if ( empty( $provider ) ) {
				esc_html_e( 'Please select a provider first.', 'plugin-check' );
			} else {
				printf(
					/* translators: %s: Provider name */
					esc_html__( 'Enter your %s API key or credentials. This is required for AI-based false positive detection.', 'plugin-check' ),
					esc_html( $this->get_provider_label( $provider ) )
				);
			}
			?>
		</p>
		<?php
	}

	/**
	 * Renders the AI model field.
	 *
	 * @since 1.8.0
	 *
	 * @param array $args Field arguments.
	 */
	public function render_model_field( $args ) {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = isset( $settings['ai_model'] ) ? esc_attr( $settings['ai_model'] ) : '';
		$provider = isset( $settings['ai_provider'] ) ? esc_attr( $settings['ai_provider'] ) : '';
		$models   = $this->get_models_for_provider( $provider );
		?>
		<select
			id="<?php echo esc_attr( $args['label_for'] ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[ai_model]' ); ?>"
			class="regular-text"
			<?php echo empty( $provider ) ? 'disabled' : ''; ?>
		>
			<option value=""><?php esc_html_e( '-- Select Model --', 'plugin-check' ); ?></option>
			<?php foreach ( $models as $model_key => $model_label ) : ?>
				<option value="<?php echo esc_attr( $model_key ); ?>" <?php selected( $value, $model_key ); ?>>
					<?php echo esc_html( $model_label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php
			if ( empty( $provider ) ) {
				esc_html_e( 'Please select a provider first.', 'plugin-check' );
			} else {
				esc_html_e( 'Select the AI model to use for analysis. Different models have different capabilities and costs.', 'plugin-check' );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Renders the severity threshold field for errors.
	 *
	 * @since 1.8.0
	 *
	 * @param array $args Field arguments.
	 */
	public function render_severity_errors_field( $args ) {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = isset( $settings['ai_severity_errors'] ) ? intval( $settings['ai_severity_errors'] ) : 7;
		?>
		<input
			type="number"
			id="<?php echo esc_attr( $args['label_for'] ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[ai_severity_errors]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			min="1"
			max="10"
			class="small-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Minimum severity for errors (Default: 7)', 'plugin-check' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the severity threshold field for warnings.
	 *
	 * @since 1.8.0
	 *
	 * @param array $args Field arguments.
	 */
	public function render_severity_warnings_field( $args ) {
		$settings = get_option( self::OPTION_NAME, array() );
		$value    = isset( $settings['ai_severity_warnings'] ) ? intval( $settings['ai_severity_warnings'] ) : 6;
		?>
		<input
			type="number"
			id="<?php echo esc_attr( $args['label_for'] ); ?>"
			name="<?php echo esc_attr( self::OPTION_NAME . '[ai_severity_warnings]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			min="1"
			max="10"
			class="small-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Minimum severity for warnings (Default: 6)', 'plugin-check' ); ?>
		</p>
		<?php
	}

	/**
	 * Gets available AI providers.
	 *
	 * @since 1.8.0
	 *
	 * @return array Array of provider keys and labels.
	 */
	protected function get_available_providers() {
		return array(
			'openai'     => __( 'OpenAI (ChatGPT)', 'plugin-check' ),
			'anthropic'  => __( 'Anthropic (Claude)', 'plugin-check' ),
			'google'     => __( 'Google (Gemini)', 'plugin-check' ),
			'azure'      => __( 'Microsoft Azure OpenAI', 'plugin-check' ),
		);
	}

	/**
	 * Gets available models for a provider.
	 *
	 * @since 1.8.0
	 *
	 * @param string $provider Provider key.
	 * @return array Array of model keys and labels.
	 */
	protected function get_models_for_provider( $provider ) {
		$models = array();

		switch ( $provider ) {
			case 'openai':
				$models = array(
					'gpt-4o'         => __( 'GPT-4o', 'plugin-check' ),
					'gpt-4-turbo'    => __( 'GPT-4 Turbo', 'plugin-check' ),
					'gpt-4'          => __( 'GPT-4', 'plugin-check' ),
					'gpt-3.5-turbo'  => __( 'GPT-3.5 Turbo', 'plugin-check' ),
				);
				break;

			case 'anthropic':
				$models = array(
					'claude-3-5-sonnet-20241022' => __( 'Claude 3.5 Sonnet', 'plugin-check' ),
					'claude-3-opus-20240229'     => __( 'Claude 3 Opus', 'plugin-check' ),
					'claude-3-sonnet-20240229'   => __( 'Claude 3 Sonnet', 'plugin-check' ),
					'claude-3-haiku-20240307'    => __( 'Claude 3 Haiku', 'plugin-check' ),
				);
				break;

			case 'google':
				$models = array(
					'gemini-1.5-pro'  => __( 'Gemini 1.5 Pro', 'plugin-check' ),
					'gemini-1.5-flash' => __( 'Gemini 1.5 Flash', 'plugin-check' ),
					'gemini-pro'      => __( 'Gemini Pro', 'plugin-check' ),
				);
				break;

			case 'azure':
				$models = array(
					'gpt-4o'         => __( 'GPT-4o (Azure)', 'plugin-check' ),
					'gpt-4-turbo'    => __( 'GPT-4 Turbo (Azure)', 'plugin-check' ),
					'gpt-4'          => __( 'GPT-4 (Azure)', 'plugin-check' ),
					'gpt-35-turbo'   => __( 'GPT-3.5 Turbo (Azure)', 'plugin-check' ),
				);
				break;
		}

		return $models;
	}

	/**
	 * Gets the label for a provider.
	 *
	 * @since 1.8.0
	 *
	 * @param string $provider Provider key.
	 * @return string Provider label.
	 */
	protected function get_provider_label( $provider ) {
		$providers = $this->get_available_providers();
		return isset( $providers[ $provider ] ) ? $providers[ $provider ] : $provider;
	}

	/**
	 * Sanitizes settings input.
	 *
	 * @since 1.8.0
	 *
	 * @param array $input Settings input.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ) {
		$sanitized = array();

		if ( isset( $input['ai_provider'] ) ) {
			$providers = array_keys( $this->get_available_providers() );
			$sanitized['ai_provider'] = in_array( $input['ai_provider'], $providers, true ) ? $input['ai_provider'] : '';
		}

		// Get current settings to handle password field behavior.
		$current_settings = get_option( self::OPTION_NAME, array() );

		if ( isset( $input['ai_api_key'] ) ) {
			// If empty, keep existing key (password field unchanged).
			if ( ! empty( $input['ai_api_key'] ) ) {
				$sanitized['ai_api_key'] = sanitize_text_field( $input['ai_api_key'] );
			} elseif ( isset( $current_settings['ai_api_key'] ) && ! empty( $current_settings['ai_api_key'] ) ) {
				// Keep existing if not explicitly changed.
				$sanitized['ai_api_key'] = $current_settings['ai_api_key'];
			} else {
				$sanitized['ai_api_key'] = '';
			}
		} elseif ( isset( $current_settings['ai_api_key'] ) ) {
			// Keep existing if not in input.
			$sanitized['ai_api_key'] = $current_settings['ai_api_key'];
		}

		if ( isset( $input['ai_model'] ) ) {
			$provider = isset( $sanitized['ai_provider'] ) ? $sanitized['ai_provider'] : ( isset( $input['ai_provider'] ) ? $input['ai_provider'] : '' );
			$models   = array_keys( $this->get_models_for_provider( $provider ) );
			$sanitized['ai_model'] = in_array( $input['ai_model'], $models, true ) ? $input['ai_model'] : '';
		}

		if ( isset( $input['ai_severity_errors'] ) ) {
			$value = intval( $input['ai_severity_errors'] );
			$sanitized['ai_severity_errors'] = ( $value >= 1 && $value <= 10 ) ? $value : 7;
		} else {
			$sanitized['ai_severity_errors'] = 7;
		}

		if ( isset( $input['ai_severity_warnings'] ) ) {
			$value = intval( $input['ai_severity_warnings'] );
			$sanitized['ai_severity_warnings'] = ( $value >= 1 && $value <= 10 ) ? $value : 6;
		} else {
			$sanitized['ai_severity_warnings'] = 6;
		}

		// Test AI connection if all required fields are provided and settings have changed.
		$provider_changed = ! isset( $current_settings['ai_provider'] ) || $current_settings['ai_provider'] !== $sanitized['ai_provider'];
		$api_key_changed  = ! isset( $current_settings['ai_api_key'] ) || $current_settings['ai_api_key'] !== $sanitized['ai_api_key'];
		$model_changed    = ! isset( $current_settings['ai_model'] ) || $current_settings['ai_model'] !== $sanitized['ai_model'];

		if ( ! empty( $sanitized['ai_provider'] ) && ! empty( $sanitized['ai_api_key'] ) && ! empty( $sanitized['ai_model'] ) && ( $provider_changed || $api_key_changed || $model_changed ) ) {
			$connection_test = $this->test_ai_connection( $sanitized['ai_provider'], $sanitized['ai_api_key'], $sanitized['ai_model'] );
			if ( is_wp_error( $connection_test ) ) {
				// Add settings error to prevent saving.
				add_settings_error(
					self::OPTION_NAME,
					'ai_connection_failed',
					sprintf(
						/* translators: %s: Error message */
						__( 'AI connection test failed: %s. Settings were not saved.', 'plugin-check' ),
						$connection_test->get_error_message()
					),
					'error'
				);
				// Return current settings instead of new ones to prevent saving invalid settings.
				return $current_settings;
			}
		}

		return $sanitized;
	}

	/**
	 * Tests the AI connection with provided credentials.
	 *
	 * @since 1.8.0
	 *
	 * @param string $provider Provider key.
	 * @param string $api_key  API key.
	 * @param string $model    Model name.
	 * @return bool|WP_Error True if connection successful, WP_Error on failure.
	 */
	protected function test_ai_connection( $provider, $api_key, $model ) {
		if ( ! class_exists( '\WordPress\AI_Client\Client' ) ) {
			return new WP_Error(
				'ai_client_not_available',
				__( 'AI client library is not available. Please ensure wp-ai-client is installed.', 'plugin-check' )
			);
		}

		// Validate required parameters.
		if ( empty( $provider ) || empty( $api_key ) || empty( $model ) ) {
			return new WP_Error(
				'ai_missing_parameters',
				__( 'Provider, API key, and model are required to test the connection.', 'plugin-check' )
			);
		}

		try {
			$ai_client = new \WordPress\AI_Client\Client(
				array(
					'provider' => $provider,
					'api_key'  => $api_key,
					'model'    => $model,
				)
			);

			// Test with a simple prompt to verify connection works.
			$test_prompt = __( 'Test connection. Respond with "OK" only.', 'plugin-check' );
			$response    = $ai_client->request(
				$test_prompt,
				array(
					'temperature' => 0.3,
					'max_tokens'  => 10,
				)
			);

			// Check if we got a valid response.
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// If we got a response (array or string), the connection works.
			if ( is_array( $response ) || is_string( $response ) ) {
				return true;
			}

			return new WP_Error(
				'ai_invalid_response',
				__( 'Received invalid response from AI service. Please check your API key and model.', 'plugin-check' )
			);
		} catch ( \Exception $e ) {
			$error_message = $e->getMessage();

			// Provide more user-friendly error messages for common issues.
			if ( false !== strpos( strtolower( $error_message ), 'authentication' ) || false !== strpos( strtolower( $error_message ), 'unauthorized' ) ) {
				return new WP_Error(
					'ai_authentication_failed',
					__( 'Authentication failed. Please check your API key.', 'plugin-check' )
				);
			}

			if ( false !== strpos( strtolower( $error_message ), 'model' ) || false !== strpos( strtolower( $error_message ), 'not found' ) ) {
				return new WP_Error(
					'ai_model_not_found',
					__( 'The selected model is not available. Please check your model selection.', 'plugin-check' )
				);
			}

			return new WP_Error(
				'ai_connection_error',
				sprintf(
					/* translators: %s: Error message */
					__( 'Connection error: %s', 'plugin-check' ),
					$error_message
				)
			);
		}
	}

	/**
	 * Gets the AI provider.
	 *
	 * @since 1.8.0
	 *
	 * @return string AI provider.
	 */
	public static function get_provider() {
		$settings = get_option( self::OPTION_NAME, array() );
		return isset( $settings['ai_provider'] ) ? $settings['ai_provider'] : '';
	}

	/**
	 * Gets the AI API key.
	 *
	 * @since 1.8.0
	 *
	 * @return string AI API key.
	 */
	public static function get_api_key() {
		$settings = get_option( self::OPTION_NAME, array() );
		return isset( $settings['ai_api_key'] ) ? $settings['ai_api_key'] : '';
	}

	/**
	 * Gets the AI model.
	 *
	 * @since 1.8.0
	 *
	 * @return string AI model.
	 */
	public static function get_model() {
		$settings = get_option( self::OPTION_NAME, array() );
		return isset( $settings['ai_model'] ) ? $settings['ai_model'] : '';
	}

	/**
	 * Gets the AI severity threshold for errors.
	 *
	 * @since 1.8.0
	 *
	 * @return int AI severity threshold for errors.
	 */
	public static function get_severity_errors() {
		$settings = get_option( self::OPTION_NAME, array() );
		return isset( $settings['ai_severity_errors'] ) ? intval( $settings['ai_severity_errors'] ) : 7;
	}

	/**
	 * Gets the AI severity threshold for warnings.
	 *
	 * @since 1.8.0
	 *
	 * @return int AI severity threshold for warnings.
	 */
	public static function get_severity_warnings() {
		$settings = get_option( self::OPTION_NAME, array() );
		return isset( $settings['ai_severity_warnings'] ) ? intval( $settings['ai_severity_warnings'] ) : 6;
	}

	/**
	 * Renders the settings page.
	 *
	 * @since 1.8.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'plugin-check' ) );
		}

		// Show updated message.
		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Check if there are any error messages already set.
			$settings_errors = get_settings_errors( self::OPTION_NAME );
			$has_errors      = false;
			if ( ! empty( $settings_errors ) ) {
				foreach ( $settings_errors as $error ) {
					if ( 'error' === $error['type'] ) {
						$has_errors = true;
						break;
					}
				}
			}

			// Only show success message if no errors.
			if ( ! $has_errors ) {
				// Check if AI settings are configured.
				$settings = get_option( self::OPTION_NAME, array() );
				if ( ! empty( $settings['ai_provider'] ) && ! empty( $settings['ai_api_key'] ) && ! empty( $settings['ai_model'] ) ) {
					add_settings_error(
						self::OPTION_NAME,
						'settings_updated',
						__( 'Settings saved successfully. AI connection verified.', 'plugin-check' ),
						'success'
					);
				} else {
					add_settings_error(
						self::OPTION_NAME,
						'settings_updated',
						__( 'Settings saved.', 'plugin-check' ),
						'success'
					);
				}
			}
		}

		settings_errors( self::OPTION_NAME );

		// Enqueue script for dynamic model selection.
		wp_enqueue_script( 'jquery' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<script type="text/javascript">
		jQuery( document ).ready( function( $ ) {
			var $provider = $( '#ai_provider' );
			var $apiKey = $( '#ai_api_key' );
			var $model = $( '#ai_model' );

			function updateFields() {
				var provider = $provider.val();
				if ( provider ) {
					$apiKey.prop( 'disabled', false );
					$model.prop( 'disabled', false );
					updateModelOptions( provider );
				} else {
					$apiKey.prop( 'disabled', true );
					$model.prop( 'disabled', true ).val( '' );
				}
			}

			function updateModelOptions( provider ) {
				var models = {
					'openai': {
						'gpt-4o': '<?php echo esc_js( __( 'GPT-4o', 'plugin-check' ) ); ?>',
						'gpt-4-turbo': '<?php echo esc_js( __( 'GPT-4 Turbo', 'plugin-check' ) ); ?>',
						'gpt-4': '<?php echo esc_js( __( 'GPT-4', 'plugin-check' ) ); ?>',
						'gpt-3.5-turbo': '<?php echo esc_js( __( 'GPT-3.5 Turbo', 'plugin-check' ) ); ?>'
					},
					'anthropic': {
						'claude-3-5-sonnet-20241022': '<?php echo esc_js( __( 'Claude 3.5 Sonnet', 'plugin-check' ) ); ?>',
						'claude-3-opus-20240229': '<?php echo esc_js( __( 'Claude 3 Opus', 'plugin-check' ) ); ?>',
						'claude-3-sonnet-20240229': '<?php echo esc_js( __( 'Claude 3 Sonnet', 'plugin-check' ) ); ?>',
						'claude-3-haiku-20240307': '<?php echo esc_js( __( 'Claude 3 Haiku', 'plugin-check' ) ); ?>'
					},
					'google': {
						'gemini-1.5-pro': '<?php echo esc_js( __( 'Gemini 1.5 Pro', 'plugin-check' ) ); ?>',
						'gemini-1.5-flash': '<?php echo esc_js( __( 'Gemini 1.5 Flash', 'plugin-check' ) ); ?>',
						'gemini-pro': '<?php echo esc_js( __( 'Gemini Pro', 'plugin-check' ) ); ?>'
					},
					'azure': {
						'gpt-4o': '<?php echo esc_js( __( 'GPT-4o (Azure)', 'plugin-check' ) ); ?>',
						'gpt-4-turbo': '<?php echo esc_js( __( 'GPT-4 Turbo (Azure)', 'plugin-check' ) ); ?>',
						'gpt-4': '<?php echo esc_js( __( 'GPT-4 (Azure)', 'plugin-check' ) ); ?>',
						'gpt-35-turbo': '<?php echo esc_js( __( 'GPT-3.5 Turbo (Azure)', 'plugin-check' ) ); ?>'
					}
				};

				var providerModels = models[ provider ] || {};
				var currentValue = $model.val();

				$model.empty();
				$model.append( '<option value=""><?php echo esc_js( __( '-- Select Model --', 'plugin-check' ) ); ?></option>' );

				$.each( providerModels, function( key, label ) {
					var selected = currentValue === key ? ' selected' : '';
					$model.append( '<option value="' + key + '"' + selected + '>' + label + '</option>' );
				} );
			}

			$provider.on( 'change', updateFields );
			updateFields();
		} );
		</script>
		<?php
	}

	/**
	 * Gets the hook suffix under which the settings page is added.
	 *
	 * @since 1.8.0
	 *
	 * @return string Hook suffix, or empty string if settings page was not added.
	 */
	public function get_hook_suffix() {
		return $this->hook_suffix;
	}

}

