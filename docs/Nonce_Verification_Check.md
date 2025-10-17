# Nonce Verification Check

## Overview

The Nonce Verification Check detects buggy and insecure usage patterns of WordPress's `wp_verify_nonce()` function that could lead to CSRF (Cross-Site Request Forgery) vulnerabilities.

## Categories

- **Security**
- **Plugin Repository**

## What It Checks

This check identifies three common mistakes when using `wp_verify_nonce()`:

### 1. Unconditional Call (Error)

**Problem:** Calling `wp_verify_nonce()` without checking its return value.

**Bad:**
```php
wp_verify_nonce( $_POST['_wpnonce'], 'my_action' );
update_option( 'important_setting', $_POST['value'] );
```

**Why it's dangerous:** The function only returns `true` or `false` but doesn't stop execution. The code continues regardless of nonce validity.

**Good:**
```php
// Option 1: Check the return value
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) ) {
    wp_die( 'Security check failed' );
}
update_option( 'important_setting', $_POST['value'] );

// Option 2: Use check_admin_referer() which exits automatically
check_admin_referer( 'my_action' );
update_option( 'important_setting', $_POST['value'] );
```

### 2. Unsafe Negated Nonce with AND Operator (Error)

**Problem:** Using `wp_verify_nonce()` after an AND (`&&`) operator in a negated condition.

**Bad:**
```php
if ( isset( $_POST['value'] ) && ! wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) ) {
    wp_die( 'Nonce failed' );
}
// Code continues here
```

**Why it's dangerous:** Due to PHP's short-circuit evaluation:
- If `isset($_POST['value'])` is `false`, the nonce check never runs
- An attacker can bypass security by not sending the `value` parameter

**Good:**
```php
// Check nonce FIRST, before any other conditions
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) ) {
    wp_die( 'Security check failed' );
}

// Then check other conditions
if ( isset( $_POST['value'] ) ) {
    // Process data
}
```

### 3. Unsafe OR Condition with Else Block (Warning)

**Problem:** Using `wp_verify_nonce()` after an OR (`||`) operator when the else block contains error handling.

**Bad:**
```php
if ( current_user_can( 'administrator' ) || wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) ) {
    update_option( 'important_setting', $_POST['value'] );
} else {
    wp_die( 'Access denied' );
}
```

**Why it's dangerous:** Due to short-circuit evaluation:
- If `current_user_can('administrator')` is `true`, the nonce is never checked
- An admin can perform the action without CSRF protection

**Good:**
```php
// Check nonce first, unconditionally
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) ) {
    wp_die( 'Security check failed' );
}

// Then check permissions
if ( ! current_user_can( 'administrator' ) ) {
    wp_die( 'Access denied' );
}

update_option( 'important_setting', $_POST['value'] );
```

## Allowed Patterns

The check allows these safe patterns:

### Assignment
```php
$is_valid = wp_verify_nonce( $_POST['_wpnonce'], 'my_action' );
if ( ! $is_valid ) {
    wp_die( 'Security check failed' );
}
```

### Return Statement
```php
function validate_nonce() {
    return wp_verify_nonce( $_POST['_wpnonce'], 'my_action' );
}
```

### Multiple Nonce Checks
```php
// Both nonces are checked
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'action1' ) && ! wp_verify_nonce( $_POST['_wpnonce2'], 'action2' ) ) {
    wp_die( 'Nonce failed' );
}
```

### Nonce Before AND
```php
// Safe: nonce is checked first
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) && isset( $_POST['value'] ) ) {
    wp_die( 'Security check failed' );
}
```

## Security Impact

These vulnerabilities can lead to:

- **CSRF attacks**: Attackers can trick authenticated users into performing unwanted actions
- **Privilege escalation**: Bypassing permission checks in combination with nonce issues
- **Data tampering**: Unauthorized modification of settings or data

## Best Practices

1. **Always verify nonces FIRST** before any other checks
2. **Never combine nonce checks with OR operators**
3. **Avoid complex conditional logic** with nonce verification
4. **Use `check_admin_referer()`** for simple cases (it exits automatically on failure)
5. **Use `wp_verify_nonce()`** when you need custom error handling

## Related Functions

- `wp_verify_nonce()` - Verifies a nonce (returns bool)
- `check_admin_referer()` - Verifies and exits on failure
- `check_ajax_referer()` - For AJAX requests
- `wp_create_nonce()` - Creates a nonce
- `wp_nonce_field()` - Outputs a nonce field in forms

## References

- [WordPress Nonces Documentation](https://developer.wordpress.org/apis/security/nonces/)
- [WordPress Security: Nonces](https://developer.wordpress.org/plugins/security/nonces/)
- [check_admin_referer() Reference](https://developer.wordpress.org/reference/functions/check_admin_referer/)

## Error Codes

- `PluginCheck.Security.VerifyNonce.UnsafeVerifyNonceStatement` - Unconditional call
- `PluginCheck.Security.VerifyNonce.UnsafeVerifyNonceNegatedAnd` - Unsafe AND pattern
- `PluginCheck.Security.VerifyNonce.UnsafeVerifyNonceElse` - Unsafe OR pattern

