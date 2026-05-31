<?php
/**
 * Class Php_Error_Reporting_Check.
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Checker\Checks\General;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use WordPress\Plugin_Check\Checker\Check_Categories;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\Abstract_File_Check;
use WordPress\Plugin_Check\Traits\Amend_Check_Result;
use WordPress\Plugin_Check\Traits\Stable_Check;

/**
 * Check for production-time PHP error reporting changes.
 *
 * @since 1.9.0
 */
class Php_Error_Reporting_Check extends Abstract_File_Check {

	use Amend_Check_Result;
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
	 * Amends the given result by running the check on the given list of files.
	 *
	 * @since 1.9.0
	 *
	 * @param Check_Result $result The check result to amend, including the plugin context to check.
	 * @param array        $files  List of absolute file paths.
	 */
	protected function check_files( Check_Result $result, array $files ) {
		$php_files   = self::filter_files_by_extension( $files, 'php' );
		$plugin_path = $result->plugin()->path();

		foreach ( $php_files as $file ) {
			// Skip test suite folders or files relative to the plugin's root path.
			$relative_file = str_replace( $plugin_path, '', $file );
			if ( preg_match( '#^(?:tests|test|testdata|phpunit)/#i', $relative_file ) || preg_match( '#/phpunit[^/]*$#i', $relative_file ) ) {
				continue;
			}

			$this->check_file( $result, $file );
		}
	}

	/**
	 * Scans a single PHP file for error reporting violations.
	 *
	 * @since 1.9.0
	 *
	 * @param Check_Result $result The check result to amend.
	 * @param string       $file   Absolute path to the file.
	 */
	private function check_file( Check_Result $result, string $file ) {
		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			return;
		}

		// Try AST-based detection first.
		$parser = ( new ParserFactory() )->create( ParserFactory::PREFER_PHP7 );
		try {
			$ast = $parser->parse( $contents );
			if ( null !== $ast ) {
				$this->check_ast( $result, $file, $ast );
				return;
			}
		} catch ( Error $e ) {
			// Fall through to regex-based detection if parsing fails.
		}

