import { store as bootStore } from '@wordpress/boot';
import { dispatch } from '@wordpress/data';
import { archive, chartBar, check, commentAuthorAvatar } from '@wordpress/icons';

export async function init(): Promise< void > {
	dispatch( bootStore ).updateMenuItem( 'ai-insights', {
		icon: commentAuthorAvatar,
	} );
	dispatch( bootStore ).updateMenuItem( 'hey-woo-history', {
		icon: archive,
	} );
	dispatch( bootStore ).updateMenuItem( 'hey-woo-reports', {
		icon: chartBar,
	} );
	dispatch( bootStore ).updateMenuItem( 'hey-woo-actions', {
		icon: check,
	} );
}
