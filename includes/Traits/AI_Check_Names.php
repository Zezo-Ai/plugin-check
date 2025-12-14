<?php
/**
 * Trait WordPress\Plugin_Check\Traits\AI_Check_Names
 *
 * @package plugin-check
 */

namespace WordPress\Plugin_Check\Traits;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\PromptBuilder;
use WordPress\AiClient\Providers\Http\HttpTransporterFactory;
use WP_Error;

/**
 * Trait for the Plugin Check Namer tool logic.
 *
 * @since 1.8.0
 */
trait AI_Check_Names {

	/**
	 * Runs the name analysis via AI.
	 *
	 * @since 1.8.0
	 *
	 * @param string $provider Provider key.
	 * @param string $api_key  API key.
	 * @param string $model    Model ID.
	 * @param string $name     Plugin name to evaluate.
	 * @return array{text:string,usage?:array}|WP_Error Analysis output with optional usage info or WP_Error.
	 */
	protected function run_name_analysis( $provider, $api_key, $model, $name ) {
		if ( ! class_exists( AiClient::class ) ) {
			return new WP_Error( 'ai_client_not_available', __( 'AI client SDK is not available.', 'plugin-check' ) );
		}

		$prompt_template = $this->get_prompt_template();
		if ( is_wp_error( $prompt_template ) ) {
			return $prompt_template;
		}

		$prompt = $prompt_template . "\n\nPlugin name: {$name}\nPlugin description: (not provided)\n";

		try {
			$registry = AiClient::defaultRegistry();
			$registry->setHttpTransporter( HttpTransporterFactory::createTransporter() );
			$registry->setProviderRequestAuthentication( $provider, $this->get_request_authentication_for_provider( $provider, $api_key ) );

			$model_instance = $registry->getProviderModel( $provider, $model );

			$builder = new PromptBuilder( $registry, $prompt );
			$builder->usingModel( $model_instance );

			// Try to set structured output if the builder supports it.
			$this->maybe_set_structured_output( $builder );

			$text = $builder->generateText();

			$result = array(
				'text' => $text,
			);

			return $result;
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ai_request_failed', $e->getMessage() );
		}
	}

