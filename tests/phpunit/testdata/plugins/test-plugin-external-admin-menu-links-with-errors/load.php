<?php
/**
 * Plugin Name: Test Plugin External Admin Menu Links With Errors
 * Plugin URI: https://github.com/WordPress/plugin-check
 * Description: Test plugin with external URLs in admin menu functions.
 * Requires at least: 6.0
 * Requires PHP: 5.6
 * Version: 1.0.0
 * Author: WordPress Performance Team
 * Author URI: https://make.wordpress.org/performance/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: test-plugin-external-admin-menu-links-with-errors
 *
 * @package test-plugin-external-admin-menu-links-with-errors
 */

/**
 * These are examples of problematic code that adds external links to admin menu.
 */

// ❌ Adding external link to main menu with https.
add_menu_page(
	'External Resource',
	'External Resource',
	'manage_options',
	'https://example.com/external-page',
	'',
	'dashicons-admin-site',
	30
);

// ❌ Adding external link to options page with http.
add_options_page(
	'Settings',
	'Settings',
	'manage_options',
	'http://example.com/settings'
);

// ❌ Adding external link to management page.
add_management_page(
	'Tools',
	'Tools',
	'manage_options',
	'https://example.com/tools'
);

// ❌ Adding external link to theme page.
add_theme_page(
	'Theme Options',
	'Theme Options',
	'manage_options',
	'https://example.com/theme-options'
);

// ❌ Adding external link to plugins page.
add_plugins_page(
	'Plugin Settings',
	'Plugin Settings',
	'manage_options',
	'https://example.com/plugin-settings'
);

// ❌ Adding external link to users page.
add_users_page(
	'User Import',
	'User Import',
	'manage_options',
	'https://example.com/user-import'
);

// ❌ Adding external link to dashboard page.
add_dashboard_page(
	'Dashboard Widget',
	'Dashboard Widget',
	'manage_options',
	'https://example.com/dashboard-widget'
);

// ❌ Adding external link with protocol-relative URL.
add_menu_page(
	'Protocol Relative',
	'Protocol Relative',
	'manage_options',
	'//example.com/protocol-relative',
	'',
	'dashicons-admin-site',
	31
);
