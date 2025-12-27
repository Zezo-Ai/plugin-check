<?php
/**
 * WriteFileSniff
 *
 * Based on code from {@link https://github.com/WordPress/WordPress-Coding-Standards}
 * which is licensed under {@link https://opensource.org/licenses/MIT}.
 *
 * @package PluginCheck
 */

namespace PluginCheckCS\PluginCheck\Sniffs\CodeAnalysis;

use PHP_CodeSniffer\Util\Tokens;
use PHPCSUtils\Utils\PassedParameters;
use WordPressCS\WordPress\AbstractFunctionParameterSniff;

/**
 * Verifies plugins don't save data in the plugin folder.
 *
 * Plugin folders are deleted when upgraded, so using them to store any data is problematic.
 * Plugins should use the uploads directory or the database instead.
 *
 * @link https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
 *
 * @since 1.1.0
 */
final class WriteFileSniff extends AbstractFunctionParameterSniff {

	/**
	 * The group name for this group of functions.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	protected $group_name = 'file_write';

	/**
	 * List of file write functions that need to be checked.
	 *
	 * @since 1.1.0
	 *
	 * @var array<string, true> Key is function name, value irrelevant.
	 */
	protected $target_functions = array(
		// File write functions - check first parameter (file path).
		'fwrite'            => true,
		'fputs'             => true,
		'file_put_contents' => true,
		'touch'             => true,
		// File copy/move functions - check second parameter (destination path).
		'copy'              => true,
		'rename'            => true,
		'copy_dir'          => true,
		'move_dir'          => true,
		'unzip_file'        => true,
	);

	/**
	 * Parameter positions for each function.
	 *
	 * @var array<string, int>
	 */
	private $param_positions = array(
		'fwrite'            => 1,
		'fputs'             => 1,
		'file_put_contents' => 1,
		'touch'             => 1,
		'copy'              => 2,
		'rename'            => 2,
		'copy_dir'          => 2,
		'move_dir'          => 2,
		'unzip_file'        => 2,
	);

	/**
	 * WordPress constants that indicate plugin directory usage.
	 *
	 * @var array
	 */
	private $plugin_constants = array(
		'WP_PLUGIN_DIR',
		'WP_PLUGIN_URL',
		'PLUGINDIR',
		'WPINC',
		'WP_CONTENT_DIR',
		'WP_CONTENT_URL',
	);

	/**
	 * WordPress functions that indicate plugin directory usage.
	 *
	 * @var array
	 */
	private $plugin_functions = array(
		'plugins_url',
		'plugin_dir_path',
		'plugin_dir_url',
	);

	/**
	 * WordPress functions that indicate safe directory usage (uploads, temp).
	 *
	 * @var array
	 */
	private $safe_functions = array(
		'wp_upload_dir',
		'wp_tempnam',
		'get_temp_dir',
	);

	/**
	 * Process the parameters of a matched function.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $stackPtr        The position of the current token in the stack.
	 * @param string $group_name      The name of the group which was matched.
	 * @param string $matched_content The token content (function name) which was matched in lowercase.
	 * @param array  $parameters      Array with information about the parameters.
	 *
	 * @return void
	 */
	public function process_parameters( $stackPtr, $group_name, $matched_content, $parameters ) {
		$param_position = isset( $this->param_positions[ $matched_content ] ) ? $this->param_positions[ $matched_content ] : 1;
		$path_param     = PassedParameters::getParameterFromStack( $parameters, $param_position, array() );

		if ( false === $path_param ) {
			return;
		}

		// Get the content of the parameter.
		$path_content = '';
		for ( $i = $path_param['start']; $i <= $path_param['end']; $i++ ) {
			if ( isset( Tokens::$emptyTokens[ $this->tokens[ $i ]['code'] ] ) ) {
				continue;
			}
			$path_content .= $this->tokens[ $i ]['content'];
		}

		// Check if the path uses safe functions (uploads directory or temp).
		foreach ( $this->safe_functions as $safe_func ) {
			if ( stripos( $path_content, $safe_func ) !== false ) {
				// Safe function detected, no error needed.
				return;
			}
		}

		// Check for plugin constants.
		foreach ( $this->plugin_constants as $constant ) {
			if ( stripos( $path_content, $constant ) !== false ) {
				$this->add_error( $stackPtr, $matched_content, $path_param['start'], 'constant ' . $constant );
				return;
			}
		}

		// Check for plugin functions.
		foreach ( $this->plugin_functions as $func ) {
			if ( stripos( $path_content, $func ) !== false ) {
				$this->add_error( $stackPtr, $matched_content, $path_param['start'], 'function ' . $func . '()' );
				return;
			}
		}

		// Check for __FILE__ or __DIR__ magic constants.
		if ( stripos( $path_content, '__FILE__' ) !== false || stripos( $path_content, '__DIR__' ) !== false ) {
			$this->add_error( $stackPtr, $matched_content, $path_param['start'], '__FILE__ or __DIR__ magic constant' );
			return;
		}

		// Check for ABSPATH usage (could be writing to WordPress root or plugin folder).
		if ( stripos( $path_content, 'ABSPATH' ) !== false ) {
			$this->phpcsFile->addWarning(
				'Writing files using ABSPATH may be problematic. Consider using wp_upload_dir() instead if storing user data or generated files.',
				$path_param['start'],
				'ABSPATHDetected'
			);
			return;
		}
	}

	/**
	 * Adds an error message for plugin directory write attempt.
	 *
	 * @param int    $stackPtr        The position of the function call.
	 * @param string $function_name   The name of the function being called.
	 * @param int    $error_ptr       The position to report the error.
	 * @param string $indicator       What indicated this is a plugin path.
	 *
	 * @return void
	 */
	private function add_error( $stackPtr, $function_name, $error_ptr, $indicator ) {
		$this->phpcsFile->addError(
			'Plugin folders are deleted when upgraded. Do not save data to the plugin folder using %s(). Detected usage of %s. Use wp_upload_dir() to get the uploads directory path or save to the database instead.',
			$error_ptr,
			'PluginDirectoryWrite',
			array( $function_name, $indicator )
		);
	}
}
