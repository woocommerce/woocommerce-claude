/**
 * Hey Woo Library - right-hand conversation preview panel.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { Button, Modal, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@wordpress/route';
import { useConversations } from '../ai-insights/hooks/useConversations';
import { MarkdownContent } from '../ai-insights/components/MarkdownContent';
import { ReportActionCards } from '../ai-insights/components/ReportActionCards';
import { parseReportActions } from '../ai-insights/report-actions';
import {
	formatUpdatedAt,
	lastSpeakerLabel,
	messagePreview,
	toHistoryConversation,
} from './history-data';
import type { ChatMessage, StoredConversation } from '../ai-insights/types';

export function inspector() {
	const search = useSearch( { strict: false } ) as { conversation?: string };
	const conversationId = typeof search.conversation === 'string' ? search.conversation : '';
	const { conversations, deleteConversations } = useConversations();
	const conversation = conversations.find( ( item ) => item.id === conversationId );

	if ( ! conversation ) {
		return (
			<aside className="hey-woo-history-preview" aria-label={ __( 'Conversation preview', 'hey-woo' ) }>
				<div className="hey-woo-history-preview__content">
					<div className="hey-woo-history-preview__running" role="status">
						<Spinner />
						<div>
							<h3>{ __( 'Loading library item', 'hey-woo' ) }</h3>
							<p>{ __( 'Hey Woo is fetching the latest report details.', 'hey-woo' ) }</p>
						</div>
					</div>
				</div>
			</aside>
		);
	}

	return (
		<HistoryPreviewPanel
			key={ conversation.id }
			conversation={ conversation }
			onDelete={ deleteConversations }
		/>
	);
}

interface HistoryPreviewPanelProps {
	conversation: StoredConversation;
	onDelete: ( conversationIds: string[] ) => Promise< void >;
}

function messageRoleLabel( message: ChatMessage ): string {
	return message.role === 'assistant' ? __( 'Hey Woo', 'hey-woo' ) : __( 'Merchant', 'hey-woo' );
}

function HistoryPreviewPanel( { conversation, onDelete }: HistoryPreviewPanelProps ) {
	const navigate = useNavigate();
	const [ isDeleteModalOpen, setIsDeleteModalOpen ] = useState( false );
	const [ isDeleting, setIsDeleting ] = useState( false );
	const historyConversation = toHistoryConversation( conversation );
	const recentMessages = conversation.messages.slice( -4 );
	const isWorkflowRun = conversation.type === 'workflow' || Boolean( conversation.workflowRun );
	const workflowStatus = conversation.workflowRun?.status;
	const latestAssistantMessage = [ ...conversation.messages ].reverse().find(
		( message ) => message.role === 'assistant' && message.content.trim()
	);
	const reportContent = latestAssistantMessage
		? parseReportActions( latestAssistantMessage.content )
		: { content: '', actions: [] };
	const hasReportContent = Boolean( reportContent.content.trim() );
	const isReportItem = isWorkflowRun || hasReportContent;

	const closeInspector = () => {
		void navigate( {
			to: '/history',
			search: {},
		} );
	};

	const continueChat = () => {
		void navigate( {
			to: '/chat',
			search: {
				conversationId: conversation.id,
			},
		} );
	};

	const deleteConversation = async () => {
		setIsDeleting( true );
		await onDelete( [ conversation.id ] );
		setIsDeleting( false );
		setIsDeleteModalOpen( false );
		closeInspector();
	};

	return (
		<aside className="hey-woo-history-preview" aria-label={ __( 'Conversation preview', 'hey-woo' ) }>
			<header className="hey-woo-history-preview__header">
				<div className="hey-woo-history-preview__heading">
					<span className="hey-woo-history-preview__eyebrow">{ historyConversation.sourceLabel }</span>
					<h2>{ conversation.title }</h2>
				</div>
				<Button
					type="button"
					variant="tertiary"
					size="compact"
					onClick={ closeInspector }
				>
					{ __( 'Close', 'hey-woo' ) }
				</Button>
			</header>

			<div className="hey-woo-history-preview__content">
				<p className="hey-woo-history-preview__description">
					{ isWorkflowRun && workflowStatus === 'running'
						? __( 'This workflow is running. You can leave this screen and come back to the Library when it finishes.', 'hey-woo' )
						: messagePreview( conversation.messages ) }
				</p>

				<dl className="hey-woo-history-preview__meta">
					{ conversation.workflowRun && (
						<div>
							<dt>{ __( 'Workflow', 'hey-woo' ) }</dt>
							<dd>{ conversation.workflowRun.label }</dd>
						</div>
					) }
					{ conversation.workflowRun && (
						<div>
							<dt>{ __( 'Status', 'hey-woo' ) }</dt>
							<dd>{ historyConversation.statusLabel }</dd>
						</div>
					) }
					{ conversation.workflowRun && (
						<div>
							<dt>{ __( 'Period', 'hey-woo' ) }</dt>
							<dd>{ conversation.workflowRun.periodLabel }</dd>
						</div>
					) }
					<div>
						<dt>{ __( 'Updated', 'hey-woo' ) }</dt>
						<dd>{ formatUpdatedAt( conversation.updatedAt ) }</dd>
					</div>
					<div>
						<dt>{ __( 'Messages', 'hey-woo' ) }</dt>
						<dd>
							{ sprintf(
								/* translators: %d: number of messages */
								_n( '%d message', '%d messages', conversation.messages.length, 'hey-woo' ),
								conversation.messages.length
							) }
						</dd>
					</div>
					<div>
						<dt>{ __( 'Last reply', 'hey-woo' ) }</dt>
						<dd>{ lastSpeakerLabel( conversation.messages ) }</dd>
					</div>
					{ conversation.workflowRun?.scheduleLabel && (
						<div>
							<dt>{ __( 'Requested cadence', 'hey-woo' ) }</dt>
							<dd>{ conversation.workflowRun.scheduleLabel }</dd>
						</div>
					) }
				</dl>

				{ isWorkflowRun && workflowStatus === 'running' && (
					<div className="hey-woo-history-preview__running" role="status">
						<Spinner />
						<div>
							<h3>{ __( 'Preparing report', 'hey-woo' ) }</h3>
							<p>{ __( 'Hey Woo is collecting the relevant store signals and drafting the briefing.', 'hey-woo' ) }</p>
						</div>
					</div>
				) }

				{ isWorkflowRun && workflowStatus === 'error' && (
					<div className="hey-woo-history-preview__error" role="alert">
						<h3>{ __( 'Workflow could not finish', 'hey-woo' ) }</h3>
						<p>{ conversation.workflowRun?.errorMessage || messagePreview( conversation.messages ) }</p>
					</div>
				) }

				{ hasReportContent && ( ! isWorkflowRun || workflowStatus === 'complete' ) && (
					<div className="hey-woo-history-preview__reports">
						<MarkdownContent content={ reportContent.content } />
						<ReportActionCards
							actions={ reportContent.actions }
							reportLabel={ conversation.title }
						/>
					</div>
				) }

				{ ( ! isReportItem || ( workflowStatus === 'complete' && ! hasReportContent ) ) && recentMessages.length > 0 && (
					<div className="hey-woo-history-preview__messages">
						<h3>{ __( 'Recent messages', 'hey-woo' ) }</h3>
						<ol>
							{ recentMessages.map( ( message ) => (
								<li key={ message.id }>
									<span>{ messageRoleLabel( message ) }</span>
									<p>{ messagePreview( [ message ] ) }</p>
								</li>
							) ) }
						</ol>
					</div>
				) }
			</div>

			<div className="hey-woo-history-preview__actions">
				<Button
					type="button"
					variant="secondary"
					isDestructive
					onClick={ () => setIsDeleteModalOpen( true ) }
				>
					{ __( 'Delete', 'hey-woo' ) }
				</Button>
				<Button
					type="button"
					variant="primary"
					disabled={ isWorkflowRun && workflowStatus === 'running' }
					onClick={ continueChat }
				>
					{ isReportItem
						? __( 'Ask about this report', 'hey-woo' )
						: __( 'Continue chat', 'hey-woo' ) }
				</Button>
			</div>

			{ isDeleteModalOpen && (
				<Modal
					title={ __( 'Delete conversation', 'hey-woo' ) }
					size="medium"
					onRequestClose={ () => setIsDeleteModalOpen( false ) }
				>
					<div className="hey-woo-history-delete-modal">
						<p>{ __( 'Delete this conversation from the library?', 'hey-woo' ) }</p>
						<ul>
							<li>{ conversation.title }</li>
						</ul>
						<div className="hey-woo-history-delete-modal__actions">
							<Button
								type="button"
								variant="secondary"
								disabled={ isDeleting }
								onClick={ () => setIsDeleteModalOpen( false ) }
							>
								{ __( 'Cancel', 'hey-woo' ) }
							</Button>
							<Button
								type="button"
								variant="primary"
								isDestructive
								isBusy={ isDeleting }
								disabled={ isDeleting }
								onClick={ deleteConversation }
							>
								{ __( 'Delete', 'hey-woo' ) }
							</Button>
						</div>
					</div>
				</Modal>
			) }
		</aside>
	);
}
