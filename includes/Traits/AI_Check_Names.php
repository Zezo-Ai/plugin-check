<?php
/**
 * Trait WordPress\Plugin_Check\Traits\AI_Check_Names
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Traits;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\PromptBuilder;
use WP_Error;

/**
 * Trait for the Plugin Check Namer tool logic.
 *
 * @since 1.8.0
 */
trait AI_Check_Names {

	use AI_Connect;

	/**
	 * Runs the name analysis via AI (makes two queries like internal scanner).
	 *
	 * @since 1.8.0
	 *
	 * @param string $provider Provider key.
	 * @param string $api_key  API key.
	 * @param string $model    Model ID.
	 * @param string $name     Plugin name to evaluate.
	 * @return string|WP_Error Analysis output or WP_Error.
	 */
	protected function run_name_analysis( $provider, $api_key, $model, $name ) {
		if ( ! class_exists( AiClient::class ) ) {
			return new WP_Error( 'ai_client_not_available', __( 'AI client SDK is not available.', 'plugin-check' ) );
		}

		// First query: Similar name search.
		$similar_name_result = $this->run_similar_name_query( $provider, $api_key, $model, $name );
		if ( is_wp_error( $similar_name_result ) ) {
			return $similar_name_result;
		}

		// Build additional context from similar name results.
		$additional_context = $this->build_similar_name_context( $similar_name_result );

		// Second query: Pre-review with similar name results as context.
		$prereview_result = $this->run_prereview_query( $provider, $api_key, $model, $name, $additional_context );
		if ( is_wp_error( $prereview_result ) ) {
			return $prereview_result;
		}

		return $prereview_result;
	}

	/**
	 * Runs the similar name query (first query).
	 *
	 * @since 1.8.0
	 *
	 * @param string $provider Provider key.
	 * @param string $api_key  API key.
	 * @param string $model    Model ID.
	 * @param string $name     Plugin name to evaluate.
	 * @return string|WP_Error Analysis output or WP_Error.
	 */
	protected function run_similar_name_query( $provider, $api_key, $model, $name ) {
		$prompt_template = $this->get_prompt_template( 'ai-check-similar-name.md' );
		if ( is_wp_error( $prompt_template ) ) {
			return $prompt_template;
		}

		$prompt = $prompt_template . "\n\nPlugin name: {$name}\nPlugin description: (not provided)\n";

		// Execute AI request with structured output configuration.
		return $this->execute_ai_request(
			$provider,
			$api_key,
			$model,
			$prompt,
			function ( $builder ) {
				$this->maybe_set_structured_output( $builder, 'similar_name' );
			}
		);
	}

	/**
	 * Runs the pre-review query (second query).
	 *
	 * @since 1.8.0
	 *
	 * @param string $provider           Provider key.
	 * @param string $api_key            API key.
	 * @param string $model              Model ID.
	 * @param string $name               Plugin name to evaluate.
	 * @param string $additional_context Additional context from similar name query.
	 * @return string|WP_Error Analysis output or WP_Error.
	 */
	protected function run_prereview_query( $provider, $api_key, $model, $name, $additional_context = '' ) {
		$prompt_template = $this->get_prompt_template( 'ai-check-prereview.md' );
		if ( is_wp_error( $prompt_template ) ) {
			return $prompt_template;
		}

		$output_template = $this->get_prompt_template( 'ai-check-prereview-output.md' );
		if ( is_wp_error( $output_template ) ) {
			return $output_template;
		}

		// Combine developer prompt (system instructions).
		$developer_prompt = $prompt_template . "\n\n" . $output_template;

		// Build user prompt with plugin information.
		$user_prompt  = "# Plugin basic information\n\n";
		$user_prompt .= "- Display name for the plugin: {$name}\n";

		// Add additional context from similar name query if available.
		if ( ! empty( $additional_context ) ) {
			$user_prompt .= "\n\n" . $additional_context;
		}

		// Combine both prompts for the AI call.
		$full_prompt = $developer_prompt . "\n\n---\n\n" . $user_prompt;

		// Execute AI request with structured output configuration.
		return $this->execute_ai_request(
			$provider,
			$api_key,
			$model,
			$full_prompt,
			function ( $builder ) {
				$this->maybe_set_structured_output( $builder, 'prereview' );
			}
		);
	}

