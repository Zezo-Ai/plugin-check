<?php
/**
 * Plugin Name: Test Plugin Safe Redirect Mixed
 * Description: A test plugin that contains both safe and unsafe redirect usage.
 * Version: 1.0.0
 */

// This should trigger the SafeRedirect check error.
wp_redirect( 'https://example.com' );

// This should not trigger any SafeRedirect check errors.
wp_safe_redirect( 'https://wordpress.org' );

// This should trigger the SafeRedirect check error.
wp_redirect( 'https://malicious-site.com' );
