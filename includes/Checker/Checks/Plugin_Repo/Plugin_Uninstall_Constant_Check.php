<?php
/**
 * Class Plugin_Uninstall_Constant_Check.
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Checker\Checks\Plugin_Repo;

use Exception;
use WordPress\Plugin_Check\Checker\Check_Categories;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\Abstract_File_Check;
use WordPress\Plugin_Check\Traits\Amend_Check_Result;
use WordPress\Plugin_Check\Traits\Stable_Check;

/**
 * Check to detect if a definition check for WP_UNINSTALL_PLUGIN takes place in uninstall.php, if it exists
 *
 * @since 1.0.0
 */
class Plugin_Uninstall_Constant_Check extends Abstract_File_Check {

	use Amend_Check_Result;
	use Stable_Check;

	/**
	 * Gets the categories for the check.
	 *
	 * Every check must have at least one category.
	 *
	 * @since 1.0.0
	 *
	 * @return array The categories for the check.
	 */
	public function get_categories() {
		return array( Check_Categories::CATEGORY_PLUGIN_REPO );
	}

	/**
	 * Checks the uninstall.php file for a definition check of the WP_UNINSTALL_PLUGIN constant
	 *
	 * @since 1.0.0
	 *
	 * @param Check_Result $result The check result to amend, including the plugin context to check.
	 * @param array        $files  List of absolute file paths.
	 *
	 * @throws Exception Thrown when the check fails with a critical error (unrelated to any errors detected as part of
	 *                   the check).
	 */
	protected function check_files( Check_Result $result, array $files ) {
		$constant_regex        = '#defined\s*\(.*WP_UNINSTALL_PLUGIN.*\)#';
		$matches               = array();
		$plugin_uninstall_file = self::filter_files_by_regex( $files, '/uninstall\.php$/' );
		if ( $plugin_uninstall_file ) {
			foreach ( $plugin_uninstall_file as $file ) {
				$uninstall_constant = self::file_preg_match( $constant_regex, [ $file ], $matches );
				if ( ! $uninstall_constant ) {
					$this->add_result_error_for_file(
						$result,
						sprintf(
						/* translators: %s: The match file name. */
							__( 'WP_UNINSTALL_PLUGIN constant not being checked. Detected: %s', 'plugin-check' ),
							esc_html( $matches[0] )
						),
						'uninstall_no_constant_check',
						$file,
						0,
						0,
						'https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/#method-2-uninstall-php',
						9
					);
				}
			}
		}
	}


	/**
	 * Gets the description for the check.
	 *
	 * Every check must have a short description explaining what the check does.
	 *
	 * @since 1.1.0
	 *
	 * @return string Description.
	 */
	public function get_description(): string {
		return __( 'Confirms usage of WP_Uninstall_Plugin constant checker in uninstall.php', 'plugin-check' );
	}

	/**
	 * Gets the documentation URL for the check.
	 *
	 * Every check must have a URL with further information about the check.
	 *
	 * @since 1.1.0
	 *
	 * @return string The documentation URL.
	 */
	public function get_documentation_url(): string {
		return __( 'https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/#method-2-uninstall-php', 'plugin-check' );
	}
}
