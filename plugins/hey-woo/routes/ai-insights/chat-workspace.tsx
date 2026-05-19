/**
 * Shared chat workspace used by the New chat route.
 */
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useSearch } from '@wordpress/route';
import moduleData from './data';
import { useChat } from './hooks/useChat';
import { useConversations } from './hooks/useConversations';
import { ChatBubble } from './components/ChatBubble';
import { ChatInput } from './components/ChatInput';
import { NoKey } from './components/states/NoKey';
import type { StoredConversation } from './types';

interface ChatViewProps {
	conversations: StoredConversation[];
	initialWorkflowDisplay?: string;
	initialWorkflowPrompt?: string;
	onSaveConversation: ( conv: StoredConversation ) => Promise< void >;
	urlConversationId?: string;
}

interface SaveConversationOptions {
	updateRoute?: boolean;
}

interface ChatHeaderProps {
	title: string;
	subtitle: string;
}

function routePathForConversation( conversationId: string ): string {
	return `/chat?conversationId=${ encodeURIComponent( conversationId ) }`;
}

function replaceCurrentRouteWithConversation( conversationId: string ): void {
	const url = new URL( window.location.href );

	url.searchParams.set( 'p', routePathForConversation( conversationId ) );
	url.searchParams.delete( 'conversationId' );
	window.history.replaceState( {}, '', url.toString() );
}

function ChatHeader( { title, subtitle }: ChatHeaderProps ) {
	return (
		<header className="hey-woo-chat-header">
			<h1 className="hey-woo-chat-header__title">
				{ title }
			</h1>
			<p className="hey-woo-chat-header__subtitle">
				{ subtitle }
			</p>
		</header>
	);
}

export function ChatWorkspace() {
	const search = useSearch( { strict: false } ) as {
		conversationId?: string;
		workflowDisplay?: string;
		workflowPrompt?: string;
	};
	const urlConversationId = search.conversationId;
	const workflowPrompt = ! urlConversationId && typeof search.workflowPrompt === 'string'
		? search.workflowPrompt
		: undefined;
	const workflowDisplay = ! urlConversationId && typeof search.workflowDisplay === 'string'
		? search.workflowDisplay
		: undefined;
	const { conversations, saveConversation } = useConversations();

	return (
		<div className="hey-woo-chat-workspace">
			<div className="hey-woo-chat-workspace__main">
				<ChatView
					key={ urlConversationId ?? workflowPrompt ?? 'new' }
					urlConversationId={ urlConversationId }
					initialWorkflowPrompt={ workflowPrompt }
					initialWorkflowDisplay={ workflowDisplay }
					conversations={ conversations }
					onSaveConversation={ saveConversation }
				/>
			</div>
		</div>
	);
}

/** Inner view — owns all chat state. Re-mounts when conversationId changes. */
function ChatView( {
	urlConversationId,
	initialWorkflowDisplay,
	initialWorkflowPrompt,
	conversations,
	onSaveConversation,
}: ChatViewProps ) {
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
	const didAutoRunWorkflowRef = useRef( false );

	useEffect( () => {
		if (
			! initialWorkflowPrompt ||
			urlConversationId ||
			didAutoRunWorkflowRef.current ||
			state.status === 'no_key'
		) {
			return;
		}

		didAutoRunWorkflowRef.current = true;
		void sendMessage( initialWorkflowPrompt, {
			displayText: initialWorkflowDisplay || __( 'Run report', 'hey-woo' ),
		} );
	}, [ initialWorkflowDisplay, initialWorkflowPrompt, sendMessage, state.status, urlConversationId ] );

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
	const chatTitle = initialConversation?.title || __( 'New chat', 'hey-woo' );
	const chatSubtitle = __( 'Ask anything about your store', 'hey-woo' );
	const isEmptyNewChat = ! urlConversationId && ! initialWorkflowPrompt && state.messages.length === 0 && ! isSending;

	if ( isEmptyNewChat ) {
		return (
			<div className="hey-woo-page hey-woo-page--chat hey-woo-page--chat-empty">
				<ChatHeader title={ chatTitle } subtitle={ chatSubtitle } />

				<div className="hey-woo-chat-content hey-woo-chat-content--start">
					<div className="hey-woo-chat-start">
						<section className="hey-woo-chat-hero" aria-label={ __( 'Start a chat', 'hey-woo' ) }>
							<h2>
								{ sprintf(
									/* translators: %s: current user's display name */
									__( 'Hello %s, what would you like to explore?', 'hey-woo' ),
									moduleData.userName || __( 'there', 'hey-woo' )
								) }
							</h2>
							<ChatInput
								onSend={ sendMessage }
								disabled={ isSending }
								placeholder={ __( 'Ask anything', 'hey-woo' ) }
								rows={ 4 }
								variant="hero"
							/>
						</section>
					</div>
				</div>
			</div>
		);
	}

	return (
		<div className="hey-woo-page hey-woo-page--chat">
			<ChatHeader title={ chatTitle } subtitle={ chatSubtitle } />

			<div className="hey-woo-chat-content">
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
		</div>
	);
}
