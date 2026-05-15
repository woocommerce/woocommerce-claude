/**
 * useChat — manage conversation state and REST calls for the chat interface.
 */
import { useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import moduleData from '../data';
import type { ChatMessage, ChatResponse, StoredConversation } from '../types';

/** Timeout for each chat request in milliseconds — slightly above the PHP server-side limit. */
const REQUEST_TIMEOUT_MS = 95_000;

export type ChatStatus = 'idle' | 'no_key' | 'sending' | 'error';

export interface ChatState {
	messages: ChatMessage[];
	status: ChatStatus;
	errorMessage: string;
}

export interface ConversationSaveOptions {
	updateRoute?: boolean;
}

export interface UseChatOptions {
	initialMessages?: ChatMessage[];
	initialConversationId?: string;
	initialTitle?: string;
	onConversationSaved?: (
		conv: StoredConversation,
		options?: ConversationSaveOptions
	) => void | Promise< void >;
}

function generateTitle( text: string ): string {
	const trimmed = text.trim().replace( /\s+/g, ' ' );
	if ( trimmed.length <= 50 ) {
		return trimmed;
	}
	const truncated = trimmed.slice( 0, 50 );
	const lastSpace = truncated.lastIndexOf( ' ' );
	return ( lastSpace > 20 ? truncated.slice( 0, lastSpace ) : truncated ) + '…';
}

export function useChat( options: UseChatOptions = {} ) {
	const {
		initialMessages = [],
		initialConversationId,
		initialTitle,
		onConversationSaved,
	} = options;

	const [ state, setState ] = useState< ChatState >( {
		messages: initialMessages,
		status: moduleData.hasKey ? 'idle' : 'no_key',
		errorMessage: '',
	} );

	// Conversation ID and title — set on first message, stable thereafter.
	const [ conversationId, setConversationId ] = useState< string | undefined >(
		initialConversationId
	);
	const conversationIdRef = useRef< string | undefined >( initialConversationId );
	const titleRef = useRef< string | undefined >( initialTitle );

	// Start the ID counter above any existing message IDs to avoid collisions.
	const nextId = useRef(
		initialMessages.length > 0
			? Math.max( ...initialMessages.map( ( m ) => m.id ) ) + 1
			: 0
	);

	// Always-current reference to the message list, so sendMessage never closes over stale state.
	const messagesRef = useRef< ChatMessage[] >( initialMessages );
	messagesRef.current = state.messages;

	const sendMessage = useCallback( async ( text: string ) => {
		const history = messagesRef.current;

		// Assign conversation ID and title on first send.
		if ( ! conversationIdRef.current ) {
			const newId = crypto.randomUUID();
			conversationIdRef.current = newId;
			setConversationId( newId );
		}
		if ( ! titleRef.current ) {
			titleRef.current = generateTitle( text );
		}

		const userMessage: ChatMessage = {
			id: nextId.current++,
			role: 'user',
			content: text,
		};
		const submittedMessages = [ ...history, userMessage ];

		setState( ( prev ) => ( {
			...prev,
			messages: [ ...prev.messages, userMessage ],
			status: 'sending',
			errorMessage: '',
		} ) );

		let timeoutId: ReturnType< typeof setTimeout > | undefined;

		try {
			if ( onConversationSaved && conversationIdRef.current && titleRef.current ) {
				await onConversationSaved( {
					id: conversationIdRef.current,
					title: titleRef.current,
					messages: submittedMessages,
					updatedAt: Date.now(),
				}, { updateRoute: false } );
			}

			const controller = new AbortController();
			timeoutId = setTimeout( () => controller.abort(), REQUEST_TIMEOUT_MS );

			const response = await fetch( moduleData.restBase + '/chat', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': moduleData.nonce,
				},
				body: JSON.stringify( {
					message: text,
					history,
				} ),
				signal: controller.signal,
			} );

			clearTimeout( timeoutId );

			if ( ! response.ok ) {
				setState( ( prev ) => ( {
					...prev,
					status: 'error',
					errorMessage: __( 'Something went wrong. Please check your connection and try again.', 'hey-woo' ),
				} ) );
				return;
			}

			const json: ChatResponse = await response.json();

			if ( json.status === 'no_key' ) {
				setState( ( prev ) => ( { ...prev, status: 'no_key' } ) );
				return;
			}

			if ( json.status === 'error' ) {
				setState( ( prev ) => ( {
					...prev,
					status: 'error',
					errorMessage: json.message,
				} ) );
				return;
			}

			const assistantMessage: ChatMessage = {
				id: nextId.current++,
				role: 'assistant',
				content: json.reply,
				...( json.charts?.length ? { charts: json.charts } : {} ),
			};

			// Compute the saved messages before calling setState so we can
			// pass them to onConversationSaved without side-effects inside the
			// setState updater (which React may call multiple times).
			const savedMessages = [ ...submittedMessages, assistantMessage ];

			if ( onConversationSaved && conversationIdRef.current && titleRef.current ) {
				await onConversationSaved( {
					id: conversationIdRef.current,
					title: titleRef.current,
					messages: savedMessages,
					updatedAt: Date.now(),
				}, { updateRoute: true } );
			}

			setState( ( prev ) => ( {
				...prev,
				messages: [ ...prev.messages, assistantMessage ],
				status: 'idle',
			} ) );
		} catch ( err ) {
			if ( timeoutId ) {
				clearTimeout( timeoutId );
			}

			const isAbort = err instanceof Error && err.name === 'AbortError';
			setState( ( prev ) => ( {
				...prev,
				status: 'error',
				errorMessage: isAbort
					? __( 'The request timed out — please try again.', 'hey-woo' )
					: __( 'Something went wrong. Please check your connection and try again.', 'hey-woo' ),
			} ) );
		}
	}, [ onConversationSaved ] );

	const clearError = useCallback( () => {
		setState( ( prev ) => ( { ...prev, status: 'idle', errorMessage: '' } ) );
	}, [] );

	return { state, sendMessage, clearError, conversationId };
}
