<?php
/**
 * Provider-agnostic AI client contract for AI Insights.
 *
 * @package WooCommerce\HeyWoo\Difm
 */

namespace WooCommerce\HeyWoo\Difm;

defined( 'ABSPATH' ) || exit;

/**
 * Normalised chat client used by the DIFM tool loop.
 *
 * Implementations return an Anthropic-shaped content array so the controller
 * can execute one provider-neutral tool loop:
 *
 * - text blocks: `array( 'type' => 'text', 'text' => '...' )`
 * - tool calls:  `array( 'type' => 'tool_use', 'id' => '...', 'name' => '...', 'input' => array() )`
 *
 * The response also includes provider, model, usage, and stop_reason metadata.
 */
interface DifmAiClientInterface {

	/**
	 * Send a conversational turn to the provider.
	 *
	 * @param array  $messages   Conversation messages.
	 * @param string $system     System prompt.
	 * @param array  $tools      Provider-neutral tool definitions.
	 * @param int    $max_tokens Maximum output tokens.
	 * @param array  $context    Safe diagnostic context.
	 * @return array|\WP_Error Normalised response, or WP_Error on failure.
	 */
	public function messages( array $messages, $system = '', array $tools = array(), $max_tokens = 4096, array $context = array() );
}
