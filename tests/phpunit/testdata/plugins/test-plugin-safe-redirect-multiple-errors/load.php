<?php
/**
 * Plugin Name: Test Plugin Safe Redirect Multiple Errors
 * Description: A test plugin that contains multiple unsafe redirect usage.
 * Version: 1.0.0
 */

// These should trigger the SafeRedirect check errors.
wp_redirect( 'https://example.com' );
wp_redirect( 'https://malicious-site.com' );
wp_redirect( 'http://unsafe-redirect.com' );
