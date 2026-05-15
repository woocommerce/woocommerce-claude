<?php
/**
 * WordPress AI Client adapter for WooCommerce for Claude AI Insights.
 *
 * @package WooCommerce\Claude\Difm
 */

namespace WooCommerce\Claude\Difm;

use WooCommerce\Claude\Telemetry\DifmAiTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Adapter around `wp_ai_client_prompt()` when WordPress AI/Core AI Client exists.
 */
class WordPressAiClientAdapter implements DifmAiClientInterface {

	/**
	 * Provider label for telemetry and response metadata.
	 */
	const PROVIDER = 'wordpress_ai';

	/**
	 * Whether the WordPress AI Client primitives needed by AI Insights exist.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		$is_supported = function_exists( 'wp_ai_client_prompt' )
			&& class_exists( 'WordPress\\AiClient\\Tools\\DTO\\FunctionDeclaration' )
			&& class_exists( 'WordPress\\AiClient\\Tools\\DTO\\FunctionResponse' );

		/**
		 * Filter whether WordPress AI/Core AI Client support is available.
		 *
		 * @since 0.5.0
		 *
		 * @param bool $is_supported Whether required WordPress AI Client primitives exist.
		 */
		return (bool) apply_filters( 'woocommerce_claude_difm_wordpress_ai_supported', $is_supported );
	}

	/**
	 * Whether WordPress AI appears to have usable AI credentials.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		if ( ! self::is_supported() ) {
			return false;
		}

		/**
		 * Filter whether WordPress AI has usable provider credentials.
		 *
		 * Return null to use the default WordPress AI helper/connector checks.
		 *
		 * @since 0.5.0
		 *
		 * @param bool|null $has_credentials Test/integration override.
		 */
		$filtered_credentials = apply_filters( 'woocommerce_claude_difm_wordpress_ai_has_credentials', null );
		if ( null !== $filtered_credentials ) {
			return (bool) $filtered_credentials;
		}

		$has_valid_ai_credentials = 'WordPress\\AI\\has_valid_ai_credentials';
		if ( function_exists( $has_valid_ai_credentials ) ) {
			try {
				return (bool) $has_valid_ai_credentials();
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		$has_ai_credentials = 'WordPress\\AI\\has_ai_credentials';
		if ( function_exists( $has_ai_credentials ) ) {
			try {
				return (bool) $has_ai_credentials();
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		return self::has_connector_setting_key();
	}

	/**
	 * Return a human-readable connector status.
	 *
	 * @return string supported_configured|supported_unconfigured|unavailable
	 */
	public static function get_status() {
		if ( ! self::is_supported() ) {
			return 'unavailable';
		}

		return self::has_api_key() ? 'supported_configured' : 'supported_unconfigured';
	}

	/**
	 * Call WordPress AI/Core AI Client.
	 *
	 * @param array  $messages   Conversation messages.
	 * @param string $system     System prompt.
	 * @param array  $tools      Provider-neutral tool definitions.
	 * @param int    $max_tokens Maximum output tokens.
	 * @param array  $context    Optional diagnostic context for logging.
	 * @return array|\WP_Error Normalised response array, or WP_Error on failure.
	 */
	public function messages( array $messages, $system = '', array $tools = array(), $max_tokens = 4096, array $context = array() ) {
		if ( ! self::is_supported() ) {
			return new \WP_Error(
				'wordpress_ai_unavailable',
				__( 'WordPress AI is not available on this site.', 'woocommerce-claude' )
			);
		}

		if ( ! self::has_api_key() ) {
			return new \WP_Error(
				'no_api_key',
				__( 'No WordPress AI provider connector is configured.', 'woocommerce-claude' )
			);
		}

		$prompt     = $this->extract_current_prompt( $messages );
		$body       = $this->build_telemetry_body( $messages, $tools, $prompt );
		$body_json  = wp_json_encode( $body );
		$body_json  = is_string( $body_json ) ? $body_json : '';
		$request_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'difm_', true );
		$start_ms   = microtime( true );

		DifmAiTelemetry::record_request( self::PROVIDER, '', $body, $body_json, $request_id, $context );

		try {
			$builder = wp_ai_client_prompt( $prompt );
			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			if ( '' !== $system && method_exists( $builder, 'using_system_instruction' ) ) {
				$builder = $builder->using_system_instruction( $system );
			}

			if ( method_exists( $builder, 'using_max_tokens' ) ) {
				$builder = $builder->using_max_tokens( $max_tokens );
			}

			$declarations = $this->build_function_declarations( $tools );
			if ( ! empty( $declarations ) && method_exists( $builder, 'using_function_declarations' ) ) {
				$builder = $builder->using_function_declarations( $declarations );
			}

			foreach ( $this->extract_function_responses( $messages ) as $function_response ) {
				if ( method_exists( $builder, 'with_function_response' ) ) {
					$builder = $builder->with_function_response( $function_response );
				}
			}

			$history = $this->build_history( $messages );
			if ( ! empty( $history ) && method_exists( $builder, 'with_history' ) ) {
				$builder = $builder->with_history( $history );
			}

			$result = $builder->generate_text_result();
		} catch ( \Throwable $e ) {
			DifmAiTelemetry::record_transport_error(
				self::PROVIDER,
				'',
				$request_id,
				(int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				'wordpress_ai_exception',
				$context
			);
			return new \WP_Error( 'wordpress_ai_error', $e->getMessage() );
		}

		$duration_ms = (int) round( ( microtime( true ) - $start_ms ) * 1000 );

		if ( is_wp_error( $result ) ) {
			DifmAiTelemetry::record_response(
				self::PROVIDER,
				'',
				$request_id,
				0,
				$duration_ms,
				array( 'error' => array( 'type' => $result->get_error_code() ) )
			);
			return $result;
		}

		$normalised = $this->normalise_result( $result );
		DifmAiTelemetry::record_response(
			self::PROVIDER,
			isset( $normalised['model'] ) ? (string) $normalised['model'] : '',
			$request_id,
			200,
			$duration_ms,
			$normalised
		);

		return $normalised;
	}

	/**
	 * Check default connector API key settings for a lightweight fallback.
	 *
	 * @return bool
	 */
	private static function has_connector_setting_key() {
		foreach ( array( 'connectors_ai_openai_api_key', 'connectors_ai_anthropic_api_key', 'connectors_ai_google_api_key' ) as $option ) {
			if ( '' !== (string) get_option( $option, '' ) ) {
				return true;
			}
		}

		foreach ( array( 'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GOOGLE_API_KEY' ) as $constant ) {
			if ( defined( $constant ) && '' !== (string) constant( $constant ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract the last user prompt text from controller messages.
	 *
	 * @param array $messages Conversation messages.
	 * @return string
	 */
	private function extract_current_prompt( array $messages ) {
		for ( $i = count( $messages ) - 1; $i >= 0; --$i ) {
			$message = $messages[ $i ];
			if ( ! is_array( $message ) || ! isset( $message['role'] ) || 'user' !== $message['role'] ) {
				continue;
			}

			$content = isset( $message['content'] ) ? $message['content'] : '';
			if ( is_string( $content ) ) {
				return $content;
			}
		}

		return '';
	}

	/**
	 * Build a redacted body only for telemetry.
	 *
	 * @param array  $messages Conversation messages.
	 * @param array  $tools    Tool definitions.
	 * @param string $prompt   Current prompt.
	 * @return array
	 */
	private function build_telemetry_body( array $messages, array $tools, $prompt ) {
		return array(
			'provider' => self::PROVIDER,
			'prompt'   => '' === $prompt ? '' : '[redacted]',
			'messages' => array_fill( 0, count( $messages ), '[redacted]' ),
			'tools'    => $tools,
		);
	}

	/**
	 * Build FunctionDeclaration DTOs.
	 *
	 * @param array $tools Tool definitions.
	 * @return array
	 */
	private function build_function_declarations( array $tools ) {
		$class = 'WordPress\\AiClient\\Tools\\DTO\\FunctionDeclaration';
		if ( ! class_exists( $class ) ) {
			return array();
		}

		$declarations = array();
		foreach ( $tools as $tool ) {
			if ( ! is_array( $tool ) || empty( $tool['name'] ) ) {
				continue;
			}

			$schema = isset( $tool['input_schema'] ) && is_array( $tool['input_schema'] )
				? $tool['input_schema']
				: array(
					'type'       => 'object',
					'properties' => (object) array(),
				);

			try {
				$declarations[] = new $class(
					(string) $tool['name'],
					isset( $tool['description'] ) ? (string) $tool['description'] : '',
					$schema
				);
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $declarations;
	}

	/**
	 * Extract FunctionResponse DTOs from tool result turns.
	 *
	 * @param array $messages Conversation messages.
	 * @return array
	 */
	private function extract_function_responses( array $messages ) {
		$class = 'WordPress\\AiClient\\Tools\\DTO\\FunctionResponse';
		if ( ! class_exists( $class ) ) {
			return array();
		}

		$responses = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['content'] ) || ! is_array( $message['content'] ) ) {
				continue;
			}

			foreach ( $message['content'] as $block ) {
				if ( ! is_array( $block ) || ! isset( $block['type'] ) || 'tool_result' !== $block['type'] ) {
					continue;
				}

				$response_body = isset( $block['content'] ) ? json_decode( (string) $block['content'], true ) : array();
				if ( ! is_array( $response_body ) ) {
					$response_body = array( 'result' => isset( $block['content'] ) ? (string) $block['content'] : '' );
				}

				try {
					$responses[] = new $class(
						isset( $block['tool_use_id'] ) ? (string) $block['tool_use_id'] : '',
						'',
						$response_body
					);
				} catch ( \Throwable $e ) {
					continue;
				}
			}
		}

		return $responses;
	}

	/**
	 * Build history DTOs when the local AI Client exposes message classes.
	 *
	 * @param array $messages Conversation messages.
	 * @return array
	 */
	private function build_history( array $messages ) {
		$message_part_class  = $this->first_existing_class(
			array(
				'WordPress\\AiClient\\Messages\\DTO\\MessagePart',
				'WordPress\\AiClient\\Common\\DTO\\MessagePart',
			)
		);
		$user_message_class  = $this->first_existing_class(
			array(
				'WordPress\\AiClient\\Messages\\DTO\\UserMessage',
				'WordPress\\AiClient\\Common\\DTO\\UserMessage',
			)
		);
		$model_message_class = $this->first_existing_class(
			array(
				'WordPress\\AiClient\\Messages\\DTO\\ModelMessage',
				'WordPress\\AiClient\\Common\\DTO\\ModelMessage',
			)
		);
		$function_call_class = 'WordPress\\AiClient\\Tools\\DTO\\FunctionCall';

		if ( '' === $message_part_class || '' === $user_message_class || '' === $model_message_class ) {
			return array();
		}

		$history_messages = array_slice( $messages, 0, max( 0, count( $messages ) - 1 ) );
		$history          = array();

		foreach ( $history_messages as $message ) {
			if ( ! is_array( $message ) || empty( $message['role'] ) || ! isset( $message['content'] ) ) {
				continue;
			}

			try {
				$parts = $this->message_parts_for_history( $message['content'], $message_part_class, $function_call_class );
				if ( empty( $parts ) ) {
					continue;
				}

				$history[] = 'assistant' === $message['role']
					? new $model_message_class( $parts )
					: new $user_message_class( $parts );
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $history;
	}

	/**
	 * Convert one message content field to MessagePart DTOs.
	 *
	 * @param mixed  $content             Message content.
	 * @param string $message_part_class  MessagePart class name.
	 * @param string $function_call_class FunctionCall class name.
	 * @return array
	 */
	private function message_parts_for_history( $content, $message_part_class, $function_call_class ) {
		if ( is_string( $content ) && '' !== $content ) {
			return array( new $message_part_class( $content ) );
		}

		if ( ! is_array( $content ) ) {
			return array();
		}

		$parts = array();
		foreach ( $content as $block ) {
			if ( ! is_array( $block ) || empty( $block['type'] ) ) {
				continue;
			}

			if ( 'text' === $block['type'] && isset( $block['text'] ) ) {
				$parts[] = new $message_part_class( (string) $block['text'] );
			}

			if ( 'tool_use' === $block['type'] && class_exists( $function_call_class ) ) {
				$input   = isset( $block['input'] ) && is_array( $block['input'] ) ? $block['input'] : array();
				$parts[] = new $message_part_class(
					new $function_call_class(
						isset( $block['id'] ) ? (string) $block['id'] : '',
						isset( $block['name'] ) ? (string) $block['name'] : '',
						$input
					)
				);
			}
		}

		return $parts;
	}

	/**
	 * Return the first available class name from a candidate list.
	 *
	 * @param array $candidates Candidate class names.
	 * @return string
	 */
	private function first_existing_class( array $candidates ) {
		foreach ( $candidates as $candidate ) {
			if ( class_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * Normalise a WordPress AI Client result into the controller contract.
	 *
	 * @param object $result AI Client result object.
	 * @return array
	 */
	private function normalise_result( $result ) {
		$content = $this->extract_result_content( $result );
		$usage   = $this->extract_result_usage( $result );
		$model   = $this->extract_result_model( $result );

		return array(
			'type'        => 'message',
			'content'     => $content,
			'stop_reason' => $this->content_has_tool_use( $content ) ? 'tool_use' : 'end_turn',
			'usage'       => $usage,
			'provider'    => self::PROVIDER,
			'model'       => $model,
		);
	}

	/**
	 * Extract normalised content blocks from an AI Client result.
	 *
	 * @param object $result AI Client result object.
	 * @return array
	 */
	private function extract_result_content( $result ) {
		$content = array();

		if ( is_object( $result ) && method_exists( $result, 'getCandidates' ) ) {
			$candidates = $result->getCandidates();
			if ( is_array( $candidates ) && ! empty( $candidates ) ) {
				$content = $this->extract_candidate_content( reset( $candidates ) );
			}
		}

		if ( empty( $content ) && is_object( $result ) && method_exists( $result, 'toText' ) ) {
			$text = (string) $result->toText();
			if ( '' !== $text ) {
				$content[] = array(
					'type' => 'text',
					'text' => $text,
				);
			}
		}

		return $content;
	}

	/**
	 * Extract content blocks from a candidate object.
	 *
	 * @param object $candidate AI Client candidate.
	 * @return array
	 */
	private function extract_candidate_content( $candidate ) {
		$content = array();
		if ( ! is_object( $candidate ) || ! method_exists( $candidate, 'getMessage' ) ) {
			return $content;
		}

		$message = $candidate->getMessage();
		if ( ! is_object( $message ) || ! method_exists( $message, 'getParts' ) ) {
			return $content;
		}

		$parts = $message->getParts();
		if ( ! is_array( $parts ) ) {
			return $content;
		}

		foreach ( $parts as $part ) {
			if ( ! is_object( $part ) ) {
				continue;
			}

			if ( method_exists( $part, 'getText' ) ) {
				$text = (string) $part->getText();
				if ( '' !== $text ) {
					$content[] = array(
						'type' => 'text',
						'text' => $text,
					);
				}
			}

			$function_call = method_exists( $part, 'getFunctionCall' ) ? $part->getFunctionCall() : null;
			if ( is_object( $function_call ) ) {
				$content[] = $this->normalise_function_call( $function_call );
			}
		}

		return $content;
	}

	/**
	 * Normalise a FunctionCall DTO.
	 *
	 * @param object $function_call FunctionCall object.
	 * @return array
	 */
	private function normalise_function_call( $function_call ) {
		$id   = method_exists( $function_call, 'getId' ) ? (string) $function_call->getId() : '';
		$name = method_exists( $function_call, 'getName' ) ? (string) $function_call->getName() : '';
		$args = method_exists( $function_call, 'getArgs' ) ? $function_call->getArgs() : array();

		return array(
			'type'  => 'tool_use',
			'id'    => $id,
			'name'  => $name,
			'input' => is_array( $args ) ? $args : array(),
		);
	}

	/**
	 * Whether content includes a tool call.
	 *
	 * @param array $content Normalised content blocks.
	 * @return bool
	 */
	private function content_has_tool_use( array $content ) {
		foreach ( $content as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'tool_use' === $block['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Extract token usage from an AI Client result.
	 *
	 * @param object $result AI Client result object.
	 * @return array
	 */
	private function extract_result_usage( $result ) {
		if ( ! is_object( $result ) || ! method_exists( $result, 'getTokenUsage' ) ) {
			return array();
		}

		$usage = $result->getTokenUsage();
		if ( ! is_object( $usage ) ) {
			return array();
		}

		$fields = array(
			'prompt_tokens'     => 'getPromptTokens',
			'completion_tokens' => 'getCompletionTokens',
			'total_tokens'      => 'getTotalTokens',
			'thought_tokens'    => 'getThoughtTokens',
		);

		$data = array();
		foreach ( $fields as $key => $method ) {
			if ( method_exists( $usage, $method ) ) {
				$value = $usage->$method();
				if ( is_numeric( $value ) ) {
					$data[ $key ] = (int) $value;
				}
			}
		}

		return $data;
	}

	/**
	 * Extract model metadata from an AI Client result.
	 *
	 * @param object $result AI Client result object.
	 * @return string
	 */
	private function extract_result_model( $result ) {
		if ( is_object( $result ) && method_exists( $result, 'getModelMetadata' ) ) {
			$model = $result->getModelMetadata();
			if ( is_object( $model ) && method_exists( $model, 'getId' ) ) {
				return (string) $model->getId();
			}
		}

		return '';
	}
}
