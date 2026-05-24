<?php
/**
 * PHPUnit test case containing error reporting configurations.
 *
 * @package plugin-check
 */

class Some_Test_Case {
	public function test_something() {
		error_reporting( 0 );
		ini_set( 'display_errors', 1 );
		define( 'WP_DEBUG', true );
	}
}
