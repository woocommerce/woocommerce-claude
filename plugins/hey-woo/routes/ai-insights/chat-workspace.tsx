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

	const goTo = ( to: string, search: Record< string, string > = {} ) => {
		void navigate( {
			to,
			search,
		} );
	};

	const shortcuts: ChatShortcut[] = [
		{
			id: 'weekly-store-review',
			title: __( 'How did the store do this week?', 'hey-woo' ),
			description: __( 'Get a merchant-friendly review of revenue, orders, customers, and what to do next.', 'hey-woo' ),
			icon: <Icon icon={ chartBar } size={ 22 } />,
			tone: 'primary',
			onClick: () => goTo( '/reports', { workflow: 'weekly-store-review' } ),
		},
		{
			id: 'revenue-drop-triage',
			title: __( 'What’s driving revenue down?', 'hey-woo' ),
			description: __( 'Diagnose a soft week or month and find the channels, products, or refunds behind it.', 'hey-woo' ),
			icon: <Icon icon={ trendingDown } size={ 22 } />,
			onClick: () => goTo( '/reports', { workflow: 'revenue-drop-triage' } ),
		},
		{
			id: 'channel-performance-review',
			title: __( 'Where are my paying customers coming from?', 'hey-woo' ),
			description: __( 'See which channels, sources, and campaigns are driving revenue and new customers.', 'hey-woo' ),
			icon: <Icon icon={ globe } size={ 22 } />,
			onClick: () => goTo( '/reports', { workflow: 'channel-performance-review' } ),
		},
		{
			id: 'product-performance-review',
			title: __( 'Which products are pulling their weight?', 'hey-woo' ),
			description: __( 'Spot top sellers, slow movers, and shifts in product mix worth acting on.', 'hey-woo' ),
			icon: <Icon icon={ tag } size={ 22 } />,
			onClick: () => goTo( '/reports', { workflow: 'product-performance-review' } ),
		},
		{
			id: 'failed-order-triage',
			title: __( 'What’s stuck in checkout?', 'hey-woo' ),
			description: __( 'Triage failed, on-hold, and unpaid orders so nothing slips through.', 'hey-woo' ),
			icon: <Icon icon={ payment } size={ 22 } />,
			onClick: () => goTo( '/reports', { workflow: 'failed-order-triage' } ),
		},
		{
			id: 'customer-value-review',
			title: __( 'Are my customers coming back?', 'hey-woo' ),
			description: __( 'Look at lifetime value, repeat rates, and cohorts to find loyalty opportunities.', 'hey-woo' ),
			icon: <Icon icon={ people } size={ 22 } />,
			onClick: () => goTo( '/reports', { workflow: 'customer-value-review' } ),
		},
	];

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
 * Kept in lock-step with `DifmRestController::TOOL_ABILITY_MAP` plus the
 * `render_chart` pseudo-tool. Tools that do not appear here fall back to the
 * generic "Working on your request" copy so a new tool name shipped without
 * a label still degrades gracefully.
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
		case 'render_chart':
			return __( 'Preparing a chart', 'hey-woo' );
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
	const detail = hasTool
		? __( 'Streaming what Hey Woo is doing as it runs.', 'hey-woo' )
		: __( 'Looking across your store data and preparing a useful answer.', 'hey-woo' );

	return (
		<div className="hey-woo-progress" role="status" aria-live="polite">
			<div className="hey-woo-progress__header">
				<span className="hey-woo-progress__mark" aria-hidden="true">
					<span />
				</span>
				<div className="hey-woo-progress__copy">
					<span className="hey-woo-progress__eyebrow">
						{ headline }
					</span>
					<span className="hey-woo-progress__text">
						{ detail }
					</span>
				</div>
			</div>
			<div className="hey-woo-progress__bar" aria-hidden="true">
				<span />
			</div>
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
	const initialConversation = urlConversationId
		? conversations.find( ( c ) => c.id === urlConversationId )
		: undefined;

	// Intentionally no URL update after a save. Updating window.location to
	// include the new conversationId after the assistant reply lands forces
	// ChatView to remount (the parent's key depends on urlConversationId),
	// and the new mount can briefly render the empty home before the
	// conversations state catches up — the merchant sees the report
	// disappear and reload to a blank screen. The conversation is already
	// persisted via useConversations, so it shows up in the Library and a
	// refresh from there restores the chat.
	const handleConversationSaved = useCallback( async (
		conversation: StoredConversation
	) => {
		await onSaveConversation( conversation );
	}, [ onSaveConversation ] );

	const { state, sendMessage, resendLast, clearError, submitFeedback } = useChat( {
		initialMessages: initialConversation?.messages,
		initialConversationId: urlConversationId,
		initialTitle: initialConversation?.title,
		onConversationSaved: handleConversationSaved,
	} );

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

					{ state.messages.map( ( msg ) => (
						<ChatBubble
							key={ msg.id }
							message={ msg }
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
					rows={ 4 }
					variant="hero"
				/>
			</div>
		</div>
	);
}
