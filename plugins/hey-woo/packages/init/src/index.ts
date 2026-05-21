import { store as bootStore } from '@wordpress/boot';
import { dispatch } from '@wordpress/data';
import { archive, chartBar, check, commentContent, home } from '@wordpress/icons';

export async function init(): Promise< void > {
	dispatch( bootStore ).updateMenuItem( 'hey-woo-today', {
		icon: home,
	} );
	dispatch( bootStore ).updateMenuItem( 'hey-woo-chat', {
		icon: commentContent,
	} );
	dispatch( bootStore ).updateMenuItem( 'hey-woo-history', {
		icon: archive,
	} );
	dispatch( bootStore ).updateMenuItem( 'hey-woo-workflows', {
		icon: chartBar,
	} );
	dispatch( bootStore ).updateMenuItem( 'hey-woo-actions', {
		icon: check,
	} );
}
