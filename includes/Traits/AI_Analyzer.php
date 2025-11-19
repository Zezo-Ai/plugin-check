<?php
/**
 * Trait WordPress\Plugin_Check\Traits\AI_Analyzer
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Traits;

use WordPress\Plugin_Check\Admin\Settings_Page;
use WordPress\Plugin_Check\Checker\Check_Context;
use WordPress\Plugin_Check\Checker\Check_Result;
use WP_Error;

/**
 * Trait for analyzing check results for false positives using AI.
 *
 * @since 1.8.0
 */
trait AI_Analyzer {

	/**
	 * AI client instance.
	 *
	 * @since 1.8.0
	 * @var object|null
	 */
	protected $ai_client;

	/**
	 * Initializes the AI client if wp-ai-client is available.
	 *
	 * @since 1.8.0
	 */
	protected function init_ai_client() {
		if ( ! class_exists( '\WordPress\AI_Client\Client' ) ) {
			return;
		}

		if ( null !== $this->ai_client ) {
			return;
		}

		// Get provider, API key, and model from settings.
		$provider = '';
		$api_key  = '';
		$model    = '';
		if ( class_exists( Settings_Page::class ) ) {
			$provider = Settings_Page::get_provider();
			$api_key  = Settings_Page::get_api_key();
			$model    = Settings_Page::get_model();
		}

		// If provider, API key, or model is not configured, don't initialize the client.
		if ( empty( $provider ) || empty( $api_key ) || empty( $model ) ) {
			return;
		}

		try {
			$this->ai_client = new \WordPress\AI_Client\Client(
				array(
					'provider' => $provider,
					'api_key'  => $api_key,
					'model'    => $model,
				)
			);
		} catch ( \Exception $e ) {
			// AI client initialization failed, continue without AI.
			$this->ai_client = null;
		}
	}

	/**
	 * Checks if AI client is available and ready.
	 *
	 * @since 1.8.0
	 *
	 * @return bool True if AI client is available, false otherwise.
	 */
	protected function is_ai_available() {
		$this->init_ai_client();
		return null !== $this->ai_client;
	}

	/**
	 * Analyzes check results for false positives.
	 *
	 * @since 1.8.0
	 *
	 * @param Check_Result  $result        Check result to analyze.
	 * @param Check_Context $check_context Check context instance.
	 * @return array|WP_Error Array of AI analysis results and stats or WP_Error on failure.
	 */
	protected function analyze_results_with_ai( Check_Result $result, Check_Context $check_context ) {
		if ( ! $this->is_ai_available() ) {
			return new WP_Error(
				'ai_not_available',
				__( 'AI client is not available.', 'plugin-check' )
			);
		}

		$errors   = $result->get_errors();
		$warnings = $result->get_warnings();

		// If no errors or warnings, nothing to analyze.
		if ( empty( $errors ) && empty( $warnings ) ) {
			return array(
				'analysis' => array(),
				'stats'    => array(
					'tokens_spent'     => 0,
					'false_positives'  => 0,
					'issues_analyzed'  => 0,
				),
			);
		}

		$analysis_results = array();
		$tokens_spent     = 0;
		$false_positives  = 0;
		$issues_analyzed  = 0;

		// Analyze errors (only those with severity less than 7).
		foreach ( $errors as $file => $file_errors ) {
			foreach ( $file_errors as $line => $line_errors ) {
				foreach ( $line_errors as $column => $column_errors ) {
					foreach ( $column_errors as $error ) {
						// Only analyze errors with severity less than 7.
						$severity = isset( $error['severity'] ) ? (int) $error['severity'] : 5;
						if ( $severity >= 7 ) {
							continue;
						}

						$analysis = $this->analyze_issue_with_ai( $file, $line, $column, $error, 'error', $check_context );
						if ( ! is_wp_error( $analysis ) ) {
							$key                        = $this->get_issue_key( $file, $line, $column, $error['code'] );
							// Include file, line, column, and code in the analysis for easier matching in JS.
							$analysis['file']           = $file;
							$analysis['line']           = $line;
							$analysis['column']         = $column;
							$analysis['code']           = $error['code'];
							$analysis_results[ $key ] = $analysis;
							$issues_analyzed++;

							// Track tokens spent.
							if ( isset( $analysis['tokens_spent'] ) ) {
								$tokens_spent += (int) $analysis['tokens_spent'];
							}

							// Count false positives.
							if ( isset( $analysis['is_false_positive'] ) && $analysis['is_false_positive'] ) {
								$false_positives++;
							}
						}
					}
				}
			}
		}

		// Analyze warnings.
		foreach ( $warnings as $file => $file_warnings ) {
			foreach ( $file_warnings as $line => $line_warnings ) {
				foreach ( $line_warnings as $column => $column_warnings ) {
					foreach ( $column_warnings as $warning ) {
						$analysis = $this->analyze_issue_with_ai( $file, $line, $column, $warning, 'warning', $check_context );
						if ( ! is_wp_error( $analysis ) ) {
							$key                        = $this->get_issue_key( $file, $line, $column, $warning['code'] );
							// Include file, line, column, and code in the analysis for easier matching in JS.
							$analysis['file']           = $file;
							$analysis['line']           = $line;
							$analysis['column']         = $column;
							$analysis['code']           = $warning['code'];
							$analysis_results[ $key ] = $analysis;
							$issues_analyzed++;

							// Track tokens spent.
							if ( isset( $analysis['tokens_spent'] ) ) {
								$tokens_spent += (int) $analysis['tokens_spent'];
							}

							// Count false positives.
							if ( isset( $analysis['is_false_positive'] ) && $analysis['is_false_positive'] ) {
								$false_positives++;
							}
						}
					}
				}
			}
		}

		return array(
			'analysis' => $analysis_results,
			'stats'    => array(
				'tokens_spent'    => $tokens_spent,
				'false_positives' => $false_positives,
				'issues_analyzed' => $issues_analyzed,
			),
		);
	}

