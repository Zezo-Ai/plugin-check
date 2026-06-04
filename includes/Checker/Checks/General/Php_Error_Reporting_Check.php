<?php
/**
 * Class Php_Error_Reporting_Check.
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
 * Delegates detection to the PhpErrorReportingSniff and translates its
 * per-pattern error codes into a single user-facing warning.
 *
 * @since 1.9.0
 */
class Php_Error_Reporting_Check extends Abstract_PHP_CodeSniffer_Check {

	use Stable_Check;

	/**
	 * Gets the categories for the check.
	 *
	 * Every check must have at least one category.
	 *
	 * @since 1.9.0
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
	 * @since 1.9.0
	 *
	 * @param Check_Result $result The check result to amend, including the plugin context to check.
	 * @return array An associative array of PHPCS CLI arguments.
	 */
	protected function get_args( Check_Result $result ) {
		return array(
			'extensions' => 'php',
			'standard'   => 'PluginCheck',
			'sniffs'     => 'PluginCheck.CodeAnalysis.PhpErrorReporting',
		);
	}

	/**
	 * Gets the description for the check.
	 *
	 * Every check must have a short description explaining what the check does.
	 *
	 * @since 1.9.0
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
	 * @since 1.9.0
	 *
	 * @return string The documentation URL.
	 */
	public function get_documentation_url(): string {
		return 'https://www.php.net/manual/en/function.error-reporting.php';
	}

	/**
	 * Amends the given result with a message for the specified file.
	 *
	 * Translates each PhpErrorReportingSniff error code into a single unified
	 * warning code (`php_error_reporting_detected`) so the check exposes one
	 * stable, user-facing message regardless of which pattern was detected.
	 *
	 * @since 1.9.0
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
		if ( 0 === strpos( $code, 'PluginCheck.CodeAnalysis.PhpErrorReporting.' ) ) {
			$warning_message = sprintf(
				'<strong>%1$s</strong><br><br>%2$s<br><br>%3$s<br><br>%4$s',
				__( 'Do not change PHP error reporting in production code', 'plugin-check' ),
				__( 'A plugin should not modify PHP\'s error-reporting configuration. Calls such as <code>error_reporting()</code>, <code>ini_set(\'display_errors\', &hellip;)</code>, or redefining <code>WP_DEBUG</code>, <code>WP_DEBUG_LOG</code>, <code>WP_DEBUG_DISPLAY</code> or <code>SCRIPT_DEBUG</code> change behaviour for every other plugin and theme on the site.', 'plugin-check' ),
				__( 'This can leak sensitive information (paths, secrets, stack traces) and breaks the standard debugging workflow for site owners and other developers. The host\'s <code>php.ini</code> and the site\'s <code>wp-config.php</code> are the correct places to control this.', 'plugin-check' ),
				__( 'Please remove these calls, or move them behind a strictly developer-only flag that is never set in shipped code.', 'plugin-check' )
			);

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
