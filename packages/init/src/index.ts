import { store as bootStore } from '@wordpress/boot';
import { dispatch } from '@wordpress/data';
import { commentAuthorAvatar } from '@wordpress/icons';

export async function init(): Promise< void > {
	dispatch( bootStore ).updateMenuItem( 'ai-insights', {
		icon: commentAuthorAvatar,
	} );
}
