<?php
/**
 * Test file for VerifyNonceSniff
 */

// BAD: Unconditional call - line 7
wp_verify_nonce( $_POST['_wpnonce'], 'my_action' );
update_option( 'my_option', $_POST['value'] );

// BAD: Unsafe AND condition - line 11
if ( isset( $_POST['value'] ) && ! wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) ) {
	wp_die( 'Nonce failed' );
}

// GOOD: Nonce check comes first
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) ) {
	wp_die( 'Nonce failed' );
}
update_option( 'my_option', $_POST['value'] );

// GOOD: Nonce before AND
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) && isset( $_POST['value'] ) ) {
	wp_die( 'Nonce failed' );
}

// BAD: OR condition with else - line 30
if ( current_user_can( 'administrator' ) || wp_verify_nonce( $_POST['_wpnonce'], 'my_action' ) ) {
	update_option( 'my_option', $_POST['value'] );
} else {
	wp_die( 'Access denied' );
}

// GOOD: Assignment is OK
$is_valid = wp_verify_nonce( $_POST['_wpnonce'], 'my_action' );

// GOOD: Return is OK
function validate_nonce() {
	return wp_verify_nonce( $_POST['_wpnonce'], 'my_action' );
}

// GOOD: Two nonce checks with AND
if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'action1' ) && ! wp_verify_nonce( $_POST['_wpnonce2'], 'action2' ) ) {
	wp_die( 'Nonce failed' );
}

// GOOD: Use check_admin_referer() for unconditional checks
check_admin_referer( 'my_action' );
update_option( 'my_option', $_POST['value'] );

