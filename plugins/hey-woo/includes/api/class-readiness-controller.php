<?php
/**
 * REST API controller for AI readiness scoring.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\API;

defined( 'ABSPATH' ) || exit;

/**
 * Hey Woo REST wrapper for shared readiness routes.
 */
class ReadinessController extends \WooCommerce\CommerceAbilities\API\AbstractReadinessController {
	const NAMESPACE = 'hey-woo/v1';
	const CONSUMER  = 'hey-woo';
}
