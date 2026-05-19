import { useState, useCallback, useEffect } from '@wordpress/element';
import moduleData from '../data';
import type { StoredConversation } from '../types';

const MAX_CONVERSATIONS = 5;
export const CONVERSATIONS_UPDATED_EVENT = 'hey-woo-conversations-updated';

function upsertAndTrim(
	conversations: StoredConversation[],
	incoming: StoredConversation
): StoredConversation[] {
	const without = conversations.filter( ( c ) => c.id !== incoming.id );
	without.unshift( incoming );
	without.sort( ( a, b ) => b.updatedAt - a.updatedAt );
	return without.slice( 0, MAX_CONVERSATIONS );
}

function publishConversationUpdate( conversations: StoredConversation[] ): void {
	moduleData.conversations = conversations;
	window.dispatchEvent(
		new CustomEvent< StoredConversation[] >( CONVERSATIONS_UPDATED_EVENT, {
			detail: conversations,
		} )
	);
}

export function useConversations() {
	const [ conversations, setConversations ] = useState< StoredConversation[] >(
		moduleData.conversations
	);

	useEffect( () => {
		const refreshConversations = ( event: Event ) => {
			const customEvent = event as CustomEvent< StoredConversation[] >;
			if ( Array.isArray( customEvent.detail ) ) {
				setConversations( customEvent.detail );
			}
		};

		window.addEventListener( CONVERSATIONS_UPDATED_EVENT, refreshConversations );

		return () => {
			window.removeEventListener( CONVERSATIONS_UPDATED_EVENT, refreshConversations );
		};
	}, [] );

	const saveConversation = useCallback( async ( conv: StoredConversation ) => {
		const nextConversations = upsertAndTrim( moduleData.conversations, conv );
		setConversations( nextConversations );
		publishConversationUpdate( nextConversations );

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

	const deleteConversations = useCallback( async ( conversationIds: string[] ) => {
		const ids = Array.from( new Set( conversationIds.filter( Boolean ) ) );

		if ( ids.length === 0 ) {
			return;
		}

		const nextConversations = moduleData.conversations.filter(
			( conversation ) => ! ids.includes( conversation.id )
		);
		setConversations( nextConversations );
		publishConversationUpdate( nextConversations );

		try {
			await fetch( moduleData.restBase + '/conversations', {
				method: 'DELETE',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': moduleData.nonce,
				},
				body: JSON.stringify( { ids } ),
			} );
		} catch ( _err ) {
			// Silent failure — deleted conversations stay hidden locally for this session.
		}
	}, [] );

	return { conversations, saveConversation, deleteConversations };
}
