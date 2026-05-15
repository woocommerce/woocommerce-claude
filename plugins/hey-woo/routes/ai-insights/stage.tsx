/**
 * Hey Woo stage — root component for the Hey Woo chat interface.
 *
 * Exported as `stage` following the @wordpress/boot route convention.
 * Rendered by the boot router when the user visits the Hey Woo page.
 *
 * The outer `stage` reads the URL search params reactively via `useSearch` so
 * that clicking a nav item (new chat or a recent conversation) causes the inner
 * `ChatView` to fully re-mount rather than try to reconcile state in-place.
 */
import './style.scss';
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useSearch } from '@wordpress/route';
import { useChat } from './hooks/useChat';
import { useConversations } from './hooks/useConversations';
import { ChatBubble } from './components/ChatBubble';
import { ChatInput } from './components/ChatInput';
import { NoKey } from './components/states/NoKey';
import { syncConversationsToNav } from '../../packages/conversations/src/index';
import type { StoredConversation } from './types';

function routePathForConversation( conversationId: string ): string {
	return `/?conversationId=${ encodeURIComponent( conversationId ) }`;
}

function replaceCurrentRouteWithConversation( conversationId: string ): void {
	const url = new URL( window.location.href );

	url.searchParams.set( 'p', routePathForConversation( conversationId ) );
	url.searchParams.delete( 'conversationId' );
	window.history.replaceState( {}, '', url.toString() );
}

/** Outer shell — reads the URL reactively and re-mounts ChatView on ID change. */
export function stage() {
	const search = useSearch( { strict: false } ) as { conversationId?: string };
	const urlConversationId = search.conversationId;
	const { conversations, saveConversation } = useConversations();

	// Keep the nav in sync whenever the conversation list changes.
	useEffect( () => {
		syncConversationsToNav( conversations );
	}, [ conversations ] );

	return (
		<ChatView
			key={ urlConversationId ?? 'new' }
			urlConversationId={ urlConversationId }
			conversations={ conversations }
			onSaveConversation={ saveConversation }
		/>
	);
}

interface ChatViewProps {
	urlConversationId?: string;
	conversations: StoredConversation[];
	onSaveConversation: ( conv: StoredConversation ) => Promise< void >;
}

interface SaveConversationOptions {
	updateRoute?: boolean;
}

/** Inner view — owns all chat state. Re-mounts when conversationId changes. */
function ChatView( { urlConversationId, conversations, onSaveConversation }: ChatViewProps ) {
	const initialConversation = urlConversationId
		? conversations.find( ( c ) => c.id === urlConversationId )
		: undefined;

	const handleConversationSaved = useCallback( async (
		conversation: StoredConversation,
		options: SaveConversationOptions = {}
	) => {
		await onSaveConversation( conversation );

		if ( options.updateRoute && ! urlConversationId ) {
			replaceCurrentRouteWithConversation( conversation.id );
		}
	}, [ onSaveConversation, urlConversationId ] );

	const { state, sendMessage, clearError } = useChat( {
		initialMessages: initialConversation?.messages,
		initialConversationId: urlConversationId,
		initialTitle: initialConversation?.title,
		onConversationSaved: handleConversationSaved,
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
					{ __( 'Ask Claude', 'hey-woo' ) }
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

				{ state.messages.map( ( msg ) => (
					<ChatBubble key={ msg.id } message={ msg } />
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
						<Button
							type="button"
							variant="tertiary"
							size="compact"
							isDestructive
							className="hey-woo-error-bar__dismiss"
							onClick={ clearError }
						>
							{ __( 'Dismiss', 'hey-woo' ) }
						</Button>
					</div>
				) }

				<div ref={ bottomRef } aria-hidden="true" />
			</div>

			<ChatInput onSend={ sendMessage } disabled={ isSending } />
		</div>
	);
}
