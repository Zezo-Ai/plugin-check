<?php
/**
 * Tests for the "Tested up to" mismatch check in Plugin_Readme_Check class.
 *
 * @package plugin-check
 */

use WordPress\Plugin_Check\Checker\Check_Context;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\Plugin_Repo\Plugin_Readme_Check;

class Tested_Up_To_Check_Tests extends WP_UnitTestCase {

	public function test_run_with_mismatch() {
		$check         = new Plugin_Readme_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-tested-up-to-mismatch/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors = $check_result->get_errors();

		$this->assertNotEmpty( $errors );
		$this->assertArrayHasKey( 'load.php', $errors );

		// Check for mismatched "Tested up to" error.
		$this->assertCount( 1, wp_list_filter( $errors['load.php'][0][0], array( 'code' => 'mismatched_tested_up_to_header' ) ) );

		// Verify the error message contains the correct versions.
		$error_items   = wp_list_filter( $errors['load.php'][0][0], array( 'code' => 'mismatched_tested_up_to_header' ) );
		$error_message = reset( $error_items )['message'];
		$this->assertStringContainsString( '6.7', $error_message );
		$this->assertStringContainsString( '6.5', $error_message );
	}

	public function test_run_with_match() {
		$check         = new Plugin_Readme_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-tested-up-to-match/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should not have mismatched tested up to error when values match.
		// Note: Other readme errors may still be present.
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $file => $file_errors ) {
				if ( isset( $file_errors[0][0] ) ) {
					$this->assertCount( 0, wp_list_filter( $file_errors[0][0], array( 'code' => 'mismatched_tested_up_to_header' ) ) );
				}
			}
		}

		// Explicitly assert that we checked for the error code.
		$this->assertTrue( true );
	}

	public function test_run_with_readme_only() {
		$check         = new Plugin_Readme_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-tested-up-to-readme-only/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should not have mismatched tested up to error when only readme has the value.
		// Note: Other readme errors may still be present.
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $file => $file_errors ) {
				if ( isset( $file_errors[0][0] ) ) {
					$this->assertCount( 0, wp_list_filter( $file_errors[0][0], array( 'code' => 'mismatched_tested_up_to_header' ) ) );
				}
			}
		}

		// Explicitly assert that we checked for the error code.
		$this->assertTrue( true );
	}

	public function test_run_with_header_only() {
		$check         = new Plugin_Readme_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-tested-up-to-header-only/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should not have mismatched tested up to error when only header has the value.
		// Note: Other readme errors may still be present.
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $file => $file_errors ) {
				if ( isset( $file_errors[0][0] ) ) {
					$this->assertCount( 0, wp_list_filter( $file_errors[0][0], array( 'code' => 'mismatched_tested_up_to_header' ) ) );
				}
			}
		}

		// Explicitly assert that we checked for the error code.
		$this->assertTrue( true );
	}

	public function test_run_with_single_file_plugin() {
		$check         = new Plugin_Readme_Check();
		$check_context = new Check_Context( WP_PLUGIN_DIR . '/hello.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should not have mismatched tested up to errors for single-file plugins.
		// Note: Other header field errors may still be present.
		if ( ! empty( $errors ) ) {
			foreach ( $errors as $file => $file_errors ) {
				if ( isset( $file_errors[0][0] ) ) {
					$this->assertCount( 0, wp_list_filter( $file_errors[0][0], array( 'code' => 'mismatched_tested_up_to_header' ) ) );
				}
			}
		}

		// Explicitly assert that we checked for the error code.
		$this->assertTrue( true );
	}

	public function test_run_with_no_readme() {
		$check         = new Plugin_Readme_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-plugin-readme-errors-no-readme/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		// Should not have tested up to errors when readme doesn't exist.
		$this->assertEmpty( wp_list_filter( $errors, array( 'code' => 'mismatched_tested_up_to_header' ) ) );
	}
}
