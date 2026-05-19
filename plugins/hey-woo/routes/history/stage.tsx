/**
 * Hey Woo History - conversation management route.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { Button } from '@wordpress/components';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { Icon, commentAuthorAvatar, trash } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@wordpress/route';
import { useConversations } from '../ai-insights/hooks/useConversations';
import {
	conversationSourceLabel,
	toHistoryConversation,
} from './history-data';
import type { HistoryConversation } from './history-data';
import type { Action, Field, View } from '@wordpress/dataviews/wp';

const HISTORY_VISIBLE_FIELDS = [ 'source', 'messageCount', 'lastSpeaker', 'updatedAt' ];
const HISTORY_LAYOUTS = {
	table: {
		fields: HISTORY_VISIBLE_FIELDS,
		titleField: 'title',
		descriptionField: 'preview',
		showMedia: false,
		layout: {
			density: 'balanced',
			styles: {
				source: { width: '120px' },
				messageCount: { width: '120px' },
				lastSpeaker: { width: '140px' },
				updatedAt: { width: '180px' },
			},
		},
	},
	list: {
		fields: HISTORY_VISIBLE_FIELDS,
		titleField: 'title',
		descriptionField: 'preview',
		showMedia: false,
		layout: {
			density: 'balanced',
		},
	},
	grid: {
		fields: HISTORY_VISIBLE_FIELDS,
		titleField: 'title',
		mediaField: 'media',
		descriptionField: 'preview',
		showMedia: true,
		layout: {
			badgeFields: [ 'source', 'messageCount' ],
			density: 'comfortable',
		},
	},
};

const DEFAULT_HISTORY_VIEW: View = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 10,
	filters: [],
	sort: {
		field: 'updatedAt',
		direction: 'desc',
	},
	...HISTORY_LAYOUTS.table,
};

interface DeleteConversationsModalProps {
	closeModal?: () => void;
	items: HistoryConversation[];
	onDelete: ( conversationIds: string[] ) => Promise< void >;
}

function DeleteConversationsModal( {
	closeModal,
	items,
	onDelete,
}: DeleteConversationsModalProps ) {
	const [ isDeleting, setIsDeleting ] = useState( false );
	const deleteCount = items.length;

	const confirmDelete = async () => {
		setIsDeleting( true );
		await onDelete( items.map( ( item ) => item.id ) );
		setIsDeleting( false );
		closeModal?.();
	};

	return (
		<div className="hey-woo-history-delete-modal">
			<p>
				{ sprintf(
					/* translators: %d: number of conversations */
					_n(
						'Delete %d conversation from history?',
						'Delete %d conversations from history?',
						deleteCount,
						'hey-woo'
					),
					deleteCount
				) }
			</p>
			<ul>
				{ items.slice( 0, 5 ).map( ( item ) => (
					<li key={ item.id }>{ item.title }</li>
				) ) }
			</ul>
			{ items.length > 5 && (
				<p className="hey-woo-history-delete-modal__more">
					{ sprintf(
						/* translators: %d: number of additional conversations */
						_n( 'And %d more.', 'And %d more.', items.length - 5, 'hey-woo' ),
						items.length - 5
					) }
				</p>
			) }
			<div className="hey-woo-history-delete-modal__actions">
				<Button
					type="button"
					variant="secondary"
					disabled={ isDeleting }
					onClick={ closeModal }
				>
					{ __( 'Cancel', 'hey-woo' ) }
				</Button>
				<Button
					type="button"
					variant="primary"
					isDestructive
					isBusy={ isDeleting }
					disabled={ isDeleting }
					onClick={ confirmDelete }
				>
					{ __( 'Delete', 'hey-woo' ) }
				</Button>
			</div>
		</div>
	);
}