	/**
	 * Builds additional context from similar name results.
	 *
	 * @since 1.8.0
	 *
	 * @param string $similar_name_result Similar name query result.
	 * @return string Additional context text.
	 */
	protected function build_similar_name_context( $similar_name_result ) {
		if ( empty( $similar_name_result ) ) {
			return '';
		}

		$context  = "# Possible similarity to other plugins, trademarks and project names.\n\n";
		$context .= "We've detected the following possible similarities. Check them and determine if there is a high similarity. This is not an exhaustive list. It is only the result of an internet search, so you need to check its validity for this case. Do not mention them in your reply.\n\n";
		$context .= $similar_name_result;

		return $context;
	}

	/**
	 * Loads the AI prompt template.
	 *
	 * @since 1.8.0
	 *
	 * @param string $filename Optional filename to load. Default 'ai-check-similar-name.md'.
	 * @return string|WP_Error Prompt template or error.
	 */
	protected function get_prompt_template( $filename = 'ai-check-similar-name.md' ) {
		if ( ! defined( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH' ) ) {
			return new WP_Error( 'plugin_constant_not_defined', __( 'Plugin constant not defined.', 'plugin-check' ) );
		}

		$path = WP_PLUGIN_CHECK_PLUGIN_DIR_PATH . 'prompts/' . $filename;
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'prompt_not_found', __( 'Prompt template not found.', 'plugin-check' ) );
		}

		$contents = (string) file_get_contents( $path );
		$contents = trim( $contents );

		if ( empty( $contents ) ) {
			return new WP_Error( 'prompt_empty', __( 'Prompt template is empty.', 'plugin-check' ) );
		}

