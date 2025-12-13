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

		$name = isset( $_POST['plugin_name'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin_name'] ) ) : '';
		$name = trim( $name );

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a plugin name.', 'plugin-check' ) ) );
		}

		$settings = get_option( self::OPTION_NAME, array() );
		$provider = isset( $settings['ai_provider'] ) ? (string) $settings['ai_provider'] : '';
		$api_key  = isset( $settings['ai_api_key'] ) ? (string) $settings['ai_api_key'] : '';
		$model    = isset( $settings['ai_model'] ) ? (string) $settings['ai_model'] : '';

		if ( empty( $provider ) || empty( $api_key ) || empty( $model ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'AI settings are not configured. Please configure Provider, API key, and Model in Plugin Check settings first.', 'plugin-check' ),
				)
			);
		}

		$analysis = $this->run_name_analysis( $provider, $api_key, $model, $name );
		if ( is_wp_error( $analysis ) ) {
			wp_send_json_error( array( 'message' => $analysis->get_error_message() ) );
		}

		$parsed = $this->parse_analysis( $analysis );

		wp_send_json_success(
			array(
				'verdict'     => $parsed['verdict'],
				'explanation' => $parsed['explanation'],
				'raw'         => $analysis,
			)
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
				<p>
					<strong><?php echo esc_html__( 'Verdict:', 'plugin-check' ); ?></strong>
					<span id="plugin-check-namer-verdict"></span>
				</p>
				<p>
					<strong><?php echo esc_html__( 'Explanation:', 'plugin-check' ); ?></strong>
					<span id="plugin-check-namer-explanation"></span>
				</p>

				<details>
					<summary><?php echo esc_html__( 'Full AI output', 'plugin-check' ); ?></summary>
					<pre id="plugin-check-namer-raw" style="white-space: pre-wrap;"></pre>
				</details>
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
			$this->store_result(
				$user_id,
				array(
					'input' => '',
					'error' => new WP_Error(
						'missing_input',
						__( 'Please enter a plugin name.', 'plugin-check' )
					),
				)
			);
			wp_safe_redirect( $this->get_page_url() );
			exit;
		}

		$settings = get_option( self::OPTION_NAME, array() );
		$provider = isset( $settings['ai_provider'] ) ? (string) $settings['ai_provider'] : '';
		$api_key  = isset( $settings['ai_api_key'] ) ? (string) $settings['ai_api_key'] : '';
		$model    = isset( $settings['ai_model'] ) ? (string) $settings['ai_model'] : '';

		if ( empty( $provider ) || empty( $api_key ) || empty( $model ) ) {
			$this->store_result(
				$user_id,
				array(
					'input' => $input,
					'error' => new WP_Error( 'missing_ai_config', __( 'AI settings are not configured. Please configure Provider, API key, and Model in Plugin Check settings first.', 'plugin-check' ) ),
				)
			);
			wp_safe_redirect( $this->get_page_url() );
			exit;
		}

		$analysis = $this->run_name_analysis( $provider, $api_key, $model, $input );

		if ( is_wp_error( $analysis ) ) {
			$this->store_result(
				$user_id,
				array(
					'input' => $input,
					'error' => $analysis,
				)
			);
			wp_safe_redirect( $this->get_page_url() );
			exit;
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
	 * Gets the page URL.
	 *
	 * @since 1.8.0
	 *
	 * @return string
	 */
	protected function get_page_url() {
		return add_query_arg( array( 'page' => self::MENU_SLUG ), admin_url( 'tools.php' ) );
	}
}
