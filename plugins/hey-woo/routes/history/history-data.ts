/**
 * Shared helpers for the History DataViews route.
 */
import { __ } from '@wordpress/i18n';
import type { ChatMessage, StoredConversation } from '../ai-insights/types';

export type ConversationSource = 'chat' | 'report';

export interface HistoryConversation extends StoredConversation {
	lastSpeaker: string;
	messageCount: number;
	preview: string;
	source: ConversationSource;
	sourceLabel: string;
	updatedLabel: string;
}

export function formatUpdatedAt( updatedAt: number ): string {
	if ( ! updatedAt ) {
		return __( 'Unknown', 'hey-woo' );
	}

	return new Intl.DateTimeFormat( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( new Date( updatedAt ) );
}

export function messagePreview( messages: ChatMessage[] ): string {
	const message = [ ...messages ].reverse().find( ( item ) => item.content.trim() );

	if ( ! message ) {
		return __( 'No messages yet.', 'hey-woo' );
	}

	const preview = message.content
		.replace( /```hey-woo-actions[\s\S]*?```/gi, '' )
		.replace( /\s+/g, ' ' )
		.trim();

	return preview.length > 140 ? `${ preview.slice( 0, 137 ) }...` : preview;
}

export function conversationSource( conversation: StoredConversation ): ConversationSource {
	const firstUserMessage = conversation.messages.find( ( message ) => message.role === 'user' );

	return firstUserMessage?.content.trim().toLowerCase().startsWith( 'run ' )
		? 'report'
		: 'chat';
}

export function conversationSourceLabel( source: ConversationSource ): string {
	return source === 'report' ? __( 'Report', 'hey-woo' ) : __( 'Chat', 'hey-woo' );
}

export function lastSpeakerLabel( messages: ChatMessage[] ): string {
	const lastMessage = [ ...messages ].reverse().find( ( item ) => item.content.trim() );

	if ( ! lastMessage ) {
		return __( 'None', 'hey-woo' );
	}

	return lastMessage.role === 'assistant' ? __( 'Hey Woo', 'hey-woo' ) : __( 'Merchant', 'hey-woo' );
}

export function toHistoryConversation( conversation: StoredConversation ): HistoryConversation {
	const source = conversationSource( conversation );

	return {
		...conversation,
		lastSpeaker: lastSpeakerLabel( conversation.messages ),
		messageCount: conversation.messages.length,
		preview: messagePreview( conversation.messages ),
		source,
		sourceLabel: conversationSourceLabel( source ),
		updatedLabel: formatUpdatedAt( conversation.updatedAt ),
	};
}
