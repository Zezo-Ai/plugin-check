<?php

use WordPress\Plugin_Check\Checker\Check_Context;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\General\Php_Error_Reporting_Check;

class Php_Error_Reporting_Check_Tests extends WP_UnitTestCase {

	public function test_run_with_errors() {
		$check        = new Php_Error_Reporting_Check();
		$context      = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-php-error-reporting-with-errors/load.php' );
		$check_result = new Check_Result( $context );

		$check->run( $check_result );

		$warnings = $check_result->get_warnings();

		$this->assertNotEmpty( $warnings );
		$this->assertArrayHasKey( 'load.php', $warnings );

		// Assert exact per-line coverage so the test fails if any specific pattern stops being detected.
		$expected_lines = array( 12, 14, 16, 18, 20, 22, 24, 26, 28, 30, 32 );
		$this->assertCount( 11, $warnings['load.php'], 'Expected exactly 11 distinct lines to be flagged.' );

		foreach ( $expected_lines as $line ) {
			$line_warnings = $warnings['load.php'][ $line ] ?? array();
			$this->assertNotEmpty( $line_warnings, "Expected a warning on line {$line}, but none was found." );

			$first_column_warnings = reset( $line_warnings );
			$warning_data          = reset( $first_column_warnings );

			$this->assertEquals( 'php_error_reporting_detected', $warning_data['code'], "Line {$line} has the wrong warning code." );
		}
	}

	public function test_run_without_errors() {
		$check        = new Php_Error_Reporting_Check();
		$context      = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-php-error-reporting-without-errors/load.php' );
		$check_result = new Check_Result( $context );

		$check->run( $check_result );

		$this->assertEquals( 0, $check_result->get_warning_count() );
		$this->assertEquals( 0, $check_result->get_error_count() );
	}
}
