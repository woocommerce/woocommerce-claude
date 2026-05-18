<?php
/**
 * Backwards-compatible knowledge provider interface.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Knowledge;

defined( 'ABSPATH' ) || exit;

/**
 * Compatibility interface for extensions using the original namespace.
 */
interface KnowledgeProvider extends \WooCommerce\CommerceAbilities\Knowledge\KnowledgeProvider {}
