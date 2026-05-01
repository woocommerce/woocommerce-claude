/**
 * useChat — manage conversation state and REST calls for the chat interface.
 */
import { useState, useCallback } from '@wordpress/element';
import moduleData from '../data';
import type { ChatMessage, ChatResponse } from '../types';

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

	const sendMessage = useCallback( async ( text: string ) => {
		const userMessage: ChatMessage = { role: 'user', content: text };

		// Optimistically append the user message and set sending state.
		setState( ( prev ) => ( {
			...prev,
			messages: [ ...prev.messages, userMessage ],
			status: 'sending',
			errorMessage: '',
		} ) );

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
			} );

			if ( ! response.ok ) {
				throw new Error( `HTTP ${ response.status }` );
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
				role: 'assistant',
				content: json.reply,
			};
			setState( ( prev ) => ( {
				...prev,
				messages: [ ...prev.messages, assistantMessage ],
				status: 'idle',
			} ) );
		} catch ( err ) {
			const message =
				err instanceof Error ? err.message : 'An unexpected error occurred.';
			setState( ( prev ) => ( {
				...prev,
				status: 'error',
				errorMessage: message,
			} ) );
		}
	}, [ state.messages ] );

	const clearError = useCallback( () => {
		setState( ( prev ) => ( { ...prev, status: 'idle', errorMessage: '' } ) );
	}, [] );

	return { state, sendMessage, clearError };
}
