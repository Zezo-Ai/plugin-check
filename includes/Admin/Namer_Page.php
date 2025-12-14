<?php
/**
 * Class WordPress\Plugin_Check\Admin\Namer_Page
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Admin;

use WordPress\Plugin_Check\Traits\AI_Check_Names;
use WordPress\Plugin_Check\Traits\AI_Connect;
use WP_Error;

/**
 * Admin page for the Plugin Check Namer tool.
 *
 * @since 1.8.0
 */
final class Namer_Page {

	use AI_Connect;
	use AI_Check_Names;

	/**
	 * Menu slug.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const MENU_SLUG = 'plugin-check-namer';

	/**
	 * Option name used by Plugin Check settings.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const OPTION_NAME = 'plugin_check_settings';

	/**
	 * Admin-post action for analysis.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	const ACTION_ANALYZE = 'plugin_check_namer_analyze';

	/**
	 * Hook suffix for the tools page.
	 *
	 * @since 1.8.0
	 * @var string
	 */
	protected $hook_suffix = '';

	/**
	 * Registers WordPress hooks.
	 *
	 * @since 1.8.0
	 */
	public function add_hooks() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_' . self::ACTION_ANALYZE, array( $this, 'handle_analyze' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_plugin_check_namer_analyze', array( $this, 'ajax_analyze' ) );
	}

	/**
	 * Adds the tools page.
	 *
	 * @since 1.8.0
	 */
	public function add_page() {
		$this->hook_suffix = add_management_page(
			__( 'Plugin Check Namer', 'plugin-check' ),
			__( 'Plugin Check Namer', 'plugin-check' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueues scripts for the tools page.
	 *
	 * @since 1.8.0
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook_suffix ) {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'plugin-check-namer',
			plugins_url( 'assets/js/plugin-check-namer.js', WP_PLUGIN_CHECK_MAIN_FILE ),
			array(),
			WP_PLUGIN_CHECK_VERSION,
			true
		);

		wp_localize_script(
			'plugin-check-namer',
			'pluginCheckNamer',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'plugin_check_namer_ajax' ),
				'messages' => array(
					'missingName'  => __( 'Please enter a plugin name.', 'plugin-check' ),
					'genericError' => __( 'An unexpected error occurred.', 'plugin-check' ),
				),
			)
		);
	}

	/**
	 * AJAX handler to analyze a plugin name.
	 *
	 * @since 1.8.0
	 */
	public function ajax_analyze() {
		check_ajax_referer( 'plugin_check_namer_ajax', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'plugin-check' ) ) );
		}

		$name = $this->get_plugin_name_from_request();
		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a plugin name.', 'plugin-check' ) ) );
		}

		$ai_config = $this->get_ai_config();
		if ( is_wp_error( $ai_config ) ) {
			wp_send_json_error( array( 'message' => $ai_config->get_error_message() ) );
		}

		$analysis = $this->run_name_analysis( $ai_config['provider'], $ai_config['api_key'], $ai_config['model'], $name );
		if ( is_wp_error( $analysis ) ) {
			wp_send_json_error( array( 'message' => $analysis->get_error_message() ) );
		}

		$parsed = $this->parse_analysis( $analysis );

		// Use formatted raw from parsed if available, otherwise use original.
		$raw_output = '';
		if ( ! empty( $parsed['raw'] ) ) {
			$raw_output = $parsed['raw'];
		} elseif ( is_array( $analysis ) && isset( $analysis['text'] ) ) {
			$raw_output = $analysis['text'];
		} elseif ( is_string( $analysis ) ) {
			$raw_output = $analysis;
		}

		// Format JSON if the raw output is JSON.
		$raw_output = $this->format_json_output( $raw_output );

		$response = array(
			'verdict'     => $parsed['verdict'],
			'explanation' => $parsed['explanation'],
			'raw'         => $raw_output,
		);

		// Add confusion arrays if available.
		if ( ! empty( $parsed['confusion_existing_plugins'] ) ) {
			$response['confusion_existing_plugins'] = $parsed['confusion_existing_plugins'];
		}
		if ( ! empty( $parsed['confusion_existing_others'] ) ) {
			$response['confusion_existing_others'] = $parsed['confusion_existing_others'];
		}

		wp_send_json_success( $response );
	}

	/**
	 * Gets plugin name from request.
	 *
	 * @since 1.8.0
	 *
	 * @return string Plugin name or empty string.
	 */
	protected function get_plugin_name_from_request() {
		$name = isset( $_POST['plugin_name'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_name'] ) ) : '';
		return trim( $name );
	}

	/**
	 * Gets AI configuration from settings.
	 *
	 * @since 1.8.0
	 *
	 * @return array|WP_Error AI config array or error.
	 */
	protected function get_ai_config() {
		$settings = get_option( self::OPTION_NAME, array() );
		$provider = isset( $settings['ai_provider'] ) ? (string) $settings['ai_provider'] : '';
		$api_key  = isset( $settings['ai_api_key'] ) ? (string) $settings['ai_api_key'] : '';
		$model    = isset( $settings['ai_model'] ) ? (string) $settings['ai_model'] : '';

		if ( empty( $provider ) || empty( $api_key ) || empty( $model ) ) {
			return new WP_Error(
				'missing_ai_config',
				__( 'AI settings are not configured. Please configure Provider, API key, and Model in Plugin Check settings first.', 'plugin-check' )
			);
		}

		return array(
			'provider' => $provider,
			'api_key'  => $api_key,
			'model'    => $model,
		);
	}

	/**
	 * Renders the page.
	 *
	 * @since 1.8.0
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Plugin Check Namer Tool', 'plugin-check' ); ?></h1>

			<p class="description">
				<?php echo esc_html__( 'Disclaimer: This tool provides guidance only and is not definitive. It contains a prompt that is used to evaluate the similarity of a plugin name to other plugin names and complish with trademark regulations.', 'plugin-check' ); ?>
			</p>

			<form id="plugin-check-namer-form" method="post">
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="plugin_check_namer_input"><?php echo esc_html__( 'Plugin name', 'plugin-check' ); ?></label>
							</th>
							<td>
								<input
									type="text"
									id="plugin_check_namer_input"
									name="plugin_check_namer_input"
									class="large-text"
									value=""
									required
								/>
								<p class="description">
									<?php echo esc_html__( 'Enter the plugin name you want to evaluate.', 'plugin-check' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit" style="text-align: left;">
					<button type="submit" class="button button-primary" id="plugin-check-namer-submit"><?php echo esc_html__( 'Evaluate name', 'plugin-check' ); ?></button>
					<span class="spinner" id="plugin-check-namer-spinner" style="float: none; margin-left: 10px;"></span>
				</p>
			</form>

			<div id="plugin-check-namer-error" class="notice notice-error" style="display: none;"><p></p></div>

			<div id="plugin-check-namer-result" style="display: none;">
				<h2><?php echo esc_html__( 'Result', 'plugin-check' ); ?></h2>
				<div id="plugin-check-namer-verdict-container" style="display: none; margin-bottom: 20px; padding: 15px; background: #fff; border-left: 4px solid #2271b1;">
					<p style="margin: 0 0 10px 0;">
						<strong><?php echo esc_html__( 'Verdict:', 'plugin-check' ); ?></strong>
						<span id="plugin-check-namer-verdict"></span>
					</p>
					<p style="margin: 0 0 10px 0;">
						<strong><?php echo esc_html__( 'Explanation:', 'plugin-check' ); ?></strong>
						<span id="plugin-check-namer-explanation"></span>
					</p>
					<p id="plugin-check-namer-timing" style="display: none; margin: 0; color: #646970; font-style: italic; font-size: 0.9em;">
						<strong><?php echo esc_html__( 'Analysis completed in:', 'plugin-check' ); ?></strong>
						<span id="plugin-check-namer-timing-value"></span>
					</p>
				</div>
				<div id="plugin-check-namer-confusion-plugins" style="display: none; margin-top: 20px;">
					<p><strong><?php echo esc_html__( 'Similar Existing Plugins', 'plugin-check' ); ?></strong></p>
					<div id="plugin-check-namer-confusion-plugins-list"></div>
				</div>

				<div id="plugin-check-namer-confusion-others" style="display: none; margin-top: 20px;">
					<h3><?php echo esc_html__( 'Similar Existing Projects/Trademarks', 'plugin-check' ); ?></h3>
					<div id="plugin-check-namer-confusion-others-list"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handles the analysis form submission.
	 *
	 * @since 1.8.0
	 */
	public function handle_analyze() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'plugin-check' ) );
		}

		check_admin_referer( 'plugin_check_namer_analyze', 'plugin_check_namer_nonce' );

		$input = isset( $_POST['plugin_check_namer_input'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_check_namer_input'] ) ) : '';
		$input = trim( $input );

		$user_id = get_current_user_id();

		if ( empty( $input ) ) {
			$this->handle_analyze_error( $user_id, '', new WP_Error( 'missing_input', __( 'Please enter a plugin name.', 'plugin-check' ) ) );
			return;
		}

		$ai_config = $this->get_ai_config();
		if ( is_wp_error( $ai_config ) ) {
			$this->handle_analyze_error( $user_id, $input, $ai_config );
			return;
		}

		$analysis = $this->run_name_analysis( $ai_config['provider'], $ai_config['api_key'], $ai_config['model'], $input );

		if ( is_wp_error( $analysis ) ) {
			$this->handle_analyze_error( $user_id, $input, $analysis );
			return;
		}

		$this->store_result(
			$user_id,
			array(
				'input'    => $input,
				'analysis' => $analysis,
			)
		);
		wp_safe_redirect( $this->get_page_url() );
		exit;
	}

	/**
	 * Handles analyze error and redirects.
	 *
	 * @since 1.8.0
	 *
	 * @param int      $user_id User ID.
	 * @param string   $input   Input value.
	 * @param WP_Error $error   Error object.
	 */
	protected function handle_analyze_error( $user_id, $input, $error ) {
		$this->store_result(
			$user_id,
			array(
				'input' => $input,
				'error' => $error,
			)
		);
		wp_safe_redirect( $this->get_page_url() );
		exit;
	}

	/**
	 * Gets the page URL.
	 *
	 * @since 1.8.0
	 *
	 * @return string
	 */
	protected function get_page_url() {
		return add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'tools.php' ) );
	}

	/**
	 * Formats JSON output with proper indentation if the text is valid JSON.
	 *
	 * @since 1.8.0
	 *
	 * @param string $text Text that might be JSON.
	 * @return string Formatted JSON or original text.
	 */
	protected function format_json_output( $text ) {
		if ( empty( $text ) || ! is_string( $text ) ) {
			return $text;
		}

		$trimmed = trim( $text );

		// Remove markdown code fences if present.
		$trimmed = preg_replace( '/^```(?:json)?\s*\n?/m', '', $trimmed );
		$trimmed = preg_replace( '/\n?```\s*$/m', '', $trimmed );
		$trimmed = trim( $trimmed );

		// Check if it looks like JSON (starts with { or [).
		if ( '{' !== $trimmed[0] && '[' !== $trimmed[0] ) {
			return $text;
		}

		// Try to extract JSON object/array if wrapped in other text.
		$json_text = $trimmed;
		$first_brace = strpos( $trimmed, '{' );
		$first_bracket = strpos( $trimmed, '[' );
		$start = -1;
		$end   = -1;

		if ( false !== $first_brace && ( false === $first_bracket || $first_brace < $first_bracket ) ) {
			// Looks like an object.
			$start = $first_brace;
			$end   = strrpos( $trimmed, '}' );
		} elseif ( false !== $first_bracket ) {
			// Looks like an array.
			$start = $first_bracket;
			$end   = strrpos( $trimmed, ']' );
		}

		if ( -1 !== $start && -1 !== $end && $end > $start ) {
			$json_text = substr( $trimmed, $start, $end - $start + 1 );
		}

		// Try to parse and format as JSON.
		$decoded = json_decode( $json_text, true );
		if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
			return wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		}

		// Not valid JSON, return original.
		return $text;
	}
}
