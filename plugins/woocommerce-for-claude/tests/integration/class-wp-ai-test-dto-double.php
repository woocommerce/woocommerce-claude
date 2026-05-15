<?php
/**
 * Test double for WordPress AI Client DTOs used by provider resolver tests.
 *
 * @package WooCommerce\Claude\Tests
 */

/**
 * Compact DTO double that can be aliased to the WP AI Client history classes.
 */
class WooCommerce_Claude_Test_Wp_Ai_Dto_Double {

	const TYPE_FUNCTION_CALL     = 'function_call';
	const TYPE_FUNCTION_RESPONSE = 'function_response';
	const TYPE_MESSAGE_PART      = 'message_part';
	const TYPE_MESSAGE           = 'message';

	/**
	 * DTO type.
	 *
	 * @var string
	 */
	private $type = self::TYPE_MESSAGE_PART;

	/**
	 * Function call ID.
	 *
	 * @var string|null
	 */
	private $id;

	/**
	 * Function arguments or response body.
	 *
	 * @var mixed
	 */
	private $payload;

	/**
	 * Wrapped message part value.
	 *
	 * @var mixed
	 */
	private $value;

	/**
	 * Message parts.
	 *
	 * @var array
	 */
	private $parts = array();

	/**
	 * Constructor.
	 *
	 * @param mixed $first  First DTO argument.
	 * @param mixed $second Second DTO argument.
	 * @param mixed $third  Third DTO argument.
	 */
	public function __construct( $first = null, $second = null, $third = null ) {
		$argument_count = func_num_args();

		if ( 3 <= $argument_count && is_string( $second ) ) {
			$this->type    = self::TYPE_FUNCTION_CALL;
			$this->id      = $first;
			$this->payload = $third;
			return;
		}

		if ( 3 <= $argument_count ) {
			$this->type    = self::TYPE_FUNCTION_RESPONSE;
			$this->id      = $first;
			$this->payload = $third;
			return;
		}

		if ( is_array( $first ) ) {
			$this->type  = self::TYPE_MESSAGE;
			$this->parts = $first;
			return;
		}

		$this->value = $first;
	}

	/**
	 * Return function arguments.
	 *
	 * @return mixed
	 */
	public function getArgs() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->payload;
	}

	/**
	 * Return function call ID.
	 *
	 * @return string|null
	 */
	public function getId() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->id;
	}

	/**
	 * Return function response.
	 *
	 * @return mixed
	 */
	public function getResponse() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->payload;
	}

	/**
	 * Return the function call when this part wraps one.
	 *
	 * @return self|null
	 */
	public function getFunctionCall() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->value instanceof self && self::TYPE_FUNCTION_CALL === $this->value->type ? $this->value : null;
	}

	/**
	 * Return the function response when this part wraps one.
	 *
	 * @return self|null
	 */
	public function getFunctionResponse() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->value instanceof self && self::TYPE_FUNCTION_RESPONSE === $this->value->type ? $this->value : null;
	}

	/**
	 * Return message parts.
	 *
	 * @return array
	 */
	public function getParts() { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
		return $this->parts;
	}
}
