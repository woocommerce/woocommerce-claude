/**
 * Shared chat workspace used by the New chat route.
 */
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { Icon, archive, calendar, chartBar, check, page, trendingUp } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@wordpress/route';
import moduleData from './data';
import { useChat } from './hooks/useChat';
import { useConversations } from './hooks/useConversations';
import { ChatBubble } from './components/ChatBubble';
import { ChatInput } from './components/ChatInput';
import { NoKey } from './components/states/NoKey';
import { ACTIONS_UPDATED_EVENT, loadActionCards } from '../actions/action-store';
import {
	startWorkflowRun,
	workflowFromSlashCommand,
	workflowRunOptionsFromMessage,
} from '../workflows/workflow-runs';
import type { StoredConversation } from './types';

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

function openActionCount(): number {
	return loadActionCards().filter( ( card ) => card.status !== 'done' ).length;
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
	const [ actionsCount, setActionsCount ] = useState( () => openActionCount() );

	useEffect( () => {
		const refreshActionsCount = () => setActionsCount( openActionCount() );

		window.addEventListener( ACTIONS_UPDATED_EVENT, refreshActionsCount );
		window.addEventListener( 'storage', refreshActionsCount );

		return () => {
			window.removeEventListener( ACTIONS_UPDATED_EVENT, refreshActionsCount );
			window.removeEventListener( 'storage', refreshActionsCount );
		};
	}, [] );

	const goTo = ( to: string, search: Record< string, string > = {} ) => {
		void navigate( {
			to,
			search,
		} );
	};
	const actionTitle = actionsCount > 0
		? sprintf(
				/* translators: %d: number of open action cards */
				_n( 'Review %d open action', 'Review %d open actions', actionsCount, 'hey-woo' ),
				actionsCount
		  )
		: __( 'Review action board', 'hey-woo' );
	const shortcuts: ChatShortcut[] = [
		{
			id: 'run-workflow',
			title: __( 'Run a workflow', 'hey-woo' ),
			description: __( 'Start a store review, acquisition check, refund triage, or catalogue audit.', 'hey-woo' ),
			icon: <Icon icon={ chartBar } size={ 22 } />,
			tone: 'primary',
			onClick: () => goTo( '/reports' ),
		},
		{
			id: 'actions',
			title: actionTitle,
			description: __( 'Work through recommended follow-ups from reports and chats.', 'hey-woo' ),
			icon: <Icon icon={ check } size={ 22 } />,
			onClick: () => goTo( '/actions' ),
		},
		{
			id: 'library',
			title: __( 'Open library', 'hey-woo' ),
			description: __( 'Find previous reports, investigations, and chats.', 'hey-woo' ),
			icon: <Icon icon={ archive } size={ 22 } />,
			onClick: () => goTo( '/history' ),
		},
		{
			id: 'schedule-weekly-review',
			title: __( 'Schedule a weekly review', 'hey-woo' ),
			description: __( 'Set up a recurring store check for the week ahead.', 'hey-woo' ),
			icon: <Icon icon={ calendar } size={ 22 } />,
			onClick: () => goTo( '/reports', {
				workflow: 'weekly-store-review',
				run: 'weekly',
			} ),
		},
		{
			id: 'check-what-changed',
			title: __( 'Check what changed', 'hey-woo' ),
			description: __( 'Investigate revenue, orders, refunds, products, or channels.', 'hey-woo' ),
			icon: <Icon icon={ trendingUp } size={ 22 } />,
			onClick: () => goTo( '/reports', {
				workflow: 'revenue-drop-triage',
			} ),
		},
		{
			id: 'catalogue-content',
			title: __( 'Improve catalogue content', 'hey-woo' ),
			description: __( 'Find missing product data, weak descriptions, and content gaps.', 'hey-woo' ),
			icon: <Icon icon={ page } size={ 22 } />,
			onClick: () => goTo( '/reports', {
				workflow: 'catalog-audit',
			} ),
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

function ChatProgress() {
	const steps = [
		__( 'Reading store context', 'hey-woo' ),
		__( 'Checking the relevant signals', 'hey-woo' ),
		__( 'Drafting the response', 'hey-woo' ),
	];

	return (
		<div className="hey-woo-progress" role="status" aria-live="polite">
			<div className="hey-woo-progress__header">
				<span className="hey-woo-progress__mark" aria-hidden="true">
					<span />
				</span>
				<div className="hey-woo-progress__copy">
					<span className="hey-woo-progress__eyebrow">
						{ __( 'Hey Woo is working', 'hey-woo' ) }
					</span>
					<span className="hey-woo-progress__text">
						{ __( 'Looking across your store data and preparing a useful answer.', 'hey-woo' ) }
					</span>
				</div>
			</div>
			<div className="hey-woo-progress__bar" aria-hidden="true">
				<span />
			</div>
			<ul className="hey-woo-progress__steps" aria-hidden="true">
				{ steps.map( ( step ) => (
					<li key={ step }>{ step }</li>
				) ) }
			</ul>
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

	const handleConversationSaved = useCallback( async (
		conversation: StoredConversation,
		options: SaveConversationOptions = {}
	) => {
		await onSaveConversation( conversation );

		if ( options.updateRoute && ! urlConversationId ) {
			replaceCurrentRouteWithConversation( conversation.id );
		}
	}, [ onSaveConversation, urlConversationId ] );

	const { state, sendMessage, clearError, submitFeedback } = useChat( {
		initialMessages: initialConversation?.messages,
		initialConversationId: urlConversationId,
		initialTitle: initialConversation?.title,
		onConversationSaved: handleConversationSaved,
	} );

	const bottomRef = useRef< HTMLDivElement >( null );
	const didAutoRunWorkflowRef = useRef( false );
	const navigate = useNavigate();
	const [ isStartingWorkflow, setIsStartingWorkflow ] = useState( false );

	const startWorkflowFromMessage = useCallback( async ( message: string ) => {
		const workflow = workflowFromSlashCommand( message );
		if ( ! workflow ) {
			return false;
		}

		setIsStartingWorkflow( true );
		const conversation = await startWorkflowRun(
			workflowRunOptionsFromMessage( workflow, message )
		).finally( () => {
			setIsStartingWorkflow( false );
		} );

		void navigate( {
			to: '/history',
			search: {
				conversation: conversation.id,
			},
		} );

		return true;
	}, [ navigate ] );

	const handleSendMessage = useCallback( async ( message: string ) => {
		if ( await startWorkflowFromMessage( message ) ) {
			return;
		}

		void sendMessage( message );
	}, [ sendMessage, startWorkflowFromMessage ] );

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
		void ( async () => {
			if ( await startWorkflowFromMessage( initialWorkflowPrompt ) ) {
				return;
			}

			void sendMessage( initialWorkflowPrompt, {
				displayText: initialWorkflowDisplay || __( 'Run workflow', 'hey-woo' ),
			} );
		} )();
	}, [ initialWorkflowDisplay, initialWorkflowPrompt, sendMessage, startWorkflowFromMessage, state.status, urlConversationId ] );

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

	const isSending = state.status === 'sending' || isStartingWorkflow;
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

					{ isSending && <ChatProgress /> }

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
