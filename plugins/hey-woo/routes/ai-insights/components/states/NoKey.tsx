/**
 * No-provider state - shown when no AI provider is configured.
 */
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Icon, plugins } from '@wordpress/icons';
import moduleData from '../../data';

export function NoKey() {
	const { providerMode, settingsUrl } = moduleData;
	const isConnectorMode = providerMode === 'connector';

	return (
		<div className="hey-woo-state hey-woo-state--no-key">
			<div className="hey-woo-state__icon" aria-hidden="true">
				<Icon icon={ plugins } size={ 48 } />
			</div>
			<h2 className="hey-woo-state__heading">
				{ __( 'Set up an AI provider to get started', 'hey-woo' ) }
			</h2>
			{ isConnectorMode ? (
				<>
					<p className="hey-woo-state__message">
						{ __(
							'Hey Woo answers questions about your store using an AI provider you connect on this site. Three quick steps:',
							'hey-woo'
						) }
					</p>
					<ol className="hey-woo-state__steps">
						<li>
							{ __(
								'Install and activate the WordPress AI plugin.',
								'hey-woo'
							) }
						</li>
						<li>
							{ __(
								'Open the plugin’s settings and toggle Enable AI on.',
								'hey-woo'
							) }
						</li>
						<li>
							{ __(
								'In Settings › Connectors, pick a provider (Anthropic, OpenAI, Google…) and paste your API key.',
								'hey-woo'
							) }
						</li>
					</ol>
				</>
			) : (
				<p className="hey-woo-state__message">
					{ __(
						"Add an Anthropic API key and Hey Woo will answer questions about your store's performance, orders, and customer trends from WordPress admin.",
						'hey-woo'
					) }
				</p>
			) }
			<Button
				variant="primary"
				href={ settingsUrl }
				className="hey-woo-state__cta"
			>
				{ isConnectorMode
					? __( 'Open Settings › Connectors', 'hey-woo' )
					: __( 'Add an AI provider in Settings', 'hey-woo' ) }
			</Button>
		</div>
	);
}
