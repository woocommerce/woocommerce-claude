import { DndContext, useDraggable } from '@dnd-kit/core';
import type { DragEndEvent } from '@dnd-kit/core';
import { CSS } from '@dnd-kit/utilities';
import { Button, Dropdown, MenuGroup, MenuItemsChoice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { memo, useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { useIdeaBoard } from './useIdeaBoard';
import type {
	IdeaBoardActionType,
	IdeaBoardAnswerability,
	IdeaBoardCard,
	IdeaBoardCardKind,
	IdeaBoardData,
	IdeaBoardGatePriority,
	IdeaBoardNoteKind,
	IdeaBoardRevenueLever,
	IdeaBoardSession,
} from './types';
import type { CSSProperties, FormEvent } from 'react';

interface IdeaBoardProps {
	restBase: string;
	nonce: string;
	days?: number;
}

type DatePreset = {
	days: number;
	label: string;
	value: string;
};

type BoardPosition = {
	x: number;
	y: number;
	rotation: number;
};

type CanvasItem = {
	card: IdeaBoardCard;
	position: BoardPosition;
	isAnchor: boolean;
};

const MAX_CARDS = 24;
const CANVAS_WIDTH = 920;
const CANVAS_HEIGHT = 780;
const ACTION_TYPES: Array< { value: IdeaBoardActionType; label: string } > = [
	{ value: 'investigate', label: __( 'Investigate', 'woocommerce-claude' ) },
	{ value: 'merchandise', label: __( 'Merchandise', 'woocommerce-claude' ) },
	{ value: 'restock', label: __( 'Restock', 'woocommerce-claude' ) },
	{ value: 'pause_campaign', label: __( 'Pause campaign', 'woocommerce-claude' ) },
	{ value: 'create_offer', label: __( 'Create offer', 'woocommerce-claude' ) },
	{ value: 'improve_product_content', label: __( 'Improve product content', 'woocommerce-claude' ) },
	{ value: 'retention_campaign', label: __( 'Retention campaign', 'woocommerce-claude' ) },
	{ value: 'pricing_test', label: __( 'Pricing test', 'woocommerce-claude' ) },
	{ value: 'checkout_fix', label: __( 'Checkout fix', 'woocommerce-claude' ) },
	{ value: 'customer_follow_up', label: __( 'Customer follow-up', 'woocommerce-claude' ) },
];

export function IdeaBoard( { restBase, nonce, days = 90 }: IdeaBoardProps ) {
	const datePresets = useMemo< DatePreset[] >( () => [
		{
			days: 7,
			label: __( 'Last 7 days', 'woocommerce-claude' ),
			value: '7',
		},
		{
			days: 30,
			label: __( 'Last 30 days', 'woocommerce-claude' ),
			value: '30',
		},
		{
			days: 90,
			label: __( 'Last 90 days', 'woocommerce-claude' ),
			value: '90',
		},
		{
			days: 365,
			label: __( 'Last 365 days', 'woocommerce-claude' ),
			value: '365',
		},
	], [] );
	const initialPreset = datePresets.find( ( preset ) => preset.days === days ) || datePresets[ 2 ];
	const [ selectedDays, setSelectedDays ] = useState( initialPreset.days );
	const activePreset = datePresets.find( ( preset ) => preset.days === selectedDays ) || initialPreset;
	const { board, status, errorMessage, refresh, reanalyse, brainstorm, answerQuestion, saveBoard, updateBoard } = useIdeaBoard( {
		restBase,
		nonce,
		days: selectedDays,
	} );
	const [ selectedInsightIds, setSelectedInsightIds ] = useState< string[] >( [] );
	const [ activeSessionId, setActiveSessionId ] = useState( '' );
	const [ signalsCollapsed, setSignalsCollapsed ] = useState( false );
	const [ noteBody, setNoteBody ] = useState( '' );
	const [ noteKind, setNoteKind ] = useState< IdeaBoardNoteKind >( 'context' );
	const [ noteTargetId, setNoteTargetId ] = useState( '' );
	const [ feedbackMessage, setFeedbackMessage ] = useState( '' );
	const [ boardView, setBoardView ] = useState< 'canvas' | 'decision' >( 'canvas' );
	const [ isCreatingQuestion, setIsCreatingQuestion ] = useState( false );
	const [ questionTitle, setQuestionTitle ] = useState( '' );
	const [ questionBody, setQuestionBody ] = useState( '' );
	const [ isCreatingAction, setIsCreatingAction ] = useState( false );
	const [ actionTitle, setActionTitle ] = useState( '' );
	const [ actionBody, setActionBody ] = useState( '' );
	const [ actionType, setActionType ] = useState< IdeaBoardActionType >( 'investigate' );
	const [ actionImpact, setActionImpact ] = useState< IdeaBoardCard[ 'expectedRevenueImpact' ] >( 'medium' );
	const [ actionEffort, setActionEffort ] = useState< IdeaBoardCard[ 'effort' ] >( 'medium' );
	const [ actionPrimaryMetric, setActionPrimaryMetric ] = useState( '' );
	const [ actionOwner, setActionOwner ] = useState( '' );
	const [ actionReviewDate, setActionReviewDate ] = useState( getDefaultReviewDate() );
	const [ actionSuccessCriteria, setActionSuccessCriteria ] = useState( '' );
	const [ detailCardId, setDetailCardId ] = useState( '' );
	const noteTextareaRef = useRef< HTMLTextAreaElement | null >( null );
	// Timer ref used to coalesce rapid-fire saves (drag moves, scorecard <select> changes)
	// so we don't POST on every individual event.
	const saveDebouncePendingRef = useRef< ReturnType< typeof setTimeout > | null >( null );
	const debouncedSaveBoard = ( updatedBoard: IdeaBoardData, rollbackBoard?: IdeaBoardData, delay = 600 ) => {
		if ( saveDebouncePendingRef.current !== null ) {
			clearTimeout( saveDebouncePendingRef.current );
		}
		saveDebouncePendingRef.current = setTimeout( () => {
			saveDebouncePendingRef.current = null;
			void saveBoard( updatedBoard, rollbackBoard );
		}, delay );
	};
	const isBusy = status === 'loading' || status === 'saving' || status === 'brainstorming' || status === 'reanalysing' || status === 'answering';
	const insights = useMemo( () => getInsightCards( board ), [ board ] );
	const sessions = board?.sessions || [];
	const activeSession = sessions.find( ( session ) => session.id === activeSessionId ) || sessions[0] || null;
	const activeRootIds = activeSession ? activeSession.rootInsightIds : selectedInsightIds;
	const activeInsights = insights.filter( ( insight ) => activeRootIds.includes( insight.id ) );
	const activeSessionCards = activeSession ? cardsForSession( board, activeSession ) : [];
	const questions = activeSessionCards.filter( ( card ) => card.kind === 'question' );
	const sessionActions = activeSessionCards.filter( isVisibleProposedAction );
	const contextCards = activeSessionCards.filter( ( card ) => card.kind === 'context' );
	const notes = activeSession && board ? board.notes.filter( ( note ) => note.sessionId === activeSession.id ) : [];
	const visibleHumanNotes = notes.filter( ( note ) => note.kind !== 'answer' );
	const notesByParent = useMemo( () => groupNotesByParent( notes ), [ notes ] );
	const blockerCount = useMemo( () => countRequiredBlockers( questions, notesByParent ), [ notesByParent, questions ] );
	const canvasItems = useMemo( () => getCanvasItems( activeInsights, questions, contextCards, sessionActions ), [ activeInsights, questions, contextCards, sessionActions ] );
	const canvasPositionMap = useMemo( () => new Map( canvasItems.map( ( item ) => [ item.card.id, item.position ] ) ), [ canvasItems ] );
	const detailItem = detailCardId ? canvasItems.find( ( item ) => item.card.id === detailCardId ) : undefined;
	const globalActions = board ? board.cards
		.filter( ( card ) => isVisibleProposedAction( card ) && card.status === 'accepted' )
		.filter( ( card ) => sessions.some( ( session ) => session.id === card.sessionId ) )
		.sort( sortCards ) : [];
	const displayPeriod = board?.freshness?.currentPeriod || board?.period;
	const showCollapsedSignals = Boolean( activeSession && signalsCollapsed );
	const canStartBrainstorm = Boolean( board && selectedInsightIds.length > 0 && ! isBusy );
	const readyToReanalyse = Boolean( activeSession && (
		activeSession.status === 'ready_to_reanalyse' || notes.some( ( note ) => isAfter( note.createdAt, activeSession.lastAnalysedAt ) )
	) );
	const canReanalyse = Boolean( board && activeSession && readyToReanalyse && ! isBusy );

	useEffect( () => {
		if ( ! board ) {
			return;
		}
		if ( activeSessionId && board.sessions.some( ( session ) => session.id === activeSessionId ) ) {
			return;
		}
		const firstSession = board.sessions[0];
		if ( firstSession ) {
			setActiveSessionId( firstSession.id );
			setSelectedInsightIds( firstSession.rootInsightIds );
		}
	}, [ activeSessionId, board ] );

	useEffect( () => {
		if ( ! feedbackMessage ) {
			return undefined;
		}
		const timeout = window.setTimeout( () => setFeedbackMessage( '' ), 4200 );
		return () => window.clearTimeout( timeout );
	}, [ feedbackMessage ] );

	const selectDatePreset = ( nextDays: number ) => {
		setSelectedDays( nextDays );
		setSelectedInsightIds( [] );
		setActiveSessionId( '' );
		setSignalsCollapsed( false );
		setNoteBody( '' );
		setNoteTargetId( '' );
		setIsCreatingQuestion( false );
		setQuestionTitle( '' );
		setQuestionBody( '' );
		setIsCreatingAction( false );
		setActionTitle( '' );
		setActionBody( '' );
		setActionType( 'investigate' );
		setActionImpact( 'medium' );
		setActionEffort( 'medium' );
		setActionPrimaryMetric( '' );
		setActionOwner( '' );
		setActionReviewDate( getDefaultReviewDate() );
		setActionSuccessCriteria( '' );
		setDetailCardId( '' );
	};

	const toggleInsight = useCallback( ( insightId: string ) => {
		setSelectedInsightIds( ( previous ) => previous.includes( insightId )
			? previous.filter( ( id ) => id !== insightId )
			: [ ...previous, insightId ]
		);
	}, [] );

	const openSession = ( session: IdeaBoardSession ) => {
		setActiveSessionId( session.id );
		setSelectedInsightIds( session.rootInsightIds );
		setNoteTargetId( '' );
		setFeedbackMessage( '' );
		setSignalsCollapsed( true );
		setIsCreatingQuestion( false );
		setIsCreatingAction( false );
		setBoardView( 'canvas' );
		setDetailCardId( '' );
	};

	const startBrainstorm = async () => {
		if ( ! board || selectedInsightIds.length === 0 ) {
			return;
		}

		const nextBoard = await brainstorm( board, selectedInsightIds );
		const nextSession = nextBoard?.sessions[ nextBoard.sessions.length - 1 ];
		if ( nextSession ) {
			setActiveSessionId( nextSession.id );
			setSelectedInsightIds( nextSession.rootInsightIds );
			setNoteTargetId( '' );
			setSignalsCollapsed( true );
			setIsCreatingQuestion( false );
			setIsCreatingAction( false );
			setBoardView( 'canvas' );
			setDetailCardId( '' );
			setFeedbackMessage( __( 'Brainstorm started. Add answers or context, then re-analyse to turn it into draft actions.', 'woocommerce-claude' ) );
		}
	};

	const prepareAnswer = ( card: IdeaBoardCard ) => {
		setNoteKind( 'answer' );
		setNoteTargetId( card.id );
		setFeedbackMessage( __( 'Add the answer below. The session will be ready to re-analyse once it is saved.', 'woocommerce-claude' ) );
		window.setTimeout( () => noteTextareaRef.current?.focus(), 0 );
	};

	const addNote = ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		if ( ! board || ! activeSession ) {
			return;
		}

		const body = noteBody.trim();
		if ( ! body ) {
			return;
		}

		const now = new Date().toISOString();
		const targetCard = board.cards.find( ( card ) => card.id === noteTargetId );
		const noteRootIds = targetCard
			? rootsForCard( targetCard, activeSession.rootInsightIds )
			: activeSession.rootInsightIds;
		const note = {
			id: createLocalId( 'note', board.notes.map( ( item ) => item.id ) ),
			sessionId: activeSession.id,
			rootInsightIds: noteRootIds,
			parentCardId: targetCard?.id || '',
			body: truncateText( body, 600 ),
			kind: noteKind,
			createdBy: 'merchant' as const,
			authorName: __( 'Store team', 'woocommerce-claude' ),
			createdAt: now,
		};
		const updatedBoard: IdeaBoardData = {
			...board,
			notes: [ ...board.notes, note ],
			sessions: board.sessions.map( ( session ) => session.id === activeSession.id
				? {
					...session,
					status: 'ready_to_reanalyse',
					updatedAt: now,
				}
				: session
			),
		};

		updateBoard( updatedBoard );
		void saveBoard( updatedBoard, board );
		setNoteBody( '' );
		setNoteTargetId( '' );
		setFeedbackMessage( __( 'Context added. Re-analyse is ready when you want AI to refine the actions.', 'woocommerce-claude' ) );
	};

	const createQuestion = ( event?: FormEvent< HTMLFormElement > ) => {
		event?.preventDefault();
		if ( ! board || ! activeSession ) {
			return;
		}
		if ( board.cards.length >= MAX_CARDS ) {
			setFeedbackMessage( __( 'This board is full. Remove a card before adding another question.', 'woocommerce-claude' ) );
			return;
		}

		const title = questionTitle.trim();
		const body = questionBody.trim();
		if ( ! title || ! body ) {
			setIsCreatingQuestion( true );
			setFeedbackMessage( __( 'Add a question and a little context before placing it on the board.', 'woocommerce-claude' ) );
			return;
		}
		if ( ! isStoreRelatedQuestion( `${ title } ${ body }` ) ) {
			setFeedbackMessage( __( 'Ask a question about your store, products, customers, orders, marketing, operations, or the selected insight.', 'woocommerce-claude' ) );
			return;
		}

		const now = new Date().toISOString();
		const rootIds = activeSession.rootInsightIds;
		const card: IdeaBoardCard = {
			id: createLocalId( 'question', board.cards.map( ( item ) => item.id ) ),
			kind: 'question',
			stage: 'investigate',
			title: truncateText( title, 80 ),
			body: truncateText( body, 240 ),
			colour: 'blue',
			order: nextOrderForKind( board, 'question' ),
			prompt: title,
			confidence: 'medium',
			evidence: '',
			timeframe: board.period.label,
			source: __( 'Merchant question', 'woocommerce-claude' ),
			status: 'merchant_input_needed',
			approvalRequired: false,
			revenueLevers: mergeRevenueLevers( activeInsights ),
			severity: 'low',
			estimatedImpact: '',
			whyItMatters: '',
			relatedSignalIds: rootIds,
			gatePriority: 'required',
			answerability: 'merchant',
			actionType: 'investigate',
			expectedRevenueImpact: 'medium',
			effort: 'medium',
			timeToImpact: '',
			riskApprovalNeeded: '',
			primaryMetric: '',
			owner: '',
			reviewDate: '',
			successCriteria: '',
			iceScore: 0,
			evidenceDetails: emptyEvidenceDetails(),
			rootInsightId: rootIds[0] || '',
			rootInsightIds: rootIds,
			sessionId: activeSession.id,
			parentCardId: rootIds[0] || '',
			createdBy: 'merchant',
			x: 340,
			y: 260 + ( questions.length % 3 ) * 120,
			rotation: -1,
		};
		const updatedBoard: IdeaBoardData = {
			...board,
			cards: [ ...board.cards, card ],
			links: [ ...board.links, ...rootIds.map( ( rootId ) => ( {
				from: rootId,
				to: card.id,
				label: __( 'Linked insight', 'woocommerce-claude' ),
			} ) ) ],
			sessions: board.sessions.map( ( session ) => session.id === activeSession.id
				? {
					...session,
					status: 'ready_to_reanalyse',
					updatedAt: now,
				}
				: session
			),
		};

		updateBoard( updatedBoard );
		void saveBoard( updatedBoard, board );
		setQuestionTitle( '' );
		setQuestionBody( '' );
		setIsCreatingQuestion( false );
		setDetailCardId( card.id );
		setFeedbackMessage( __( 'Question added. Answer it with the team or ask AI when the board has enough context.', 'woocommerce-claude' ) );
	};

	const answerQuestionWithAI = async ( card: IdeaBoardCard ) => {
		if ( ! board || card.kind !== 'question' ) {
			return;
		}

		const nextBoard = await answerQuestion( board, card.id );
		if ( nextBoard ) {
			setDetailCardId( card.id );
			setFeedbackMessage( __( 'AI added an answer to the question. Review it before re-analysing.', 'woocommerce-claude' ) );
		}
	};

	const createAction = ( event?: FormEvent< HTMLFormElement > ) => {
		event?.preventDefault();
		if ( ! board || ! activeSession ) {
			return;
		}
		if ( board.cards.length >= MAX_CARDS ) {
			setFeedbackMessage( __( 'This board is full. Remove a question or draft before adding another action.', 'woocommerce-claude' ) );
			return;
		}

		const title = actionTitle.trim();
		const body = actionBody.trim();
		const primaryMetric = actionPrimaryMetric.trim();
		const successCriteria = actionSuccessCriteria.trim();
		if ( ! title || ! body || ! primaryMetric || ! successCriteria ) {
			setIsCreatingAction( true );
			setFeedbackMessage( __( 'Add an action, primary metric, and success criteria before creating it.', 'woocommerce-claude' ) );
			return;
		}

		const now = new Date().toISOString();
		const rootIds = activeSession.rootInsightIds;
		const confidence = 'medium' as const;
		const reviewDate = actionReviewDate || getDefaultReviewDate();
		const card: IdeaBoardCard = {
			id: createLocalId( 'action', board.cards.map( ( item ) => item.id ) ),
			kind: 'action',
			stage: 'proposed_actions',
			title: truncateText( title, 80 ),
			body: truncateText( body, 240 ),
			colour: 'lime',
			order: nextOrderForKind( board, 'action' ),
			prompt: __( 'Review this as a merchant-approved draft action.', 'woocommerce-claude' ),
			confidence,
			evidence: '',
			evidenceDetails: emptyEvidenceDetails(),
			timeframe: board.period.label,
			source: __( 'Merchant draft', 'woocommerce-claude' ),
			status: 'approval_required',
			approvalRequired: true,
			revenueLevers: mergeRevenueLevers( activeInsights ),
			severity: 'low',
			estimatedImpact: '',
			whyItMatters: '',
			relatedSignalIds: rootIds,
			gatePriority: 'optional',
			answerability: 'both',
			actionType,
			expectedRevenueImpact: actionImpact,
			effort: actionEffort,
			timeToImpact: '',
			riskApprovalNeeded: __( 'Merchant approval needed before changing spend, stock, prices, customer contact, or publishing.', 'woocommerce-claude' ),
			primaryMetric: truncateText( primaryMetric, 120 ),
			owner: truncateText( actionOwner.trim(), 80 ),
			reviewDate,
			successCriteria: truncateText( successCriteria, 180 ),
			iceScore: computeIceScore( actionImpact, confidence, actionEffort ),
			rootInsightId: rootIds[0] || '',
			rootInsightIds: rootIds,
			sessionId: activeSession.id,
			parentCardId: '',
			createdBy: 'merchant',
			x: 620,
			y: 380,
			rotation: 1,
		};
		const updatedBoard: IdeaBoardData = {
			...board,
			cards: [ ...board.cards, card ],
			links: [ ...board.links, ...rootIds.map( ( rootId ) => ( {
				from: rootId,
				to: card.id,
				label: __( 'Linked insight', 'woocommerce-claude' ),
			} ) ) ],
			sessions: board.sessions.map( ( session ) => session.id === activeSession.id
				? {
					...session,
					status: 'ready_to_reanalyse',
					updatedAt: now,
				}
				: session
			),
		};

		updateBoard( updatedBoard );
		void saveBoard( updatedBoard, board );
		setActionTitle( '' );
		setActionBody( '' );
		setActionType( 'investigate' );
		setActionImpact( 'medium' );
		setActionEffort( 'medium' );
		setActionPrimaryMetric( '' );
		setActionOwner( '' );
		setActionReviewDate( getDefaultReviewDate() );
		setActionSuccessCriteria( '' );
		setIsCreatingAction( false );
		setFeedbackMessage( __( 'Proposed action added to the brainstorm board. Accept it when it is ready for the queue.', 'woocommerce-claude' ) );
	};

	const removeCard = ( card: IdeaBoardCard ) => {
		if ( ! board || ! activeSession || card.kind === 'insight' ) {
			return;
		}

		const now = new Date().toISOString();
		const updatedBoard: IdeaBoardData = {
			...board,
			cards: board.cards.filter( ( item ) => item.id !== card.id ),
			links: board.links.filter( ( link ) => link.from !== card.id && link.to !== card.id ),
			notes: board.notes.map( ( note ) => note.parentCardId === card.id
				? {
					...note,
					parentCardId: '',
				}
				: note
			),
			sessions: board.sessions.map( ( session ) => session.id === activeSession.id
				? {
					...session,
					status: 'ready_to_reanalyse',
					updatedAt: now,
				}
				: session
			),
		};

		updateBoard( updatedBoard );
		void saveBoard( updatedBoard, board );
		if ( detailCardId === card.id ) {
			setDetailCardId( '' );
		}
		setFeedbackMessage( __( 'Card removed. Re-analyse is ready so AI can respond to the changed workspace.', 'woocommerce-claude' ) );
	};

	const acceptAction = ( card: IdeaBoardCard ) => {
		if ( ! board || ! activeSession || card.kind !== 'action' ) {
			return;
		}
		if ( ! isActionScorecardComplete( card ) ) {
			setDetailCardId( card.id );
			setFeedbackMessage( __( 'Complete the action scorecard before accepting this draft.', 'woocommerce-claude' ) );
			return;
		}

		const now = new Date().toISOString();
		const updatedBoard: IdeaBoardData = {
			...board,
			cards: board.cards.map( ( item ) => item.id === card.id
				? {
					...item,
					status: 'accepted',
					approvalRequired: false,
				}
				: item
			),
			sessions: board.sessions.map( ( session ) => session.id === activeSession.id
				? {
					...session,
					updatedAt: now,
				}
				: session
			),
		};

		updateBoard( updatedBoard );
		void saveBoard( updatedBoard, board );
		setFeedbackMessage( __( 'Action accepted and added to the queue.', 'woocommerce-claude' ) );
	};

	const updateActionMetadata = ( card: IdeaBoardCard, updates: Partial< IdeaBoardCard > ) => {
		if ( ! board || card.kind !== 'action' ) {
			return;
		}

		const updatedBoard: IdeaBoardData = {
			...board,
			cards: board.cards.map( ( item ) => {
				if ( item.id !== card.id ) {
					return item;
				}
				const nextCard = {
					...item,
					...updates,
				};
				return {
					...nextCard,
					iceScore: computeIceScore( nextCard.expectedRevenueImpact, nextCard.confidence, nextCard.effort ),
				};
			} ),
		};

		updateBoard( updatedBoard );
		debouncedSaveBoard( updatedBoard, board );
		setFeedbackMessage( __( 'Action scorecard updated.', 'woocommerce-claude' ) );
	};

	const updateCardPosition = ( cardId: string, position: BoardPosition ) => {
		if ( ! board ) {
			return;
		}

		const updatedBoard: IdeaBoardData = {
			...board,
			cards: board.cards.map( ( card ) => card.id === cardId
				? {
					...card,
					x: clamp( Math.round( position.x ), 0, CANVAS_WIDTH - 220 ),
					y: clamp( Math.round( position.y ), 0, CANVAS_HEIGHT - 150 ),
					rotation: position.rotation,
				}
				: card
			),
		};

		updateBoard( updatedBoard );
		debouncedSaveBoard( updatedBoard, board );
	};

	const handleCanvasDragEnd = ( event: DragEndEvent ) => {
		if ( ! event.delta.x && ! event.delta.y ) {
			return;
		}
		const cardId = String( event.active.id );
		const currentPosition = canvasPositionMap.get( cardId );
		if ( ! currentPosition ) {
			return;
		}

		updateCardPosition( cardId, {
			...currentPosition,
			x: currentPosition.x + event.delta.x,
			y: currentPosition.y + event.delta.y,
		} );
	};

	return (
		<div className="hey-woo-idea-page">
			<header className="hey-woo-idea-header">
				<div className="hey-woo-idea-header__summary">
					<div className="hey-woo-idea-header__title">
						<h1>{ __( 'Idea board', 'woocommerce-claude' ) }</h1>
						<span>{ __( 'AI', 'woocommerce-claude' ) }</span>
					</div>
					<div className="hey-woo-idea-header__meta">
						<DatePresetDropdown
							label={ activePreset.label }
							presets={ datePresets }
							value={ activePreset.value }
							onSelect={ selectDatePreset }
							disabled={ isBusy }
						/>
						{ displayPeriod && (
							<span className="hey-woo-idea-header__date-range">
								{ sprintf(
									/* translators: 1: start date, 2: end date. */
									__( '%1$s to %2$s', 'woocommerce-claude' ),
									formatDate( displayPeriod.start ),
									formatDate( displayPeriod.end )
								) }
							</span>
						) }
					</div>
				</div>
				<div className="hey-woo-idea-header__actions">
					<Button variant="secondary" onClick={ () => refresh() } disabled={ isBusy }>
						{ status === 'loading'
							? __( 'Refreshing', 'woocommerce-claude' )
							: __( 'Refresh signals', 'woocommerce-claude' ) }
					</Button>
					<Button variant="primary" onClick={ startBrainstorm } disabled={ ! canStartBrainstorm }>
						{ status === 'brainstorming'
							? __( 'Starting brainstorm', 'woocommerce-claude' )
							: __( 'Start brainstorm', 'woocommerce-claude' ) }
					</Button>
				</div>
			</header>

			{ status === 'error' && (
				<div className="hey-woo-idea-error" role="alert">
					{ errorMessage }
				</div>
			) }

			{ feedbackMessage && (
				<div className="hey-woo-idea-feedback" role="status">
					{ feedbackMessage }
				</div>
			) }

			{ board?.freshness?.isStale && (
				<div className="hey-woo-idea-stale" role="status">
					<span>
						{ sprintf(
							/* translators: 1: saved period label, 2: saved start date, 3: saved end date, 4: current start date, 5: current end date. */
							__(
								'This board still shows %1$s, %2$s to %3$s. Refresh to update it to %4$s to %5$s.',
								'woocommerce-claude'
							),
							board.period.label,
							formatDate( board.period.start ),
							formatDate( board.period.end ),
							formatDate( board.freshness.currentPeriod.start ),
							formatDate( board.freshness.currentPeriod.end )
						) }
					</span>
					<Button variant="secondary" onClick={ () => refresh() } disabled={ isBusy }>
						{ __( 'Refresh board', 'woocommerce-claude' ) }
					</Button>
				</div>
			) }

			{ status === 'loading' && ! board && (
				<div className="hey-woo-idea-loading">
					<span className="hey-woo-idea-loading__pin" />
					{ __( 'Building the board...', 'woocommerce-claude' ) }
				</div>
			) }

			{ board && (
				<main className={ `hey-woo-idea-workspace${ showCollapsedSignals ? ' is-signals-collapsed' : '' }` }>
					<aside className={ `hey-woo-idea-signals${ showCollapsedSignals ? ' is-collapsed' : '' }` } aria-label={ __( 'Store signals', 'woocommerce-claude' ) }>
						<div className="hey-woo-idea-signals__top">
							{ ! showCollapsedSignals && (
								<PanelHeader
									title={ __( 'Store signals', 'woocommerce-claude' ) }
									count={ insights.length }
									description={ __( 'Concrete AI insights from the selected period.', 'woocommerce-claude' ) }
								/>
							) }
							{ activeSession && (
								<Button variant="tertiary" onClick={ () => setSignalsCollapsed( ( value ) => ! value ) } disabled={ isBusy }>
									{ showCollapsedSignals ? __( 'Expand', 'woocommerce-claude' ) : __( 'Collapse', 'woocommerce-claude' ) }
								</Button>
							) }
						</div>
						{ showCollapsedSignals ? (
							<button
								type="button"
								className="hey-woo-idea-signals__collapsed"
								onClick={ () => setSignalsCollapsed( false ) }
							>
								<strong>{ insights.length }</strong>
								<span>{ __( 'Signals', 'woocommerce-claude' ) }</span>
							</button>
						) : (
							<>
								<div className="hey-woo-idea-signals__list">
									{ insights.map( ( insight ) => (
										<SignalCard
											key={ insight.id }
											insight={ insight }
											isSelected={ selectedInsightIds.includes( insight.id ) }
											isRelated={ activeRootIds.some( ( rootId ) => insight.relatedSignalIds.includes( rootId ) || rootId === insight.id ) && ! selectedInsightIds.includes( insight.id ) }
											onToggle={ toggleInsight }
										/>
									) ) }
								</div>
								<div className="hey-woo-idea-signals__actions">
									<Button variant="primary" onClick={ startBrainstorm } disabled={ ! canStartBrainstorm }>
										{ __( 'Start brainstorm', 'woocommerce-claude' ) }
									</Button>
									{ selectedInsightIds.length > 0 && (
										<Button variant="tertiary" onClick={ () => setSelectedInsightIds( [] ) } disabled={ isBusy }>
											{ __( 'Clear', 'woocommerce-claude' ) }
										</Button>
									) }
								</div>
								{ sessions.length > 0 && (
									<div className="hey-woo-idea-sessions">
										<h2>{ __( 'Sessions', 'woocommerce-claude' ) }</h2>
										{ sessions.map( ( session ) => (
											<button
												key={ session.id }
												type="button"
												className={ `hey-woo-idea-session-link${ activeSession?.id === session.id ? ' is-active' : '' }` }
												onClick={ () => openSession( session ) }
											>
												<span>{ session.title }</span>
												<StatusPill status={ session.status } />
											</button>
										) ) }
									</div>
								) }
							</>
						) }
					</aside>

					<section className="hey-woo-idea-session" aria-label={ __( 'Brainstorm session', 'woocommerce-claude' ) }>
						{ activeSession ? (
								<>
									<header className="hey-woo-idea-session__header">
										<div>
											<div className="hey-woo-idea-session__eyebrow">
												{ __( 'Brainstorm board', 'woocommerce-claude' ) }
												<StatusPill status={ activeSession.status } />
												<span>{ signalCountLabel( activeInsights.length ) }</span>
											</div>
											<h2>{ __( 'Decide what to do next', 'woocommerce-claude' ) }</h2>
											<p>{ __( 'Investigate store signals, add context, and turn the best ideas into revenue actions.', 'woocommerce-claude' ) }</p>
											{ ! readyToReanalyse && (
												<p className="hey-woo-idea-session__hint">
													{ __( 'Answer a question or add context to make this ready for re-analysis.', 'woocommerce-claude' ) }
												</p>
											) }
										</div>
										<div className="hey-woo-idea-session__actions">
											<div className="hey-woo-idea-view-toggle" role="group" aria-label={ __( 'Board view', 'woocommerce-claude' ) }>
												<Button variant={ boardView === 'canvas' ? 'primary' : 'secondary' } onClick={ () => setBoardView( 'canvas' ) } disabled={ isBusy }>
													{ __( 'Canvas', 'woocommerce-claude' ) }
												</Button>
												<Button variant={ boardView === 'decision' ? 'primary' : 'secondary' } onClick={ () => setBoardView( 'decision' ) } disabled={ isBusy }>
													{ __( 'Decision', 'woocommerce-claude' ) }
												</Button>
											</div>
											<Button variant="secondary" onClick={ () => setIsCreatingQuestion( ( value ) => ! value ) } disabled={ isBusy || board.cards.length >= MAX_CARDS }>
												{ __( 'Create question', 'woocommerce-claude' ) }
											</Button>
											<Button variant="secondary" onClick={ () => setIsCreatingAction( ( value ) => ! value ) } disabled={ isBusy || board.cards.length >= MAX_CARDS }>
												{ __( 'Create action', 'woocommerce-claude' ) }
											</Button>
											<Button variant={ readyToReanalyse ? 'primary' : 'secondary' } onClick={ () => board && reanalyse( board ) } disabled={ ! canReanalyse }>
												{ status === 'reanalysing'
													? __( 'Re-analysing', 'woocommerce-claude' )
													: blockerCount > 0
														? sprintf(
															/* translators: %d: blocker count. */
															blockerCount === 1 ? __( '%d blocker left before draft actions', 'woocommerce-claude' ) : __( '%d blockers left before draft actions', 'woocommerce-claude' ),
															blockerCount
														)
														: __( 'Re-analyse with context', 'woocommerce-claude' ) }
											</Button>
										</div>
									</header>

									<DecisionBriefPanel brief={ activeSession.decisionBrief || board.decisionBrief } />

									{ readyToReanalyse && (
									<div className="hey-woo-idea-ready" role="status">
										<strong>{ __( 'Ready to re-analyse', 'woocommerce-claude' ) }</strong>
										<span>{ __( 'New context is available for AI to refine the summary and draft actions.', 'woocommerce-claude' ) }</span>
										</div>
									) }

									{ isCreatingQuestion && (
										<form className="hey-woo-idea-board-form" onSubmit={ createQuestion }>
											<label>
												<span>{ __( 'Question', 'woocommerce-claude' ) }</span>
												<input
													type="text"
													value={ questionTitle }
													onChange={ ( event ) => setQuestionTitle( event.currentTarget.value ) }
													maxLength={ 80 }
													disabled={ isBusy }
												/>
											</label>
											<label>
												<span>{ __( 'Why ask it', 'woocommerce-claude' ) }</span>
												<textarea
													value={ questionBody }
													onChange={ ( event ) => setQuestionBody( event.currentTarget.value ) }
													rows={ 2 }
													maxLength={ 240 }
													disabled={ isBusy }
												/>
											</label>
											<div className="hey-woo-idea-note-form__actions">
												<Button variant="primary" type="submit" disabled={ isBusy || ! questionTitle.trim() || ! questionBody.trim() }>
													{ __( 'Add to board', 'woocommerce-claude' ) }
												</Button>
												<Button variant="tertiary" type="button" onClick={ () => setIsCreatingQuestion( false ) } disabled={ isBusy }>
													{ __( 'Cancel', 'woocommerce-claude' ) }
												</Button>
											</div>
										</form>
									) }

									{ isCreatingAction && (
										<form className="hey-woo-idea-board-form" onSubmit={ createAction }>
											<label>
												<span>{ __( 'Action', 'woocommerce-claude' ) }</span>
												<input
													type="text"
													value={ actionTitle }
													onChange={ ( event ) => setActionTitle( event.currentTarget.value ) }
													maxLength={ 80 }
													disabled={ isBusy }
												/>
											</label>
											<label>
												<span>{ __( 'Why this helps', 'woocommerce-claude' ) }</span>
												<textarea
													value={ actionBody }
													onChange={ ( event ) => setActionBody( event.currentTarget.value ) }
													rows={ 2 }
													maxLength={ 240 }
													disabled={ isBusy }
												/>
											</label>
											<label>
												<span>{ __( 'Type', 'woocommerce-claude' ) }</span>
												<select value={ actionType } onChange={ ( event ) => setActionType( event.currentTarget.value as IdeaBoardActionType ) } disabled={ isBusy }>
													{ ACTION_TYPES.map( ( option ) => (
														<option key={ option.value } value={ option.value }>{ option.label }</option>
													) ) }
												</select>
											</label>
											<label>
												<span>{ __( 'Impact', 'woocommerce-claude' ) }</span>
												<select value={ actionImpact } onChange={ ( event ) => setActionImpact( event.currentTarget.value as IdeaBoardCard[ 'expectedRevenueImpact' ] ) } disabled={ isBusy }>
													<ScoreOptions />
												</select>
											</label>
											<label>
												<span>{ __( 'Effort', 'woocommerce-claude' ) }</span>
												<select value={ actionEffort } onChange={ ( event ) => setActionEffort( event.currentTarget.value as IdeaBoardCard[ 'effort' ] ) } disabled={ isBusy }>
													<ScoreOptions />
												</select>
											</label>
											<label>
												<span>{ __( 'Primary metric', 'woocommerce-claude' ) }</span>
												<input
													type="text"
													value={ actionPrimaryMetric }
													onChange={ ( event ) => setActionPrimaryMetric( event.currentTarget.value ) }
													maxLength={ 120 }
													disabled={ isBusy }
												/>
											</label>
											<label>
												<span>{ __( 'Owner', 'woocommerce-claude' ) }</span>
												<input
													type="text"
													value={ actionOwner }
													onChange={ ( event ) => setActionOwner( event.currentTarget.value ) }
													maxLength={ 80 }
													placeholder={ __( 'Unassigned', 'woocommerce-claude' ) }
													disabled={ isBusy }
												/>
											</label>
											<label>
												<span>{ __( 'Review date', 'woocommerce-claude' ) }</span>
												<input
													type="date"
													value={ actionReviewDate }
													onChange={ ( event ) => setActionReviewDate( event.currentTarget.value ) }
													disabled={ isBusy }
												/>
											</label>
											<label className="hey-woo-idea-board-form__wide">
												<span>{ __( 'Success criteria', 'woocommerce-claude' ) }</span>
												<textarea
													value={ actionSuccessCriteria }
													onChange={ ( event ) => setActionSuccessCriteria( event.currentTarget.value ) }
													rows={ 2 }
													maxLength={ 180 }
													disabled={ isBusy }
												/>
											</label>
											<div className="hey-woo-idea-note-form__actions">
												<Button variant="primary" type="submit" disabled={ isBusy || ! actionTitle.trim() || ! actionBody.trim() || ! actionPrimaryMetric.trim() || ! actionSuccessCriteria.trim() }>
													{ __( 'Add to board', 'woocommerce-claude' ) }
												</Button>
												<Button variant="tertiary" type="button" onClick={ () => setIsCreatingAction( false ) } disabled={ isBusy }>
													{ __( 'Cancel', 'woocommerce-claude' ) }
												</Button>
											</div>
										</form>
									) }

									{ boardView === 'canvas' ? (
										<BrainstormCanvas
											items={ canvasItems }
											notesByParent={ notesByParent }
											detailItem={ detailItem || null }
											isBusy={ isBusy }
											minimiseAnswered={ activeSession.status === 'analysed' }
											onDragEnd={ handleCanvasDragEnd }
											onAnswer={ prepareAnswer }
											onAnswerWithAI={ answerQuestionWithAI }
											onDetails={ ( card ) => setDetailCardId( card.id ) }
											onCloseDetails={ () => setDetailCardId( '' ) }
											onRemove={ removeCard }
											onAcceptAction={ acceptAction }
											onUpdateActionMetadata={ updateActionMetadata }
										/>
									) : (
										<DecisionView
											insights={ activeInsights }
											questions={ questions }
											contextCards={ contextCards }
											actions={ sessionActions }
											notesByParent={ notesByParent }
											isBusy={ isBusy }
											onAnswer={ prepareAnswer }
											onAnswerWithAI={ answerQuestionWithAI }
											onDetails={ ( card ) => setDetailCardId( card.id ) }
											onRemove={ removeCard }
											onAcceptAction={ acceptAction }
										/>
									) }
									{ boardView === 'decision' && detailItem && (
										<CardDetailPanel
											card={ detailItem.card }
											notes={ notesByParent.get( detailItem.card.id ) || [] }
											onClose={ () => setDetailCardId( '' ) }
											onAnswer={ prepareAnswer }
											onAnswerWithAI={ answerQuestionWithAI }
											isBusy={ isBusy }
											onUpdateActionMetadata={ updateActionMetadata }
										/>
									) }

									<section className="hey-woo-idea-section">
										<SectionTitle title={ __( 'Human context', 'woocommerce-claude' ) } count={ visibleHumanNotes.length + contextCards.length } />
									<form className="hey-woo-idea-note-form" onSubmit={ addNote }>
										<div className="hey-woo-idea-note-form__row">
											<label>
												<span>{ __( 'Type', 'woocommerce-claude' ) }</span>
												<select value={ noteKind } onChange={ ( event ) => setNoteKind( event.currentTarget.value as IdeaBoardNoteKind ) } disabled={ isBusy }>
													<option value="context">{ __( 'Context', 'woocommerce-claude' ) }</option>
													<option value="answer">{ __( 'Answer', 'woocommerce-claude' ) }</option>
													<option value="note">{ __( 'Note', 'woocommerce-claude' ) }</option>
												</select>
											</label>
											<label>
												<span>{ __( 'Attach to', 'woocommerce-claude' ) }</span>
												<select value={ noteTargetId } onChange={ ( event ) => setNoteTargetId( event.currentTarget.value ) } disabled={ isBusy }>
													<option value="">{ __( 'Whole session', 'woocommerce-claude' ) }</option>
													{ activeInsights.map( ( insight ) => (
														<option key={ insight.id } value={ insight.id }>{ insight.title }</option>
													) ) }
													{ questions.map( ( question ) => (
														<option key={ question.id } value={ question.id }>{ question.title }</option>
													) ) }
												</select>
											</label>
										</div>
										<textarea
											ref={ noteTextareaRef }
											value={ noteBody }
											onChange={ ( event ) => setNoteBody( event.currentTarget.value ) }
											rows={ 3 }
											maxLength={ 600 }
											disabled={ isBusy }
										/>
										<div className="hey-woo-idea-note-form__actions">
												<Button variant="secondary" type="submit" disabled={ isBusy || ! noteBody.trim() }>
													{ noteKind === 'answer'
														? __( 'Add answer', 'woocommerce-claude' )
														: __( 'Add context', 'woocommerce-claude' ) }
												</Button>
											</div>
										</form>
										<div className="hey-woo-idea-note-list">
											{ visibleHumanNotes.map( ( note ) => (
												<article key={ note.id } className="hey-woo-idea-note">
													<div>
														<span>{ noteKindLabel( note.kind ) }</span>
														<strong>{ note.authorName }</strong>
														{ note.parentCardId && (
															<em>{ cardTitleById( note.parentCardId, board.cards ) }</em>
														) }
													</div>
													<p>{ note.body }</p>
												</article>
											) ) }
											{ visibleHumanNotes.length === 0 && <EmptyLine text={ __( 'No human context yet. Answers stay attached to their question cards.', 'woocommerce-claude' ) } /> }
										</div>
									</section>
								</>
						) : (
							<div className="hey-woo-idea-empty-session">
								<h2>{ __( 'No brainstorm session yet', 'woocommerce-claude' ) }</h2>
								{ activeInsights.length > 0 ? (
									<>
										<div className="hey-woo-idea-insight-grid">
											{ activeInsights.map( ( insight ) => (
												<InsightDetail key={ insight.id } insight={ insight } compact />
											) ) }
										</div>
										<Button variant="primary" onClick={ startBrainstorm } disabled={ ! canStartBrainstorm }>
											{ __( 'Start brainstorm', 'woocommerce-claude' ) }
										</Button>
									</>
								) : (
									<p>{ __( 'Select one or more store signals.', 'woocommerce-claude' ) }</p>
								) }
							</div>
						) }
					</section>

					<aside className="hey-woo-idea-actions" aria-label={ __( 'Action queue', 'woocommerce-claude' ) }>
						<PanelHeader
							title={ __( 'Action queue', 'woocommerce-claude' ) }
							count={ globalActions.length }
							description={ __( 'Accepted actions from brainstorm sessions.', 'woocommerce-claude' ) }
						/>
						<div className="hey-woo-idea-action-list">
							{ globalActions.map( ( action ) => {
								const session = sessions.find( ( item ) => item.id === action.sessionId );
								return (
									<article key={ action.id } className="hey-woo-idea-action">
										<div className="hey-woo-idea-action__icon" aria-hidden="true">{ actionIcon( action ) }</div>
										<div>
											<h3>{ action.title }</h3>
											<p>{ action.body }</p>
											<div className="hey-woo-idea-action__meta">
												{ session && (
													<button type="button" onClick={ () => openSession( session ) }>
														{ session.title }
													</button>
												) }
												<span>{ __( 'Accepted', 'woocommerce-claude' ) }</span>
												<span>{ actionTypeLabel( action.actionType ) }</span>
												<span>{ sprintf(
													/* translators: %d: ICE score. */
													__( 'ICE %d', 'woocommerce-claude' ),
													action.iceScore
												) }</span>
												{ action.primaryMetric && <span>{ action.primaryMetric }</span> }
											</div>
										</div>
									</article>
								);
							} ) }
							{ globalActions.length === 0 && <EmptyLine text={ __( 'No accepted actions yet.', 'woocommerce-claude' ) } /> }
						</div>
					</aside>
				</main>
			) }
		</div>
	);
}

function DatePresetDropdown( {
	label,
	presets,
	value,
	onSelect,
	disabled,
}: {
	label: string;
	presets: DatePreset[];
	value: string;
	onSelect: ( days: number ) => void;
	disabled: boolean;
} ) {
	const choices = useMemo( () => presets.map( ( preset ) => ( {
		label: preset.label,
		value: preset.value,
	} ) ), [ presets ] );

	return (
		<Dropdown
			className="hey-woo-idea-date"
			contentClassName="hey-woo-idea-date__popover"
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<Button
					className="hey-woo-idea-date__button"
					variant="secondary"
					onClick={ onToggle }
					disabled={ disabled }
					aria-expanded={ isOpen }
					aria-haspopup="true"
				>
					<span>{ label }</span>
				</Button>
			) }
			renderContent={ ( { onClose } ) => (
				<MenuGroup className="hey-woo-idea-date__menu" label={ __( 'Date range', 'woocommerce-claude' ) }>
					<MenuItemsChoice
						choices={ choices }
						value={ value }
						onHover={ () => undefined }
						onSelect={ ( nextValue ) => {
							const preset = presets.find( ( item ) => item.value === nextValue );
							if ( preset ) {
								onSelect( preset.days );
							}
							onClose();
						} }
					/>
				</MenuGroup>
			) }
		/>
	);
}

