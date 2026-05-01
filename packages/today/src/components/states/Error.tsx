/**
 * Error state — shown when briefing generation fails.
 */
import { __ } from '@wordpress/i18n';

interface ErrorProps {
	message: string;
	onRetry: () => void;
}

export function Error( { message, onRetry }: ErrorProps ) {
	return (
		<div className="hey-woo-state hey-woo-state--error">
			<div className="hey-woo-state__icon" aria-hidden="true">⚠</div>
			<h2 className="hey-woo-state__heading">
				{ __( 'Something went wrong', 'hey-woo' ) }
			</h2>
			{ message && (
				<p className="hey-woo-state__detail">{ message }</p>
			) }
			<button
				type="button"
				className="button button-secondary hey-woo-state__cta"
				onClick={ onRetry }
			>
				{ __( 'Try again', 'hey-woo' ) }
			</button>
		</div>
	);
}