	/**
	 * Loads the AI prompt template.
	 *
	 * @since 1.8.0
	 *
	 * @return string|WP_Error Prompt template or error.
	 */
	protected function get_prompt_template() {
		if ( ! defined( 'WP_PLUGIN_CHECK_PLUGIN_DIR_PATH' ) ) {
			return new WP_Error( 'plugin_constant_not_defined', __( 'Plugin constant not defined.', 'plugin-check' ) );
		}

		$path = WP_PLUGIN_CHECK_PLUGIN_DIR_PATH . 'prompts/ai-check-similar-name.md';
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
	 * @param string|array $analysis    AI output (string or array with 'text' and optional 'usage').
	 * @param string       $plugin_slug Optional plugin slug to filter out self-references.
	 * @return array{verdict:string,explanation:string,raw?:string,confusion_existing_plugins?:array,confusion_existing_others?:array,usage?:array}
	 */
	protected function parse_analysis( $analysis, $plugin_slug = '' ) {
		// Handle both string and array responses.
		if ( is_array( $analysis ) ) {
			$analysis = isset( $analysis['text'] ) ? $analysis['text'] : '';
		}

		$analysis_trim = trim( (string) $analysis );

		if ( empty( $analysis_trim ) ) {
			$result = array(
				'verdict'     => __( 'Unknown (empty response)', 'plugin-check' ),
				'explanation' => __( 'The AI did not return any analysis.', 'plugin-check' ),
			);
			return $result;
		}

		// Try JSON first (some models respond with JSON despite instructions).
		$json_result = $this->parse_json_analysis( $analysis_trim, $plugin_slug );
		if ( null !== $json_result ) {
			// Add enriched data from processed response.
			if ( ! empty( $json_result['processed_data'] ) ) {
				$processed = $json_result['processed_data'];
				$json_result['raw'] = wp_json_encode( $processed, JSON_PRETTY_PRINT );
				if ( ! empty( $processed['confusion_existing_plugins'] ) ) {
					$json_result['confusion_existing_plugins'] = $processed['confusion_existing_plugins'];
				}
				if ( ! empty( $processed['confusion_existing_others'] ) ) {
					$json_result['confusion_existing_others'] = $processed['confusion_existing_others'];
				}
				unset( $json_result['processed_data'] );
			} else {
				// Format the JSON even if we don't have processed_data.
				$decoded_for_format = json_decode( $analysis_trim, true );
				if ( is_array( $decoded_for_format ) ) {
					$json_result['raw'] = wp_json_encode( $decoded_for_format, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				} else {
					$json_result['raw'] = $analysis_trim;
				}
			}
			if ( ! empty( $usage ) ) {
				$json_result['usage'] = $usage;
			}
			return $json_result;
		}

		$percentage  = $this->extract_percentage( $analysis_trim );
		$explanation = $this->extract_explanation( $analysis_trim );

		if ( null === $percentage ) {
			$result = array(
				'verdict'     => __( 'Unknown (could not parse score)', 'plugin-check' ),
				'explanation' => $explanation,
			);
			if ( ! empty( $usage ) ) {
				$result['usage'] = $usage;
			}
			return $result;
		}

		$result = $this->verdict_from_percentage( $percentage, $explanation );
		if ( ! empty( $usage ) ) {
			$result['usage'] = $usage;
		}
		return $result;
	}

	/**
	 * Parses JSON analysis response.
	 *
	 * @since 1.8.0
	 *
	 * @param string $analysis_trim Trimmed analysis text.
	 * @param string $plugin_slug   Optional plugin slug to filter out self-references.
	 * @return array{verdict:string,explanation:string,processed_data?:array}|null Parsed result or null if not JSON.
	 */
	protected function parse_json_analysis( $analysis_trim, $plugin_slug = '' ) {
		// Try to extract JSON from markdown code blocks if present.
		$json_text = $this->extract_json_from_text( $analysis_trim );

		// Try parsing the extracted JSON.
		$decoded = json_decode( $json_text, true );

		// If that failed, try parsing the original text.
		if ( ! is_array( $decoded ) || json_last_error() !== JSON_ERROR_NONE ) {
			$decoded = json_decode( $analysis_trim, true );
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['name_similarity_percentage'] ) ) {
			return null;
		}

		// Post-process and validate confusion arrays.
		$processed_data = $this->post_process_analysis( $decoded, $plugin_slug );

		$percentage  = (int) $processed_data['name_similarity_percentage'];
		$explanation = isset( $processed_data['similarity_explanation'] ) ? trim( (string) $processed_data['similarity_explanation'] ) : '';

		if ( empty( $explanation ) ) {
			$explanation = __( 'See the full AI output below.', 'plugin-check' );
		}

		$result = $this->verdict_from_percentage( $percentage, $explanation );
		$result['processed_data'] = $processed_data;

		return $result;
	}

	/**
	 * Post-processes AI analysis response to validate and enrich confusion arrays.
	 *
	 * @since 1.8.0
	 *
	 * @param array  $completion_array Decoded JSON response from AI.
	 * @param string $plugin_slug     Optional plugin slug to filter out self-references.
	 * @return array Post-processed analysis array.
	 */
	protected function post_process_analysis( $completion_array, $plugin_slug = '' ) {
		if ( ! is_array( $completion_array ) ) {
			return $completion_array;
		}

		// Process confusion_existing_plugins array.
		if ( ! empty( $completion_array['confusion_existing_plugins'] ) && is_array( $completion_array['confusion_existing_plugins'] ) ) {
			foreach ( $completion_array['confusion_existing_plugins'] as $key => $confusion_existing_plugin ) {
				if ( ! is_array( $confusion_existing_plugin ) ) {
					unset( $completion_array['confusion_existing_plugins'][ $key ] );
					continue;
				}

				$completion_array['confusion_existing_plugins'][ $key ]['owner_username'] = 'Unknown';

				// Check if the link is a WordPress plugin URL.
				if ( ! empty( $confusion_existing_plugin['link'] ) && preg_match( '#^https?://wordpress\.org/plugins/([^/]+)/?#i', $confusion_existing_plugin['link'], $matches ) ) {
					$slug = $matches[1];

					// Remove if this is the same plugin as the current one.
					if ( ! empty( $plugin_slug ) && $plugin_slug === $slug ) {
						unset( $completion_array['confusion_existing_plugins'][ $key ] );
						continue;
					}

					// Fetch plugin data from WordPress.org API.
					$plugin_data = $this->fetch_wporg_plugin_data( $slug );
					if ( ! empty( $plugin_data ) ) {
						// Update name if available.
						if ( isset( $plugin_data['name'] ) ) {
							$completion_array['confusion_existing_plugins'][ $key ]['name'] = html_entity_decode( $plugin_data['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						}

						// Update active installations if available.
						if ( isset( $plugin_data['active_installs'] ) ) {
							$completion_array['confusion_existing_plugins'][ $key ]['active_installations'] = $plugin_data['active_installs'] . '+';
						}

						// Extract author username from profile URL.
						if ( ! empty( $plugin_data['author_profile'] ) && preg_match( '#https?://profiles\.wordpress\.org/([^/]+)/?#i', $plugin_data['author_profile'], $author_matches ) ) {
							$completion_array['confusion_existing_plugins'][ $key ]['owner_username'] = $author_matches[1];
						}
					} else {
						// Remove if plugin doesn't exist or API call failed.
						unset( $completion_array['confusion_existing_plugins'][ $key ] );
					}
				} else {
					// Remove if it's not a WordPress plugin URL.
					unset( $completion_array['confusion_existing_plugins'][ $key ] );
				}
			}
		}

		// Process confusion_existing_others array.
		if ( ! empty( $completion_array['confusion_existing_others'] ) && is_array( $completion_array['confusion_existing_others'] ) ) {
			foreach ( $completion_array['confusion_existing_others'] as $key => $confusion_existing_others ) {
				if ( ! is_array( $confusion_existing_others ) ) {
					unset( $completion_array['confusion_existing_others'][ $key ] );
					continue;
				}

				// This is not the place for plugins - remove if it's a WordPress plugin URL.
				if ( ! empty( $confusion_existing_others['link'] ) && preg_match( '#^https?://wordpress\.org/plugins/([^/]+)/?#i', $confusion_existing_others['link'], $matches ) ) {
					unset( $completion_array['confusion_existing_others'][ $key ] );
					continue;
				}

				// Check if domain exists via DNS lookup.
				if ( ! empty( $confusion_existing_others['link'] ) ) {
					$parsed_url = parse_url( $confusion_existing_others['link'] );
					$host       = $parsed_url['host'] ?? null;

					if ( $host ) {
						// Check if A or AAAA DNS record exists for the host.
						if ( ! checkdnsrr( $host, 'A' ) && ! checkdnsrr( $host, 'AAAA' ) ) {
							unset( $completion_array['confusion_existing_others'][ $key ] );
							continue;
						}
					} else {
						unset( $completion_array['confusion_existing_others'][ $key ] );
						continue;
					}

					// Sometimes the AI invents a website with the same name of the plugin, verify it exists.
					if ( ! empty( $confusion_existing_others['name'] ) && ! empty( $confusion_existing_others['link'] ) ) {
						$plugin_name = $this->get_current_plugin_name();
						if ( ! empty( $plugin_name ) && ( $confusion_existing_others['name'] === $plugin_name ) ) {
							$response_code = $this->check_url_exists( $confusion_existing_others['link'] );
							if ( in_array( $response_code, array( 400, 404, 414 ), true ) ) {
								unset( $completion_array['confusion_existing_others'][ $key ] );
								continue;
							}
						}
					}
				}
			}
		}

		// Reindex arrays to ensure sequential keys.
		if ( ! empty( $completion_array['confusion_existing_plugins'] ) ) {
			$completion_array['confusion_existing_plugins'] = array_values( $completion_array['confusion_existing_plugins'] );
		}
		if ( ! empty( $completion_array['confusion_existing_others'] ) ) {
			$completion_array['confusion_existing_others'] = array_values( $completion_array['confusion_existing_others'] );
		}

		return $completion_array;
	}

	/**
	 * Fetches plugin data from WordPress.org API.
	 *
	 * @since 1.8.0
	 *
	 * @param string $slug Plugin slug.
	 * @return array|false Plugin data array or false on failure.
	 */
	protected function fetch_wporg_plugin_data( $slug ) {
		if ( empty( $slug ) ) {
			return false;
		}

		$api_url = "https://wordpress.org/plugins/wp-json/plugins/v1/plugin/{$slug}";

		// Use wp_remote_get if available, otherwise fall back to curl.
		if ( function_exists( 'wp_remote_get' ) ) {
			$response = wp_remote_get(
				$api_url,
				array(
					'timeout'     => 5,
					'user-agent' => 'Plugin Check Internal Script',
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $response_code ) {
				return false;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			return is_array( $data ) ? $data : false;
		}

		// Fallback to curl if wp_remote_get is not available.
		if ( ! function_exists( 'curl_init' ) ) {
			return false;
		}

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $api_url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_USERAGENT, 'Plugin Check Internal Script' );

		$response_body = curl_exec( $ch );
		$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( false === $response_body || 200 !== $response_code ) {
			return false;
		}

		$data = json_decode( $response_body, true );
		return is_array( $data ) ? $data : false;
	}

	/**
	 * Checks if a URL exists by making an HTTP request.
	 *
	 * @since 1.8.0
	 *
	 * @param string $url URL to check.
	 * @return int HTTP response code.
	 */
	protected function check_url_exists( $url ) {
		if ( empty( $url ) ) {
			return 0;
		}

		// Use wp_remote_head if available.
		if ( function_exists( 'wp_remote_head' ) ) {
			$response = wp_remote_head(
				$url,
				array(
					'timeout'     => 5,
					'user-agent' => 'Plugin Check Internal Script',
				)
			);

			if ( is_wp_error( $response ) ) {
				return 0;
			}

			return wp_remote_retrieve_response_code( $response );
		}

		// Fallback to curl.
		if ( ! function_exists( 'curl_init' ) ) {
			return 0;
		}

		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_NOBODY, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_USERAGENT, 'Plugin Check Internal Script' );

		curl_exec( $ch );
		$response_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		return (int) $response_code;
	}

	/**
	 * Gets the current plugin name being checked.
	 *
	 * @since 1.8.0
	 *
	 * @return string Plugin name or empty string.
	 */
	protected function get_current_plugin_name() {
		// This method should be overridden by the class using this trait
		// to provide the current plugin name if available.
		return '';
	}

	/**
	 * Attempts to set structured output on the builder if supported.
	 *
	 * @since 1.8.0
	 *
	 * @param PromptBuilder $builder The PromptBuilder instance.
	 * @return void
	 */
	protected function maybe_set_structured_output( $builder ) {
		// Define the JSON schema for structured output.
		$json_schema = array(
			'type'       => 'object',
			'properties' => array(
				'name_similarity_percentage' => array( 'type' => 'number' ),
				'similarity_explanation'     => array( 'type' => 'string' ),
				'confusion_existing_plugins' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'                => array( 'type' => 'string' ),
							'similarity_level'    => array( 'type' => 'string' ),
							'explanation'         => array( 'type' => 'string' ),
							'active_installations' => array( 'type' => 'string' ),
							'link'                => array( 'type' => 'string' ),
						),
						'required'             => array( 'name', 'similarity_level', 'explanation', 'active_installations', 'link' ),
						'additionalProperties' => false,
					),
				),
				'confusion_existing_others'  => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
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
	}

	/**
	 * Extracts JSON from text, removing markdown code fences if present.
	 *
	 * @since 1.8.0
	 *
	 * @param string $text Text that may contain JSON.
	 * @return string Extracted JSON text.
	 */
	protected function extract_json_from_text( $text ) {
		$text = trim( $text );

		// Remove markdown code fences.
		$text = preg_replace( '/^```(?:json)?\s*\n?/m', '', $text );
		$text = preg_replace( '/\n?```\s*$/m', '', $text );

		// Try to find JSON object boundaries.
		if ( preg_match( '/\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', $text, $matches ) ) {
			return $matches[0];
		}

		// If no match, try to find first { to last }.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false !== $start && false !== $end && $end > $start ) {
			return substr( $text, $start, $end - $start + 1 );
		}

		return $text;
	}

	/**
	 * Extracts percentage from analysis text.
	 *
	 * @since 1.8.0
	 *
	 * @param string $analysis_trim Trimmed analysis text.
	 * @return int|null Percentage or null if not found.
	 */
	protected function extract_percentage( $analysis_trim ) {
		$patterns = array(
			'/name_similarity_percentage\s*:\s*(\d{1,3})/i',
			'/[-*]\s*name_similarity_percentage\s*:\s*(\d{1,3})/i',
			'/"name_similarity_percentage"\s*:\s*(\d{1,3})/i',
			'/(?:similarity|percentage)[\s:]+(\d{1,3})/i',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $analysis_trim, $matches ) ) {
				return (int) $matches[1];
			}
		}

		return null;
	}