const PanelHeader = memo( function PanelHeader( { title, description, count }: { title: string; description: string; count: number } ) {
	return (
		<header className="hey-woo-idea-panel-header">
			<div>
				<h2>{ title }</h2>
				<p>{ description }</p>
			</div>
			<span>{ count }</span>
		</header>
	);
} );

const SectionTitle = memo( function SectionTitle( { title, count }: { title: string; count: number } ) {
	return (
		<header className="hey-woo-idea-section__title">
			<h3>{ title }</h3>
			<span>{ count }</span>
		</header>
	);
} );

const SignalCard = memo( function SignalCard( {
	insight,
	isSelected,
	isRelated,
	onToggle,
}: {
	insight: IdeaBoardCard;
	isSelected: boolean;
	isRelated: boolean;
	onToggle: ( insightId: string ) => void;
} ) {
	return (
		<label className={ `hey-woo-idea-signal${ isSelected ? ' is-selected' : '' }${ isRelated ? ' is-related' : '' }` }>
			<input
				type="checkbox"
				checked={ isSelected }
				onChange={ () => onToggle( insight.id ) }
			/>
			<span className="hey-woo-idea-signal__body">
				<span className="hey-woo-idea-signal__badges">
					<span>{ severityLabel( insight.severity ) }</span>
					{ insight.estimatedImpact && <span>{ insight.estimatedImpact }</span> }
					{ isRelated && <span>{ __( 'Related', 'woocommerce-claude' ) }</span> }
				</span>
				<strong>{ insight.title }</strong>
				<span>{ insight.body }</span>
				{ insight.evidence && <em>{ insight.evidence }</em> }
				{ insight.whyItMatters && <small>{ insight.whyItMatters }</small> }
				{ insight.revenueLevers.length > 0 && (
					<span className="hey-woo-idea-levers">
						{ insight.revenueLevers.slice( 0, 3 ).map( ( lever ) => <b key={ lever }>{ leverLabel( lever ) }</b> ) }
					</span>
				) }
			</span>
		</label>
	);
} );

