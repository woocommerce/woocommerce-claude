/**
 * Shared helpers for the Library DataViews route.
 */
import { __ } from '@wordpress/i18n';
import { parseReportActions } from '../ai-insights/report-actions';
import { WORKFLOWS } from '../ai-insights/workflows';
import type {
	ChatMessage,
	StoredConversation,
	WorkflowRunStatus,
} from '../ai-insights/types';

export type ConversationSource = 'chat' | 'report';

export interface HistoryConversation extends StoredConversation {
	lastSpeaker: string;
	messageCount: number;
	preview: string;
	source: ConversationSource;
	sourceLabel: string;
	status: string;
	statusLabel: string;
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

	const { content } = parseReportActions( message.content );
	const preview = content
		.replace( /^#{1,6}\s+/gm, '' )
		.replace( /\*\*(.+?)\*\*/g, '$1' )
		.replace( /\s+/g, ' ' )
		.trim();

	return preview.length > 140 ? `${ preview.slice( 0, 137 ) }...` : preview;
}

export function conversationSource( conversation: StoredConversation ): ConversationSource {
	if ( conversation.type === 'workflow' || conversation.workflowRun ) {
		return 'report';
	}

	const firstUserMessage = conversation.messages.find( ( message ) => message.role === 'user' );
	const firstUserText = firstUserMessage?.content.trim().toLowerCase() || '';
	const isWorkflowRun = WORKFLOWS.some( ( workflow ) => (
		firstUserText.startsWith( `/${ workflow.slug }` ) ||
		firstUserText.includes( workflow.label.toLowerCase() )
	) );

	return isWorkflowRun || firstUserText.startsWith( 'run ' )
		? 'report'
		: 'chat';
}

export function conversationSourceLabel( source: ConversationSource ): string {
	return source === 'report' ? __( 'Report', 'hey-woo' ) : __( 'Chat', 'hey-woo' );
}

export function workflowRunStatusLabel( status: WorkflowRunStatus | undefined ): string {
	switch ( status ) {
		case 'running':
			return __( 'Running', 'hey-woo' );
		case 'complete':
			return __( 'Complete', 'hey-woo' );
		case 'error':
			return __( 'Needs attention', 'hey-woo' );
	}

	return __( 'Ready', 'hey-woo' );
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
	const status = conversation.workflowRun?.status || 'ready';

	return {
		...conversation,
		lastSpeaker: lastSpeakerLabel( conversation.messages ),
		messageCount: conversation.messages.length,
		preview: conversation.workflowRun?.errorMessage || messagePreview( conversation.messages ),
		source,
		sourceLabel: conversationSourceLabel( source ),
		status,
		statusLabel: workflowRunStatusLabel( conversation.workflowRun?.status ),
		updatedLabel: formatUpdatedAt( conversation.updatedAt ),
	};
}
