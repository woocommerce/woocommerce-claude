/**
 * AI Insights stage — root component for the WooCommerce for Claude chat interface.
 *
 * Exported as `stage` following the @wordpress/boot route convention.
 * Rendered by the boot router when the user visits the AI Insights page.
 */
import './style.scss';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useChat } from './hooks/useChat';
import { ChatBubble } from './components/ChatBubble';
import { ChatInput } from './components/ChatInput';
import { NoKey } from './components/states/NoKey';

export function stage() {
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
					{ __( 'AI Insights', 'woocommerce-claude' ) }
				</h1>
				<p className="hey-woo-chat-header__subtitle">
					{ __( 'Ask anything about your store', 'woocommerce-claude' ) }
				</p>
			</header>

			<div className="hey-woo-messages" role="log" aria-live="polite">
				{ state.messages.length === 0 && (
					<p className="hey-woo-messages__empty">
						{ __(
							'Ask about revenue, orders, products, customers, or anything else about your store.',
							'woocommerce-claude'
						) }
					</p>
				) }

				{ state.messages.map( ( msg ) => (
					<ChatBubble key={ msg.id } message={ msg } />
				) ) }

				{ isSending && (
					<div className="hey-woo-bubble hey-woo-bubble--assistant hey-woo-bubble--typing" aria-label="Thinking">
						<span className="hey-woo-bubble__role">WooCommerce for Claude</span>
						<span className="hey-woo-typing-indicator" aria-hidden="true">
							<span />
							<span />
							<span />
						</span>
					</div>
				) }

				{ state.status === 'error' && (
					<div className="hey-woo-error-bar" role="alert">
						<span>{ state.errorMessage || __( 'Something went wrong.', 'woocommerce-claude' ) }</span>
						<button
							type="button"
							className="hey-woo-error-bar__dismiss"
							onClick={ clearError }
						>
							{ __( 'Dismiss', 'woocommerce-claude' ) }
						</button>
					</div>
				) }

				<div ref={ bottomRef } aria-hidden="true" />
			</div>

			<ChatInput onSend={ sendMessage } disabled={ isSending } />
		</div>
	);
}
