/**
 * webpack configuration for the Hey Woo DIFM "Today" page.
 *
 * Extends the default @wordpress/scripts config and points the entry at the
 * Today package source, with output landing in build/today/ (the same path
 * DifmAdminPage::do_enqueue() references).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		index: path.resolve( __dirname, 'packages/today/src/index.tsx' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build/today' ),
	},
};
