<?php
/**
 * PluginCheck\Sniffs\Security\VerifyNonceSniff
 *
 * Detects buggy and insecure usage patterns of wp_verify_nonce().
 *
 * @package plugin-check
 * @since 1.7.0
 */

namespace PluginCheck\Sniffs\Security;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;
use PHP_CodeSniffer\Util\Tokens;

/**
 * Check for buggy/insecure use of wp_verify_nonce()
 *
 * This sniff detects common mistakes when using wp_verify_nonce() that could
 * lead to CSRF vulnerabilities due to improper conditional logic.
 *
 * @since 1.7.0
 */
class VerifyNonceSniff implements Sniff {

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @since 1.7.0
	 *
	 * @return array
	 */
	public function register() {
		return Tokens::$functionNameTokens;
	}

	/**
	 * Processes this test, when one of its tokens is encountered.
	 *
	 * @since 1.7.0
	 *
	 * @param File $phpcsFile The file being scanned.
	 * @param int  $stackPtr  The position of the current token in the stack.
	 *
	 * @return void
	 */
	public function process( File $phpcsFile, $stackPtr ) {
		$tokens = $phpcsFile->getTokens();

		if ( 'wp_verify_nonce' !== $tokens[ $stackPtr ]['content'] ) {
			return;
		}

		$this->check_unconditional_call( $phpcsFile, $stackPtr, $tokens );
		$this->check_unsafe_negated_and( $phpcsFile, $stackPtr, $tokens );
		$this->check_unsafe_or_condition( $phpcsFile, $stackPtr, $tokens );
	}

	/**
	 * Check for unconditional wp_verify_nonce() call (not in conditional, return, or assignment).
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $stackPtr  The position of the current token.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return void
	 */
	private function check_unconditional_call( File $phpcsFile, $stackPtr, $tokens ) {
		// Check if it's in a conditional expression.
		if ( $this->is_in_conditional( $phpcsFile, $stackPtr, $tokens ) ) {
			return;
		}

		// Check if it's a return statement.
		if ( $this->is_return_statement( $phpcsFile, $stackPtr, $tokens ) ) {
			return;
		}

		// Check if it's an assignment.
		if ( $this->is_assignment( $phpcsFile, $stackPtr, $tokens ) ) {
			return;
		}

		$phpcsFile->addError(
			__( 'Unconditional call to wp_verify_nonce(). The return value must be checked. Consider using check_admin_referer() instead, which exits on failure.', 'plugin-check' ),
			$stackPtr,
			'UnsafeVerifyNonceStatement'
		);
	}

	/**
	 * Check for unsafe negated nonce with AND operator.
	 * Pattern: if ( $something && !wp_verify_nonce() )
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $stackPtr  The position of the current token.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return void
	 */
	private function check_unsafe_negated_and( File $phpcsFile, $stackPtr, $tokens ) {
		if ( ! $this->is_in_conditional( $phpcsFile, $stackPtr, $tokens ) ) {
			return;
		}

		if ( ! $this->is_negated( $phpcsFile, $stackPtr, $tokens ) ) {
			return;
		}

		$condition = $this->get_condition_ptr( $phpcsFile, $stackPtr, $tokens );
		if ( false === $condition ) {
			return;
		}

		// Find AND operator before the nonce check.
		$andPtr = $this->find_boolean_operator_before( $phpcsFile, $stackPtr, $tokens, $condition, array( T_BOOLEAN_AND, T_LOGICAL_AND ) );

		if ( false === $andPtr ) {
			return;
		}

		// Check if the condition scope contains error terminator.
		if ( ! $this->scope_contains_error_terminator( $phpcsFile, $condition, $tokens ) ) {
			return;
		}

		// Check if there's another wp_verify_nonce() before the AND.
		if ( $this->has_verify_nonce_before_operator( $phpcsFile, $andPtr, $tokens, $condition ) ) {
			return;
		}

		$phpcsFile->addError(
			__( 'Unsafe use of wp_verify_nonce() with AND operator. If the condition before && is false, the nonce is never checked. Move nonce verification before the && or use separate conditions.', 'plugin-check' ),
			$stackPtr,
			'UnsafeVerifyNonceNegatedAnd'
		);
	}

