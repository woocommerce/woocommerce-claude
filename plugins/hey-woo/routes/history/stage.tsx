/**
 * Hey Woo History - conversation management route.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { Button } from '@wordpress/components';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { Icon, commentContent, trash } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate } from '@wordpress/route';
import { useAdaptiveDataViewsPageSize } from '../ai-insights/hooks/useAdaptiveDataViewsPageSize';
import { useConversations } from '../ai-insights/hooks/useConversations';
import {
	conversationSourceLabel,
	toHistoryConversation,
} from './history-data';
import type { HistoryConversation } from './history-data';
import type { Action, Field, View } from '@wordpress/dataviews/wp';

const HISTORY_VISIBLE_FIELDS = [ 'source', 'status', 'messageCount', 'updatedAt' ];
const HISTORY_PAGE_SIZE_OPTIONS = [ 10, 20, 50 ];
const HISTORY_LAYOUTS = {
	table: {
		fields: HISTORY_VISIBLE_FIELDS,
		titleField: 'title',
		showMedia: false,
		layout: {
			density: 'balanced',
			styles: {
				source: { width: '120px' },
				status: { width: '140px' },
				messageCount: { width: '120px' },
				updatedAt: { width: '180px' },
			},
		},
	},
	list: {
		fields: HISTORY_VISIBLE_FIELDS,
		titleField: 'title',
		showMedia: false,
		layout: {
			density: 'balanced',
		},
	},
	grid: {
		fields: HISTORY_VISIBLE_FIELDS,
		titleField: 'title',
		mediaField: 'media',
		showMedia: true,
		layout: {
			badgeFields: [ 'source', 'status' ],
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
	const navigate = useNavigate();
	const [ view, setView ] = useState< View >( DEFAULT_HISTORY_VIEW );
	const [ selection, setSelection ] = useState< string[] >( [] );
	const { conversations, deleteConversations } = useConversations();
	const historyItems = useMemo< HistoryConversation[] >(
		() => conversations.map( toHistoryConversation ),
		[ conversations ]
	);
	const { perPageSizes, rootRef } = useAdaptiveDataViewsPageSize( {
		itemCount: historyItems.length,
		pageSizeOptions: HISTORY_PAGE_SIZE_OPTIONS,
		setView,
		view,
	} );
	const sourceOptions = useMemo(
		() => [
			{ value: 'chat', label: conversationSourceLabel( 'chat' ) },
			{ value: 'report', label: conversationSourceLabel( 'report' ) },
		],
		[]
	);

	const openConversation = useCallback( ( conversationId: string ) => {
		void navigate( {
			to: '/chat',
			search: {
				conversationId,
			},
		} );
	}, [ navigate ] );

	const deleteConversationIds = useCallback( async ( conversationIds: string[] ) => {
		await deleteConversations( conversationIds );
		setSelection( [] );
	}, [ deleteConversations ] );

	const fields = useMemo< Field< HistoryConversation >[] >(
		() => [
			{
				id: 'media',
				label: __( 'Preview', 'hey-woo' ),
				enableHiding: false,
				enableSorting: false,
				render: () => (
					<div className="hey-woo-history-media">
						<Icon icon={ commentContent } size={ 32 } />
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
				render: ( { item } ) => (
					<span className={ `hey-woo-history-source hey-woo-history-source--${ item.source }` }>
						{ item.sourceLabel }
					</span>
				),
			},
			{
				id: 'status',
				label: __( 'Status', 'hey-woo' ),
				enableGlobalSearch: true,
				enableSorting: true,
				getValue: ( { item } ) => item.statusLabel,
				render: ( { item } ) => (
					<span className={ `hey-woo-history-status hey-woo-history-status--${ item.status }` }>
						{ item.statusLabel }
					</span>
				),
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
		[ deleteConversationIds ]
	);
	const resetView = () => setView( DEFAULT_HISTORY_VIEW );
	const hasConversationHistory = historyItems.length > 0;

	return (
		<div ref={ rootRef } className="hey-woo-page hey-woo-page--history">
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
					__next40pxDefaultSize
					onClick={ () => {
						void navigate( {
							to: '/chat',
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
					perPageSizes,
				} }
				data={ shownConversations }
				defaultLayouts={ HISTORY_LAYOUTS }
				empty={
					<div className="hey-woo-history-empty" role="status">
						<p>
							{ hasConversationHistory
								? __( 'No history items match those filters.', 'hey-woo' )
								: __( 'No history items yet.', 'hey-woo' ) }
						</p>
						<Button
							type="button"
							variant="secondary"
							__next40pxDefaultSize
							onClick={ hasConversationHistory ? resetView : () => {
								void navigate( {
									to: '/chat',
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
				onClickItem={ ( item ) => openConversation( item.id ) }
				onReset={ resetView }
				paginationInfo={ paginationInfo }
				searchLabel={ __( 'Search history', 'hey-woo' ) }
				selection={ selection }
				view={ view }
			/>
		</div>
	);
}
