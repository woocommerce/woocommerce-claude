<?php
/**
 * Hey Woo knowledge registry alias.
 *
 * @package WooCommerce\HeyWoo
 */

namespace WooCommerce\HeyWoo\Knowledge;

defined( 'ABSPATH' ) || exit;

class_alias( \WooCommerce\CommerceAbilities\Knowledge\KnowledgeRegistry::class, __NAMESPACE__ . '\KnowledgeRegistry' );
