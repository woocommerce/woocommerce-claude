/**
 * Shared chat workspace used by the New chat route.
 */
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useRef } from '@wordpress/element';
import { Icon, chartBar, globe, payment, people, tag, trendingDown } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@wordpress/route';
import moduleData from './data';
import { useChat } from './hooks/useChat';
import { useChatProgress } from './hooks/useChatProgress';
import { useConversations } from './hooks/useConversations';
import { ChatBubble } from './components/ChatBubble';
import { ChatInput } from './components/ChatInput';
import { NoKey } from './components/states/NoKey';
import { RETRYABLE_CHAT_ERROR_KINDS } from './types';
import type { ChatErrorKind, StoredConversation } from './types';

type ShortcutTone = 'primary' | 'neutral';

interface ChatShortcut {
	id: string;
	title: string;
	description: string;
	icon: JSX.Element;
	tone?: ShortcutTone;
	onClick: () => void;
}

interface ChatViewProps {
	conversations: StoredConversation[];
	initialWorkflowDisplay?: string;
	initialWorkflowPrompt?: string;
	onSaveConversation: ( conv: StoredConversation ) => Promise< void >;
	urlConversationId?: string;
}

interface ChatHeaderProps {
	title: string;
	subtitle: string;
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

function ChatShortcutCard( { shortcut }: { shortcut: ChatShortcut } ) {
	return (
		<button
			type="button"
			className={ `hey-woo-chat-shortcut hey-woo-chat-shortcut--${ shortcut.tone ?? 'neutral' }` }
			onClick={ shortcut.onClick }
		>
			<span className="hey-woo-chat-shortcut__icon" aria-hidden="true">
				{ shortcut.icon }
			</span>
			<span className="hey-woo-chat-shortcut__body">
				<span className="hey-woo-chat-shortcut__title">{ shortcut.title }</span>
				<span className="hey-woo-chat-shortcut__description">{ shortcut.description }</span>
			</span>
		</button>
	);
}

function ChatShortcuts() {
	const navigate = useNavigate();

	const launchWorkflow = ( slug: string, display: string ) => {
		void navigate( {
			to: '/chat',
			search: {
				workflowPrompt: `/${ slug }`,
				workflowDisplay: display,
			},
		} );
	};

	const definitions: Omit< ChatShortcut, 'onClick' >[] = [
		{
			id: 'weekly-store-review',
			title: __( 'How did the store do this week?', 'hey-woo' ),
			description: __( 'Get a merchant-friendly review of revenue, orders, customers, and what to do next.', 'hey-woo' ),
			icon: <Icon icon={ chartBar } size={ 22 } />,
			tone: 'primary',
		},
		{
			id: 'revenue-drop-triage',
			title: __( 'What’s driving revenue down?', 'hey-woo' ),
			description: __( 'Diagnose a soft week or month and find the channels, products, or refunds behind it.', 'hey-woo' ),
			icon: <Icon icon={ trendingDown } size={ 22 } />,
		},
		{
			id: 'channel-performance-review',
			title: __( 'Where are my paying customers coming from?', 'hey-woo' ),
			description: __( 'See which channels, sources, and campaigns are driving revenue and new customers.', 'hey-woo' ),
			icon: <Icon icon={ globe } size={ 22 } />,
		},
		{
			id: 'product-performance-review',
			title: __( 'Which products are pulling their weight?', 'hey-woo' ),
			description: __( 'Spot top sellers, slow movers, and shifts in product mix worth acting on.', 'hey-woo' ),
			icon: <Icon icon={ tag } size={ 22 } />,
		},
		{
			id: 'failed-order-triage',
			title: __( 'What’s stuck in checkout?', 'hey-woo' ),
			description: __( 'Triage failed, on-hold, and unpaid orders so nothing slips through.', 'hey-woo' ),
			icon: <Icon icon={ payment } size={ 22 } />,
		},
		{
			id: 'customer-value-review',
			title: __( 'Are my customers coming back?', 'hey-woo' ),
			description: __( 'Look at lifetime value, repeat rates, and cohorts to find loyalty opportunities.', 'hey-woo' ),
			icon: <Icon icon={ people } size={ 22 } />,
		},
	];

	const shortcuts: ChatShortcut[] = definitions.map( ( def ) => ( {
		...def,
		onClick: () => launchWorkflow( def.id, def.title ),
	} ) );

	return (
		<nav className="hey-woo-chat-shortcuts" aria-label={ __( 'Hey Woo shortcuts', 'hey-woo' ) }>
			{ shortcuts.map( ( shortcut ) => (
				<ChatShortcutCard key={ shortcut.id } shortcut={ shortcut } />
			) ) }
		</nav>
	);
}

interface ChatErrorBarProps {
	kind?: ChatErrorKind;
	message: string;
	onRetry: () => void;
	onDismiss: () => void;
}

/**
 * Inline error surface for the chat workspace.
 *
 * Renders the merchant-friendly message produced by ChatErrorMapper (or by
 * client-side abort/network detection), plus a kind-specific primary action:
 *   - bad_key       → "Open settings" link to the Hey Woo settings tab.
 *   - retryable     → "Retry" button that re-issues the last chat request.
 *   - everything    → "Dismiss" to hide the bar so the merchant can type again.
 */
function ChatErrorBar( { kind, message, onRetry, onDismiss }: ChatErrorBarProps ) {
	const canRetry = !! kind && RETRYABLE_CHAT_ERROR_KINDS.has( kind );
	const isBadKey = 'bad_key' === kind;
	const fallback = __( 'Something went wrong.', 'hey-woo' );

	return (
		<div className="hey-woo-error-bar" role="alert">
			<span className="hey-woo-error-bar__message">{ message || fallback }</span>
			<div className="hey-woo-error-bar__actions">
				{ isBadKey && moduleData.settingsUrl && (
					<Button
						variant="primary"
						size="compact"
						href={ moduleData.settingsUrl }
					>
						{ __( 'Open settings', 'hey-woo' ) }
					</Button>
				) }
				{ canRetry && (
					<Button variant="primary" size="compact" onClick={ onRetry }>
						{ __( 'Retry', 'hey-woo' ) }
					</Button>
				) }
				<Button
					variant="tertiary"
					size="compact"
					className="hey-woo-error-bar__dismiss"
					onClick={ onDismiss }
				>
					{ __( 'Dismiss', 'hey-woo' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Friendly merchant-facing labels for the tool names the controller exposes.
 *
 * Kept in lock-step with `DifmRestController::TOOL_ABILITY_MAP`. Tools that
 * do not appear here fall back to the generic "Working on your request"
 * copy so a new tool name shipped without a label still degrades gracefully.
 */
function friendlyToolLabel( tool: string ): string {
	switch ( tool ) {
		case 'analytics_totals':
			return __( 'Looking up store totals', 'hey-woo' );
		case 'analytics_breakdown':
			return __( 'Breaking down by category or product', 'hey-woo' );
		case 'analytics_series':
			return __( 'Plotting trend data', 'hey-woo' );
		case 'analytics_rows':
			return __( 'Filtering store records', 'hey-woo' );
		case 'get_product_details':
			return __( 'Reading product details', 'hey-woo' );
		case 'search_products':
			return __( 'Searching products', 'hey-woo' );
		case 'get_store_profile':
			return __( 'Checking store profile', 'hey-woo' );
		case 'get_readiness_score':
			return __( 'Calculating readiness score', 'hey-woo' );
		case 'get_recommendations':
			return __( 'Gathering recommendations', 'hey-woo' );
		case 'suggest_improvements':
			return __( 'Suggesting improvements', 'hey-woo' );
		default:
			return __( 'Working on your request', 'hey-woo' );
	}
}

interface ChatProgressProps {
	progressId?: string;
}

function ChatProgress( { progressId }: ChatProgressProps ) {
	const progress = useChatProgress( progressId, true );

	const hasTool = !! progress.tool;
	const headline = hasTool
		? friendlyToolLabel( progress.tool as string )
		: __( 'Hey Woo is working', 'hey-woo' );

	return (
		<div className="hey-woo-progress" role="status" aria-live="polite">
			<span className="hey-woo-progress__mark" aria-hidden="true">
				<span />
			</span>
			<span className="hey-woo-progress__eyebrow">
				{ headline }
			</span>
		</div>
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
	// Read from moduleData first (synchronous in-memory cache) so the
	// remount triggered by the URL update below picks up the just-saved
	// conversation without flashing the empty home. useConversations'
	// React state can lag a tick behind publishConversationUpdate, which
	// would otherwise show a blank screen between the save and the state
	// catching up.
	const initialConversation = urlConversationId
		? ( moduleData.conversations.find( ( c ) => c.id === urlConversationId )
			?? conversations.find( ( c ) => c.id === urlConversationId ) )
		: undefined;

	const handleConversationSaved = useCallback( async (
		conversation: StoredConversation
	) => {
		await onSaveConversation( conversation );
	}, [ onSaveConversation ] );

	const { state, sendMessage, resendLast, clearError, submitFeedback, conversationId } = useChat( {
		initialMessages: initialConversation?.messages,
		initialConversationId: urlConversationId,
		initialTitle: initialConversation?.title,
		onConversationSaved: handleConversationSaved,
	} );

	const navigate = useNavigate();

	// Once the first assistant reply lands on a fresh chat, push the
	// conversationId into the URL so the sidebar "New session" link
	// (which points at `/`) navigates to a different URL than the active
	// session. Without this the click would be a same-URL no-op and the
	// merchant would have no way to start a fresh session. Deferred until
	// the assistant message is in state so the remount doesn't orphan the
	// in-flight fetch.
	useEffect( () => {
		if ( urlConversationId || ! conversationId ) {
			return;
		}
		if ( ! state.messages.some( ( m ) => m.role === 'assistant' ) ) {
			return;
		}
		void navigate( {
			to: '/',
			search: { conversationId },
			replace: true,
		} );
	}, [ urlConversationId, conversationId, state.messages, navigate ] );

	const bottomRef = useRef< HTMLDivElement >( null );
	const didAutoRunWorkflowRef = useRef( false );

	const handleSendMessage = useCallback( ( message: string ) => {
		void sendMessage( message );
	}, [ sendMessage ] );

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
			displayText: initialWorkflowDisplay || __( 'Run workflow', 'hey-woo' ),
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
	// Index of the most recent assistant message — only the latest turn
	// shows follow-up chips, older ones' suggestions are stale.
	const lastAssistantIndex = ( () => {
		for ( let i = state.messages.length - 1; i >= 0; i-- ) {
			if ( state.messages[ i ].role === 'assistant' ) {
				return i;
			}
		}
		return -1;
	} )();

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
								onSend={ handleSendMessage }
								disabled={ isSending }
								placeholder={ __( 'Ask anything', 'hey-woo' ) }
								rows={ 4 }
								variant="hero"
							/>
							<ChatShortcuts />
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

					{ state.messages.map( ( msg, idx ) => (
						<ChatBubble
							key={ msg.id }
							message={ msg }
							isLatest={ idx === lastAssistantIndex }
							onAskFollowup={ handleSendMessage }
							followupDisabled={ isSending }
							onSubmitFeedback={ submitFeedback }
						/>
					) ) }

					{ isSending && <ChatProgress progressId={ state.progressId } /> }

					{ state.status === 'error' && (
						<ChatErrorBar
							kind={ state.errorKind }
							message={ state.errorMessage }
							onRetry={ () => void resendLast() }
							onDismiss={ clearError }
						/>
					) }

					<div ref={ bottomRef } aria-hidden="true" />
				</div>

				<ChatInput
					onSend={ handleSendMessage }
					disabled={ isSending }
					placeholder={ __( 'Ask anything', 'hey-woo' ) }
					rows={ 1 }
					variant="docked"
				/>
			</div>
		</div>
	);
}
