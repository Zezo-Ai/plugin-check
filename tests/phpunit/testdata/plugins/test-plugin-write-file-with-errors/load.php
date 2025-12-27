<?php
/**
 * Plugin Name: Test Plugin Write File With Errors
 * Plugin URI: https://github.com/WordPress/plugin-check
 * Description: Test plugin that writes files to plugin directory (not allowed).
 * Author: WordPress Performance Team
 * Author URI: https://make.wordpress.org/performance/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: test-plugin-write-file-with-errors
 *
 * @package test-plugin-write-file-with-errors
 */

// Example 1: Writing to plugin directory using __FILE__.
function test_write_with_file_constant() {
	$file_path = dirname( __FILE__ ) . '/cache/data.txt';
	file_put_contents( $file_path, 'Some data' );
}

// Example 2: Writing to plugin directory using __DIR__.
function test_write_with_dir_constant() {
	$cache_file = __DIR__ . '/logs/debug.log';
	fwrite( fopen( $cache_file, 'w' ), 'Debug info' );
}

// Example 3: Writing using plugin_dir_path().
function test_write_with_plugin_dir_path() {
	$log_file = plugin_dir_path( __FILE__ ) . 'error.log';
	fputs( fopen( $log_file, 'a' ), 'Error message' );
}

// Example 4: Copying to plugin directory using WP_PLUGIN_DIR.
function test_copy_to_plugin_dir() {
	$source = '/tmp/file.txt';
	$dest   = WP_PLUGIN_DIR . '/test-plugin-write-file-with-errors/backup.txt';
	copy( $source, $dest );
}

// Example 5: Moving files to plugin directory.
function test_move_to_plugin_dir() {
	$source = '/tmp/temp.txt';
	$dest   = plugin_dir_path( __FILE__ ) . 'moved.txt';
	rename( $source, $dest );
}

// Example 6: Using WP_CONTENT_DIR (warning case).
function test_write_to_content_dir() {
	$file = WP_CONTENT_DIR . '/custom-data.txt';
	touch( $file );
}

// Example 7: Using plugins_url (should error).
function test_write_with_plugins_url() {
	$base_path = str_replace( 'http://', '', plugins_url() );
	$file_path = $base_path . '/test-plugin/data.json';
	file_put_contents( $file_path, '{}' );
}

