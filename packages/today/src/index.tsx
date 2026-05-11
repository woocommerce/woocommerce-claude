/**
 * Entry point for the WooCommerce for Claude AI Insights page.
 *
 * @wordpress/scripts compiles this into build/today/index.js, which is
 * enqueued by DifmAdminPage::do_enqueue() as a classic WordPress script.
 * Page-load data is passed from PHP via wp_localize_script() and accessed
 * through the typed singleton exported from ./data.
 *
 * WooCommerce creates #woocommerce-claude-insights-app for its submenu pages (pattern:
 * ${slug}-app). We mount into that element first, falling back to
 * #woocommerce-claude-insights-root for standard WordPress admin rendering.
 *
 * Styles are compiled from src/style.scss by webpack into
 * build/today/style-index.css and enqueued separately by do_enqueue().
 */
import { createRoot } from '@wordpress/element';
import { App } from './App';
import './style.scss';

const root =
	document.getElementById( 'woocommerce-claude-insights-app' ) ||
	document.getElementById( 'woocommerce-claude-insights-root' );

if ( root ) {
	createRoot( root ).render( <App /> );
}