export function stage() {
	const search = useSearch( { strict: false } ) as { conversation?: string };
	const navigate = useNavigate();
	const selectedConversationId = typeof search.conversation === 'string' ? search.conversation : '';
	const [ view, setView ] = useState< View >( DEFAULT_HISTORY_VIEW );
	const [ selection, setSelection ] = useState< string[] >( [] );
	const { conversations, deleteConversations } = useConversations();
	const historyItems = useMemo< HistoryConversation[] >(
		() => conversations.map( toHistoryConversation ),
		[ conversations ]
	);
	const sourceOptions = useMemo(
		() => [
			{ value: 'chat', label: conversationSourceLabel( 'chat' ) },
			{ value: 'report', label: conversationSourceLabel( 'report' ) },
		],
		[]
	);

	const selectConversation = useCallback( ( conversationId: string ) => {
		void navigate( {
			to: '/history',
			search: {
				conversation: conversationId,
			},
		} );
	}, [ navigate ] );

	const closeSelectedConversation = useCallback( () => {
		void navigate( {
			to: '/history',
			search: {},
		} );
	}, [ navigate ] );

	const deleteConversationIds = useCallback( async ( conversationIds: string[] ) => {
		await deleteConversations( conversationIds );
		setSelection( [] );

		if ( selectedConversationId && conversationIds.includes( selectedConversationId ) ) {
			closeSelectedConversation();
		}
	}, [ closeSelectedConversation, deleteConversations, selectedConversationId ] );

	const fields = useMemo< Field< HistoryConversation >[] >(
		() => [
			{
				id: 'media',
				label: __( 'Preview', 'hey-woo' ),
				enableHiding: false,
				enableSorting: false,
				render: () => (
					<div className="hey-woo-history-media">
						<Icon icon={ commentAuthorAvatar } size={ 32 } />
					</div>
				),
			},
			{
				id: 'title',
				label: __( 'Conversation', 'hey-woo' ),
				enableHiding: false,
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.title,
			},
			{
				id: 'preview',
				label: __( 'Preview', 'hey-woo' ),
				enableHiding: false,
				enableGlobalSearch: true,
				enableSorting: false,
				getValue: ( { item } ) => item.preview,
			},
			{
				id: 'source',
				label: __( 'Source', 'hey-woo' ),
				elements: sourceOptions,
				enableGlobalSearch: true,
				enableSorting: true,
				filterBy: {
					operators: [ 'isAny' ],
					isPrimary: true,
				},
				getValue: ( { item } ) => item.source,
				render: ( { item } ) => item.sourceLabel,
			},
			{
				id: 'messageCount',
				label: __( 'Messages', 'hey-woo' ),
				enableSorting: true,
				getValue: ( { item } ) => item.messageCount,
				render: ( { item } ) => sprintf(
					/* translators: %d: number of messages */
					_n( '%d message', '%d messages', item.messageCount, 'hey-woo' ),
					item.messageCount
				),
			},
			{
				id: 'lastSpeaker',
				label: __( 'Last reply', 'hey-woo' ),
				enableGlobalSearch: true,
				enableSorting: true,
				getValue: ( { item } ) => item.lastSpeaker,
			},
			{
				id: 'updatedAt',
				label: __( 'Updated', 'hey-woo' ),
				enableHiding: false,
				enableSorting: true,
				getValue: ( { item } ) => item.updatedAt,
				render: ( { item } ) => (
					<span className="hey-woo-history-date">{ item.updatedLabel }</span>
				),
			},
		],
		[ sourceOptions ]
	);
	const { data: shownConversations, paginationInfo } = useMemo(
		() => filterSortAndPaginate( historyItems, view, fields ),
		[ fields, historyItems, view ]
	);
	const actions = useMemo< Action< HistoryConversation >[] >(
		() => [
			{
				id: 'preview-conversation',
				label: ( items ) => items[ 0 ]?.id === selectedConversationId
					? __( 'Selected', 'hey-woo' )
					: __( 'Preview', 'hey-woo' ),
				isPrimary: true,
				context: 'single',
				callback: ( items ) => {
					const conversation = items[ 0 ];

					if ( conversation ) {
						selectConversation( conversation.id );
					}
				},
			},
			{
				id: 'delete-conversations',
				label: ( items ) => sprintf(
					/* translators: %d: number of conversations */
					_n( 'Delete %d conversation', 'Delete %d conversations', items.length, 'hey-woo' ),
					items.length
				),
				icon: trash,
				supportsBulk: true,
				modalHeader: ( items ) => sprintf(
					/* translators: %d: number of conversations */
					_n( 'Delete %d conversation', 'Delete %d conversations', items.length, 'hey-woo' ),
					items.length
				),
				RenderModal: ( { items, closeModal } ) => (
					<DeleteConversationsModal
						items={ items }
						closeModal={ closeModal }
						onDelete={ deleteConversationIds }
					/>
				),
			},
		],
		[ deleteConversationIds, selectConversation, selectedConversationId ]
	);
	const resetView = () => setView( DEFAULT_HISTORY_VIEW );
	const hasConversationHistory = historyItems.length > 0;

	return (
		<div className="hey-woo-page hey-woo-page--history">
			<header className="hey-woo-history-header">
				<div>
					<h1 className="hey-woo-history-header__title">{ __( 'History', 'hey-woo' ) }</h1>
					<p className="hey-woo-history-header__count">
						{ sprintf(
							/* translators: %d: number of visible conversations */
							_n( '%d conversation', '%d conversations', paginationInfo.totalItems, 'hey-woo' ),
							paginationInfo.totalItems
						) }
					</p>
				</div>
				<Button
					type="button"
					variant="primary"
					onClick={ () => {
						void navigate( {
							to: '/',
							search: {},
						} );
					} }
				>
					{ __( 'New chat', 'hey-woo' ) }
				</Button>
			</header>

			<DataViews
				actions={ actions }
				config={ {
					perPageSizes: [ 10, 20, 50 ],
				} }
				data={ shownConversations }
				defaultLayouts={ HISTORY_LAYOUTS }
				empty={
					<div className="hey-woo-history-empty" role="status">
						<p>
							{ hasConversationHistory
								? __( 'No conversations match those filters.', 'hey-woo' )
								: __( 'No conversations yet.', 'hey-woo' ) }
						</p>
						<Button
							type="button"
							variant="secondary"
							onClick={ hasConversationHistory ? resetView : () => {
								void navigate( {
									to: '/',
									search: {},
								} );
							} }
						>
							{ hasConversationHistory
								? __( 'Clear filters', 'hey-woo' )
								: __( 'Start a new chat', 'hey-woo' ) }
						</Button>
					</div>
				}
				fields={ fields }
				getItemId={ ( item ) => item.id }
				isItemClickable={ () => true }
				onChangeSelection={ setSelection }
				onChangeView={ setView }
				onClickItem={ ( item ) => selectConversation( item.id ) }
				onReset={ resetView }
				paginationInfo={ paginationInfo }
				searchLabel={ __( 'Search history', 'hey-woo' ) }
				selection={ selection }
				view={ view }
			/>
		</div>
	);
}