function DecisionBriefPanel( { brief }: { brief: IdeaBoardData[ 'decisionBrief' ] } ) {
	const items = [
		{ label: __( 'What changed', 'woocommerce-claude' ), value: brief.whatChanged },
		{ label: __( 'Commercial why', 'woocommerce-claude' ), value: brief.commercialWhy },
		{ label: __( 'Unknowns', 'woocommerce-claude' ), value: brief.biggestUnknowns },
		{ label: __( 'Best next move', 'woocommerce-claude' ), value: brief.bestNextMove },
		{ label: __( 'Upside/risk', 'woocommerce-claude' ), value: brief.upsideRisk },
	].filter( ( item ) => item.value );

	if ( items.length === 0 ) {
		return null;
	}

	return (
		<section className="hey-woo-idea-brief" aria-label={ __( 'Decision brief', 'woocommerce-claude' ) }>
			{ items.map( ( item ) => (
				<div key={ item.label }>
					<span>{ item.label }</span>
					<p>{ item.value }</p>
				</div>
			) ) }
		</section>
	);
}

function DecisionView( {
	insights,
	questions,
	contextCards,
	actions,
	notesByParent,
	isBusy,
	onAnswer,
	onAnswerWithAI,
	onDetails,
	onRemove,
	onAcceptAction,
}: {
	insights: IdeaBoardCard[];
	questions: IdeaBoardCard[];
	contextCards: IdeaBoardCard[];
	actions: IdeaBoardCard[];
	notesByParent: Map< string, IdeaBoardData[ 'notes' ] >;
	isBusy: boolean;
	onAnswer: ( card: IdeaBoardCard ) => void;
	onAnswerWithAI: ( card: IdeaBoardCard ) => void;
	onDetails: ( card: IdeaBoardCard ) => void;
	onRemove: ( card: IdeaBoardCard ) => void;
	onAcceptAction: ( card: IdeaBoardCard ) => void;
} ) {
	const accepted = actions.filter( ( card ) => card.status === 'accepted' );
	const drafts = actions.filter( ( card ) => card.status !== 'accepted' );
	const columns = [
		{ key: 'signals', title: __( 'Signal', 'woocommerce-claude' ), cards: insights },
		{ key: 'questions', title: __( 'Questions', 'woocommerce-claude' ), cards: questions },
		{ key: 'context', title: __( 'Answers/context', 'woocommerce-claude' ), cards: contextCards },
		{ key: 'drafts', title: __( 'Draft actions', 'woocommerce-claude' ), cards: drafts },
		{ key: 'accepted', title: __( 'Accepted', 'woocommerce-claude' ), cards: accepted },
	];

	return (
		<section className="hey-woo-idea-decision-view" aria-label={ __( 'Decision view', 'woocommerce-claude' ) }>
			{ columns.map( ( column ) => (
				<div key={ column.key } className="hey-woo-idea-decision-column">
					<header>
						<h3>{ column.title }</h3>
						<span>{ column.cards.length }</span>
					</header>
					<div>
						{ column.cards.map( ( card ) => (
							<DecisionCard
								key={ card.id }
								card={ card }
								notes={ notesByParent.get( card.id ) || [] }
								isBusy={ isBusy }
								onAnswer={ onAnswer }
								onAnswerWithAI={ onAnswerWithAI }
								onDetails={ onDetails }
								onRemove={ onRemove }
								onAcceptAction={ onAcceptAction }
							/>
						) ) }
						{ column.cards.length === 0 && <EmptyLine text={ __( 'Nothing here yet.', 'woocommerce-claude' ) } /> }
					</div>
				</div>
			) ) }
		</section>
	);
}

