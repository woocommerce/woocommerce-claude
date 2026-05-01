/**
 * App — root component for the Hey Woo conversational assistant.
 *
 * Renders the full-height chat interface: a scrollable message history
 * above a fixed compose bar. Routes between no-key, error, and chat states.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useChat } from './hooks/useChat';
import { ChatBubble } from './components/ChatBubble';
import { ChatInput } from './components/ChatInput';
import { NoKey } from './components/states/NoKey';

export function App() {
	const { state, sendMessage, clearError } = useChat();
	const bottomRef = useRef< HTMLDivElement >( null );

	// Scroll to the latest message whenever messages change.
	useEffect( () => {
		bottomRef.current?.scrollIntoView( { behavior: 'smooth' } );
	}, [ state.messages ] );

	if ( state.status === 'no_key' ) {
		return (
			<div className="hey-woo-page">
				<NoKey />
			</div>
		);
	}

	const isSending = state.status === 'sending';

	return (
		<div className="hey-woo-page hey-woo-page--chat">
			<header className="hey-woo-chat-header">
				<h1 className="hey-woo-chat-header__title">
					{ __( 'Hey Woo!', 'hey-woo' ) }
				</h1>
				<p className="hey-woo-chat-header__subtitle">
					{ __( 'Ask anything about your store', 'hey-woo' ) }
				</p>
			</header>

			<div className="hey-woo-messages" role="log" aria-live="polite">
				{ state.messages.length === 0 && (
					<p className="hey-woo-messages__empty">
						{ __(
							'Ask about revenue, orders, products, customers, or anything else about your store.',
							'hey-woo'
						) }
					</p>
				) }

				{ state.messages.map( ( msg, i ) => (
					<ChatBubble key={ i } message={ msg } />
				) ) }

				{ isSending && (
					<div className="hey-woo-bubble hey-woo-bubble--assistant hey-woo-bubble--typing" aria-label="Thinking">
						<span className="hey-woo-bubble__role">Hey Woo</span>
						<span className="hey-woo-typing-indicator" aria-hidden="true">
							<span />
							<span />
							<span />
						</span>
					</div>
				) }

				{ state.status === 'error' && (
					<div className="hey-woo-error-bar" role="alert">
						<span>{ state.errorMessage || __( 'Something went wrong.', 'hey-woo' ) }</span>
						<button
							type="button"
							className="hey-woo-error-bar__dismiss"
							onClick={ clearError }
						>
							{ __( 'Dismiss', 'hey-woo' ) }
						</button>
					</div>
				) }

				<div ref={ bottomRef } aria-hidden="true" />
			</div>

			<ChatInput onSend={ sendMessage } disabled={ isSending } />
		</div>
	);
}
