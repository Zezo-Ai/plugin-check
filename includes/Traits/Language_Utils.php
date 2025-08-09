<?php
/**
 * Trait WordPress\Plugin_Check\Traits\Language_Utils
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Traits;

use LanguageDetection\Language;

/**
 * Trait for language utilities.
 *
 * @since 1.7.0
 */
trait Language_Utils {

	/**
	 * Checks if the content is in an official WordPress language.
	 *
	 * @since 1.6.0
	 *
	 * @param string $content The content to check.
	 * @return bool True if the content is in an official language, otherwise false.
	 */
	protected function is_on_official_language( string $content ): bool {
		$lang_detector = new Language();
		$languages     = $lang_detector->detect( $content )->bestResults()->close();

		if ( isset( $languages['en'] ) || ( ! isset( $languages['en'] ) && isset( $languages['ia'] ) ) ) {
			return true;
		}

		return false;
	}
}
