<?php
/**
 * WordPress AI Client adapter for Hey Woo.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

use WooCommerce\HeyWoo\Telemetry\DifmAiTelemetry;

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
	 * HTTP request timeout in seconds for WordPress AI connector calls.
	 */
	const REQUEST_TIMEOUT = 90;

	/**
	 * Selected native WordPress AI provider ID.
	 *
	 * @var string
	 */
	private $provider_id = '';

	/**
	 * Constructor.
	 *
	 * @param string $provider_id Native WordPress AI provider ID.
	 */
	public function __construct( $provider_id = '' ) {
		$this->provider_id = is_string( $provider_id ) ? sanitize_key( $provider_id ) : '';
	}

	/**
	 * Return the selected native WordPress AI provider ID.
	 *
	 * @return string
	 */
	public function get_provider_id() {
		return $this->provider_id;
	}

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
		return (bool) apply_filters( 'hey_woo_difm_wordpress_ai_supported', $is_supported );
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
		$filtered_credentials = apply_filters( 'hey_woo_difm_wordpress_ai_has_credentials', null );
		if ( null !== $filtered_credentials ) {
			return (bool) $filtered_credentials;
		}

		return ! empty( self::get_configured_provider_ids() );
	}

	/**
	 * Return configured WordPress AI provider IDs keyed to labels.
	 *
	 * @return array<string,string>
	 */
	public static function get_configured_provider_options() {
		$options = array();

		foreach ( self::get_configured_provider_ids() as $provider_id ) {
			$options[ $provider_id ] = self::get_provider_label( $provider_id );
		}

		return $options;
	}

	/**
	 * Return configured WordPress AI provider IDs from the native registry.
	 *
	 * @return array<int,string>
	 */
	public static function get_configured_provider_ids() {
		$provider_ids = array();

		if ( self::is_supported() ) {
			$registry = self::get_ai_registry();
			if (
				is_object( $registry )
				&& method_exists( $registry, 'getRegisteredProviderIds' )
				&& method_exists( $registry, 'hasProvider' )
				&& method_exists( $registry, 'isProviderConfigured' )
			) {
				try {
					foreach ( (array) $registry->getRegisteredProviderIds() as $provider_id ) {
						if ( ! is_string( $provider_id ) || '' === $provider_id ) {
							continue;
						}

						if ( ! self::is_ai_provider_connector( $provider_id ) ) {
							continue;
						}

						if ( ! $registry->hasProvider( $provider_id ) ) {
							continue;
						}

						if ( ! $registry->isProviderConfigured( $provider_id ) ) {
							continue;
						}

						$provider_ids[] = $provider_id;
					}
				} catch ( \Throwable $e ) {
					$provider_ids = array();
				}
			}
		}

		/**
		 * Filter configured WordPress AI provider IDs for tests and integrations.
		 *
		 * @since 0.5.0
		 *
		 * @param array<int,string> $provider_ids Configured provider IDs.
		 */
		$provider_ids = apply_filters( 'hey_woo_difm_wordpress_ai_configured_provider_ids', $provider_ids );

		return self::normalise_provider_ids( $provider_ids );
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
	 * Return the preferred configured WordPress AI provider ID.
	 *
	 * @return string
	 */
	public static function get_default_provider_id() {
		return self::get_preferred_provider_id();
	}

	/**
	 * Return a human-readable label for a native provider ID.
	 *
	 * @param string $provider_id Native WordPress AI provider ID.
	 * @return string
	 */
	public static function get_provider_label( $provider_id ) {
		return DifmProviderEnvironment::get_connector_label( $provider_id );
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
				__( 'WordPress AI is not available on this site.', 'hey-woo' )
			);
		}

		if ( ! self::has_api_key() ) {
			return new \WP_Error(
				'no_api_key',
				__( 'No WordPress AI provider connector is configured.', 'hey-woo' )
			);
		}

		$prompt      = $this->extract_current_prompt( $messages );
		$body        = $this->build_telemetry_body( $messages, $tools, $prompt );
		$body_json   = wp_json_encode( $body );
		$body_json   = is_string( $body_json ) ? $body_json : '';
		$request_id  = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'difm_', true );
		$start_ms    = microtime( true );
		$provider_id = '' !== $this->provider_id ? $this->provider_id : self::get_preferred_provider_id();

		DifmAiTelemetry::record_request( self::PROVIDER, '', $body, $body_json, $request_id, $context );

		try {
			$builder = wp_ai_client_prompt( $prompt );
			if ( is_wp_error( $builder ) ) {
				return $builder;
			}

			if ( '' !== $provider_id && $this->is_builder_method_callable( $builder, 'using_provider' ) ) {
				$builder = $builder->using_provider( $provider_id );
			}

			if ( '' !== $system && $this->is_builder_method_callable( $builder, 'using_system_instruction' ) ) {
				$builder = $builder->using_system_instruction( $system );
			}

			if ( $this->is_builder_method_callable( $builder, 'using_max_tokens' ) ) {
				$builder = $builder->using_max_tokens( $max_tokens );
			}

			$builder = $this->apply_request_timeout( $builder );

			$declarations = $this->build_function_declarations( $tools );
			if ( ! empty( $declarations ) && $this->is_builder_method_callable( $builder, 'using_function_declarations' ) ) {
				$builder = $builder->using_function_declarations( ...$declarations );
			}

			$history = $this->build_history( $messages );
			if ( ! empty( $history ) && $this->is_builder_method_callable( $builder, 'with_history' ) ) {
				$builder = $builder->with_history( ...$history );
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
	 * Return the WordPress AI Client default registry when available.
	 *
	 * @return object|null
	 */
	private static function get_ai_registry() {
		$class = 'WordPress\\AiClient\\AiClient';
		if ( ! class_exists( $class ) || ! method_exists( $class, 'defaultRegistry' ) ) {
			return null;
		}

		try {
			return $class::defaultRegistry();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Whether a provider ID belongs to a native WordPress AI provider connector.
	 *
	 * @param string $provider_id Provider ID.
	 * @return bool
	 */
	private static function is_ai_provider_connector( $provider_id ) {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return true;
		}

		$connectors = wp_get_connectors();
		if ( empty( $connectors ) || ! isset( $connectors[ $provider_id ] ) ) {
			return true;
		}

		return isset( $connectors[ $provider_id ]['type'] ) && 'ai_provider' === $connectors[ $provider_id ]['type'];
	}

	/**
	 * Return the preferred configured WordPress AI provider ID.
	 *
	 * @return string
	 */
	private static function get_preferred_provider_id() {
		$configured_ids = self::get_configured_provider_ids();
		if ( empty( $configured_ids ) ) {
			return '';
		}

		$preference = array( 'anthropic', 'openai', 'google' );

		/**
		 * Filter the WordPress AI provider preference order for AI Insights.
		 *
		 * @since 0.5.0
		 *
		 * @param array<int,string> $preference     Provider IDs in priority order.
		 * @param array<int,string> $configured_ids Configured provider IDs.
		 */
		$preference = apply_filters( 'hey_woo_difm_wordpress_ai_provider_preference', $preference, $configured_ids );
		$preference = self::normalise_provider_ids( $preference );

		foreach ( $preference as $provider_id ) {
			if ( in_array( $provider_id, $configured_ids, true ) ) {
				return $provider_id;
			}
		}

		return (string) reset( $configured_ids );
	}

	/**
	 * Normalise a provider ID list.
	 *
	 * @param mixed $provider_ids Provider IDs.
	 * @return array<int,string>
	 */
	private static function normalise_provider_ids( $provider_ids ) {
		if ( ! is_array( $provider_ids ) ) {
			return array();
		}

		$normalised = array();
		foreach ( $provider_ids as $provider_id ) {
			if ( ! is_string( $provider_id ) ) {
				continue;
			}

			$provider_id = sanitize_key( $provider_id );
			if ( '' !== $provider_id && ! in_array( $provider_id, $normalised, true ) ) {
				$normalised[] = $provider_id;
			}
		}

		return $normalised;
	}

	/**
	 * Whether the WP AI builder can call a fluent method.
	 *
	 * @param object $builder Builder object.
	 * @param string $method  Snake_case method name.
	 * @return bool
	 */
	private function is_builder_method_callable( $builder, $method ) {
		return is_object( $builder ) && is_callable( array( $builder, $method ) );
	}

	/**
	 * Apply the plugin's chat timeout to native connector requests when supported.
	 *
	 * @param object $builder Builder object.
	 * @return object
	 */
	private function apply_request_timeout( $builder ) {
		if ( ! $this->is_builder_method_callable( $builder, 'using_request_options' ) ) {
			return $builder;
		}

		$request_options_class = 'WordPress\\AiClient\\Providers\\Http\\DTO\\RequestOptions';
		if ( ! class_exists( $request_options_class ) || ! method_exists( $request_options_class, 'fromArray' ) ) {
			return $builder;
		}

		/**
		 * Filter the WordPress AI connector request timeout used by Ask AI.
		 *
		 * @since 0.5.0
		 *
		 * @param float $timeout Timeout in seconds.
		 */
		$timeout = apply_filters( 'hey_woo_difm_wordpress_ai_request_timeout', self::REQUEST_TIMEOUT );
		if ( ! is_numeric( $timeout ) || (float) $timeout < 0 ) {
			$timeout = self::REQUEST_TIMEOUT;
		}

		$timeout_key_constant = $request_options_class . '::KEY_TIMEOUT';
		$timeout_key          = defined( $timeout_key_constant )
			? constant( $timeout_key_constant )
			: 'timeout';

		try {
			return $builder->using_request_options(
				$request_options_class::fromArray(
					array(
						$timeout_key => (float) $timeout,
					)
				)
			);
		} catch ( \Throwable $e ) {
			return $builder;
		}
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

			if ( $this->content_contains_tool_result( $content ) ) {
				// Mid-loop continuation: the real user prompt is already in history.
				return __( 'Continue using the tool results.', 'hey-woo' );
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
	 * Build history DTOs when the local AI Client exposes message classes.
	 *
	 * @param array $messages Conversation messages.
	 * @return array
	 */
	private function build_history( array $messages ) {
		$message_part_class      = $this->first_existing_class(
			array(
				'WordPress\\AiClient\\Messages\\DTO\\MessagePart',
				'WordPress\\AiClient\\Common\\DTO\\MessagePart',
			)
		);
		$user_message_class      = $this->first_existing_class(
			array(
				'WordPress\\AiClient\\Messages\\DTO\\UserMessage',
				'WordPress\\AiClient\\Common\\DTO\\UserMessage',
			)
		);
		$model_message_class     = $this->first_existing_class(
			array(
				'WordPress\\AiClient\\Messages\\DTO\\ModelMessage',
				'WordPress\\AiClient\\Common\\DTO\\ModelMessage',
			)
		);
		$function_call_class     = 'WordPress\\AiClient\\Tools\\DTO\\FunctionCall';
		$function_response_class = 'WordPress\\AiClient\\Tools\\DTO\\FunctionResponse';

		if ( '' === $message_part_class || '' === $user_message_class || '' === $model_message_class ) {
			return array();
		}

		$history_messages = $this->last_message_is_current_text_prompt( $messages )
			? array_slice( $messages, 0, max( 0, count( $messages ) - 1 ) )
			: $messages;
		$history          = array();

		foreach ( $history_messages as $message ) {
			if ( ! is_array( $message ) || empty( $message['role'] ) || ! isset( $message['content'] ) ) {
				continue;
			}

			try {
				$parts = $this->message_parts_for_history( $message['content'], $message_part_class, $function_call_class, $function_response_class );
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
	 * @param string $function_response_class FunctionResponse class name.
	 * @return array
	 */
	private function message_parts_for_history( $content, $message_part_class, $function_call_class, $function_response_class ) {
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
				$input   = isset( $block['input'] ) ? $this->normalise_function_call_args_for_history( $block['input'] ) : null;
				$parts[] = new $message_part_class(
					new $function_call_class(
						isset( $block['id'] ) ? (string) $block['id'] : '',
						isset( $block['name'] ) ? (string) $block['name'] : '',
						$input
					)
				);
			}

			if ( 'tool_result' === $block['type'] && class_exists( $function_response_class ) ) {
				$parts[] = new $message_part_class(
					new $function_response_class(
						isset( $block['tool_use_id'] ) ? (string) $block['tool_use_id'] : '',
						null,
						$this->normalise_function_response_body_for_history( $block )
					)
				);
			}
		}

		return $parts;
	}

	/**
	 * Whether the final message is the current text prompt, not history.
	 *
	 * @param array $messages Conversation messages.
	 * @return bool
	 */
	private function last_message_is_current_text_prompt( array $messages ) {
		if ( empty( $messages ) ) {
			return false;
		}

		$message = end( $messages );
		return is_array( $message )
			&& isset( $message['role'], $message['content'] )
			&& 'user' === $message['role']
			&& is_string( $message['content'] );
	}

	/**
	 * Whether a content array contains tool results.
	 *
	 * @param mixed $content Message content.
	 * @return bool
	 */
	private function content_contains_tool_result( $content ) {
		if ( ! is_array( $content ) ) {
			return false;
		}

		foreach ( $content as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'tool_result' === $block['type'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decode a tool result block for FunctionResponse history replay.
	 *
	 * @param array $block Tool result block.
	 * @return mixed
	 */
	private function normalise_function_response_body_for_history( array $block ) {
		$content = isset( $block['content'] ) ? (string) $block['content'] : '';
		$decoded = '' !== $content ? json_decode( $content, true ) : null;

		return null === $decoded ? array( 'result' => $content ) : $decoded;
	}

	/**
	 * Normalise tool-call args before replaying history through WP AI Client.
	 *
	 * The native Anthropic connector converts null args to an empty JSON object.
	 * Passing an empty PHP array would encode as [] and Anthropic rejects that.
	 *
	 * @param mixed $input Tool-call input.
	 * @return mixed
	 */
	private function normalise_function_call_args_for_history( $input ) {
		if ( is_object( $input ) ) {
			return $input;
		}

		if ( ! is_array( $input ) || empty( $input ) ) {
			return null;
		}

		return $input;
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
