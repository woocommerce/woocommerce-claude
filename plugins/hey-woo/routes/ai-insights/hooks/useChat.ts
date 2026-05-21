/**
 * useChat — manage conversation state and REST calls for the chat interface.
 */
import { useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import moduleData from '../data';
import type { ChatMessage, ChatResponse, FeedbackRating, StoredConversation } from '../types';
import { saveConversationRecord } from './useConversations';

/** Timeout for multi-tool report workflows in milliseconds. */
const REQUEST_TIMEOUT_MS = 180_000;

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

export interface SendMessageOptions {
	displayText?: string;
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

/**
 * Ask the server for an AI-generated short title for the first turn of a chat.
 *
 * Fires fire-and-forget after the first assistant response, so the merchant
 * never waits on it. When the server returns a title, the matching stored
 * conversation is saved again with the new title, replacing the truncated
 * first-message placeholder useChat assigned when sendMessage opened the chat.
 *
 * Silent on every error path — a failure leaves the placeholder title.
 */
async function upgradeConversationTitle(
	conversationId: string,
	userMessage: string,
	assistantMessage: string
): Promise< void > {
	try {
		const response = await fetch( moduleData.restBase + '/title', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': moduleData.nonce,
			},
			body: JSON.stringify( {
				user_message: userMessage,
				assistant_message: assistantMessage,
			} ),
		} );

		if ( ! response.ok ) {
			return;
		}

		const json = ( await response.json() ) as {
			status?: string;
			title?: unknown;
		};

		if ( json.status !== 'ok' || typeof json.title !== 'string' ) {
			return;
		}

		const nextTitle = json.title.trim();
		if ( '' === nextTitle ) {
			return;
		}

		const latest = moduleData.conversations.find( ( conv ) => conv.id === conversationId );
		if ( ! latest ) {
			return;
		}

		await saveConversationRecord( {
			...latest,
			title: nextTitle,
			updatedAt: Date.now(),
		} );
	} catch ( _err ) {
		// Title upgrade is best-effort; leave the placeholder if anything fails.
	}
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

	const sendMessage = useCallback( async ( text: string, sendOptions: SendMessageOptions = {} ) => {
		const history = messagesRef.current;
		const displayText = sendOptions.displayText?.trim() || text;
		const isFirstTurn = history.length === 0;

		// Assign conversation ID and title on first send.
		if ( ! conversationIdRef.current ) {
			const newId = crypto.randomUUID();
			conversationIdRef.current = newId;
			setConversationId( newId );
		}
		if ( ! titleRef.current ) {
			titleRef.current = generateTitle( displayText );
		}

		const userMessage: ChatMessage = {
			id: nextId.current++,
			role: 'user',
			content: displayText,
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
				let errorMessage = __( 'Something went wrong. Please check your connection and try again.', 'hey-woo' );
				try {
					const errorJson = ( await response.json() ) as { message?: unknown };
					if ( typeof errorJson.message === 'string' && errorJson.message.trim() ) {
						errorMessage = errorJson.message;
					}
				} catch {
					// Keep the generic connection message when the server does not return JSON.
				}

				setState( ( prev ) => ( {
					...prev,
					status: 'error',
					errorMessage,
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

			// Build the saved-messages snapshot from the always-current
			// messagesRef so any feedback that submitFeedback persisted while
			// this request was in flight is carried into the final save. Using
			// the pre-flight `submittedMessages` snapshot here would silently
			// overwrite an in-flight feedback update with a newer updatedAt.
			const savedMessages = [ ...messagesRef.current, assistantMessage ];

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

			// Upgrade the truncated placeholder title to a real AI-generated one
			// after the first turn completes. Fire-and-forget so the merchant
			// never waits on it; the Library reflects the upgraded title on next
			// view.
			if ( isFirstTurn && conversationIdRef.current ) {
				void upgradeConversationTitle( conversationIdRef.current, displayText, json.reply ).then( () => {
					const upgraded = moduleData.conversations.find(
						( conv ) => conv.id === conversationIdRef.current
					);
					if ( upgraded && upgraded.title ) {
						titleRef.current = upgraded.title;
					}
				} );
			}
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

	const submitFeedback = useCallback(
		async ( messageId: number, rating: FeedbackRating, comment?: string ): Promise< void > => {
			const conversation = conversationIdRef.current;
			const title = titleRef.current;
			if ( ! conversation || ! title ) {
				throw new Error( 'no_conversation' );
			}

			const trimmedComment = comment?.trim() ?? '';

			const response = await fetch( moduleData.restBase + '/feedback', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': moduleData.nonce,
				},
				body: JSON.stringify( {
					conversation_id: conversation,
					message_id: messageId,
					rating,
					comment: trimmedComment,
				} ),
			} );

			if ( ! response.ok ) {
				throw new Error( 'feedback_failed' );
			}

			const submittedAt = Date.now();
			const updatedMessages = messagesRef.current.map( ( msg ) => {
				if ( msg.id !== messageId ) {
					return msg;
				}

				return {
					...msg,
					feedback: {
						rating,
						submittedAt,
						...( '' !== trimmedComment ? { comment: trimmedComment } : {} ),
					},
				};
			} );

			setState( ( prev ) => ( { ...prev, messages: updatedMessages } ) );

			if ( onConversationSaved ) {
				await onConversationSaved( {
					id: conversation,
					title,
					messages: updatedMessages,
					updatedAt: submittedAt,
				} );
			}
		},
		[ onConversationSaved ]
	);

	return { state, sendMessage, clearError, submitFeedback, conversationId };
}
