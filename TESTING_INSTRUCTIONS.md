# Test PR #1317

## Install

1. Download `plugin-check-pr-1317.zip` from the current directory.
2. WP Admin → Plugins → Add New Plugin → Upload.
3. Activate "Plugin Check (PCP)".

Requirements: WordPress 6.5+, PHP 7.2+.

## What changed

- Renamed the new PHP error reporting sniff and check from `PhpErrorReporting*` / `Php_Error_Reporting_Check*` to `PHPErrorReporting*` / `PHP_Error_Reporting_Check*` to match project naming conventions (e.g., `Abstract_PHP_CodeSniffer_Check`).
- Condensed the user-facing warning message into a single paragraph without HTML formatting, as requested by the reviewer.
- Fixed a const-report bug detected by CodeRabbit: `const` declarations now correctly report the constant name, not a trailing `()`.
- Corrected a test fixture comment label.

## Test

1. Go to WP Admin → Tools → Plugin Check.
2. Select a plugin you know calls `error_reporting()`, `ini_set( 'display_errors', ... )`, `define( 'WP_DEBUG', ... )`, or `const WP_DEBUG = ...`.
3. Run the check and look for the warning under the `php_error_reporting` slug.
4. Expected: one concise warning per flagged line, code `php_error_reporting_detected`, severity 8.
5. Check a plugin with none of those patterns and confirm no warnings.

## Verify no regressions

- No PHP fatal or warning in the Plugin Check admin screen.
- Other existing checks still run (e.g., `late_escaping`, `safe_redirect`).
