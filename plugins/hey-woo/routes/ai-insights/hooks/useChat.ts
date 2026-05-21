/**
 * useChat — manage conversation state and REST calls for the chat interface.
 */
import { useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import moduleData from '../data';
import type {
	ChatErrorKind,
	ChatMessage,
	ChatResponse,
	FeedbackRating,
	StoredConversation,
} from '../types';
import { saveConversationRecord } from './useConversations';

/** Timeout for multi-tool report workflows in milliseconds. */
const REQUEST_TIMEOUT_MS = 180_000;

export type ChatStatus = 'idle' | 'no_key' | 'sending' | 'error';

export interface ChatState {
	messages: ChatMessage[];
	status: ChatStatus;
	errorMessage: string;
	errorKind?: ChatErrorKind;
	/**
	 * Identifier for the in-flight chat turn, threaded into the POST body and
	 * read by useChatProgress to poll the matching server-side transient.
	 * Refreshed on every runChatRequest call.
	 */
	progressId?: string;
}

export interface UseChatOptions {
	initialMessages?: ChatMessage[];
	initialConversationId?: string;
	initialTitle?: string;
	onConversationSaved?: ( conv: StoredConversation ) => void | Promise< void >;
}

export interface SendMessageOptions {
	displayText?: string;
}

interface LastSend {
	text: string;
	history: ChatMessage[];
	isFirstTurn: boolean;
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

/**
 * Narrow an unknown server-supplied `kind` to a valid ChatErrorKind, falling
 * back to 'generic' if it is unrecognised or missing. Keeps the runtime check
 * in one place so callers can treat the result as a guaranteed kind.
 */
function normaliseErrorKind( raw: unknown ): ChatErrorKind {
	const known: ChatErrorKind[] = [
		'bad_key',
		'rate_limited',
		'overloaded',
		'timeout',
		'network',
		'generic',
	];
	return typeof raw === 'string' && ( known as string[] ).includes( raw )
		? ( raw as ChatErrorKind )
		: 'generic';
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

	// Snapshot of the most recent send, so resendLast can re-issue the same
	// request without re-appending the user message or rebuilding state.
	const lastSendRef = useRef< LastSend | undefined >( undefined );

	// Synchronous guard against parallel provider requests. React's setState
	// is async, so a fast Retry double-click (or a Retry-then-Send race) can
	// fire two runChatRequest calls before status flips to 'sending' and the
	// Retry button stops rendering. A ref flipped synchronously here drops
	// the duplicate before any work runs.
	const inFlightRef = useRef( false );

	/**
	 * Fire the chat REST call and apply the response to state. Shared by
	 * sendMessage (initial submission) and resendLast (Retry button).
	 *
	 * The caller is responsible for placing the user message into state
	 * before invoking this; runChatRequest only handles the request and the
	 * resulting assistant message (or error).
	 */
	const runChatRequest = useCallback(
		async ( text: string, history: ChatMessage[], isFirstTurn: boolean ): Promise< void > => {
			if ( inFlightRef.current ) {
				return;
			}
			inFlightRef.current = true;

			const progressId = crypto.randomUUID();

			setState( ( prev ) => ( {
				...prev,
				status: 'sending',
				errorMessage: '',
				errorKind: undefined,
				progressId,
			} ) );

			let timeoutId: ReturnType< typeof setTimeout > | undefined;

			try {
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
						progress_id: progressId,
					} ),
					signal: controller.signal,
				} );

				clearTimeout( timeoutId );

				if ( ! response.ok ) {
					let errorMessage = __( 'Something went wrong. Please check your connection and try again.', 'hey-woo' );
					let errorKind: ChatErrorKind = 'generic';
					try {
						const errorJson = ( await response.json() ) as {
							message?: unknown;
							kind?: unknown;
						};
						if ( typeof errorJson.message === 'string' && errorJson.message.trim() ) {
							errorMessage = errorJson.message;
						}
						if ( errorJson.kind !== undefined ) {
							errorKind = normaliseErrorKind( errorJson.kind );
						}
					} catch {
						// Keep the generic connection message when the server does not return JSON.
					}

					setState( ( prev ) => ( {
						...prev,
						status: 'error',
						errorMessage,
						errorKind,
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
						errorKind: normaliseErrorKind( json.kind ),
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
				// the pre-flight snapshot would silently overwrite an in-flight
				// feedback update with a newer updatedAt.
				const savedMessages = [ ...messagesRef.current, assistantMessage ];

				if ( onConversationSaved && conversationIdRef.current && titleRef.current ) {
					await onConversationSaved( {
						id: conversationIdRef.current,
						title: titleRef.current,
						messages: savedMessages,
						updatedAt: Date.now(),
					} );
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
					void upgradeConversationTitle(
						conversationIdRef.current,
						text,
						json.reply
					).then( () => {
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
					errorKind: isAbort ? 'timeout' : 'network',
					errorMessage: isAbort
						? __( 'The request timed out — please try again.', 'hey-woo' )
						: __( 'Could not reach Hey Woo. Check your connection and try again.', 'hey-woo' ),
				} ) );
			} finally {
				inFlightRef.current = false;
			}
		},
		[ onConversationSaved ]
	);

	const sendMessage = useCallback(
		async ( text: string, sendOptions: SendMessageOptions = {} ) => {
			// Drop the send if another provider request is already in flight.
			// runChatRequest's own guard catches parallel Retry double-clicks,
			// but sendMessage mutates state and persists the new user message
			// before runChatRequest runs; without an early return here, a
			// Retry-then-Send race would leave a phantom user bubble saved
			// with no assistant reply to follow it.
			if ( inFlightRef.current ) {
				return;
			}

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
				errorKind: undefined,
			} ) );

			if ( onConversationSaved && conversationIdRef.current && titleRef.current ) {
				await onConversationSaved( {
					id: conversationIdRef.current,
					title: titleRef.current,
					messages: submittedMessages,
					updatedAt: Date.now(),
				} );
			}

			// Snapshot the intent so a Retry click after a transient failure
			// re-issues the same provider request without touching the user
			// bubble already rendered above.
			lastSendRef.current = { text, history, isFirstTurn };

			await runChatRequest( text, history, isFirstTurn );
		},
		[ onConversationSaved, runChatRequest ]
	);

	const resendLast = useCallback( async () => {
		const last = lastSendRef.current;
		if ( ! last ) {
			return;
		}
		await runChatRequest( last.text, last.history, last.isFirstTurn );
	}, [ runChatRequest ] );

	const clearError = useCallback( () => {
		setState( ( prev ) => ( {
			...prev,
			status: 'idle',
			errorMessage: '',
			errorKind: undefined,
		} ) );
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

	return { state, sendMessage, resendLast, clearError, submitFeedback, conversationId };
}