function DecisionCard( {
	card,
	notes,
	isBusy,
	onAnswer,
	onAnswerWithAI,
	onDetails,
	onRemove,
	onAcceptAction,
}: {
	card: IdeaBoardCard;
	notes: IdeaBoardData[ 'notes' ];
	isBusy: boolean;
	onAnswer: ( card: IdeaBoardCard ) => void;
	onAnswerWithAI: ( card: IdeaBoardCard ) => void;
	onDetails: ( card: IdeaBoardCard ) => void;
	onRemove: ( card: IdeaBoardCard ) => void;
	onAcceptAction: ( card: IdeaBoardCard ) => void;
} ) {
	return (
		<article className={ `hey-woo-idea-decision-card hey-woo-idea-decision-card--${ card.kind }` }>
			<header>
				<span>{ kindLabel( card.kind ) }</span>
				{ card.kind === 'question' && <em>{ gateLabel( card.gatePriority ) }</em> }
				{ card.kind === 'action' && <em>{ sprintf(
					/* translators: %d: ICE score. */
					__( 'ICE %d', 'woocommerce-claude' ),
					card.iceScore
				) }</em> }
			</header>
			<h4>{ card.title }</h4>
			<p>{ card.body }</p>
			<div className="hey-woo-idea-decision-card__chips">
				{ card.revenueLevers.slice( 0, 3 ).map( ( lever ) => <span key={ lever }>{ leverLabel( lever ) }</span> ) }
				{ card.estimatedImpact && <span>{ card.estimatedImpact }</span> }
				{ card.kind === 'question' && <span>{ answerabilityLabel( card.answerability ) }</span> }
				{ card.kind === 'action' && <span>{ actionTypeLabel( card.actionType ) }</span> }
			</div>
			{ notes.length > 0 && (
				<div className="hey-woo-idea-decision-card__answer">
					<strong>{ noteKindLabel( notes[ notes.length - 1 ].kind ) }</strong>
					<span>{ notes[ notes.length - 1 ].body }</span>
				</div>
			) }
			<div className="hey-woo-idea-decision-card__actions">
				<Button variant="tertiary" onClick={ () => onDetails( card ) } disabled={ isBusy }>
					{ __( 'Details', 'woocommerce-claude' ) }
				</Button>
				{ card.kind === 'question' && (
					<>
						<Button variant="secondary" onClick={ () => onAnswer( card ) } disabled={ isBusy }>
							{ __( 'Answer', 'woocommerce-claude' ) }
						</Button>
						<Button variant="secondary" onClick={ () => onAnswerWithAI( card ) } disabled={ isBusy }>
							{ __( 'Answer with AI', 'woocommerce-claude' ) }
						</Button>
					</>
				) }
				{ card.kind === 'action' && card.status !== 'accepted' && (
					<Button variant="primary" onClick={ () => onAcceptAction( card ) } disabled={ isBusy }>
						{ __( 'Accept', 'woocommerce-claude' ) }
					</Button>
				) }
				{ card.kind !== 'insight' && card.status !== 'accepted' && (
					<Button variant="tertiary" isDestructive onClick={ () => onRemove( card ) } disabled={ isBusy }>
						{ card.kind === 'action' ? __( 'Dismiss', 'woocommerce-claude' ) : __( 'Remove', 'woocommerce-claude' ) }
					</Button>
				) }
			</div>
		</article>
	);
}

