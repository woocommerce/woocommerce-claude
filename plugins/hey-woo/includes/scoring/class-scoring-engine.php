<?php
/**
 * Hey Woo scoring engine wrapper.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Scoring;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility wrapper for the shared scoring engine.
 */
class ScoringEngine extends \WooCommerce\CommerceAbilities\Scoring\ScoringEngine {

	/**
	 * Build the scoring engine with Hey Woo's compatibility hook scope.
	 */
	public function __construct() {
		parent::__construct( 'hey-woo' );
	}
}
