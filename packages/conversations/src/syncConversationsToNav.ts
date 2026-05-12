import { store as bootStore } from '@wordpress/boot';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import type { StoredConversation } from './types';

export function syncConversationsToNav( conversations: StoredConversation[] ): void {
	if ( ! conversations.length ) {
		return;
	}

	dispatch( bootStore ).registerMenuItem( 'ai-insights-recents', {
		id: 'ai-insights-recents',
		label: __( 'Recents', 'woocommerce-claude' ),
		to: '/',
		parent_type: 'drilldown',
	} );

	conversations.forEach( ( conv ) => {
		dispatch( bootStore ).registerMenuItem( `ai-insights-conv-${ conv.id }`, {
			id: `ai-insights-conv-${ conv.id }`,
			label: conv.title,
			to: `/?conversationId=${ conv.id }`,
			parent: 'ai-insights-recents',
		} );
	} );
}
