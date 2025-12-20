<?php
/**
 * Tests for the "Tested up to" mismatch check in Plugin_Header_Fields_Check class.
 *
 * @package plugin-check
 */

use WordPress\Plugin_Check\Checker\Check_Context;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\Plugin_Repo\Plugin_Header_Fields_Check;

class Tested_Up_To_Check_Tests extends WP_UnitTestCase {

	public function test_run_with_mismatch() {
		$check         = new Plugin_Header_Fields_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-tested-up-to-mismatch/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors = $check_result->get_errors();

		$this->assertNotEmpty( $errors );
		$this->assertArrayHasKey( 'readme.txt', $errors );

		// Check for mismatched "Tested up to" error.
		$this->assertCount( 1, wp_list_filter( $errors['readme.txt'][0][0], array( 'code' => 'mismatched_tested_up_to_header' ) ) );

		// Verify the error message contains the correct versions.
		$error_message = $errors['readme.txt'][0][0][0]['message'];
		$this->assertStringContainsString( '6.7', $error_message );
		$this->assertStringContainsString( '6.5', $error_message );
	}

	public function test_run_with_match() {
		$check         = new Plugin_Header_Fields_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-tested-up-to-match/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should have no errors or warnings when values match.
		$this->assertEmpty( $errors );
		$this->assertEmpty( $warnings );
	}

	public function test_run_with_readme_only() {
		$check         = new Plugin_Header_Fields_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-tested-up-to-readme-only/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should have no errors or warnings when only readme has the value.
		$this->assertEmpty( $errors );
		$this->assertEmpty( $warnings );
	}

	public function test_run_with_header_only() {
		$check         = new Plugin_Header_Fields_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-tested-up-to-header-only/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should have no errors or warnings when only header has the value.
		$this->assertEmpty( $errors );
		$this->assertEmpty( $warnings );
	}

	public function test_run_with_single_file_plugin() {
		$check         = new Plugin_Header_Fields_Check();
		$check_context = new Check_Context( WP_PLUGIN_DIR . '/hello.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should skip single-file plugins.
		$this->assertEmpty( $errors );
		$this->assertEmpty( $warnings );
	}

	public function test_run_with_no_readme() {
		$check         = new Plugin_Header_Fields_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-plugin-readme-errors-no-readme/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should not have tested up to errors when readme doesn't exist.
		$this->assertEmpty( wp_list_filter( $errors, array( 'code' => 'mismatched_tested_up_to_header' ) ) );
	}
}