function InsightDetail( { insight, compact = false }: { insight: IdeaBoardCard; compact?: boolean } ) {
	return (
		<article className={ `hey-woo-idea-insight-detail hey-woo-idea-insight-detail--${ insight.colour }${ compact ? ' is-compact' : '' }` }>
			<div className="hey-woo-idea-insight-detail__kind">{ __( 'AI insight', 'woocommerce-claude' ) }</div>
			<h3>{ insight.title }</h3>
			<p>{ insight.body }</p>
			<dl>
				{ insight.evidence && (
					<>
						<dt>{ __( 'Evidence', 'woocommerce-claude' ) }</dt>
						<dd>{ insight.evidence }</dd>
					</>
				) }
				{ insight.timeframe && (
					<>
						<dt>{ __( 'Timeframe', 'woocommerce-claude' ) }</dt>
						<dd>{ insight.timeframe }</dd>
					</>
				) }
				{ insight.source && (
					<>
						<dt>{ __( 'Source', 'woocommerce-claude' ) }</dt>
						<dd>{ insight.source }</dd>
					</>
				) }
				<dt>{ __( 'Confidence', 'woocommerce-claude' ) }</dt>
				<dd>{ confidenceLabel( insight.confidence ) }</dd>
			</dl>
			{ insight.prompt && <div className="hey-woo-idea-insight-detail__prompt">{ insight.prompt }</div> }
		</article>
	);
}