	/**
	 * Check for unsafe OR condition.
	 * Pattern: if ( $something || wp_verify_nonce() ) { } else { exit; }
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $stackPtr  The position of the current token.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return void
	 */
	private function check_unsafe_or_condition( File $phpcsFile, $stackPtr, $tokens ) {
		if ( ! $this->is_in_conditional( $phpcsFile, $stackPtr, $tokens ) ) {
			return;
		}

		if ( $this->is_negated( $phpcsFile, $stackPtr, $tokens ) ) {
			return;
		}

		$condition = $this->get_condition_ptr( $phpcsFile, $stackPtr, $tokens );
		if ( false === $condition ) {
			return;
		}

		// Find OR operator.
		$orPtr = $this->find_boolean_operator_before( $phpcsFile, $stackPtr, $tokens, $condition, array( T_BOOLEAN_OR, T_LOGICAL_OR ) );

		if ( false === $orPtr ) {
			return;
		}

		// Check if there's an else clause.
		if ( ! isset( $tokens[ $condition ]['scope_closer'] ) ) {
			return;
		}

		$elsePtr = $phpcsFile->findNext( T_ELSE, $tokens[ $condition ]['scope_closer'], null, false );
		if ( false === $elsePtr ) {
			return;
		}

		// Check if else scope contains error terminator.
		if ( ! isset( $tokens[ $elsePtr ]['scope_opener'] ) || ! isset( $tokens[ $elsePtr ]['scope_closer'] ) ) {
			return;
		}

		if ( ! $this->scope_contains_error_terminator_in_range( $phpcsFile, $tokens[ $elsePtr ]['scope_opener'], $tokens[ $elsePtr ]['scope_closer'], $tokens ) ) {
			return;
		}

		// Check if there's another wp_verify_nonce() in the condition.
		if ( $this->has_verify_nonce_before_operator( $phpcsFile, $orPtr, $tokens, $condition ) ) {
			return;
		}

		$phpcsFile->addWarning(
			__( 'Possibly unsafe use of wp_verify_nonce() with OR operator. If the condition before || is true, the nonce is never checked. Move nonce verification before the || or use separate conditions.', 'plugin-check' ),
			$stackPtr,
			'UnsafeVerifyNonceElse'
		);
	}

	/**
	 * Check if the token is in a conditional expression.
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $stackPtr  The position of the current token.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return bool
	 */
	private function is_in_conditional( File $phpcsFile, $stackPtr, $tokens ) {
		$condition = $this->get_condition_ptr( $phpcsFile, $stackPtr, $tokens );
		return false !== $condition;
	}

	/**
	 * Get the condition pointer (if, elseif, etc.) for the current token.
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $stackPtr  The position of the current token.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return int|false
	 */
	private function get_condition_ptr( File $phpcsFile, $stackPtr, $tokens ) {
		$conditionTypes = array( T_IF, T_ELSEIF );

		foreach ( $tokens[ $stackPtr ]['conditions'] as $condPtr => $condType ) {
			if ( in_array( $condType, $conditionTypes, true ) ) {
				return $condPtr;
			}
		}

		// Look backward for a condition.
		$openParen = $phpcsFile->findPrevious( T_OPEN_PARENTHESIS, $stackPtr - 1 );
		if ( false !== $openParen && isset( $tokens[ $openParen ]['parenthesis_owner'] ) ) {
			$owner = $tokens[ $openParen ]['parenthesis_owner'];
			if ( in_array( $tokens[ $owner ]['code'], $conditionTypes, true ) ) {
				return $owner;
			}
		}

		return false;
	}

	/**
	 * Check if the wp_verify_nonce() call is negated.
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $stackPtr  The position of the current token.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return bool
	 */
	private function is_negated( File $phpcsFile, $stackPtr, $tokens ) {
		$prev = $phpcsFile->findPrevious( Tokens::$emptyTokens, $stackPtr - 1, null, true );
		return false !== $prev && T_BOOLEAN_NOT === $tokens[ $prev ]['code'];
	}

