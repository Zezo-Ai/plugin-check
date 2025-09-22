<?php
/**
 * Tests for the Safe_Redirect_Check class.
 *
 * @package plugin-check
 */

use WordPress\Plugin_Check\Checker\Check_Categories;
use WordPress\Plugin_Check\Checker\Check_Context;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\Security\Safe_Redirect_Check;

class Safe_Redirect_Check_Tests extends WP_UnitTestCase {

	public function test_get_categories() {
		$check      = new Safe_Redirect_Check();
		$categories = $check->get_categories();

		$this->assertIsArray( $categories );
		$this->assertContains( Check_Categories::CATEGORY_SECURITY, $categories );
		$this->assertContains( Check_Categories::CATEGORY_PLUGIN_REPO, $categories );
		$this->assertCount( 2, $categories );
	}

	public function test_get_description() {
		$check       = new Safe_Redirect_Check();
		$description = $check->get_description();

		$this->assertIsString( $description );
		$this->assertNotEmpty( $description );
		$this->assertStringContainsString( 'wp_safe_redirect', $description );
		$this->assertStringContainsString( 'wp_redirect', $description );
	}

	public function test_get_documentation_url() {
		$check             = new Safe_Redirect_Check();
		$documentation_url = $check->get_documentation_url();

		$this->assertIsString( $documentation_url );
		$this->assertNotEmpty( $documentation_url );
		$this->assertStringContainsString( 'wp_safe_redirect', $documentation_url );
	}

	public function test_run_with_errors() {
		$check         = new Safe_Redirect_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-safe-redirect/safe-redirect-errors.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$warnings = $check_result->get_warnings();

		$this->assertNotEmpty( $warnings );
		$this->assertArrayHasKey( 'safe-redirect-errors.php', $warnings );
		$this->assertEquals( 6, $check_result->get_warning_count() );

		// Check for WordPress.Security.SafeRedirect error on Line no 9 and column no at 1.
		$this->assertArrayHasKey( 9, $warnings['safe-redirect-errors.php'] );
		$this->assertArrayHasKey( 1, $warnings['safe-redirect-errors.php'][9] );
		$this->assertArrayHasKey( 'code', $warnings['safe-redirect-errors.php'][9][1][0] );
		$this->assertEquals( 'WordPress.Security.SafeRedirect.wp_redirect_wp_redirect', $warnings['safe-redirect-errors.php'][9][1][0]['code'] );
	}

	public function test_run_without_errors() {
		$check         = new Safe_Redirect_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-safe-redirect/load.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$errors   = $check_result->get_errors();
		$warnings = $check_result->get_warnings();

		$this->assertEmpty( $errors['load.php'] );
		$this->assertEmpty( $warnings['load.php'] );
		$this->assertEquals( 0, $check_result->get_error_count() );
		$this->assertEquals( 6, $check_result->get_warning_count() );
	}

	public function test_run_with_multiple_unsafe_redirects() {
		$check         = new Safe_Redirect_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-safe-redirect/safe-redirect-multiple-errors.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$warnings = $check_result->get_warnings();

		$this->assertNotEmpty( $warnings );
		$this->assertArrayHasKey( 'safe-redirect-multiple-errors.php', $warnings );
		$this->assertEquals( 6, $check_result->get_warning_count() );

		// Check for multiple WordPress.Security.SafeRedirect warnings.
		$this->assertArrayHasKey( 9, $warnings['safe-redirect-multiple-errors.php'] );
		$this->assertArrayHasKey( 10, $warnings['safe-redirect-multiple-errors.php'] );
		$this->assertArrayHasKey( 11, $warnings['safe-redirect-multiple-errors.php'] );

		$this->assertEquals( 'WordPress.Security.SafeRedirect.wp_redirect_wp_redirect', $warnings['safe-redirect-multiple-errors.php'][9][1][0]['code'] );
		$this->assertEquals( 'WordPress.Security.SafeRedirect.wp_redirect_wp_redirect', $warnings['safe-redirect-multiple-errors.php'][10][1][0]['code'] );
		$this->assertEquals( 'WordPress.Security.SafeRedirect.wp_redirect_wp_redirect', $warnings['safe-redirect-multiple-errors.php'][11][1][0]['code'] );
	}

	public function test_run_with_mixed_redirects() {
		$check         = new Safe_Redirect_Check();
		$check_context = new Check_Context( UNIT_TESTS_PLUGIN_DIR . 'test-plugin-safe-redirect/safe-redirect-mixed.php' );
		$check_result  = new Check_Result( $check_context );

		$check->run( $check_result );

		$warnings = $check_result->get_warnings();

		$this->assertNotEmpty( $warnings );
		$this->assertArrayHasKey( 'safe-redirect-mixed.php', $warnings );
		$this->assertEquals( 6, $check_result->get_warning_count() );

		// Should only detect unsafe redirects, not safe ones.
		$this->assertArrayHasKey( 9, $warnings['safe-redirect-mixed.php'] );
		$this->assertArrayHasKey( 15, $warnings['safe-redirect-mixed.php'] );

		// Should not have warnings for safe redirects.
		$this->assertArrayNotHasKey( 10, $warnings['safe-redirect-mixed.php'] );

		$this->assertEquals( 'WordPress.Security.SafeRedirect.wp_redirect_wp_redirect', $warnings['safe-redirect-mixed.php'][9][1][0]['code'] );
		$this->assertEquals( 'WordPress.Security.SafeRedirect.wp_redirect_wp_redirect', $warnings['safe-redirect-mixed.php'][15][1][0]['code'] );
	}
}
