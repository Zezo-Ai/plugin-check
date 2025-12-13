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
	 * @return string|WP_Error Analysis output or WP_Error.
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

			return $builder->generateText();
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
	 * @param string $analysis AI output.
	 * @return array{verdict:string,explanation:string}
	 */
	protected function parse_analysis( $analysis ) {
		$analysis_trim = trim( (string) $analysis );

		if ( empty( $analysis_trim ) ) {
			return array(
				'verdict'     => __( 'Unknown (empty response)', 'plugin-check' ),
				'explanation' => __( 'The AI did not return any analysis.', 'plugin-check' ),
			);
		}

		// Try JSON first (some models respond with JSON despite instructions).
		if ( '{' === $analysis_trim[0] || '[' === $analysis_trim[0] ) {
			$decoded = json_decode( $analysis_trim, true );
			if ( is_array( $decoded ) && isset( $decoded['name_similarity_percentage'] ) ) {
				$percentage  = (int) $decoded['name_similarity_percentage'];
				$explanation = isset( $decoded['similarity_explanation'] ) ? trim( (string) $decoded['similarity_explanation'] ) : '';

				if ( empty( $explanation ) ) {
					$explanation = __( 'See the full AI output below.', 'plugin-check' );
				}

				return $this->verdict_from_percentage( $percentage, $explanation );
			}
		}

		// Try to find percentage with various formats.
		$percentage = null;

		// Format: name_similarity_percentage: 50.
		if ( preg_match( '/name_similarity_percentage\s*:\s*(\d{1,3})/i', $analysis_trim, $matches ) ) {
			$percentage = (int) $matches[1];
		} elseif ( preg_match( '/[-*]\s*name_similarity_percentage\s*:\s*(\d{1,3})/i', $analysis_trim, $matches ) ) {
			// Format: - name_similarity_percentage: 50 (checklist format).
			$percentage = (int) $matches[1];
		} elseif ( preg_match( '/"name_similarity_percentage"\s*:\s*(\d{1,3})/i', $analysis_trim, $matches ) ) {
			// Format: "name_similarity_percentage": 50 (JSON-like).
			$percentage = (int) $matches[1];
		} elseif ( preg_match( '/(?:similarity|percentage)[\s:]+(\d{1,3})/i', $analysis_trim, $matches ) ) {
			// Format: Percentage: 50 or Similarity: 50.
			$percentage = (int) $matches[1];
		}

		// Try to find explanation.
		$explanation = '';
		if ( preg_match( '/similarity_explanation\s*:\s*(.+?)(?:\n\s*(?:confusion_existing_plugins|confusion_existing_others)\s*:|\z)/is', $analysis_trim, $matches ) ) {
			$explanation = trim( preg_replace( '/\s+/', ' ', $matches[1] ) );
			// Remove quotes if present.
			$explanation = trim( $explanation, '"\'`' );
		} elseif ( preg_match( '/[-*]\s*similarity_explanation\s*:\s*(.+?)(?:\n\s*[-*]|\z)/is', $analysis_trim, $matches ) ) {
			$explanation = trim( preg_replace( '/\s+/', ' ', $matches[1] ) );
			// Remove quotes if present.
			$explanation = trim( $explanation, '"\'`' );
		}

		if ( empty( $explanation ) ) {
			// Try to extract first meaningful paragraph.
			$lines = explode( "\n", $analysis_trim );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( strlen( $line ) > 50 && ! preg_match( '/^(name_similarity|similarity_explanation|confusion_|[-*]\s*$)/i', $line ) ) {
					$explanation = $line;
					break;
				}
			}

			if ( empty( $explanation ) ) {
				$explanation = __( 'See the full AI output below.', 'plugin-check' );
			}
		}

		if ( null === $percentage ) {
			return array(
				'verdict'     => __( 'Unknown (could not parse score)', 'plugin-check' ),
				'explanation' => $explanation,
			);
		}

		return $this->verdict_from_percentage( $percentage, $explanation );
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