	/**
	 * Check if it's a return statement.
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $stackPtr  The position of the current token.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return bool
	 */
	private function is_return_statement( File $phpcsFile, $stackPtr, $tokens ) {
		$prev = $phpcsFile->findPrevious( Tokens::$emptyTokens, $stackPtr - 1, null, true );
		return false !== $prev && T_RETURN === $tokens[ $prev ]['code'];
	}

	/**
	 * Check if it's an assignment.
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $stackPtr  The position of the current token.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return bool
	 */
	private function is_assignment( File $phpcsFile, $stackPtr, $tokens ) {
		$closeParen = $phpcsFile->findNext( T_CLOSE_PARENTHESIS, $stackPtr );
		if ( false === $closeParen ) {
			return false;
		}

		$next = $phpcsFile->findNext( Tokens::$emptyTokens, $closeParen + 1, null, true );
		return false !== $next && T_SEMICOLON === $tokens[ $next ]['code'];
	}

	/**
	 * Find boolean operator before the current position.
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile   The file being scanned.
	 * @param int   $stackPtr    The position of the current token.
	 * @param array $tokens      The stack of tokens.
	 * @param int   $condition   The condition pointer.
	 * @param array $operatorTypes Array of operator token types to search for.
	 *
	 * @return int|false
	 */
	private function find_boolean_operator_before( File $phpcsFile, $stackPtr, $tokens, $condition, $operatorTypes ) {
		if ( ! isset( $tokens[ $condition ]['parenthesis_opener'] ) ) {
			return false;
		}

		$start = $tokens[ $condition ]['parenthesis_opener'];

		for ( $i = $stackPtr - 1; $i > $start; $i-- ) {
			if ( in_array( $tokens[ $i ]['code'], $operatorTypes, true ) ) {
				return $i;
			}
		}

		return false;
	}

	/**
	 * Check if there's another wp_verify_nonce() before the operator.
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $operatorPtr The position of the operator.
	 * @param array $tokens    The stack of tokens.
	 * @param int   $condition The condition pointer.
	 *
	 * @return bool
	 */
	private function has_verify_nonce_before_operator( File $phpcsFile, $operatorPtr, $tokens, $condition ) {
		if ( ! isset( $tokens[ $condition ]['parenthesis_opener'] ) ) {
			return false;
		}

		$start = $tokens[ $condition ]['parenthesis_opener'];

		for ( $i = $operatorPtr - 1; $i > $start; $i-- ) {
			if ( isset( $tokens[ $i ]['content'] ) && 'wp_verify_nonce' === $tokens[ $i ]['content'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if scope contains an error terminator (exit, die, return, etc.).
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $condition The condition pointer.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return bool
	 */
	private function scope_contains_error_terminator( File $phpcsFile, $condition, $tokens ) {
		if ( ! isset( $tokens[ $condition ]['scope_opener'] ) || ! isset( $tokens[ $condition ]['scope_closer'] ) ) {
			return false;
		}

		return $this->scope_contains_error_terminator_in_range(
			$phpcsFile,
			$tokens[ $condition ]['scope_opener'],
			$tokens[ $condition ]['scope_closer'],
			$tokens
		);
	}

	/**
	 * Check if a range contains an error terminator.
	 *
	 * @since 1.7.0
	 *
	 * @param File  $phpcsFile The file being scanned.
	 * @param int   $start     Start position.
	 * @param int   $end       End position.
	 * @param array $tokens    The stack of tokens.
	 *
	 * @return bool
	 */
	private function scope_contains_error_terminator_in_range( File $phpcsFile, $start, $end, $tokens ) {
		$terminators = array(
			'exit',
			'die',
			'wp_send_json_error',
			'wp_nonce_ays',
			'wp_die',
		);

		for ( $i = $start; $i < $end; $i++ ) {
			if ( T_RETURN === $tokens[ $i ]['code'] ) {
				return true;
			}

			if ( isset( $tokens[ $i ]['content'] ) && in_array( $tokens[ $i ]['content'], $terminators, true ) ) {
				return true;
			}
		}

		return false;
	}
}
