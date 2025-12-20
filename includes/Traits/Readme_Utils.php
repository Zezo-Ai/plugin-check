<?php
/**
 * Trait WordPress\Plugin_Check\Traits\Readme_Utils
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Traits;

use WordPress\Plugin_Check\Lib\Readme\Parser as PCPParser;
use WordPressdotorg\Plugin_Directory\Readme\Parser as DotorgParser;

/**
 * Trait for readme utilities.
 *
 * @since 1.0.0
 */
trait Readme_Utils {

	/**
	 * Filter the given array of files for readme files (readme.txt or readme.md).
	 *
	 * @since 1.0.0
	 *
	 * @param array  $files                Array of file files to be filtered.
	 * @param string $plugin_relative_path Plugin relative path.
	 * @return array An array containing readme.txt or readme.md files, or an empty array if none are found.
	 */
	protected function filter_files_for_readme( array $files, $plugin_relative_path ) {
		// Find the readme file.
		$readme_list = preg_grep( '/\/readme\.(txt|md)$/i', $files );

		// Filter the readme files located at root.
		$potential_readme_files = array_filter(
			$readme_list,
			function ( $file ) use ( $plugin_relative_path ) {
				$file = str_replace( $plugin_relative_path, '', $file );
				return ! str_contains( $file, '/' );
			}
		);

		// If the readme file does not exist, then return empty array.
		if ( empty( $potential_readme_files ) ) {
			return array();
		}

		// Find the .txt versions of the readme files.
		$readme_txt = array_filter(
			$potential_readme_files,
			function ( $file ) {
				return preg_match( '/^readme\.txt$/i', basename( $file ) );
			}
		);

		return $readme_txt ? $readme_txt : $potential_readme_files;
	}

	/**
	 * Gets the "Tested up to" value from the readme file.
	 *
	 * @since 1.8.0
	 *
	 * @param string $plugin_path The plugin directory path.
	 * @return string The "Tested up to" value from readme, or empty string if not found.
	 */
	protected function get_readme_tested_value( $plugin_path ) {
		// Build list of potential readme files.
		$potential_files = array(
			$plugin_path . 'readme.txt',
			$plugin_path . 'readme.md',
			$plugin_path . 'README.txt',
			$plugin_path . 'README.md',
		);

		// Filter to only existing files.
		$existing_files = array_filter( $potential_files, 'file_exists' );

		if ( empty( $existing_files ) ) {
			return '';
		}

		// Use filter_files_for_readme to get the correct readme (prioritizes .txt).
		$readme = $this->filter_files_for_readme( $existing_files, $plugin_path );

		if ( empty( $readme ) ) {
			return '';
		}

		$readme_file = reset( $readme );

		// Parse the readme file.
		$parser = class_exists( DotorgParser::class ) ? new DotorgParser( $readme_file ) : new PCPParser( $readme_file );

		return isset( $parser->tested ) ? $parser->tested : '';
	}
}
