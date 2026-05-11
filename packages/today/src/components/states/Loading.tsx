/**
 * Loading state — shown while the briefing is being generated or fetched.
 */
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function Loading() {
	return (
		<div className="hey-woo-state hey-woo-state--loading">
			<Spinner />
			<p className="hey-woo-state__message">
				{ __( 'Generating your briefing…', 'woocommerce-claude' ) }
			</p>
			<p className="hey-woo-state__sub">
				{ __( 'This may take up to 20 seconds on the first visit of the day.', 'woocommerce-claude' ) }
			</p>
		</div>
	);
}
