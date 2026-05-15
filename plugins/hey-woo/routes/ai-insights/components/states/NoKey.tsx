/**
 * No-key state — shown when no Anthropic API key is configured.
 */
import { __ } from '@wordpress/i18n';
import moduleData from '../../data';

export function NoKey() {
	const { settingsUrl } = moduleData;

	return (
		<div className="hey-woo-state hey-woo-state--no-key">
			<div className="hey-woo-state__icon" aria-hidden="true">🔑</div>
			<h2 className="hey-woo-state__heading">
				{ __( 'Add your Anthropic API key to get started', 'hey-woo' ) }
			</h2>
			<p className="hey-woo-state__message">
				{ __(
					"Paste your Anthropic API key and Hey Woo will answer questions about your store's performance, orders, and customer trends from WordPress admin.",
					'hey-woo'
				) }
			</p>
			<a
				href={ settingsUrl }
				className="button button-primary hey-woo-state__cta"
			>
				{ __( 'Add API key in Settings', 'hey-woo' ) }
			</a>
		</div>
	);
}