	/**
	 * Analyzes a single issue for false positive potential.
	 *
	 * @since 1.8.0
	 *
	 * @param string        $file          File path where the issue was found.
	 * @param int           $line          Line number where the issue was found.
	 * @param int           $column        Column number where the issue was found.
	 * @param array         $issue         Issue data (message, code, etc.).
	 * @param string        $type          Issue type ('error' or 'warning').
	 * @param Check_Context $check_context Check context instance.
	 * @return array|WP_Error Analysis result or WP_Error on failure.
	 */
	protected function analyze_issue_with_ai( $file, $line, $column, $issue, $type, Check_Context $check_context ) {
		$file_path    = $check_context->path( '/' ) . $file;
		$file_content = '';

		// Read the file content if it exists.
		if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
			$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		// Get context around the line.
		$context_lines = $this->get_code_context( $file_content, $line );

		// Build the prompt for AI analysis.
		$prompt = $this->build_analysis_prompt( $file, $line, $column, $issue, $type, $context_lines );

		try {
			$response = $this->ai_client->request(
				$prompt,
				array(
					'temperature' => 0.3,
					'max_tokens'  => 500,
				)
			);

			$analysis = $this->parse_ai_response( $response, $issue );

			// Track tokens spent if available in response.
			if ( is_array( $response ) && isset( $response['usage'] ) ) {
				$usage = $response['usage'];
				$tokens = 0;
				if ( isset( $usage['total_tokens'] ) ) {
					$tokens = (int) $usage['total_tokens'];
				} elseif ( isset( $usage['prompt_tokens'] ) && isset( $usage['completion_tokens'] ) ) {
					$tokens = (int) $usage['prompt_tokens'] + (int) $usage['completion_tokens'];
				}
				$analysis['tokens_spent'] = $tokens;
			}

			return $analysis;
		} catch ( \Exception $e ) {
			return new WP_Error(
				'ai_request_failed',
				sprintf(
					// translators: %s: Error message.
					__( 'AI analysis failed: %s', 'plugin-check' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Gets code context around a specific line.
	 *
	 * @since 1.8.0
	 *
	 * @param string $file_content Full file content.
	 * @param int    $line         Line number.
	 * @param int    $context      Number of lines before and after.
	 * @return string Code context.
	 */
	protected function get_code_context( $file_content, $line, $context = 10 ) {
		if ( empty( $file_content ) ) {
			return '';
		}

		$lines = explode( "\n", $file_content );
		$start = max( 0, $line - $context - 1 );
		$end   = min( count( $lines ), $line + $context );

		$context_lines = array_slice( $lines, $start, $end - $start );

		return implode( "\n", $context_lines );
	}

	/**
	 * Builds the prompt for AI analysis.
	 *
	 * @since 1.8.0
	 *
	 * @param string $file         File path.
	 * @param int    $line         Line number.
	 * @param int    $column       Column number.
	 * @param array  $issue        Issue data.
	 * @param string $type         Issue type.
	 * @param string $code_context Code context.
	 * @return string AI prompt.
	 */
	protected function build_analysis_prompt( $file, $line, $column, $issue, $type, $code_context ) {
		$prompt = sprintf(
			// translators: %1$s: Issue type, %2$s: File path, %3$s: Line number, %4$s: Issue code, %5$s: Issue message.
			__(
				'You are analyzing a WordPress plugin check result for potential false positives.

Issue Type: %1$s
File: %2$s
Line: %3$d
Column: %4$d
Issue Code: %5$s
Issue Message: %6$s

Code Context:
```
%7$s
```

Please analyze if this is likely a false positive or a legitimate issue. Consider:
- Whether the code is actually problematic
- If there are legitimate exceptions or edge cases
- Whether the check might be too strict
- The context and intent of the code

Provide your analysis in JSON format with the following structure:
{
  "is_false_positive": boolean,
  "confidence": float (0.0 to 1.0),
  "reasoning": "string explanation",
  "recommendation": "string recommendation"
}

Respond ONLY with valid JSON, no other text.',
				'plugin-check'
			),
			$type,
			$file,
			$line,
			$column,
			$issue['code'],
			$issue['message'],
			$code_context
		);

		return $prompt;
	}

	/**
	 * Parses the AI response into a structured format.
	 *
	 * @since 1.8.0
	 *
	 * @param string|array $response AI response.
	 * @param array        $issue    Original issue data.
	 * @return array Parsed analysis result.
	 */
	protected function parse_ai_response( $response, $issue ) {
		$response_text = is_array( $response ) && isset( $response['content'] ) ? $response['content'] : (string) $response;

		// Try to extract JSON from the response.
		if ( preg_match( '/\{[^}]+\}/s', $response_text, $matches ) ) {
			$json = json_decode( $matches[0], true );
			if ( json_last_error() === JSON_ERROR_NONE && is_array( $json ) ) {
				return array(
					'is_false_positive' => isset( $json['is_false_positive'] ) ? (bool) $json['is_false_positive'] : false,
					'confidence'        => isset( $json['confidence'] ) ? floatval( $json['confidence'] ) : 0.5,
					'reasoning'         => isset( $json['reasoning'] ) ? sanitize_text_field( $json['reasoning'] ) : '',
					'recommendation'    => isset( $json['recommendation'] ) ? sanitize_text_field( $json['recommendation'] ) : '',
					'original_issue'    => $issue,
				);
			}
		}

		// Fallback if JSON parsing fails.
		return array(
			'is_false_positive' => false,
			'confidence'        => 0.5,
			'reasoning'         => __( 'Unable to parse AI response.', 'plugin-check' ),
			'recommendation'    => __( 'Manual review recommended.', 'plugin-check' ),
			'original_issue'    => $issue,
		);
	}

	/**
	 * Generates a unique key for an issue.
	 *
	 * @since 1.8.0
	 *
	 * @param string $file   File path.
	 * @param int    $line   Line number.
	 * @param int    $column Column number.
	 * @param string $code   Issue code.
	 * @return string Unique key.
	 */
	protected function get_issue_key( $file, $line, $column, $code ) {
		return md5( $file . ':' . $line . ':' . $column . ':' . $code );
	}
}

