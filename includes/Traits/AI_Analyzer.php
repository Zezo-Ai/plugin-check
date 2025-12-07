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
	 * Checks if AI client is available and ready.
	 *
	 * @since 1.8.0
	 *
	 * @return bool True if AI client is available, false otherwise.
	 */
	protected function is_ai_available() {
		// Check if AI_Client class exists.
		if ( ! class_exists( '\WordPress\AI_Client\AI_Client' ) ) {
			return false;
		}

		// Get provider, API key, and model from settings.
		if ( ! class_exists( Settings_Page::class ) ) {
			return false;
		}

		$provider = Settings_Page::get_provider();
		$api_key  = Settings_Page::get_api_key();
		$model    = Settings_Page::get_model();

		// If provider, API key, or model is not configured, AI is not available.
		if ( empty( $provider ) || empty( $api_key ) || empty( $model ) ) {
			return false;
		}

		return true;
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
					'tokens_spent'    => 0,
					'false_positives' => 0,
					'issues_analyzed' => 0,
				),
			);
		}

		$analysis_results = array();
		$tokens_spent     = 0;
		$false_positives  = 0;
		$issues_analyzed  = 0;

		// Get severity thresholds from settings.
		$error_threshold   = Settings_Page::get_severity_errors();
		$warning_threshold = Settings_Page::get_severity_warnings();

		// Analyze errors (only those with severity less than threshold).
		foreach ( $errors as $file => $file_errors ) {
			foreach ( $file_errors as $line => $line_errors ) {
				foreach ( $line_errors as $column => $column_errors ) {
					foreach ( $column_errors as $error ) {
						// Only analyze errors with severity < threshold (low severity = more likely false positive).
						$severity = isset( $error['severity'] ) ? (int) $error['severity'] : 5;
						if ( $severity >= $error_threshold ) {
							continue;
						}

						$analysis = $this->analyze_issue_with_ai( $file, $line, $column, $error, 'error', $check_context );
						if ( ! is_wp_error( $analysis ) ) {
							$key = $this->get_issue_key( $file, $line, $column, $error['code'] );
							// Include file, line, column, and code in the analysis for easier matching in JS.
							$analysis['file']         = $file;
							$analysis['line']         = $line;
							$analysis['column']       = $column;
							$analysis['code']         = $error['code'];
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

		// Analyze warnings (only those with severity less than threshold).
		foreach ( $warnings as $file => $file_warnings ) {
			foreach ( $file_warnings as $line => $line_warnings ) {
				foreach ( $line_warnings as $column => $column_warnings ) {
					foreach ( $column_warnings as $warning ) {
						// Only analyze warnings with severity < threshold (low severity = more likely false positive).
						$severity = isset( $warning['severity'] ) ? (int) $warning['severity'] : 5;
						if ( $severity >= $warning_threshold ) {
							continue;
						}

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
		// Ensure AI is available before proceeding.
		if ( ! $this->is_ai_available() ) {
			return new WP_Error(
				'ai_not_available',
				__( 'AI client is not available.', 'plugin-check' )
			);
		}

		$file_path    = $check_context->path( '/' ) . $file;
		$file_content = '';

		if ( file_exists( $file_path ) && is_readable( $file_path ) ) {
			$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		$context_lines = $this->get_code_context( $file_content, $line );

		$prompt = $this->build_analysis_prompt( $file, $line, $column, $issue, $type, $context_lines );

		try {
			$provider = Settings_Page::get_provider();
			$api_key  = Settings_Page::get_api_key();
			$model    = Settings_Page::get_model();

			// Ensure credentials are registered with the provider registry.
			if ( class_exists( '\WordPress\AiClient\AiClient' ) ) {
				$registry = \WordPress\AiClient\AiClient::defaultRegistry();

				if ( $registry->hasProvider( $provider ) ) {
					$registry->setProviderRequestAuthentication(
						$provider,
						new \WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication( $api_key )
					);
				}
			}

			// Build prompt with provider and model configuration.
			$prompt_builder = \WordPress\AI_Client\AI_Client::prompt( $prompt )
				->using_temperature( 0.3 )
				->using_max_completion_tokens( 500 );

			// Set provider and model using config.
			if ( ! empty( $provider ) ) {
				$prompt_builder->using_provider( $provider );

				if ( ! empty( $model ) && class_exists( '\WordPress\AiClient\Providers\Models\DTO\ModelConfig' ) ) {
					$model_config = new \WordPress\AiClient\Providers\Models\DTO\ModelConfig( $model );
					$prompt_builder->using_model_config( $model_config );
				}
			}

			$result = $prompt_builder->generate_text_result();

			$response_text = $result->text();

			$analysis = $this->parse_ai_response( $response_text, $issue );

			// Track tokens spent if available in result.
			$usage = $result->usage();
			if ( null !== $usage ) {
				$tokens = 0;
				if ( null !== $usage->totalTokens() ) {
					$tokens = $usage->totalTokens();
				} elseif ( null !== $usage->promptTokens() && null !== $usage->completionTokens() ) {
					$tokens = $usage->promptTokens() + $usage->completionTokens();
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
	 * @param string $response_text AI response text.
	 * @param array  $issue         Original issue data.
	 * @return array Parsed analysis result.
	 */
	protected function parse_ai_response( $response_text, $issue ) {
		// Try to extract JSON from the response.
		if ( preg_match( '/\{[^}]+\}/s', $response_text, $matches ) ) {
			$json = json_decode( $matches[0], true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $json ) ) {
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

