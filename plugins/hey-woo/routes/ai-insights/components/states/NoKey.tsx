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
			<p className="hey-woo-state__message">
				{ __(
					'Hey Woo answers questions about your store using an AI provider you connect on this site.',
					'hey-woo'
				) }
			</p>
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
