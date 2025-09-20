<?php
/**
 * Plugin Name: Test Plugin Safe Redirect Errors
 * Description: A test plugin that contains unsafe redirect usage.
 * Version: 1.0.0
 */

// This should trigger the SafeRedirect check error.
wp_redirect( 'https://example.com' );
