<?php
/**
 * Backwards-compatible knowledge registry alias.
 *
 * @package WooCommerce\Claude
 */

namespace WooCommerce\Claude\Knowledge;

defined( 'ABSPATH' ) || exit;

class_alias( \WooCommerce\CommerceAbilities\Knowledge\KnowledgeRegistry::class, __NAMESPACE__ . '\KnowledgeRegistry' );
