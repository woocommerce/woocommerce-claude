<?php
/**
 * Boot module asset shim.
 *
 * @wordpress/boot is provided by Gutenberg/Core at runtime. The generated
 * page.php still looks for this file to resolve classic script prerequisites.
 * This shim supplies those dependencies so the template works correctly.
 *
 * @package WooCommerce\HeyWoo
 */

return array(
	'dependencies' => array(
		'react',
		'react-dom',
		'react-jsx-runtime',
		'wp-commands',
		'wp-components',
		'wp-compose',
		'wp-core-data',
		'wp-data',
		'wp-editor',
		'wp-element',
		'wp-html-entities',
		'wp-i18n',
		'wp-keyboard-shortcuts',
		'wp-keycodes',
		'wp-notices',
		'wp-primitives',
		'wp-private-apis',
		'wp-theme',
		'wp-url',
	),
	'version'      => '0.1.0',
);
