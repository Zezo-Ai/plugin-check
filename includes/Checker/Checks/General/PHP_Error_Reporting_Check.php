<?php
/**
 * Class PHP_Error_Reporting_Check.
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Checker\Checks\General;

use WordPress\Plugin_Check\Checker\Check_Categories;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\Abstract_PHP_CodeSniffer_Check;
use WordPress\Plugin_Check\Traits\Stable_Check;

/**
 * Check for production-time PHP error reporting changes.
 *
 * Delegates detection to the PHPErrorReportingSniff and translates its
 * per-pattern error codes into a single user-facing warning.
 *
 * @since 2.1.0
 */
class PHP_Error_Reporting_Check extends Abstract_PHP_CodeSniffer_Check {

	use Stable_Check;

	/**
	 * Gets the categories for the check.
	 *
	 * Every check must have at least one category.
	 *
	 * @since 2.1.0
	 *
	 * @return array The categories for the check.
	 */
	public function get_categories() {
		return array(
			Check_Categories::CATEGORY_GENERAL,
		);
	}

	/**
	 * Returns an associative array of arguments to pass to PHPCS.
	 *
	 * @since 2.1.0
	 *
	 * @param Check_Result $result The check result to amend, including the plugin context to check.
	 * @return array An associative array of PHPCS CLI arguments.
	 */
	protected function get_args( Check_Result $result ) {
		return array(
			'extensions' => 'php',
			'standard'   => 'PluginCheck',
			'sniffs'     => 'PluginCheck.CodeAnalysis.PHPErrorReporting',
		);
	}

	/**
	 * Gets the description for the check.
	 *
	 * Every check must have a short description explaining what the check does.
	 *
	 * @since 2.1.0
	 *
	 * @return string Description.
	 */
	public function get_description(): string {
		return __( 'Detects runtime changes to PHP error reporting configuration or WordPress debug constants.', 'plugin-check' );
	}

	/**
	 * Gets the documentation URL for the check.
	 *
	 * Every check must have a URL with further information about the check.
	 *
	 * @since 2.1.0
	 *
	 * @return string The documentation URL.
	 */
	public function get_documentation_url(): string {
		return 'https://www.php.net/manual/en/function.error-reporting.php';
	}

	/**
	 * Amends the given result with a message for the specified file.
	 *
	 * Translates each PHPErrorReportingSniff error code into a single unified
	 * warning code (`php_error_reporting_detected`) so the check exposes one
	 * stable, user-facing message regardless of which pattern was detected.
	 *
	 * @since 2.1.0
	 *
	 * @param Check_Result $result   The check result to amend, including the plugin context to check.
	 * @param bool         $error    Whether it is an error or notice.
	 * @param string       $message  Error message.
	 * @param string       $code     Error code.
	 * @param string       $file     Absolute path to the file where the issue was found.
	 * @param int          $line     The line on which the message occurred. Default is 0 (unknown line).
	 * @param int          $column   The column on which the message occurred. Default is 0 (unknown column).
	 * @param string       $docs     URL for further information about the message.
	 * @param int          $severity Severity level. Default is 5.
	 */
	protected function add_result_message_for_file( Check_Result $result, $error, $message, $code, $file, $line = 0, $column = 0, string $docs = '', $severity = 5 ) {
		if ( 0 === strpos( $code, 'PluginCheck.CodeAnalysis.PHPErrorReporting.' ) ) {
			$warning_message = esc_html__( 'Plugins must not modify PHP error reporting settings or define WordPress debug constants (WP_DEBUG, WP_DEBUG_LOG, WP_DEBUG_DISPLAY, SCRIPT_DEBUG). Doing so can leak sensitive information and disrupt the standard debugging workflow. These configurations belong in php.ini or wp-config.php, not in plugin code.', 'plugin-check' );

			$docs     = $this->get_documentation_url();
			$code     = 'php_error_reporting_detected';
			$severity = 8;

			parent::add_result_message_for_file(
				$result,
				false,
				$warning_message,
				$code,
				$file,
				$line,
				$column,
				$docs,
				$severity
			);
			return;
		}

		parent::add_result_message_for_file(
			$result,
			$error,
			$message,
			$code,
			$file,
			$line,
			$column,
			$docs,
			$severity
		);
	}
}