function BrainstormCanvas( {
	items,
	notesByParent,
	detailItem,
	isBusy,
	minimiseAnswered,
	onDragEnd,
	onAnswer,
	onAnswerWithAI,
	onDetails,
	onCloseDetails,
	onRemove,
	onAcceptAction,
	onUpdateActionMetadata,
}: {
	items: CanvasItem[];
	notesByParent: Map< string, IdeaBoardData[ 'notes' ] >;
	detailItem: CanvasItem | null;
	isBusy: boolean;
	minimiseAnswered: boolean;
	onDragEnd: ( event: DragEndEvent ) => void;
	onAnswer: ( card: IdeaBoardCard ) => void;
	onAnswerWithAI: ( card: IdeaBoardCard ) => void;
	onDetails: ( card: IdeaBoardCard ) => void;
	onCloseDetails: () => void;
	onRemove: ( card: IdeaBoardCard ) => void;
	onAcceptAction: ( card: IdeaBoardCard ) => void;
	onUpdateActionMetadata: ( card: IdeaBoardCard, updates: Partial< IdeaBoardCard > ) => void;
} ) {
	const detailNotes = detailItem ? notesByParent.get( detailItem.card.id ) || [] : [];

	return (
		<section className={ `hey-woo-idea-canvas-shell${ detailItem ? ' has-detail' : '' }` } aria-label={ __( 'Brainstorm board', 'woocommerce-claude' ) }>
			<div className="hey-woo-idea-canvas">
				<DndContext onDragEnd={ onDragEnd }>
					<div className="hey-woo-idea-canvas__surface">
						{ items.map( ( item ) => (
							<CanvasStickyCard
								key={ item.card.id }
								item={ item }
								notes={ notesByParent.get( item.card.id ) || [] }
								isBusy={ isBusy }
								minimiseAnswered={ minimiseAnswered }
								onAnswer={ onAnswer }
								onAnswerWithAI={ onAnswerWithAI }
								onDetails={ onDetails }
								onRemove={ onRemove }
								onAcceptAction={ onAcceptAction }
							/>
						) ) }
						{ items.length === 0 && (
							<div className="hey-woo-idea-canvas__empty">
								{ __( 'Start with the selected insight, then add context or re-analyse to fill the board.', 'woocommerce-claude' ) }
							</div>
						) }
					</div>
				</DndContext>
			</div>
			{ detailItem && (
				<CardDetailPanel
					card={ detailItem.card }
					notes={ detailNotes }
					onClose={ onCloseDetails }
					onAnswer={ onAnswer }
					onAnswerWithAI={ onAnswerWithAI }
					isBusy={ isBusy }
					onUpdateActionMetadata={ onUpdateActionMetadata }
				/>
			) }
		</section>
	);
}

function CanvasStickyCard( props: {
	item: CanvasItem;
	notes: IdeaBoardData[ 'notes' ];
	isBusy: boolean;
	minimiseAnswered: boolean;
	onAnswer: ( card: IdeaBoardCard ) => void;
	onAnswerWithAI: ( card: IdeaBoardCard ) => void;
	onDetails: ( card: IdeaBoardCard ) => void;
	onRemove: ( card: IdeaBoardCard ) => void;
	onAcceptAction: ( card: IdeaBoardCard ) => void;
} ) {
	if ( props.item.isAnchor ) {
		return <CanvasStickyCardShell { ...props } dragHandleProps={ null } transform="" isDragging={ false } />;
	}

	return <DraggableCanvasStickyCard { ...props } />;
}

function DraggableCanvasStickyCard( props: {
	item: CanvasItem;
	notes: IdeaBoardData[ 'notes' ];
	isBusy: boolean;
	minimiseAnswered: boolean;
	onAnswer: ( card: IdeaBoardCard ) => void;
	onAnswerWithAI: ( card: IdeaBoardCard ) => void;
	onDetails: ( card: IdeaBoardCard ) => void;
	onRemove: ( card: IdeaBoardCard ) => void;
	onAcceptAction: ( card: IdeaBoardCard ) => void;
} ) {
	const { attributes, listeners, setNodeRef, transform, isDragging } = useDraggable( {
		id: props.item.card.id,
		disabled: props.isBusy,
	} );

	return (
		<CanvasStickyCardShell
			{ ...props }
			setNodeRef={ setNodeRef }
			dragHandleProps={ { ...attributes, ...listeners } }
			transform={ transform ? CSS.Translate.toString( transform ) : '' }
			isDragging={ isDragging }
		/>
	);
}

function CanvasStickyCardShell( {
	item,
	notes,
	isBusy,
	minimiseAnswered,
	onAnswer,
	onAnswerWithAI,
	onDetails,
	onRemove,
	onAcceptAction,
	dragHandleProps,
	transform,
	isDragging,
	setNodeRef,
}: {
	item: CanvasItem;
	notes: IdeaBoardData[ 'notes' ];
	isBusy: boolean;
	minimiseAnswered: boolean;
	onAnswer: ( card: IdeaBoardCard ) => void;
	onAnswerWithAI: ( card: IdeaBoardCard ) => void;
	onDetails: ( card: IdeaBoardCard ) => void;
	onRemove: ( card: IdeaBoardCard ) => void;
	onAcceptAction: ( card: IdeaBoardCard ) => void;
	dragHandleProps: Record< string, unknown > | null;
	transform: string;
	isDragging: boolean;
	setNodeRef?: ( element: HTMLElement | null ) => void;
} ) {
	const { card, position, isAnchor } = item;
	const hasAnswer = card.kind === 'question' && notes.length > 0;
	const isMinimised = hasAnswer && minimiseAnswered;
	const className = [
		'hey-woo-idea-sticky',
		`hey-woo-idea-sticky--${ card.kind }`,
		isAnchor ? 'is-anchor' : '',
		isMinimised ? 'is-minimised' : '',
		isDragging ? 'is-dragging' : '',
	].filter( Boolean ).join( ' ' );
	const style = {
		left: `${ position.x }px`,
		top: `${ position.y }px`,
		transform: `${ transform ? `${ transform } ` : '' }rotate(${ position.rotation }deg)`,
	} as CSSProperties;

	return (
		<article ref={ setNodeRef } className={ className } style={ style }>
			<header>
				<span>{ kindLabel( card.kind ) }</span>
				{ card.kind === 'question' && (
					<em className={ hasAnswer ? 'is-answered' : '' }>
						{ hasAnswer ? __( 'Answered', 'woocommerce-claude' ) : gateLabel( card.gatePriority ) }
					</em>
				) }
				{ card.kind === 'action' && (
					<em className={ card.status === 'accepted' || isActionScorecardComplete( card ) ? 'is-answered' : '' }>
						{ card.status === 'accepted'
							? __( 'Accepted', 'woocommerce-claude' )
							: isActionScorecardComplete( card )
								? sprintf(
									/* translators: %d: ICE score. */
									__( 'ICE %d', 'woocommerce-claude' ),
									card.iceScore
								)
								: __( 'Scorecard needed', 'woocommerce-claude' ) }
					</em>
				) }
				{ dragHandleProps && (
					<button
						type="button"
						className="hey-woo-idea-sticky__pin"
						disabled={ isBusy }
						aria-label={ __( 'Move card', 'woocommerce-claude' ) }
						{ ...dragHandleProps }
					/>
				) }
			</header>
			<h4>{ card.title }</h4>
			<p>{ card.body }</p>
			{ notes.length > 0 && (
				<div className="hey-woo-idea-sticky__answer">
					<strong>{ noteKindLabel( notes[ notes.length - 1 ].kind ) }</strong>
					<span>{ notes[ notes.length - 1 ].body }</span>
				</div>
			) }
			{ ( card.evidence || rootsForCard( card, [] ).length > 1 || card.revenueLevers.length > 0 || card.estimatedImpact || card.kind === 'question' || card.kind === 'action' ) && (
				<div className="hey-woo-idea-sticky__chips">
					{ card.evidence && <span>{ card.evidence }</span> }
					{ card.revenueLevers.slice( 0, 3 ).map( ( lever ) => <span key={ lever }>{ leverLabel( lever ) }</span> ) }
					{ card.estimatedImpact && <span>{ card.estimatedImpact }</span> }
					{ card.kind === 'question' && <span>{ answerabilityLabel( card.answerability ) }</span> }
					{ card.kind === 'action' && <span>{ actionTypeLabel( card.actionType ) }</span> }
					{ rootsForCard( card, [] ).length > 1 && <span>{ __( 'Cross-insight', 'woocommerce-claude' ) }</span> }
				</div>
			) }
			{ ! isAnchor && (
				<div className="hey-woo-idea-sticky__actions">
					<Button variant="tertiary" onClick={ () => onDetails( card ) } disabled={ isBusy }>
						{ __( 'Details', 'woocommerce-claude' ) }
					</Button>
					{ card.kind === 'question' && ! isMinimised && (
						<>
							<Button variant="secondary" onClick={ () => onAnswer( card ) } disabled={ isBusy }>
								{ __( 'Answer', 'woocommerce-claude' ) }
							</Button>
							<Button variant="secondary" onClick={ () => onAnswerWithAI( card ) } disabled={ isBusy }>
								{ __( 'Answer with AI', 'woocommerce-claude' ) }
							</Button>
						</>
					) }
					{ card.kind === 'action' && card.status !== 'accepted' && ! isMinimised && (
						<Button variant="primary" onClick={ () => onAcceptAction( card ) } disabled={ isBusy }>
							{ __( 'Accept', 'woocommerce-claude' ) }
						</Button>
					) }
					{ ! isMinimised && (
						<Button variant="tertiary" isDestructive onClick={ () => onRemove( card ) } disabled={ isBusy }>
							{ card.kind === 'action' ? __( 'Dismiss', 'woocommerce-claude' ) : __( 'Remove', 'woocommerce-claude' ) }
						</Button>
					) }
				</div>
			) }
		</article>
	);
}

