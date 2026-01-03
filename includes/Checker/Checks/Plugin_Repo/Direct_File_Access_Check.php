<?php
/**
 * Class Direct_File_Access_Check.
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Checker\Checks\Plugin_Repo;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use WordPress\Plugin_Check\Checker\Check_Categories;
use WordPress\Plugin_Check\Checker\Check_Result;
use WordPress\Plugin_Check\Checker\Checks\Abstract_File_Check;
use WordPress\Plugin_Check\Traits\Amend_Check_Result;
use WordPress\Plugin_Check\Traits\Find_Uninstall;
use WordPress\Plugin_Check\Traits\Stable_Check;

/**
 * Check for direct file access protection in PHP files.
 *
 * Files that only contain a PHP class the risk of something funky happening
 * when directly accessed is pretty small. For files that contain procedural code,
 * functions and function calls, the chance of security risks is a lot bigger.
 *
 * This check verifies that PHP files have proper guards to prevent direct access,
 * using checks like: if ( ! defined( 'ABSPATH' ) ) exit;
 *
 * @since 1.8.0
 */
class Direct_File_Access_Check extends Abstract_File_Check {

	use Amend_Check_Result;
	use Find_Uninstall;
	use Stable_Check;

	/**
	 * Gets the categories for the check.
	 *
	 * Every check must have at least one category.
	 *
	 * @since 1.8.0
	 *
	 * @return array The categories for the check.
	 */
	public function get_categories() {
		return array(
			Check_Categories::CATEGORY_SECURITY,
			Check_Categories::CATEGORY_PLUGIN_REPO,
		);
	}

	/**
	 * Amends the given result by running the check on the given list of files.
	 *
	 * @since 1.8.0
	 *
	 * @param Check_Result $result The check result to amend, including the plugin context to check.
	 * @param array        $files  List of absolute file paths.
	 */
	protected function check_files( Check_Result $result, array $files ) {
		// Only check PHP files.
		$php_files = self::filter_files_by_extension( $files, 'php' );

		$plugin_path = $result->plugin()->path();

		foreach ( $php_files as $file ) {
			// Skip uninstall.php files - they have their own check.
			if ( $this->is_uninstall_file( $file, $plugin_path ) ) {
				continue;
			}

			if ( ! $this->has_direct_access_protection( $file ) ) {
				if ( ! $this->is_valid_for_direct_access( $file ) ) {
					$this->add_result_error_for_file(
						$result,
						__( 'PHP file should prevent direct access. Add a check like: if ( ! defined( \'ABSPATH\' ) ) exit;', 'plugin-check' ),
						'missing_direct_file_access_protection',
						$file,
						0,
						0,
						'https://developer.wordpress.org/plugins/wordpress-org/common-issues/#direct-file-access',
						6
					);
				}
			}
		}
	}

	/**
	 * Removes PHP tag, comments, namespace and use statements from file contents.
	 *
	 * @since 1.8.0
	 *
	 * @param string $contents The file contents to clean.
	 * @return string Cleaned file contents.
	 */
	private function clean_file_contents( $contents ) {
		// Remove the opening PHP tag if present.
		$contents = preg_replace( '/^<\?php\s*/i', '', $contents );

		// Remove all comments.
		$contents = preg_replace( '/\/\*.*?\*\//s', '', $contents );
		$contents = preg_replace( '/\/\/.*$/m', '', $contents );
		$contents = preg_replace( '/#.*$/m', '', $contents );
		$contents = preg_replace( '/^\s*\*.*$/m', '', $contents );

		// Remove namespace and use statements (they don't execute code).
		$contents = preg_replace( '/namespace\s+[^{;]+(?:;|\{)/i', '', $contents );
		$contents = preg_replace( '/use\s+[^;]+;/i', '', $contents );

		return $contents;
	}

	/**
	 * Checks if a file has proper direct access protection.
	 *
	 * @since 1.8.0
	 *
	 * @param string $file The file path to check.
	 * @return bool True if the file has protection, false otherwise.
	 */
	private function has_direct_access_protection( $file ) {
		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			return false;
		}

		// Remove the opening PHP tag if present.
		$contents = preg_replace( '/^<\?php\s*/i', '', $contents );

		// Get first 50 lines to check for guards.
		$lines       = explode( "\n", $contents );
		$first_lines = array_slice( $lines, 0, 50 );
		$beginning   = implode( "\n", $first_lines );

