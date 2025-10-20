<?php
/**
 * DirectDBSniff
 *
 * Based on code from {@link https://github.com/WordPress/WordPress-Coding-Standards}
 * which is licensed under {@link https://opensource.org/licenses/MIT}.
 *
 * @package PluginCheck
 */

namespace PluginCheckCS\PluginCheck\Sniffs\Security;

/**
 * Flag Database direct queries.
 *
 * @link    https://vip.wordpress.com/documentation/vip-go/code-review-blockers-warnings-notices/#direct-database-queries
 *
 * @package PluginCheck
 *
 * @since   1.0.0
 */
class DirectDBSniff extends AbstractEscapingCheckSniff {

	/**
	 * Rule name for this sniff.
	 *
	 * @var string
	 */
	protected $rule_name = 'UnescapedDBParameter';

	/**
	 * Override the parent class escaping functions to only allow SQL-safe escapes.
	 *
	 * @var array
	 */
	protected $escapingFunctions = array(
		'absint'               => true,
		'floatval'             => true,
		'intval'               => true,
		'json_encode'          => true,
		'like_escape'          => true,
		'wp_json_encode'       => true,
		'isset'                => true,
		'esc_sql'              => true,
		'wp_parse_id_list'     => true,
		'bp_esc_like'          => true,
		'sanitize_sql_orderby' => true,
	);

	/**
	 * Functions that are often mistaken for SQL escaping functions, but are not SQL safe.
	 *
	 * @var array
	 */
	protected $notEscapingFunctions = array(
		'addslashes',
		'addcslashes',
		'sanitize_text_field',
		'sanitize_title',
		'sanitize_key',
		'filter_input',
		'esc_attr',
	);

	/**
	 * None of these are SQL safe.
	 *
	 * @var array
	 */
	protected $sanitizingFunctions = array();

	/**
	 * Unslashing functions array.
	 *
	 * @var array
	 */
	protected $unslashingFunctions = array();

	/**
	 * Functions that are neither safe nor unsafe. Their output is as safe as the data passed as parameters.
	 *
	 * @var array
	 */
	protected $neutralFunctions = array(
		'implode'             => true,
		'join'                => true,
		'array_keys'          => true,
		'array_values'        => true,
		'sanitize_text_field' => true, // Note that this does not escape for SQL.
		'array_fill'          => true,
		'sprintf'             => true, // Sometimes used to get around formatting table and column names in queries.
		'array_filter'        => true,
	);

	/**
	 * Functions with output that can be assumed to be safe. Escaping is always preferred, but alerting on these is unnecessary noise.
	 *
	 * @var array
	 */
	protected $implicitSafeFunctions = array(
		'gmdate'              => true,
		'current_time'        => true,
		'mktime'              => true,
		'get_post_types'      => true,
		'get_charset_collate' => true,
		'get_blog_prefix'     => true,
		'get_post_stati'      => true,
		'count'               => true,
		'strtotime'           => true,
		'uniqid'              => true,
		'md5'                 => true,
		'sha1'                => true,
		'rand'                => true,
		'mt_rand'             => true,
		'max'                 => true,
		'table_name'          => true,
	);

	/**
	 * $wpdb methods with escaping built-in.
	 *
	 * @var array
	 */
	protected $safe_methods = array(
		'delete'  => true,
		'replace' => true,
		'update'  => true,
		'insert'  => true,
		// Note: 'prepare' is commented out as it requires manual escaping.
	);

	/**
	 * $wpdb methods that require the first parameter to be escaped.
	 *
	 * @var array
	 */
	protected $unsafe_methods = array(
		'query'       => true,
		'get_var'     => true,
		'get_col'     => true,
		'get_row'     => true,
		'get_results' => true,
	);

	/**
	 * Safe constants array.
	 *
	 * @var array
	 */
	protected $safe_constants = array(
		'ARRAY_A' => true,
		'OBJECT'  => true,
	);

	/**
	 * A list of variable names that, if used unescaped in a SQL query, will only produce a warning rather than an error.
	 * For example, 'SELECT * FROM {$table}' is commonly used and typically a red herring.
	 *
	 * @var array
	 */
	protected $warn_only_parameters = array(
		'$table',
		'$table_name',
		'$table_prefix',
		'$column_name',
		'$this', // Typically something like $this->tablename.
		'$order_by',
		'$orderby',
		'$where',
		'$wheres',
		'$join',
		'$joins',
		'$bp_prefix',
		'$where_sql',
		'$join_sql',
		'$from_sql',
		'$select_sql',
		'$meta_query_sql',
	);

	/**
	 * A list of SQL query prefixes that with only produce a warning instead of an error if they contain unsafe parameters.
	 * For example, 'CREATE TABLE $tablename' is often used because there are no clear ways to escape a table name.
	 *
	 * @var array
	 */
	protected $warn_only_queries = array(
		'CREATE TABLE',
		'SHOW TABLE',
		'DROP TABLE',
		'TRUNCATE TABLE',
	);

	/**
	 * Keep track of sanitized and unsanitized variables.
	 *
	 * @var array
	 */
	protected $sanitized_variables = array();

	/**
	 * Unsanitized variables array.
	 *
	 * @var array
	 */
	protected $unsanitized_variables = array();

	/**
	 * Assignments array.
	 *
	 * @var array
	 */
	protected $assignments = array();

	/**
	 * Used for providing extra context from some methods.
	 *
	 * @var int|null
	 */
	protected $methodPtr = null;

	/**
	 * Unsafe pointer.
	 *
	 * @var int|null
	 */
	protected $unsafe_ptr = null;

	/**
	 * Unsafe expression.
	 *
	 * @var string|null
	 */
	protected $unsafe_expression = null;

	/**
	 * Expression severity.
	 *
	 * @var int
	 */
	protected $expression_severity = 0;

	/**
	 * Returns an array of tokens this test wants to listen for.
	 *
	 * @return array
	 */
	public function register() {
		return array(
			\T_VARIABLE,
			\T_STRING,
		);
	}

	/**
	 * Is a SQL query of a type that should only produce a warning when it contains unescaped parameters?
	 *
	 * For example, CREATE TABLE queries usually include unescaped table and column names.
	 *
	 * @param string $sql The SQL query to check.
	 * @return bool
	 */
	public function is_warning_expression( $sql ) {
		foreach ( $this->warn_only_queries as $warn_query ) {
			if ( 0 === strpos( ltrim( $sql, '\'"' ), $warn_query ) ) {
				return true;
			}
		}

		return false;
	}
}
