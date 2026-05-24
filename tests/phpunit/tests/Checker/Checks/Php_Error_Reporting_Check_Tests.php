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

		$this->assertEquals( 8, $check_result->get_warning_count() );

		$first_line_warnings   = reset( $warnings['load.php'] );
		$first_column_warnings = reset( $first_line_warnings );
		$warning_data          = reset( $first_column_warnings );

		$this->assertEquals( 'php_error_reporting_detected', $warning_data['code'] );
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