	/**
	 * Extracts explanation from analysis text.
	 *
	 * @since 1.8.0
	 *
	 * @param string $analysis_trim Trimmed analysis text.
	 * @return string Explanation text.
	 */
	protected function extract_explanation( $analysis_trim ) {
		$patterns = array(
			'/similarity_explanation\s*:\s*(.+?)(?:\n\s*(?:confusion_existing_plugins|confusion_existing_others)\s*:|\z)/is',
			'/[-*]\s*similarity_explanation\s*:\s*(.+?)(?:\n\s*[-*]|\z)/is',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $analysis_trim, $matches ) ) {
				$explanation = trim( preg_replace( '/\s+/', ' ', $matches[1] ) );
				$explanation = trim( $explanation, '"\'`' );
				if ( ! empty( $explanation ) ) {
					return $explanation;
				}
			}
		}

		// Try to extract first meaningful paragraph.
		$lines = explode( "\n", $analysis_trim );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( strlen( $line ) > 50 && ! preg_match( '/^(name_similarity|similarity_explanation|confusion_|[-*]\s*$)/i', $line ) ) {
				return $line;
			}
		}

		return __( 'See the full AI output below.', 'plugin-check' );
	}

	/**
	 * Builds verdict string from a percentage.
	 *
	 * @since 1.8.0
	 *
	 * @param int    $percentage  Confusion risk score.
	 * @param string $explanation Explanation.
	 * @return array{verdict:string,explanation:string}
	 */
	protected function verdict_from_percentage( $percentage, $explanation ) {
		if ( $percentage <= 20 ) {
			/* translators: %d: Confusion risk score (0-100). */
			$verdict = sprintf( __( 'Good (low confusion risk: %d/100)', 'plugin-check' ), $percentage );
		} elseif ( $percentage <= 50 ) {
			/* translators: %d: Confusion risk score (0-100). */
			$verdict = sprintf( __( 'Needs review (medium confusion risk: %d/100)', 'plugin-check' ), $percentage );
		} else {
			/* translators: %d: Confusion risk score (0-100). */
			$verdict = sprintf( __( 'Problematic (high confusion risk: %d/100)', 'plugin-check' ), $percentage );
		}

		return array(
			'verdict'     => $verdict,
			'explanation' => $explanation,
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