		// Clean up the content.
		$without_comments = preg_replace( '#/\*.*?\*/#s', '', $beginning );
		$without_comments = preg_replace( '#//.*$#m', '', $without_comments );
		$without_comments = preg_replace( '#^\s*\*\s.*$#m', '', $without_comments );
		$without_comments = preg_replace( '/\n\s*\n\s*\n/', "\n\n", $without_comments );
		$without_comments = trim( $without_comments );

		// Pattern 1: defined( 'ABSPATH' ) || exit; or, exit; .
		if ( preg_match( "/defined\s*\(\s*['\"]ABSPATH['\"]\s*\)\s*(?:\|\||or)\s*(?:exit|die)\s*(?:\([^)]*\))?\s*;/i", $without_comments ) ) {
			return true;
		}

		// Pattern 2: defined( 'WPINC' ) || exit; or, die();.
		if ( preg_match( "/defined\s*\(\s*['\"]WPINC['\"]\s*\)\s*(?:\|\||or)\s*(?:exit|die)\s*(?:\([^)]*\))?\s*;/i", $without_comments ) ) {
			return true;
		}

		// Pattern 3: if ( ! defined( 'ABSPATH' ) ) exit; or, exit;.
		if ( preg_match( "/if\s*\(\s*!\s*defined\s*\(\s*['\"]ABSPATH['\"]\s*\)\s*\)\s*(?:\{|exit|die)/i", $without_comments ) ) {
			return true;
		}

		// Pattern 4: if ( ! defined( 'WPINC' ) ) exit; {exit; or, die();}.
		if ( preg_match( "/if\s*\(\s*!\s*defined\s*\(\s*['\"]WPINC['\"]\s*\)\s*\)\s*(?:\{|exit|die)/i", $without_comments ) ) {
			return true;
		}

