import { useState, useCallback, useEffect } from '@wordpress/element';
import moduleData from '../data';
import type { StoredConversation } from '../types';

const MAX_CONVERSATIONS = 50;
export const CONVERSATIONS_UPDATED_EVENT = 'hey-woo-conversations-updated';

function trimConversations( conversations: StoredConversation[] ): StoredConversation[] {
	return [ ...conversations ]
		.sort( ( a, b ) => b.updatedAt - a.updatedAt )
		.slice( 0, MAX_CONVERSATIONS );
}

function mergeAndTrim( conversations: StoredConversation[] ): StoredConversation[] {
	const byId = new Map< string, StoredConversation >();

	for ( const conversation of conversations ) {
		const existing = byId.get( conversation.id );
		if ( ! existing || conversation.updatedAt >= existing.updatedAt ) {
			byId.set( conversation.id, conversation );
		}
	}

	return trimConversations( Array.from( byId.values() ) );
}

function upsertAndTrim(
	conversations: StoredConversation[],
	incoming: StoredConversation
): StoredConversation[] {
	return mergeAndTrim( [ incoming, ...conversations ] );
}

function publishConversationUpdate( conversations: StoredConversation[] ): void {
	moduleData.conversations = conversations;
	window.dispatchEvent(
		new CustomEvent< StoredConversation[] >( CONVERSATIONS_UPDATED_EVENT, {
			detail: conversations,
		} )
	);
}

// Per-conversation-id chain so overlapping saves for the same conversation
// hit the server in updatedAt order. The server has a stale-write guard, but
// serialising here avoids cheap 409s when, say, the chat-completion save and
// the auto-title save race against each other on the same conversation.
const pendingSaves = new Map< string, Promise< void > >();

async function sendSaveRequest( conv: StoredConversation ): Promise< void > {
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
}

export async function saveConversationRecord( conv: StoredConversation ) {
	const nextConversations = upsertAndTrim( moduleData.conversations, conv );
	publishConversationUpdate( nextConversations );

	const previous = pendingSaves.get( conv.id );
	const current = ( async () => {
		if ( previous ) {
			try {
				await previous;
			} catch ( _err ) {
				// A prior failure must not block subsequent saves.
			}
		}
		await sendSaveRequest( conv );
	} )();

	pendingSaves.set( conv.id, current );
	try {
		await current;
	} finally {
		if ( pendingSaves.get( conv.id ) === current ) {
			pendingSaves.delete( conv.id );
		}
	}
}

async function fetchConversationRecords(): Promise< StoredConversation[] | null > {
	try {
		const response = await fetch( moduleData.restBase + '/conversations', {
			method: 'GET',
			headers: {
				'X-WP-Nonce': moduleData.nonce,
			},
		} );

		if ( ! response.ok ) {
			return null;
		}

		const json = await response.json();
		return Array.isArray( json ) ? json as StoredConversation[] : null;
	} catch ( _err ) {
		return null;
	}
}

export function useConversations() {
	const [ conversations, setConversations ] = useState< StoredConversation[] >(
		moduleData.conversations
	);

	useEffect( () => {
		let isMounted = true;

		const refreshConversations = ( event: Event ) => {
			const customEvent = event as CustomEvent< StoredConversation[] >;
			if ( Array.isArray( customEvent.detail ) ) {
				setConversations( customEvent.detail );
			}
		};

		window.addEventListener( CONVERSATIONS_UPDATED_EVENT, refreshConversations );

		void ( async () => {
			const remoteConversations = await fetchConversationRecords();
			if ( ! isMounted || ! remoteConversations ) {
				return;
			}

			const nextConversations = mergeAndTrim( [
				...moduleData.conversations,
				...remoteConversations,
			] );
			publishConversationUpdate( nextConversations );
		} )();

		return () => {
			isMounted = false;
			window.removeEventListener( CONVERSATIONS_UPDATED_EVENT, refreshConversations );
		};
	}, [] );

	const saveConversation = useCallback( async ( conv: StoredConversation ) => {
		await saveConversationRecord( conv );
		setConversations( moduleData.conversations );
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
