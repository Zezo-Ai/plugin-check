<?php
/**
 * Plugin Name: Test Plugin Safe Redirect Without Errors
 * Description: A test plugin that uses safe redirects correctly.
 * Version: 1.0.0
 */

// These should not trigger any SafeRedirect check errors.
wp_safe_redirect( 'https://example.com' );
wp_safe_redirect( 'https://wordpress.org' );
wp_safe_redirect( 'https://developer.wordpress.org' );