function CardDetailPanel( {
	card,
	notes,
	onClose,
	onAnswer,
	onAnswerWithAI,
	isBusy,
	onUpdateActionMetadata,
}: {
	card: IdeaBoardCard;
	notes: IdeaBoardData[ 'notes' ];
	onClose: () => void;
	onAnswer: ( card: IdeaBoardCard ) => void;
	onAnswerWithAI: ( card: IdeaBoardCard ) => void;
	isBusy: boolean;
	onUpdateActionMetadata: ( card: IdeaBoardCard, updates: Partial< IdeaBoardCard > ) => void;
} ) {
	return (
		<aside className="hey-woo-idea-detail" aria-label={ __( 'Card details', 'woocommerce-claude' ) }>
			<header>
				<div>
					<span>{ kindLabel( card.kind ) }</span>
					<h3>{ card.title }</h3>
				</div>
				<button type="button" onClick={ onClose } aria-label={ __( 'Close details', 'woocommerce-claude' ) }>
					{ __( 'Close', 'woocommerce-claude' ) }
				</button>
			</header>
			<p>{ card.body }</p>
			<dl>
				{ card.evidence && (
					<>
						<dt>{ __( 'Evidence', 'woocommerce-claude' ) }</dt>
						<dd>{ card.evidence }</dd>
					</>
				) }
				{ card.source && (
					<>
						<dt>{ __( 'Source', 'woocommerce-claude' ) }</dt>
						<dd>{ card.source }</dd>
					</>
				) }
				{ card.timeframe && (
					<>
						<dt>{ __( 'Timeframe', 'woocommerce-claude' ) }</dt>
						<dd>{ card.timeframe }</dd>
					</>
				) }
				<dt>{ __( 'Confidence', 'woocommerce-claude' ) }</dt>
				<dd>{ confidenceLabel( card.confidence ) }</dd>
			</dl>
			<EvidenceDrawer card={ card } />
			{ card.kind === 'action' && (
				<ActionScorecardEditor card={ card } isBusy={ isBusy } onSave={ onUpdateActionMetadata } />
			) }
			{ notes.length > 0 && (
				<div className="hey-woo-idea-detail__notes">
					<h4>{ __( 'Attached answers and notes', 'woocommerce-claude' ) }</h4>
					{ notes.map( ( note ) => (
						<article key={ note.id }>
							<strong>{ noteKindLabel( note.kind ) }</strong>
							<span>{ note.authorName }</span>
							<p>{ note.body }</p>
						</article>
					) ) }
				</div>
			) }
			{ card.kind === 'question' && (
				<div className="hey-woo-idea-detail__actions">
					<Button variant="secondary" onClick={ () => onAnswer( card ) } disabled={ isBusy }>
						{ __( 'Answer', 'woocommerce-claude' ) }
					</Button>
					<Button variant="primary" onClick={ () => onAnswerWithAI( card ) } disabled={ isBusy }>
						{ __( 'Answer with AI', 'woocommerce-claude' ) }
					</Button>
				</div>
			) }
		</aside>
	);
}

function EvidenceDrawer( { card }: { card: IdeaBoardCard } ) {
	const details = card.evidenceDetails;
	const rows = [
		{ label: __( 'Metric baseline', 'woocommerce-claude' ), value: details.metricBaseline },
		{ label: __( 'Comparison', 'woocommerce-claude' ), value: details.comparisonPeriod },
		{ label: __( 'Products', 'woocommerce-claude' ), value: details.involvedProducts },
		{ label: __( 'Orders', 'woocommerce-claude' ), value: details.involvedOrders },
		{ label: __( 'Customers', 'woocommerce-claude' ), value: details.involvedCustomers },
		{ label: __( 'Confidence reason', 'woocommerce-claude' ), value: details.confidenceReason },
		{ label: __( 'Data freshness', 'woocommerce-claude' ), value: details.dataFreshness },
		{ label: __( 'What AI does not know', 'woocommerce-claude' ), value: details.unknowns },
	].filter( ( row ) => row.value );

	if ( rows.length === 0 && details.wooLinks.length === 0 && card.revenueLevers.length === 0 && ! card.whyItMatters ) {
		return null;
	}

	return (
		<section className="hey-woo-idea-evidence">
			<h4>{ __( 'Evidence', 'woocommerce-claude' ) }</h4>
			{ card.revenueLevers.length > 0 && (
				<div className="hey-woo-idea-evidence__levers">
					{ card.revenueLevers.map( ( lever ) => <span key={ lever }>{ leverLabel( lever ) }</span> ) }
				</div>
			) }
			{ card.whyItMatters && <p>{ card.whyItMatters }</p> }
			{ rows.length > 0 && (
				<dl>
					{ rows.map( ( row ) => (
						<FragmentRow key={ row.label } label={ row.label } value={ row.value } />
					) ) }
				</dl>
			) }
			{ details.wooLinks.length > 0 && (
				<div className="hey-woo-idea-evidence__links">
					{ details.wooLinks.map( ( link ) => (
						<a key={ `${ link.label }-${ link.url }` } href={ link.url }>{ link.label }</a>
					) ) }
				</div>
			) }
		</section>
	);
}

const FragmentRow = memo( function FragmentRow( { label, value }: { label: string; value: string } ) {
	return (
		<>
			<dt>{ label }</dt>
			<dd>{ value }</dd>
		</>
	);
} );

function ActionScorecardEditor( {
	card,
	isBusy,
	onSave,
}: {
	card: IdeaBoardCard;
	isBusy: boolean;
	onSave: ( card: IdeaBoardCard, updates: Partial< IdeaBoardCard > ) => void;
} ) {
	const [ draft, setDraft ] = useState( () => ( {
		actionType: card.actionType,
		expectedRevenueImpact: card.expectedRevenueImpact,
		effort: card.effort,
		timeToImpact: card.timeToImpact,
		riskApprovalNeeded: card.riskApprovalNeeded,
		primaryMetric: card.primaryMetric,
		owner: card.owner,
		reviewDate: card.reviewDate || getDefaultReviewDate(),
		successCriteria: card.successCriteria,
	} ) );

	useEffect( () => {
		setDraft( {
			actionType: card.actionType,
			expectedRevenueImpact: card.expectedRevenueImpact,
			effort: card.effort,
			timeToImpact: card.timeToImpact,
			riskApprovalNeeded: card.riskApprovalNeeded,
			primaryMetric: card.primaryMetric,
			owner: card.owner,
			reviewDate: card.reviewDate || getDefaultReviewDate(),
			successCriteria: card.successCriteria,
		} );
	}, [ card ] );

	return (
		<section className="hey-woo-idea-scorecard">
			<header>
				<h4>{ __( 'Action scorecard', 'woocommerce-claude' ) }</h4>
				<span>{ sprintf(
					/* translators: %d: ICE score. */
					__( 'ICE %d', 'woocommerce-claude' ),
					computeIceScore( draft.expectedRevenueImpact, card.confidence, draft.effort )
				) }</span>
			</header>
			<div className="hey-woo-idea-scorecard__grid">
				<label>
					<span>{ __( 'Type', 'woocommerce-claude' ) }</span>
					<select value={ draft.actionType } onChange={ ( event ) => setDraft( { ...draft, actionType: event.currentTarget.value as IdeaBoardActionType } ) } disabled={ isBusy }>
						{ ACTION_TYPES.map( ( option ) => (
							<option key={ option.value } value={ option.value }>{ option.label }</option>
						) ) }
					</select>
				</label>
				<label>
					<span>{ __( 'Impact', 'woocommerce-claude' ) }</span>
					<select value={ draft.expectedRevenueImpact } onChange={ ( event ) => setDraft( { ...draft, expectedRevenueImpact: event.currentTarget.value as IdeaBoardCard[ 'expectedRevenueImpact' ] } ) } disabled={ isBusy }>
						<ScoreOptions />
					</select>
				</label>
				<label>
					<span>{ __( 'Effort', 'woocommerce-claude' ) }</span>
					<select value={ draft.effort } onChange={ ( event ) => setDraft( { ...draft, effort: event.currentTarget.value as IdeaBoardCard[ 'effort' ] } ) } disabled={ isBusy }>
						<ScoreOptions />
					</select>
				</label>
				<label>
					<span>{ __( 'Metric', 'woocommerce-claude' ) }</span>
					<input value={ draft.primaryMetric } onChange={ ( event ) => setDraft( { ...draft, primaryMetric: event.currentTarget.value } ) } maxLength={ 120 } disabled={ isBusy } />
				</label>
				<label>
					<span>{ __( 'Owner', 'woocommerce-claude' ) }</span>
					<input value={ draft.owner } onChange={ ( event ) => setDraft( { ...draft, owner: event.currentTarget.value } ) } maxLength={ 80 } placeholder={ __( 'Unassigned', 'woocommerce-claude' ) } disabled={ isBusy } />
				</label>
				<label>
					<span>{ __( 'Review', 'woocommerce-claude' ) }</span>
					<input type="date" value={ draft.reviewDate } onChange={ ( event ) => setDraft( { ...draft, reviewDate: event.currentTarget.value } ) } disabled={ isBusy } />
				</label>
				<label>
					<span>{ __( 'Time', 'woocommerce-claude' ) }</span>
					<input value={ draft.timeToImpact } onChange={ ( event ) => setDraft( { ...draft, timeToImpact: event.currentTarget.value } ) } maxLength={ 80 } disabled={ isBusy } />
				</label>
				<label className="hey-woo-idea-scorecard__wide">
					<span>{ __( 'Risk / approval', 'woocommerce-claude' ) }</span>
					<textarea value={ draft.riskApprovalNeeded } onChange={ ( event ) => setDraft( { ...draft, riskApprovalNeeded: event.currentTarget.value } ) } rows={ 2 } maxLength={ 160 } disabled={ isBusy } />
				</label>
				<label className="hey-woo-idea-scorecard__wide">
					<span>{ __( 'Success criteria', 'woocommerce-claude' ) }</span>
					<textarea value={ draft.successCriteria } onChange={ ( event ) => setDraft( { ...draft, successCriteria: event.currentTarget.value } ) } rows={ 2 } maxLength={ 180 } disabled={ isBusy } />
				</label>
			</div>
			<Button
				variant="secondary"
				onClick={ () => onSave( card, {
					...draft,
					iceScore: computeIceScore( draft.expectedRevenueImpact, card.confidence, draft.effort ),
				} ) }
				disabled={ isBusy || ! draft.primaryMetric.trim() || ! draft.successCriteria.trim() }
			>
				{ __( 'Save scorecard', 'woocommerce-claude' ) }
			</Button>
		</section>
	);
}

const ScoreOptions = memo( function ScoreOptions() {
	return (
		<>
			<option value="low">{ __( 'Low', 'woocommerce-claude' ) }</option>
			<option value="medium">{ __( 'Medium', 'woocommerce-claude' ) }</option>
			<option value="high">{ __( 'High', 'woocommerce-claude' ) }</option>
		</>
	);
} );

const StatusPill = memo( function StatusPill( { status }: { status: string } ) {
	return <span className={ `hey-woo-idea-status hey-woo-idea-status--${ status }` }>{ statusLabel( status ) }</span>;
} );

const EmptyLine = memo( function EmptyLine( { text }: { text: string } ) {
	return <div className="hey-woo-idea-empty-line">{ text }</div>;
} );

function getInsightCards( board: IdeaBoardData | null ) {
	return board ? board.cards.filter( ( card ) => card.kind === 'insight' ).sort( sortCards ) : [];
}

function getCanvasItems(
	insights: IdeaBoardCard[],
	questions: IdeaBoardCard[],
	contextCards: IdeaBoardCard[],
	actions: IdeaBoardCard[]
): CanvasItem[] {
	return [
		...insights.map( ( card, index ) => ( {
			card,
			position: getCanvasPosition( card, index, 'insight' ),
			isAnchor: true,
		} ) ),
		...questions.map( ( card, index ) => ( {
			card,
			position: getCanvasPosition( card, index, 'question' ),
			isAnchor: false,
		} ) ),
		...contextCards.map( ( card, index ) => ( {
			card,
			position: getCanvasPosition( card, index, 'context' ),
			isAnchor: false,
		} ) ),
		...actions.map( ( card, index ) => ( {
			card,
			position: getCanvasPosition( card, index, 'action' ),
			isAnchor: false,
		} ) ),
	];
}