		// Pattern 5: if ( ! defined( 'ABSPATH' ) ) { die(); }, WPINC.
		if ( preg_match( "/if\s*\(\s*!\s*defined\s*\(\s*['\"](?:ABSPATH|WPINC)['\"]\s*\)\s*\)\s*\{[^}]*die\s*\(/i", $without_comments ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if a file is valid for direct access
	 *
	 * Files that only contain class/namespace definitions are generally safe for direct access.
	 * Files with procedural code (functions, hooks, defines) should always have guards.
	 *
	 * @since 1.8.0
	 *
	 * @param string $file The file path to check.
	 * @return bool True if the file is safe for direct access, false otherwise.
	 */
	private function is_valid_for_direct_access( $file ) {
		$contents = file_get_contents( $file );
		if ( false === $contents ) {
			return false;
		}

		$parser = ( new ParserFactory() )->create( ParserFactory::PREFER_PHP7 );
		try {
			$ast = $parser->parse( $contents );
			if ( null === $ast ) {
				return $this->is_valid_for_direct_access_regex( $contents );
			}

			return $this->is_ast_valid_for_direct_access( $ast );
		} catch ( Error $e ) {
			return $this->is_valid_for_direct_access_regex( $contents );
		}
	}

	/**
	 * Checks if AST only contains structural code (safe for direct access).
	 *
	 * @since 1.9.0
	 *
	 * @param array $ast The parsed AST nodes.
	 * @return bool True if the AST only contains structural code, false otherwise.
	 */
	private function is_ast_valid_for_direct_access( array $ast ) {
		$safe_node_types = array(
			Stmt\Nop::class,
			Stmt\Namespace_::class,
			Stmt\Use_::class,
			Stmt\GroupUse::class,
			Stmt\Class_::class,
			Stmt\Interface_::class,
			Stmt\Trait_::class,
			Stmt\Enum_::class,
		);

		$has_assignments = false;
		$has_returns     = false;

		foreach ( $ast as $node ) {
			$node_class = get_class( $node );

			if ( in_array( $node_class, $safe_node_types, true ) ) {
				if ( $node instanceof Stmt\Namespace_ && ! empty( $node->stmts ) ) {
					if ( ! $this->is_ast_valid_for_direct_access( $node->stmts ) ) {
						return false;
					}
				}
				continue;
			}

			if ( $node instanceof Stmt\Function_ ) {
				return false;
			}

			if ( $node instanceof Stmt\Return_ ) {
				if ( $this->is_safe_return_expression( $node->expr ) ) {
					$has_returns = true;
					continue;
				}
				return false;
			}

			if ( $node instanceof Stmt\Expression ) {
				if ( $this->is_safe_expression( $node->expr ) ) {
					continue;
				}
				if ( $this->is_asset_assignment( $node->expr ) ) {
					$has_assignments = true;
					continue;
				}
				return false;
			}

			if ( $node instanceof Stmt\If_ ) {
				if ( $this->is_safe_if_statement( $node ) ) {
					continue;
				}
				return false;
			}

			return false;
		}

		if ( $has_assignments && $has_returns ) {
			return true;
		}

		return true;
	}

	/**
	 * Checks if an expression is an asset file assignment (variable = array/string).
	 *
	 * @since 1.9.0
	 *
	 * @param Expr $expr The expression to check.
	 * @return bool True if asset assignment, false otherwise.
	 */
	private function is_asset_assignment( $expr ) {
		if ( ! ( $expr instanceof Expr\Assign ) ) {
			return false;
		}

		return $this->is_safe_expression( $expr->expr );
	}

	/**
	 * Checks if an expression is safe (doesn't execute code).
	 *
	 * @since 1.9.0
	 *
	 * @param Expr|null $expr The expression to check.
	 * @return bool True if the expression is safe, false otherwise.
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function is_safe_expression( $expr ) {
		if ( null === $expr ) {
			return true;
		}

		if ( $this->is_safe_scalar( $expr ) ) {
			return true;
		}

		if ( $this->is_safe_encapsed_string( $expr ) ) {
			return true;
		}

		if ( $expr instanceof Expr\ConstFetch ) {
			return true;
		}

		if ( $this->is_safe_array( $expr ) ) {
			return true;
		}

		if ( $this->is_safe_function_call( $expr ) ) {
			return true;
		}

		if ( $this->is_safe_concat( $expr ) ) {
			return true;
		}

		if ( $this->is_unsafe_expression( $expr ) ) {
			return false;
		}

		return false;
	}

	/**
	 * Checks if expression is a safe scalar value.
	 *
	 * @since 1.9.0
	 *
	 * @param Expr $expr The expression to check.
	 * @return bool True if safe scalar, false otherwise.
	 */
	private function is_safe_scalar( $expr ) {
		$class = get_class( $expr );
		return 'PhpParser\Node\Scalar\String_' === $class
			|| 'PhpParser\Node\Scalar\LNumber' === $class
			|| 'PhpParser\Node\Scalar\DNumber' === $class
			|| 'PhpParser\Node\Scalar\EncapsedStringPart' === $class;
	}

	/**
	 * Checks if expression is a safe encapsed string.
	 *
	 * @since 1.9.0
	 *
	 * @param Expr $expr The expression to check.
	 * @return bool True if safe encapsed string, false otherwise.
	 */
	private function is_safe_encapsed_string( $expr ) {
		if ( 'PhpParser\Node\Scalar\Encapsed' !== get_class( $expr ) ) {
			return false;
		}

		// Type assertion: $expr is PhpParser\Node\Scalar\Encapsed which has a $parts property.
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase,Generic.Commenting.DocComment.MissingShort
		if ( ! isset( $expr->parts ) || ! is_array( $expr->parts ) ) {
			return false;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		foreach ( $expr->parts as $part ) {
			if ( ! $this->is_safe_expression( $part ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks if expression is a safe array.
	 *
	 * @since 1.9.0
	 *
	 * @param Expr $expr The expression to check.
	 * @return bool True if safe array, false otherwise.
	 */
	private function is_safe_array( $expr ) {
		if ( ! ( $expr instanceof Expr\Array_ ) ) {
			return false;
		}

		foreach ( $expr->items as $item ) {
			if ( null !== $item && null !== $item->value && ! $this->is_safe_expression( $item->value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks if expression is a safe function call.
	 *
	 * @since 1.9.0
	 *
	 * @param Expr $expr The expression to check.
	 * @return bool True if safe function call, false otherwise.
	 */
	private function is_safe_function_call( $expr ) {
		if ( ! ( $expr instanceof Expr\FuncCall ) ) {
			return false;
		}

		$function_name = $this->get_function_name( $expr );
		if ( null === $function_name || ! in_array( $function_name, $this->get_allowed_functions(), true ) ) {
			return false;
		}

		foreach ( $expr->args as $arg ) {
			if ( null !== $arg->value && ! $this->is_safe_expression( $arg->value ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Checks if expression is a safe concatenation.
	 *
	 * @since 1.9.0
	 *
	 * @param Expr $expr The expression to check.
	 * @return bool True if safe concatenation, false otherwise.
	 */
	private function is_safe_concat( $expr ) {
		if ( ! ( $expr instanceof Expr\BinaryOp\Concat ) ) {
			return false;
		}

		return $this->is_safe_expression( $expr->left ) && $this->is_safe_expression( $expr->right );
	}

	/**
	 * Checks if expression is unsafe (executes code).
	 *
	 * @since 1.9.0
	 *
	 * @param Expr $expr The expression to check.
	 * @return bool True if unsafe, false otherwise.
	 */
	private function is_unsafe_expression( $expr ) {
		return $expr instanceof Expr\Assign
			|| $expr instanceof Expr\AssignOp
			|| $expr instanceof Expr\MethodCall
			|| $expr instanceof Expr\StaticCall
			|| $expr instanceof Expr\Exit_;
	}

	/**
	 * Checks if an If statement is safe (only contains safe function calls and returns).
	 *
	 * @since 1.9.0
	 *
	 * @param Stmt\If_ $node The If statement node.
	 * @return bool True if safe, false otherwise.
	 */
	private function is_safe_if_statement( Stmt\If_ $node ) {
		if ( ! $this->is_safe_condition( $node->cond ) ) {
			return false;
		}

		if ( empty( $node->stmts ) ) {
			return true;
		}

		foreach ( $node->stmts as $stmt ) {
			if ( $stmt instanceof Stmt\Return_ ) {
				if ( ! $this->is_safe_return_expression( $stmt->expr ) ) {
					return false;
				}
				continue;
			}

			if ( $stmt instanceof Stmt\If_ ) {
				if ( ! $this->is_safe_if_statement( $stmt ) ) {
					return false;
				}
				continue;
			}

			return false;
		}

		if ( ! empty( $node->elseifs ) ) {
			foreach ( $node->elseifs as $elseif ) {
				if ( ! $this->is_safe_elseif_statement( $elseif ) ) {
					return false;
				}
			}
		}

		if ( null !== $node->else ) {
			foreach ( $node->else->stmts as $stmt ) {
				if ( $stmt instanceof Stmt\Return_ ) {
					if ( ! $this->is_safe_return_expression( $stmt->expr ) ) {
						return false;
					}
					continue;
				}

				if ( $stmt instanceof Stmt\If_ ) {
					if ( ! $this->is_safe_if_statement( $stmt ) ) {
						return false;
					}
					continue;
				}

				return false;
			}
		}

		return true;
	}

	/**
	 * Checks if an ElseIf statement is safe.
	 *
	 * @since 1.9.0
	 *
	 * @param Stmt\ElseIf_ $node The ElseIf statement node.
	 * @return bool True if safe, false otherwise.
	 */
	private function is_safe_elseif_statement( Stmt\ElseIf_ $node ) {
		if ( ! $this->is_safe_condition( $node->cond ) ) {
			return false;
		}

		if ( empty( $node->stmts ) ) {
			return true;
		}

		foreach ( $node->stmts as $stmt ) {
			if ( $stmt instanceof Stmt\Return_ ) {
				if ( ! $this->is_safe_return_expression( $stmt->expr ) ) {
					return false;
				}
				continue;
			}

			if ( $stmt instanceof Stmt\If_ ) {
				if ( ! $this->is_safe_if_statement( $stmt ) ) {
					return false;
				}
				continue;
			}

			return false;
		}

		return true;
	}

	/**
	 * Checks if a condition expression is safe (only safe function calls).
	 *
	 * @since 1.9.0
	 *
	 * @param Expr $cond The condition expression.
	 * @return bool True if safe, false otherwise.
	 */
	private function is_safe_condition( $cond ) {
		if ( $cond instanceof Expr\BooleanNot ) {
			return $this->is_safe_condition( $cond->expr );
		}

		if ( $cond instanceof Expr\BinaryOp\BooleanAnd || $cond instanceof Expr\BinaryOp\BooleanOr ) {
			return $this->is_safe_condition( $cond->left ) && $this->is_safe_condition( $cond->right );
		}

		if ( $cond instanceof Expr\FuncCall ) {
			$function_name = $this->get_function_name( $cond );
			return null !== $function_name && in_array( $function_name, $this->get_allowed_functions(), true );
		}

		return false;
	}

	/**
	 * Checks if a return expression is safe.
	 *
	 * @since 1.9.0
	 *
	 * @param Expr|null $expr The expression to check.
	 * @return bool True if the return expression is safe, false otherwise.
	 */
	private function is_safe_return_expression( $expr ) {
		return $this->is_safe_expression( $expr );
	}

	/**
	 * Gets the function name from a function call expression.
	 *
	 * @since 1.9.0
	 *
	 * @param Expr\FuncCall $node The function call node.
	 * @return string|null The function name, or null if not found.
	 */
	private function get_function_name( Expr\FuncCall $node ) {
		if ( $node->name instanceof Node\Name ) {
			return $node->name->toString();
		}
		return null;
	}

	/**
	 * Gets the list of allowed functions that don't require guards.
	 *
	 * @since 1.9.0
	 *
	 * @return array List of allowed function names.
	 */
	private function get_allowed_functions() {
		return array(
			'class_exists',
			'function_exists',
			'interface_exists',
			'trait_exists',
			'defined',
		);
	}

	/**
	 * Fallback method using regex for files that can't be parsed.
	 *
	 * @since 1.9.0
	 *
	 * @param string $contents The file contents.
	 * @return bool True if the file is safe for direct access, false otherwise.
	 */
	private function is_valid_for_direct_access_regex( $contents ) {
		$contents = $this->clean_file_contents( $contents );

		if ( $this->is_asset_file( $contents ) ) {
			return true;
		}

		if ( $this->has_procedural_code( $contents ) ) {
			return false;
		}

		if ( $this->has_only_safe_function_calls( $contents ) ) {
			return true;
		}

		if ( $this->has_only_class_definitions( $contents ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if file only contains return statements (asset files - safe).
	 *
	 * @since 1.8.0
	 *
	 * @param string $contents The cleaned file contents.
	 * @return bool True if file is an asset file, false otherwise.
	 */
	private function is_asset_file( $contents ) {
		$without_assignments  = preg_replace( '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*[^;]+;/', '', $contents );
		$without_returns      = preg_replace( '/return\s+[^;]+;/', '', $without_assignments );
		$without_array_assign = preg_replace( '/\$[a-zA-Z_][a-zA-Z0-9_]*\s*=\s*array\s*\([^)]*\)\s*;/', '', $without_returns );
		$cleaned              = preg_replace( '/\s+/', ' ', trim( $without_array_assign ) );

		return empty( $cleaned ) || preg_match( '/^(<\?php)?\s*$/', $cleaned );
	}

	/**
	 * Checks if file contains procedural code that should have guards.
	 *
	 * @since 1.8.0
	 *
	 * @param string $contents The cleaned file contents.
	 * @return bool True if file has procedural code, false otherwise.
	 */
	private function has_procedural_code( $contents ) {
		if ( preg_match( '/\bdefine\s*\(/i', $contents ) ) {
			return true;
		}

		if ( preg_match( '/\badd_action\s*\(/i', $contents ) || preg_match( '/\badd_filter\s*\(/i', $contents ) ) {
			return true;
		}

		if ( preg_match( '/^\s*function\s+\w+\s*\(/im', $contents ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Checks if file only contains safe function calls with return statements.
	 *
	 * @since 1.8.0
	 *
	 * @param string $contents The cleaned file contents.
	 * @return bool True if file has only safe function calls, false otherwise.
	 */
	private function has_only_safe_function_calls( $contents ) {
		$safe_if_count      = preg_match_all( '/if\s*\([^)]*(?:class_exists|function_exists|interface_exists|trait_exists|defined)\s*\(/i', $contents );
		$return_count       = preg_match_all( '/return\s*;/', $contents );
		$all_function_calls = preg_match_all( '/\b(?!class_exists|function_exists|interface_exists|trait_exists|defined|return|if|else|elseif|isset|empty|unset|array|list|echo|print)\w+\s*\(/i', $contents );

		return $safe_if_count > 0 && $return_count >= $safe_if_count && 0 === $all_function_calls;
	}

	/**
	 * Checks if file contains only class/interface/trait definitions.
	 *
	 * @since 1.8.0
	 *
	 * @param string $contents The cleaned file contents.
	 * @return bool True if file has only class definitions, false otherwise.
	 */
	private function has_only_class_definitions( $contents ) {
		return (bool) preg_match( '/(?:^|\s)(?:final\s+)?(?:abstract\s+)?(?:class|interface|trait)\s+\w+/i', $contents );
	}

	/**
	 * Gets the description for the check.
	 *
	 * Every check must have a short description explaining what the check does.
	 *
	 * @since 1.8.0
	 *
	 * @return string Description.
	 */
	public function get_description(): string {
		return __( 'Checks that PHP files have proper guards to prevent direct file access.', 'plugin-check' );
	}

	/**
	 * Gets the documentation URL for the check.
	 *
	 * Every check must have a URL with further information about the check.
	 *
	 * @since 1.8.0
	 *
	 * @return string The documentation URL.
	 */
	public function get_documentation_url(): string {
		return __( 'https://developer.wordpress.org/plugins/wordpress-org/common-issues/#direct-file-access', 'plugin-check' );
	}
}
