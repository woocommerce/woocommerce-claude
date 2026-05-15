import { store as bootStore } from '@wordpress/boot';
import { dispatch } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import type { StoredConversation } from './types';

const MAX_CONVERSATIONS = 5;

function conversationRoute( conversationId: string ): string {
	return `/?conversationId=${ encodeURIComponent( conversationId ) }`;
}

export function syncConversationsToNav( conversations: StoredConversation[] ): void {
	const recentConversations = conversations.slice( 0, MAX_CONVERSATIONS );

	if ( ! recentConversations.length ) {
		return;
	}

	dispatch( bootStore ).registerMenuItem( 'ai-insights-recents', {
		id: 'ai-insights-recents',
		label: __( 'Recents', 'hey-woo' ),
		to: '/',
		parent_type: 'drilldown',
	} );

	recentConversations.forEach( ( conv, index ) => {
		const id = `ai-insights-recent-${ index }`;

		dispatch( bootStore ).registerMenuItem( id, {
			id,
			label: conv.title,
			to: conversationRoute( conv.id ),
			parent: 'ai-insights-recents',
		} );
	} );
}
