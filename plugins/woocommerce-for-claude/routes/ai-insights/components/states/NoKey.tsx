/**
 * No-provider state - shown when no AI provider is configured.
 */
import { __ } from '@wordpress/i18n';
import moduleData from '../../data';

export function NoKey() {
	const { providerMode, settingsUrl } = moduleData;
	let message = __(
		"Add an Anthropic API key and WooCommerce for Claude will answer questions about your store's performance, orders, and customer trends from WordPress admin.",
		'woocommerce-claude'
	);
	if ( providerMode === 'connector' ) {
		message = __(
			"Connect a WordPress AI provider in Settings > Connectors and WooCommerce for Claude will answer questions about your store's performance, orders, and customer trends from WordPress admin.",
			'woocommerce-claude'
		);
	}

	return (
		<div className="hey-woo-state hey-woo-state--no-key">
			<div className="hey-woo-state__icon" aria-hidden="true">🔑</div>
			<h2 className="hey-woo-state__heading">
				{ __( 'Add an AI provider to get started', 'woocommerce-claude' ) }
			</h2>
			<p className="hey-woo-state__message">{ message }</p>
			<a
				href={ settingsUrl }
				className="button button-primary hey-woo-state__cta"
			>
				{ __( 'Add an AI provider in Settings', 'woocommerce-claude' ) }
			</a>
		</div>
	);
}
