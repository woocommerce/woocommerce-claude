/**
 * Loading state — shown while the initial page data is being fetched.
 */
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function Loading() {
	return (
		<div className="hey-woo-state hey-woo-state--loading">
			<Spinner />
			<p className="hey-woo-state__message">
				{ __( 'Loading…', 'hey-woo' ) }
			</p>
		</div>
	);
}
