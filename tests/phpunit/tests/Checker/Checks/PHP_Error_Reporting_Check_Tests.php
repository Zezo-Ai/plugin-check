<?php

use WordPress\Plugin_Check\Checker\Check_Context;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\General\PHP_Error_Reporting_Check;

class PHP_Error_Reporting_Check_Tests extends WP_UnitTestCase {

	public function test_run_with_errors() {
		$check        = new PHP_Error_Reporting_Check();
		$context      = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-php-error-reporting-with-errors/load.php' );
		$check_result = new Check_Result( $context );

		$check->run( $check_result );

		$errors = $check_result->get_errors();

		$this->assertNotEmpty( $errors );
		$this->assertArrayHasKey( 'load.php', $errors );

		// Assert exact per-line coverage so the test fails if any specific pattern stops being detected.
		$expected = array(
			12 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall',
			14 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.DirectErrorReportingCall',
			16 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveDisplay_errors',
			18 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveError_reporting',
			20 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveDisplay_errors',
			22 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveError_reporting',
			24 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.DefineWP_DEBUG',
			26 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.DefineWP_DEBUG_LOG',
			28 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.DefineWP_DEBUG_DISPLAY',
			30 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.DefineSCRIPT_DEBUG',
			32 => 'PluginCheck.CodeAnalysis.PHPErrorReporting.ConstWP_DEBUG',
		);
		$this->assertCount( 11, $errors['load.php'], 'Expected exactly 11 distinct lines to be flagged.' );

		foreach ( $expected as $line => $expected_code ) {
			$line_errors = $errors['load.php'][ $line ] ?? array();
			$this->assertNotEmpty( $line_errors, "Expected an error on line {$line}, but none was found." );

			$first_column_errors = reset( $line_errors );
			$error_data          = reset( $first_column_errors );

			$this->assertEquals( $expected_code, $error_data['code'], "Line {$line} has the wrong error code." );
			$this->assertEquals( 8, $error_data['severity'], "Line {$line} has the wrong severity." );
		}
	}

	public function test_run_without_errors() {
		$check        = new PHP_Error_Reporting_Check();
		$context      = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-php-error-reporting-without-errors/load.php' );
		$check_result = new Check_Result( $context );

		$check->run( $check_result );

		$this->assertEquals( 0, $check_result->get_warning_count() );
		$this->assertEquals( 0, $check_result->get_error_count() );
	}
}