		return $contents;
	}

	/**
	 * Parses the analysis into a verdict and explanation.
	 *
	 * @since 1.8.0
	 *
	 * @param string $analysis AI output in markdown/YAML format.
	 * @return array
	 */
	protected function parse_analysis( $analysis ) {
		if ( empty( $analysis ) ) {
			return array(
				'verdict'     => '❓ ' . __( 'Empty Response', 'plugin-check' ),
				'explanation' => __( 'The AI did not return any analysis. Please try again.', 'plugin-check' ),
			);
		}

		$analysis_trim = trim( (string) $analysis );
		$parsed_data   = $this->parse_markdown_format( $analysis_trim );

		if ( ! empty( $parsed_data ) && isset( $parsed_data['possible_naming_issues'] ) ) {
			return $this->parse_prereview_response( $parsed_data );
		}

		// Unable to parse markdown format.
		return array(
			'verdict'     => '❓ ' . __( 'Unable to Parse', 'plugin-check' ),
			'explanation' => wp_kses_post( __( 'The AI response could not be parsed. Raw response:', 'plugin-check' ) . '<br><br>' . esc_html( substr( $analysis_trim, 0, 500 ) ) ),
			'raw'         => $analysis_trim,
		);
	}

	/**
	 * Parses markdown/YAML-like format from AI response.
	 *
	 * Format: - key: value
	 *
	 * @since 1.8.0
	 *
	 * @param string $text AI response text.
	 * @return array Parsed data array.
	 */
	protected function parse_markdown_format( $text ) {
		$result = array();
		$lines  = explode( "\n", $text );

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// Skip empty lines and lines that don't start with '-'.
			if ( empty( $line ) ) {
				continue;
			}

			// Remove leading '- ' and parse key: value.
			$line = ltrim( $line, '- ' );

			// Find the first colon to split key and value.
			$colon_pos = strpos( $line, ':' );
			if ( false === $colon_pos ) {
				continue;
			}

			$key   = trim( substr( $line, 0, $colon_pos ) );
			$value = trim( substr( $line, $colon_pos + 1 ) );

			// Skip if key is empty.
			if ( empty( $key ) ) {
				continue;
			}

			// Try to parse value as JSON array if it starts with '['.
			if ( 0 === strpos( $value, '[' ) ) {
				$decoded = json_decode( $value, true );
				if ( is_array( $decoded ) ) {
					$result[ $key ] = $decoded;
					continue;
				}
			}

			// Parse boolean values.
			if ( 'true' === strtolower( $value ) ) {
				$result[ $key ] = true;
				continue;
			}
			if ( 'false' === strtolower( $value ) ) {
				$result[ $key ] = false;
				continue;
			}

			// Parse comma-separated values (for disallowed_type).
			if ( false !== strpos( $value, ',' ) && 'disallowed_type' === $key ) {
				$result[ $key ] = array_map( 'trim', explode( ',', $value ) );
				continue;
			}

			// Store as string.
			$result[ $key ] = $value;
		}

		return $result;
	}

	/**
	 * Parses pre-review response format into user-friendly output.
	 *
	 * @since 1.8.0
	 *
	 * @param array $decoded Decoded JSON response.
	 * @return array{verdict:string,explanation:string,processed_data:array} Parsed result.
	 */
	protected function parse_prereview_response( $decoded ) {
		$issues = array();

		// Collect issues.
		if ( ! empty( $decoded['possible_naming_issues'] ) ) {
			$issues[] = __( 'Naming', 'plugin-check' );
		}
		if ( ! empty( $decoded['possible_owner_issues'] ) ) {
			$issues[] = __( 'Owner/Trademark', 'plugin-check' );
		}
		if ( ! empty( $decoded['possible_description_issues'] ) ) {
			$issues[] = __( 'Description', 'plugin-check' );
		}

		// Build verdict.
		$is_disallowed = ! empty( $decoded['disallowed'] );

		if ( $is_disallowed ) {
			$verdict = '❌ ' . __( 'Disallowed', 'plugin-check' );
		} elseif ( ! empty( $issues ) ) {
			$verdict = '⚠️ ' . __( 'Issues Found', 'plugin-check' ) . ': ' . implode( ', ', $issues );
		} else {
			$verdict = '✅ ' . __( 'No Issues Detected', 'plugin-check' );
		}

		// Build user-friendly explanation with sections.
		$explanation_parts = array();

		// Disallowed section (highest priority).
		if ( $is_disallowed ) {
			$disallowed_text = '';
			if ( ! empty( $decoded['disallowed_explanation'] ) ) {
				$disallowed_text .= $decoded['disallowed_explanation'];
			}
			if ( ! empty( $decoded['disallowed_type'] ) && is_array( $decoded['disallowed_type'] ) ) {
				$disallowed_text .= ' (' . implode( ', ', $decoded['disallowed_type'] ) . ')';
			}
			if ( ! empty( $disallowed_text ) ) {
				$explanation_parts[] = '<strong>' . __( '🚫 Disallowed:', 'plugin-check' ) . '</strong> ' . $disallowed_text;
			}
		}

		// Naming issues section.
		if ( ! empty( $decoded['possible_naming_issues'] ) && ! empty( $decoded['naming_explanation'] ) ) {
			$explanation_parts[] = '<strong>' . __( '📝 Naming:', 'plugin-check' ) . '</strong> ' . $decoded['naming_explanation'];
		}

		// Owner/Trademark issues section.
		if ( ! empty( $decoded['possible_owner_issues'] ) && ! empty( $decoded['owner_explanation'] ) ) {
			$explanation_parts[] = '<strong>' . __( '©️ Owner/Trademark:', 'plugin-check' ) . '</strong> ' . $decoded['owner_explanation'];
		}

		// Description issues section.
		if ( ! empty( $decoded['possible_description_issues'] ) && ! empty( $decoded['description_explanation'] ) ) {
			$explanation_parts[] = '<strong>' . __( '📄 Description:', 'plugin-check' ) . '</strong> ' . $decoded['description_explanation'];
		}

		// Trademarks detected.
		if ( ! empty( $decoded['trademarks_or_project_names_array'] ) && is_array( $decoded['trademarks_or_project_names_array'] ) ) {
			$trademarks          = implode( ', ', array_map( 'esc_html', $decoded['trademarks_or_project_names_array'] ) );
			$explanation_parts[] = '<strong>' . __( '™️ Trademarks Detected:', 'plugin-check' ) . '</strong> ' . $trademarks;
		}

		// Suggestions section (always helpful).
		$suggestions = array();
		if ( ! empty( $decoded['suggested_display_name'] ) ) {
			$suggestions[] = '<strong>' . __( 'Display Name:', 'plugin-check' ) . '</strong> ' . esc_html( $decoded['suggested_display_name'] );
		}
		if ( ! empty( $decoded['suggested_slug'] ) ) {
			$suggestions[] = '<strong>' . __( 'Slug:', 'plugin-check' ) . '</strong> ' . esc_html( $decoded['suggested_slug'] );
		}
		if ( ! empty( $decoded['short_description'] ) ) {
			$suggestions[] = '<strong>' . __( 'Description:', 'plugin-check' ) . '</strong> ' . esc_html( $decoded['short_description'] );
		}
		if ( ! empty( $decoded['plugin_category'] ) ) {
			$suggestions[] = '<strong>' . __( 'Category:', 'plugin-check' ) . '</strong> ' . esc_html( $decoded['plugin_category'] );
		}

		if ( ! empty( $suggestions ) ) {
			$explanation_parts[] = '<br><strong>' . __( '💡 Suggestions:', 'plugin-check' ) . '</strong><br>' . implode( '<br>', $suggestions );
		}

		// Language check.
		if ( isset( $decoded['description_language_is_in_english'] ) && false === $decoded['description_language_is_in_english'] ) {
			if ( ! empty( $decoded['description_what_is_not_in_english'] ) ) {
				$explanation_parts[] = '<strong>' . __( '🌐 Language:', 'plugin-check' ) . '</strong> ' . $decoded['description_what_is_not_in_english'];
			}
		}

		$explanation = ! empty( $explanation_parts ) ? implode( '<br><br>', $explanation_parts ) : __( 'No detailed analysis available.', 'plugin-check' );

		return array(
			'verdict'        => $verdict,
			'explanation'    => wp_kses_post( $explanation ),
			'processed_data' => $decoded,
		);
	}

	/**
	 * Attempts to set structured output on the builder if supported.
	 *
	 * @since 1.8.0
	 *
	 * @param PromptBuilder $builder The PromptBuilder instance.
	 * @param string        $query_type Type of query: 'similar_name' or 'prereview'.
	 * @return void
	 */
	protected function maybe_set_structured_output( $builder, $query_type = 'similar_name' ) {
		// Define the JSON schema based on query type.
		if ( 'prereview' === $query_type ) {
			$json_schema = $this->get_prereview_schema();
		} else {
			$json_schema = $this->get_similar_name_schema();
		}

		// Try different method names that might be used for structured output.
		$methods = array( 'withStructuredOutput', 'setResponseFormat', 'usingResponseFormat', 'withJsonSchema' );

		foreach ( $methods as $method ) {
			if ( method_exists( $builder, $method ) ) {
				call_user_func( array( $builder, $method ), $json_schema );
				break;
			}
		}

		// Try setting response format as a property if it exists.
		// Note: Using reflection to set property as it may not be public.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		if ( property_exists( $builder, 'responseFormat' ) || property_exists( $builder, 'response_format' ) ) {
			$prop_name = property_exists( $builder, 'responseFormat' ) ? 'responseFormat' : 'response_format';
			try {
				$reflection = new \ReflectionClass( $builder );
				$property   = $reflection->getProperty( $prop_name );
				$property->setAccessible( true );
				$property->setValue(
					$builder,
					array(
						'type'   => 'json_schema',
						'schema' => $json_schema,
					)
				);
			} catch ( \Exception $e ) {
				// If reflection fails, try direct assignment.
				if ( property_exists( $builder, $prop_name ) ) {
					$builder->$prop_name = array(
						'type'   => 'json_schema',
						'schema' => $json_schema,
					);
				}
			}
		}
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Gets the JSON schema for similar name query.
	 *
	 * @since 1.8.0
	 *
	 * @return array JSON schema array.
	 */
	protected function get_similar_name_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name_similarity_percentage' => array( 'type' => 'number' ),
				'similarity_explanation'     => array( 'type' => 'string' ),
				'confusion_existing_plugins' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'                 => array( 'type' => 'string' ),
							'similarity_level'     => array( 'type' => 'string' ),
							'explanation'          => array( 'type' => 'string' ),
							'active_installations' => array( 'type' => 'string' ),
							'link'                 => array( 'type' => 'string' ),
						),
						'required'             => array( 'name', 'similarity_level', 'explanation', 'active_installations', 'link' ),
						'additionalProperties' => false,
					),
				),
				'confusion_existing_others'  => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'             => array( 'type' => 'string' ),
							'similarity_level' => array( 'type' => 'string' ),
							'explanation'      => array( 'type' => 'string' ),
							'link'             => array( 'type' => 'string' ),
						),
						'required'             => array( 'name', 'similarity_level', 'explanation', 'link' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array(
				'name_similarity_percentage',
				'similarity_explanation',
				'confusion_existing_plugins',
				'confusion_existing_others',
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Gets the JSON schema for pre-review query.
	 *
	 * @since 1.8.0
	 *
	 * @return array JSON schema array.
	 */
	protected function get_prereview_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'possible_naming_issues'             => array( 'type' => 'boolean' ),
				'naming_explanation'                 => array( 'type' => 'string' ),
				'possible_owner_issues'              => array( 'type' => 'boolean' ),
				'owner_explanation'                  => array( 'type' => 'string' ),
				'possible_description_issues'        => array( 'type' => 'boolean' ),
				'description_explanation'            => array( 'type' => 'string' ),
				'disallowed'                         => array( 'type' => 'boolean' ),
				'disallowed_explanation'             => array( 'type' => 'string' ),
				'disallowed_type'                    => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
				'trademarks_or_project_names_array'  => array(
					'type'  => 'array',
					'items' => array(
						'type' => 'string',
					),
				),
				'suggested_display_name'             => array( 'type' => 'string' ),
				'suggested_slug'                     => array( 'type' => 'string' ),
				'short_description'                  => array( 'type' => 'string' ),
				'description_language_is_in_english' => array( 'type' => 'boolean' ),
				'description_what_is_not_in_english' => array( 'type' => 'string' ),
				'plugin_category'                    => array( 'type' => 'string' ),
			),
			'required'             => array(
				'possible_naming_issues',
				'naming_explanation',
				'possible_owner_issues',
				'owner_explanation',
				'possible_description_issues',
				'description_explanation',
				'disallowed',
				'disallowed_explanation',
				'disallowed_type',
				'trademarks_or_project_names_array',
				'suggested_display_name',
				'suggested_slug',
				'short_description',
				'description_language_is_in_english',
				'description_what_is_not_in_english',
				'plugin_category',
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Stores a transient result.
	 *
	 * @since 1.8.0
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Result data.
	 */
	protected function store_result( $user_id, $data ) {
		set_transient( $this->get_result_transient_key( $user_id ), $data, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Gets the transient key.
	 *
	 * @since 1.8.0
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	protected function get_result_transient_key( $user_id ) {
		return 'plugin_check_namer_result_' . (int) $user_id;
	}
}
