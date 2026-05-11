/**
 * useChat — manage conversation state and REST calls for the chat interface.
 */
import { useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import moduleData from '../data';
import type { ChatMessage, ChatResponse } from '../types';

/** Timeout for each chat request in milliseconds — slightly above the PHP server-side limit. */
const REQUEST_TIMEOUT_MS = 95_000;

export type ChatStatus = 'idle' | 'no_key' | 'sending' | 'error';

export interface ChatState {
	messages: ChatMessage[];
	status: ChatStatus;
	errorMessage: string;
}

export function useChat() {
	const [ state, setState ] = useState< ChatState >( {
		messages: [],
		status: moduleData.hasKey ? 'idle' : 'no_key',
		errorMessage: '',
	} );

	// Stable counter for generating unique message IDs.
	const nextId = useRef( 0 );

	const sendMessage = useCallback( async ( text: string ) => {
		const userMessage: ChatMessage = {
			id: nextId.current++,
			role: 'user',
			content: text,
		};

		// Optimistically append the user message and set sending state.
		setState( ( prev ) => ( {
			...prev,
			messages: [ ...prev.messages, userMessage ],
			status: 'sending',
			errorMessage: '',
		} ) );

		const controller = new AbortController();
		const timeoutId = setTimeout( () => controller.abort(), REQUEST_TIMEOUT_MS );

		try {
			const history = state.messages; // history before new message.
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
					errorMessage: __( 'Something went wrong. Please check your connection and try again.', 'woocommerce-claude' ),
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

			// Append Claude's reply.
			const assistantMessage: ChatMessage = {
				id: nextId.current++,
				role: 'assistant',
				content: json.reply,
			};
			setState( ( prev ) => ( {
				...prev,
				messages: [ ...prev.messages, assistantMessage ],
				status: 'idle',
			} ) );
		} catch ( err ) {
			clearTimeout( timeoutId );

			const isAbort = err instanceof Error && err.name === 'AbortError';
			setState( ( prev ) => ( {
				...prev,
				status: 'error',
				errorMessage: isAbort
					? __( 'The request timed out — please try again.', 'woocommerce-claude' )
					: __( 'Something went wrong. Please check your connection and try again.', 'woocommerce-claude' ),
			} ) );
		}
	}, [ state.messages ] );

	const clearError = useCallback( () => {
		setState( ( prev ) => ( { ...prev, status: 'idle', errorMessage: '' } ) );
	}, [] );

	return { state, sendMessage, clearError };
}
