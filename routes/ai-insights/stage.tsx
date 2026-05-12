/**
 * AI Insights stage — root component for the WooCommerce for Claude chat interface.
 *
 * Exported as `stage` following the @wordpress/boot route convention.
 * Rendered by the boot router when the user visits the AI Insights page.
 *
 * The outer `stage` reads the URL search params reactively via `useSearch` so
 * that clicking a nav item (new chat or a recent conversation) causes the inner
 * `ChatView` to fully re-mount rather than try to reconcile state in-place.
 */
import './style.scss';
import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useSearch } from '@wordpress/route';
import { useChat } from './hooks/useChat';
import { useConversations } from './hooks/useConversations';
import { ChatBubble } from './components/ChatBubble';
import { ChatInput } from './components/ChatInput';
import { NoKey } from './components/states/NoKey';
import { syncConversationsToNav } from '../../packages/conversations/src/index';

/** Outer shell — reads the URL reactively and re-mounts ChatView on ID change. */
export function stage() {
	const search = useSearch( { strict: false } ) as { conversationId?: string };
	const urlConversationId = search.conversationId;

	return (
		<ChatView
			key={ urlConversationId ?? 'new' }
			urlConversationId={ urlConversationId }
		/>
	);
}

interface ChatViewProps {
	urlConversationId?: string;
}

/** Inner view — owns all chat state. Re-mounts when conversationId changes. */
function ChatView( { urlConversationId }: ChatViewProps ) {
	const { conversations, saveConversation } = useConversations();

	const initialConversation = urlConversationId
		? conversations.find( ( c ) => c.id === urlConversationId )
		: undefined;

	const { state, sendMessage, clearError, conversationId } = useChat( {
		initialMessages: initialConversation?.messages,
		initialConversationId: urlConversationId,
		onConversationSaved: saveConversation,
	} );

	const bottomRef = useRef< HTMLDivElement >( null );

	// Scroll to the latest message whenever messages change.
	// Use 'instant' on the first paint (loaded history) to avoid jarring animation.
	const didInitialScrollRef = useRef( false );
	useEffect( () => {
		const behavior = didInitialScrollRef.current ? 'smooth' : 'instant';
		didInitialScrollRef.current = true;
		bottomRef.current?.scrollIntoView( { behavior } );
	}, [ state.messages ] );

	// Push the conversation ID into the URL after the first message so a page
	// refresh reopens the same conversation. Go through history directly —
	// TanStack Router intercepts replaceState and keeps useSearch in sync.
	useEffect( () => {
		if ( conversationId && ! urlConversationId ) {
			const url = new URL( window.location.href );
			url.searchParams.set( 'conversationId', conversationId );
			window.history.replaceState( {}, '', url.toString() );
		}
	}, [ conversationId ] );

	// Keep the nav in sync whenever the conversation list changes.
	useEffect( () => {
		syncConversationsToNav( conversations );
	}, [ conversations ] );

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