function getCanvasPosition( card: IdeaBoardCard, index: number, kind: IdeaBoardCardKind ): BoardPosition {
	if ( ( card.x || card.y ) && kind !== 'insight' ) {
		return {
			x: clamp( card.x, 0, CANVAS_WIDTH - 220 ),
			y: clamp( card.y, 0, CANVAS_HEIGHT - 150 ),
			rotation: clamp( card.rotation, -6, 6 ),
		};
	}

	if ( kind === 'insight' ) {
		return {
			x: 24,
			y: 36 + ( index * 178 ),
			rotation: index % 2 === 0 ? -2 : 1,
		};
	}
	if ( kind === 'action' ) {
		return {
			x: 640,
			y: 78 + ( index * 200 ),
			rotation: index % 2 === 0 ? 2 : -1,
		};
	}
	if ( kind === 'context' ) {
		return {
			x: 360 + ( index % 2 ) * 180,
			y: 520 + Math.floor( index / 2 ) * 130,
			rotation: index % 2 === 0 ? 1 : -2,
		};
	}
	return {
		x: 320 + ( index % 2 ) * 250,
		y: 46 + Math.floor( index / 2 ) * 220,
		rotation: index % 2 === 0 ? -1 : 2,
	};
}

function groupNotesByParent( notes: IdeaBoardData[ 'notes' ] ) {
	const grouped = new Map< string, IdeaBoardData[ 'notes' ] >();
	notes.forEach( ( note ) => {
		if ( ! note.parentCardId ) {
			return;
		}
		grouped.set( note.parentCardId, [ ...( grouped.get( note.parentCardId ) || [] ), note ] );
	} );
	return grouped;
}

function cardsForSession( board: IdeaBoardData | null, session: IdeaBoardSession ) {
	if ( ! board ) {
		return [];
	}

	const rootIds = new Set( session.rootInsightIds );
	return board.cards
		.filter( ( card ) => card.kind !== 'insight' )
		.filter( ( card ) => card.sessionId === session.id || ( ! card.sessionId && rootsForCard( card, [] ).some( ( id ) => rootIds.has( id ) ) ) )
		.sort( sortCards );
}

function rootsForCard( card: IdeaBoardCard, fallback: string[] ) {
	if ( Array.isArray( card.rootInsightIds ) && card.rootInsightIds.length > 0 ) {
		return card.rootInsightIds;
	}
	if ( card.rootInsightId ) {
		return [ card.rootInsightId ];
	}
	return fallback;
}

function sortCards( a: IdeaBoardCard, b: IdeaBoardCard ) {
	if ( a.order !== b.order ) {
		return a.order - b.order;
	}
	return a.title.localeCompare( b.title );
}

function nextOrderForKind( board: IdeaBoardData, kind: IdeaBoardCardKind ) {
	const kindCards = board.cards.filter( ( card ) => card.kind === kind );
	return kindCards.length ? Math.max( ...kindCards.map( ( card ) => card.order ) ) + 1 : 0;
}

function isVisibleProposedAction( card: IdeaBoardCard ) {
	if ( card.kind !== 'action' ) {
		return false;
	}

	return ! card.source.toLowerCase().includes( 'brainstorm session' );
}

function cardTitleById( cardId: string, cards: IdeaBoardCard[] ) {
	return cards.find( ( card ) => card.id === cardId )?.title || __( 'Whole session', 'woocommerce-claude' );
}

function signalCountLabel( count: number ) {
	return sprintf(
		/* translators: %d: selected insight count. */
		count === 1 ? __( '%d signal', 'woocommerce-claude' ) : __( '%d signals', 'woocommerce-claude' ),
		count
	);
}

function clamp( value: number, min: number, max: number ) {
	return Math.max( min, Math.min( max, value ) );
}

function createLocalId( prefix: string, existingIds: string[] ) {
	const ids = new Set( existingIds );
	let id = `${ prefix }-${ Date.now().toString( 36 ) }`;
	let suffix = 2;
	while ( ids.has( id ) ) {
		id = `${ prefix }-${ Date.now().toString( 36 ) }-${ suffix }`;
		suffix += 1;
	}
	return id;
}

function truncateText( value: string, maxLength: number ) {
	return value.length <= maxLength ? value : `${ value.slice( 0, maxLength - 3 ).trim() }...`;
}

function isStoreRelatedQuestion( value: string ) {
	const text = value.toLowerCase();
	const keywords = [
		'ad',
		'ads',
		'attribute',
		'attributes',
		'back-in-stock',
		'bestseller',
		'bestsellers',
		'campaign',
		'catalogue',
		'checkout',
		'conversion',
		'coupon',
		'customer',
		'customers',
		'description',
		'descriptions',
		'discount',
		'email',
		'inventory',
		'margin',
		'marketing',
		'order',
		'orders',
		'payment',
		'price',
		'product',
		'products',
		'refund',
		'returns',
		'revenue',
		'sales',
		'search',
		'shipping',
		'sku',
		'stock',
		'store',
		'supplier',
		'support',
		'traffic',
		'woocommerce',
	];

	return keywords.some( ( keyword ) => text.includes( keyword ) );
}

function formatDate( value: string ) {
	if ( ! value ) {
		return '';
	}

	const date = new Date( `${ value }T00:00:00` );
	if ( Number.isNaN( date.getTime() ) ) {
		return value;
	}

	return date.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
	} );
}

function isAfter( value: string, compareTo: string ) {
	if ( ! value || ! compareTo ) {
		return Boolean( value );
	}
	return new Date( value ).getTime() > new Date( compareTo ).getTime();
}

function emptyEvidenceDetails(): IdeaBoardCard[ 'evidenceDetails' ] {
	return {
		metricBaseline: '',
		comparisonPeriod: '',
		involvedProducts: '',
		involvedOrders: '',
		involvedCustomers: '',
		confidenceReason: '',
		dataFreshness: '',
		unknowns: '',
		wooLinks: [],
	};
}

function mergeRevenueLevers( cards: IdeaBoardCard[] ): IdeaBoardRevenueLever[] {
	const levers = new Set< IdeaBoardRevenueLever >();
	cards.forEach( ( card ) => card.revenueLevers.forEach( ( lever ) => levers.add( lever ) ) );
	return levers.size ? Array.from( levers ) : [ 'revenue_protection' ];
}

function countRequiredBlockers( questions: IdeaBoardCard[], notesByParent: Map< string, IdeaBoardData[ 'notes' ] > ) {
	return questions.filter( ( question ) => {
		if ( question.gatePriority !== 'required' || question.answerability === 'ai' ) {
			return false;
		}
		const notes = notesByParent.get( question.id ) || [];
		return ! notes.some( ( note ) => note.kind === 'answer' || note.kind === 'context' );
	} ).length;
}

function isActionScorecardComplete( card: IdeaBoardCard ) {
	return Boolean(
		card.kind === 'action'
		&& card.actionType
		&& card.expectedRevenueImpact
		&& card.effort
		&& card.primaryMetric.trim()
		&& card.reviewDate.trim()
		&& card.successCriteria.trim()
	);
}

function computeIceScore( impact: IdeaBoardCard[ 'expectedRevenueImpact' ], confidence: IdeaBoardCard[ 'confidence' ], effort: IdeaBoardCard[ 'effort' ] ) {
	const ease = 4 - levelScore( effort );
	return levelScore( impact ) * levelScore( confidence ) * ease;
}

function levelScore( value: IdeaBoardCard[ 'confidence' ] ) {
	if ( value === 'high' ) {
		return 3;
	}
	if ( value === 'low' ) {
		return 1;
	}
	return 2;
}

function getDefaultReviewDate() {
	const date = new Date();
	date.setDate( date.getDate() + 7 );
	return date.toISOString().slice( 0, 10 );
}

function kindLabel( kind: IdeaBoardCardKind ) {
	if ( kind === 'question' ) {
		return __( 'Question', 'woocommerce-claude' );
	}
	if ( kind === 'context' ) {
		return __( 'Context', 'woocommerce-claude' );
	}
	if ( kind === 'action' ) {
		return __( 'Action', 'woocommerce-claude' );
	}
	return __( 'Insight', 'woocommerce-claude' );
}

function leverLabel( lever: IdeaBoardRevenueLever ) {
	const labels: Record< IdeaBoardRevenueLever, string > = {
		traffic: __( 'Traffic', 'woocommerce-claude' ),
		conversion: __( 'Conversion', 'woocommerce-claude' ),
		aov: __( 'AOV', 'woocommerce-claude' ),
		retention: __( 'Retention', 'woocommerce-claude' ),
		margin: __( 'Margin', 'woocommerce-claude' ),
		inventory: __( 'Inventory', 'woocommerce-claude' ),
		pricing: __( 'Pricing', 'woocommerce-claude' ),
		campaign_spend: __( 'Campaign spend', 'woocommerce-claude' ),
		catalogue_quality: __( 'Catalogue quality', 'woocommerce-claude' ),
		revenue_protection: __( 'Revenue protection', 'woocommerce-claude' ),
	};
	return labels[ lever ];
}

function severityLabel( severity: IdeaBoardCard[ 'severity' ] ) {
	if ( severity === 'critical' ) {
		return __( 'Critical', 'woocommerce-claude' );
	}
	if ( severity === 'high' ) {
		return __( 'High', 'woocommerce-claude' );
	}
	if ( severity === 'low' ) {
		return __( 'Low', 'woocommerce-claude' );
	}
	return __( 'Medium', 'woocommerce-claude' );
}

function gateLabel( gate: IdeaBoardGatePriority ) {
	if ( gate === 'required' ) {
		return __( 'Required gate', 'woocommerce-claude' );
	}
	if ( gate === 'optional' ) {
		return __( 'Optional follow-up', 'woocommerce-claude' );
	}
	return __( 'Useful context', 'woocommerce-claude' );
}

function answerabilityLabel( answerability: IdeaBoardAnswerability ) {
	if ( answerability === 'ai' ) {
		return __( 'AI can answer', 'woocommerce-claude' );
	}
	if ( answerability === 'merchant' ) {
		return __( 'Merchant must answer', 'woocommerce-claude' );
	}
	return __( 'AI + merchant', 'woocommerce-claude' );
}

function actionTypeLabel( value: IdeaBoardActionType ) {
	return ACTION_TYPES.find( ( option ) => option.value === value )?.label || __( 'Investigate', 'woocommerce-claude' );
}

function noteKindLabel( kind: IdeaBoardNoteKind ) {
	if ( kind === 'answer' ) {
		return __( 'Answer', 'woocommerce-claude' );
	}
	if ( kind === 'context' ) {
		return __( 'Context', 'woocommerce-claude' );
	}
	return __( 'Note', 'woocommerce-claude' );
}

function confidenceLabel( confidence: IdeaBoardCard[ 'confidence' ] ) {
	if ( confidence === 'high' ) {
		return __( 'High', 'woocommerce-claude' );
	}
	if ( confidence === 'low' ) {
		return __( 'Low', 'woocommerce-claude' );
	}
	return __( 'Medium', 'woocommerce-claude' );
}

function statusLabel( status: string ) {
	if ( status === 'ready_to_reanalyse' ) {
		return __( 'Ready', 'woocommerce-claude' );
	}
	if ( status === 'analysed' ) {
		return __( 'Analysed', 'woocommerce-claude' );
	}
	if ( status === 'accepted' ) {
		return __( 'Accepted', 'woocommerce-claude' );
	}
	return __( 'Active', 'woocommerce-claude' );
}

function actionIcon( card: IdeaBoardCard ) {
	const text = `${ card.title } ${ card.body }`.toLowerCase();
	if ( text.includes( 'email' ) || text.includes( 'campaign' ) ) {
		return '@';
	}
	if ( text.includes( 'ad' ) || text.includes( 'pause' ) ) {
		return '||';
	}
	return 'OK';
}
