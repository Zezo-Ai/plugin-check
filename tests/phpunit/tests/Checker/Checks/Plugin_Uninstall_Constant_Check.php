<?php
/**
 * Tests for the Plugin_Uninstall_Constant_Check class.
 *
 * @package plugin-check
 */

use WordPress\Plugin_Check\Checker\Check_Context;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\Plugin_Repo\Plugin_Uninstall_Constant_Check;

class Plugin_Uninstall_Constant_Check_Tests extends WP_UnitTestCase {

	public function test_run_with_plugin_uninstall_errors( ) {
		$test_file = 'test-plugin-uninstall-constant-errors/load.php';
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . $test_file );
		$check_result  = new Check_Result( $check_context );

		$check = new Plugin_Uninstall_Constant_Check();
		$check->run( $check_result );

		$errors = $check_result->get_errors();

		echo 'in test';
		echo var_dump( array($test_file, $errors));

		$this->assertNotEmpty( $errors );
		$this->assertArrayHasKey( 'uninstall.php', $errors );
		$this->assertSame( 1, $check_result->get_error_count() );

		$this->assertTrue( isset( $errors[ 'uninstall.php' ][0][0][0] ) );
		$this->assertSame( 'uninstall_no_constant_check', $errors[ 'uninstall.php' ][0][0][0]['code'] );

	}

	public function test_run_without_any_errors() {
		$test_file = 'test-plugin-uninstall-constant-without-errors/load.php';
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . $test_file);
		$check_result  = new Check_Result( $check_context );

		$check = new Plugin_Uninstall_Constant_Check();
		$check->run( $check_result );

		$errors   = $check_result->get_errors();

		$this->assertEmpty( $errors );
		$this->assertEquals( 0, $check_result->get_error_count() );
	}
}