		$this->check_regex( $result, $file, $contents );
	}

	/**
	 * Scans the AST of a file for error reporting violations.
	 *
	 * @since 1.9.0
	 *
	 * @param Check_Result $result The check result to amend.
	 * @param string       $file   Absolute path to the file.
	 * @param array        $ast    The parsed AST nodes.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function check_ast( Check_Result $result, string $file, array $ast ) {
		$node_finder = new NodeFinder();
		$func_calls  = $node_finder->findInstanceOf( $ast, Expr\FuncCall::class );

		foreach ( $func_calls as $func_call ) {
			// @phpstan-ignore-next-line Access to property $name on Expr\FuncCall.
			if ( ! $func_call->name instanceof Node\Name ) {
				continue;
			}

			// @phpstan-ignore-next-line Access to property $name on Expr\FuncCall.
			$func_name = strtolower( $func_call->name->toString() );
			$line      = method_exists( $func_call, 'getStartLine' ) ? $func_call->getStartLine() : 0;

			// 1. Direct calls to error_reporting().
			if ( 'error_reporting' === $func_name ) {
				$this->add_violation( $result, $file, $line );
				continue;
			}

			// 2. ini_set() / ini_alter().
			if ( in_array( $func_name, array( 'ini_set', 'ini_alter' ), true ) ) {
				if ( ! empty( $func_call->args[0] ) ) {
					$first_arg = $func_call->args[0]->value;
					if ( $first_arg instanceof Node\Scalar\String_ ) {
						$arg_value = strtolower( $first_arg->value );
						if ( in_array( $arg_value, array( 'error_reporting', 'display_errors' ), true ) ) {
							$this->add_violation( $result, $file, $line );
							continue;
						}
					}
				}
			}

			// 3. define() overrides.
			if ( 'define' === $func_name ) {
				if ( ! empty( $func_call->args[0] ) ) {
					$first_arg = $func_call->args[0]->value;
					if ( $first_arg instanceof Node\Scalar\String_ ) {
						$arg_value = $first_arg->value;
						if ( in_array( $arg_value, array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG' ), true ) ) {
							$this->add_violation( $result, $file, $line );
							continue;
						}
					}
				}
			}
		}

		// Also check for the const keyword: e.g. const WP_DEBUG = true.
		$consts = $node_finder->findInstanceOf( $ast, Stmt\Const_::class );
		foreach ( $consts as $const_stmt ) {
			// @phpstan-ignore-next-line Access to property $consts on Stmt\Const_.
			foreach ( $const_stmt->consts as $const ) {
				$const_name = $const->name->toString();
				$line       = method_exists( $const, 'getStartLine' ) ? $const->getStartLine() : 0;
				if ( in_array( $const_name, array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG' ), true ) ) {
					$this->add_violation( $result, $file, $line );
				}
			}
		}
	}

	/**
	 * Fallback regex-based detection for error reporting violations.
	 *
	 * @since 1.9.0
	 *
	 * @param Check_Result $result   The check result to amend.
	 * @param string       $file     Absolute path to the file.
	 * @param string       $contents File contents.
	 */
	private function check_regex( Check_Result $result, string $file, string $contents ) {
		// Clean comments before checking regex.
		$cleaned = preg_replace( '/\/\*.*?\*\//s', '', $contents );
		$cleaned = preg_replace( '/\/\/.*$/m', '', $cleaned );
		$cleaned = preg_replace( '/#.*$/m', '', $cleaned );

		$patterns = array(
			// error_reporting(...).
			'/\berror_reporting\s*\(/i',
			// ini_set(...) / ini_alter(...).
			'/\bini_(?:set|alter)\s*\(\s*[\'"](?:error_reporting|display_errors)[\'"]/i',
			// define(...).
			'/\bdefine\s*\(\s*[\'"](?:WP_DEBUG|WP_DEBUG_LOG|WP_DEBUG_DISPLAY|SCRIPT_DEBUG)[\'"]/i',
			// const WP_DEBUG = ....
			'/\bconst\s+(?:WP_DEBUG|WP_DEBUG_LOG|WP_DEBUG_DISPLAY|SCRIPT_DEBUG)\b/i',
		);

		// Scan line by line to locate line numbers.
		$lines = explode( "\n", $cleaned );
		foreach ( $lines as $index => $line_content ) {
			$line_num = $index + 1;
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $line_content ) ) {
					$this->add_violation( $result, $file, $line_num );
					break; // Only flag once per line.
				}
			}
		}
	}

	/**
	 * Adds a standard warning message for a violation.
	 *
	 * @since 1.9.0
	 *
	 * @param Check_Result $result The check result to amend.
	 * @param string       $file   Absolute path to the file.
	 * @param int          $line   The line number on which the warning occurred.
	 */
	private function add_violation( Check_Result $result, string $file, int $line ) {
		$message = sprintf(
			'<strong>%1$s</strong><br><br>%2$s<br><br>%3$s<br><br>%4$s',
			__( 'Do not change PHP error reporting in production code', 'plugin-check' ),
			__( 'A plugin should not modify PHP\'s error-reporting configuration. Calls such as <code>error_reporting()</code>, <code>ini_set(\'display_errors\', &hellip;)</code>, or redefining <code>WP_DEBUG</code>, <code>WP_DEBUG_LOG</code>, <code>WP_DEBUG_DISPLAY</code> or <code>SCRIPT_DEBUG</code> change behaviour for every other plugin and theme on the site.', 'plugin-check' ),
			__( 'This can leak sensitive information (paths, secrets, stack traces) and breaks the standard debugging workflow for site owners and other developers. The host\'s <code>php.ini</code> and the site\'s <code>wp-config.php</code> are the correct places to control this.', 'plugin-check' ),
			__( 'Please remove these calls, or move them behind a strictly developer-only flag that is never set in shipped code.', 'plugin-check' )
		);

		$this->add_result_warning_for_file(
			$result,
			$message,
			'php_error_reporting_detected',
			$file,
			$line,
			0,
			'https://www.php.net/manual/en/function.error-reporting.php',
			8
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
}
