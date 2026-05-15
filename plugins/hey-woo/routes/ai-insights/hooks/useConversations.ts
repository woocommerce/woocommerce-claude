import { useState, useCallback } from '@wordpress/element';
import moduleData from '../data';
import type { StoredConversation } from '../types';

const MAX_CONVERSATIONS = 5;

function upsertAndTrim(
	conversations: StoredConversation[],
	incoming: StoredConversation
): StoredConversation[] {
	const without = conversations.filter( ( c ) => c.id !== incoming.id );
	without.unshift( incoming );
	without.sort( ( a, b ) => b.updatedAt - a.updatedAt );
	return without.slice( 0, MAX_CONVERSATIONS );
}

export function useConversations() {
	const [ conversations, setConversations ] = useState< StoredConversation[] >(
		moduleData.conversations
	);

	const saveConversation = useCallback( async ( conv: StoredConversation ) => {
		setConversations( ( prev ) => upsertAndTrim( prev, conv ) );

		try {
			await fetch( moduleData.restBase + '/conversations', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': moduleData.nonce,
				},
				body: JSON.stringify( conv ),
			} );
		} catch ( _err ) {
			// Silent failure — conversation remains available in local state for this session.
		}
	}, [] );

	return { conversations, saveConversation };
}
