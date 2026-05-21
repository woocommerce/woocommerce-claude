/**
 * Hey Woo Actions - kanban-style action board.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { Button, Modal, SelectControl, TextareaControl, TextControl } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { chevronLeft, chevronRight, plus, trash } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	ACTIONS_UPDATED_EVENT,
	addActionCard,
	loadActionCards,
	saveActionCards,
} from './action-store';
import type { ActionPriority, ActionStatus, HeyWooActionCard } from './action-store';

interface Lane {
	status: ActionStatus;
	label: string;
	description: string;
}

const LANES: Lane[] = [
	{
		status: 'todo',
		label: __( 'To do', 'hey-woo' ),
		description: __( 'New follow-ups from reports and manual actions.', 'hey-woo' ),
	},
	{
		status: 'doing',
		label: __( 'Doing', 'hey-woo' ),
		description: __( 'Actions currently being worked through.', 'hey-woo' ),
	},
	{
		status: 'done',
		label: __( 'Done', 'hey-woo' ),
		description: __( 'Completed merchant follow-ups.', 'hey-woo' ),
	},
];

const PRIORITY_LABELS: Record< ActionPriority, string > = {
	high: __( 'High', 'hey-woo' ),
	medium: __( 'Medium', 'hey-woo' ),
	low: __( 'Low', 'hey-woo' ),
};

const STATUS_LABELS: Record< ActionStatus, string > = {
	todo: __( 'To do', 'hey-woo' ),
	doing: __( 'Doing', 'hey-woo' ),
	done: __( 'Done', 'hey-woo' ),
};

const EMPTY_FORM = {
	title: '',
	description: '',
	priority: 'medium' as ActionPriority,
};

function laneIndex( status: ActionStatus ): number {
	return LANES.findIndex( ( lane ) => lane.status === status );
}

function saveCardsWithMove( cards: HeyWooActionCard[], cardId: string, status: ActionStatus ): void {
	const now = Date.now();
	const nextCards = cards.map( ( card ) =>
		card.id === cardId
			? {
					...card,
					status,
					updatedAt: now,
					order: now,
			  }
			: card
	);

	saveActionCards( nextCards );
}

function ActionCard( {
	card,
	onMove,
	onDelete,
	onOpen,
	onDragStart,
}: {
	card: HeyWooActionCard;
	onMove: ( cardId: string, status: ActionStatus ) => void;
	onDelete: ( cardId: string ) => void;
	onOpen: ( cardId: string ) => void;
	onDragStart: ( cardId: string ) => void;
} ) {
	const currentLaneIndex = laneIndex( card.status );
	const previousLane = LANES[ currentLaneIndex - 1 ];
	const nextLane = LANES[ currentLaneIndex + 1 ];
	const sourceLabel = card.reportLabel ?? card.source;
	const summary = card.summary || card.description || card.evidence;
	const openCard = () => onOpen( card.id );
	const handleKeyDown = ( event: React.KeyboardEvent< HTMLElement > ) => {
		if ( event.key === 'Enter' || event.key === ' ' ) {
			event.preventDefault();
			openCard();
		}
	};
	const stopAction = ( event: React.MouseEvent ) => event.stopPropagation();

	return (
		<article
			className={ `hey-woo-action-card hey-woo-action-card--${ card.priority }` }
			draggable
			role="button"
			tabIndex={ 0 }
			aria-label={ sprintf(
				/* translators: %s: action card title */
				__( 'Open action details for %s', 'hey-woo' ),
				card.title
			) }
			onClick={ openCard }
			onKeyDown={ handleKeyDown }
			onDragStart={ () => onDragStart( card.id ) }
		>
			<header className="hey-woo-action-card__header">
				<div className="hey-woo-action-card__meta">
					<span className="hey-woo-action-card__priority">{ PRIORITY_LABELS[ card.priority ] }</span>
					<span className="hey-woo-action-card__status">{ STATUS_LABELS[ card.status ] }</span>
				</div>
				<Button
					type="button"
					icon={ trash }
					label={ __( 'Delete action', 'hey-woo' ) }
					size="compact"
					variant="tertiary"
					isDestructive
					onClick={ ( event ) => {
						stopAction( event );
						onDelete( card.id );
					} }
				/>
			</header>

			<h3>{ card.title }</h3>
			{ card.keyMetric && (
				<strong className="hey-woo-action-card__signal">{ card.keyMetric }</strong>
			) }
			{ summary && (
				<p className="hey-woo-action-card__summary">{ summary }</p>
			) }
			{ card.impact && (
				<p className="hey-woo-action-card__impact">{ card.impact }</p>
			) }

			<footer className="hey-woo-action-card__footer">
				<span>{ sourceLabel }</span>
				<div className="hey-woo-action-card__moves">
					<Button
						type="button"
						icon={ chevronLeft }
						label={ previousLane
							? sprintf(
									/* translators: %s: lane name */
									__( 'Move to %s', 'hey-woo' ),
									previousLane.label
							  )
							: __( 'No previous lane', 'hey-woo' ) }
						size="compact"
						variant="tertiary"
						disabled={ ! previousLane }
						onClick={ ( event ) => {
							stopAction( event );
							previousLane && onMove( card.id, previousLane.status );
						} }
					/>
					<Button
						type="button"
						icon={ chevronRight }
						label={ nextLane
							? sprintf(
									/* translators: %s: lane name */
									__( 'Move to %s', 'hey-woo' ),
									nextLane.label
							  )
							: __( 'No next lane', 'hey-woo' ) }
						size="compact"
						variant="tertiary"
						disabled={ ! nextLane }
						onClick={ ( event ) => {
							stopAction( event );
							nextLane && onMove( card.id, nextLane.status );
						} }
					/>
				</div>
			</footer>
		</article>
	);
}

function formatDate( timestamp: number ): string {
	return new Date( timestamp ).toLocaleDateString( undefined, {
		day: 'numeric',
		month: 'short',
		year: 'numeric',
	} );
}

function ActionDetailsModal( {
	card,
	onClose,
	onDelete,
	onUpdate,
}: {
	card: HeyWooActionCard;
	onClose: () => void;
	onDelete: ( cardId: string ) => void;
	onUpdate: ( cardId: string, updates: Partial< HeyWooActionCard > ) => void;
} ) {
	const hasSignals = Boolean( card.keyMetric || card.impact || card.expectedOutcome );
	const summary = card.summary || card.description;

	return (
		<Modal
			title={ __( 'Action details', 'hey-woo' ) }
			size="medium"
			className="hey-woo-actions-modal hey-woo-action-details-modal"
			onRequestClose={ onClose }
		>
			<div className="hey-woo-action-details">
				<header className="hey-woo-action-details__hero">
					<div className="hey-woo-action-details__hero-meta">
						<span className={ `hey-woo-action-details__priority hey-woo-action-details__priority--${ card.priority }` }>
							{ PRIORITY_LABELS[ card.priority ] }
						</span>
						<span>{ STATUS_LABELS[ card.status ] }</span>
						<span>{ card.reportLabel ?? card.source }</span>
					</div>
					<h2>{ card.title }</h2>
					{ summary && <p>{ summary }</p> }
				</header>

				<div className="hey-woo-action-details__controls">
					<SelectControl
						label={ __( 'Status', 'hey-woo' ) }
						value={ card.status }
						options={ [
							{ label: STATUS_LABELS.todo, value: 'todo' },
							{ label: STATUS_LABELS.doing, value: 'doing' },
							{ label: STATUS_LABELS.done, value: 'done' },
						] }
						onChange={ ( status ) => onUpdate( card.id, { status: status as ActionStatus } ) }
					/>
					<SelectControl
						label={ __( 'Priority', 'hey-woo' ) }
						value={ card.priority }
						options={ [
							{ label: PRIORITY_LABELS.high, value: 'high' },
							{ label: PRIORITY_LABELS.medium, value: 'medium' },
							{ label: PRIORITY_LABELS.low, value: 'low' },
						] }
						onChange={ ( priority ) => onUpdate( card.id, { priority: priority as ActionPriority } ) }
					/>
				</div>

				{ hasSignals && (
					<section className="hey-woo-action-details__section">
						<h3>{ __( 'Key signals', 'hey-woo' ) }</h3>
						<div className="hey-woo-action-details__signals">
							{ card.keyMetric && (
								<div>
									<span>{ __( 'Signal', 'hey-woo' ) }</span>
									<strong>{ card.keyMetric }</strong>
								</div>
							) }
							{ card.impact && (
								<div>
									<span>{ __( 'Impact', 'hey-woo' ) }</span>
									<strong>{ card.impact }</strong>
								</div>
							) }
							{ card.expectedOutcome && (
								<div>
									<span>{ __( 'Expected outcome', 'hey-woo' ) }</span>
									<strong>{ card.expectedOutcome }</strong>
								</div>
							) }
						</div>
					</section>
				) }

				{ card.evidence && (
					<section className="hey-woo-action-details__section">
						<h3>{ __( 'Why this matters', 'hey-woo' ) }</h3>
						<p>{ card.evidence }</p>
					</section>
				) }

				{ card.nextSteps?.length ? (
					<section className="hey-woo-action-details__section">
						<h3>{ __( 'Recommended next steps', 'hey-woo' ) }</h3>
						<ol>
							{ card.nextSteps.map( ( step ) => (
								<li key={ step }>{ step }</li>
							) ) }
						</ol>
					</section>
				) : null }

				<section className="hey-woo-action-details__section">
					<h3>{ __( 'Source context', 'hey-woo' ) }</h3>
					<dl className="hey-woo-action-details__meta">
						<div>
							<dt>{ __( 'Source', 'hey-woo' ) }</dt>
							<dd>{ card.reportLabel ?? card.source }</dd>
						</div>
						{ card.period && (
							<div>
								<dt>{ __( 'Period', 'hey-woo' ) }</dt>
								<dd>{ card.period }</dd>
							</div>
						) }
						<div>
							<dt>{ __( 'Created', 'hey-woo' ) }</dt>
							<dd>{ formatDate( card.createdAt ) }</dd>
						</div>
						<div>
							<dt>{ __( 'Updated', 'hey-woo' ) }</dt>
							<dd>{ formatDate( card.updatedAt ) }</dd>
						</div>
					</dl>
				</section>

				<div className="hey-woo-action-details__actions">
					<Button
						type="button"
						variant="tertiary"
						isDestructive
						onClick={ () => {
							onDelete( card.id );
							onClose();
						} }
					>
						{ __( 'Delete action', 'hey-woo' ) }
					</Button>
					<Button type="button" variant="primary" onClick={ onClose }>
						{ __( 'Done', 'hey-woo' ) }
					</Button>
				</div>
			</div>
		</Modal>
	);
}

export function stage() {
	const [ cards, setCards ] = useState< HeyWooActionCard[] >( () => loadActionCards() );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ isAddModalOpen, setIsAddModalOpen ] = useState( false );
	const [ selectedCardId, setSelectedCardId ] = useState< string | null >( null );
	const [ draggingCardId, setDraggingCardId ] = useState< string | null >( null );
	const groupedCards = useMemo(
		() => LANES.reduce< Record< ActionStatus, HeyWooActionCard[] > >( ( grouped, lane ) => {
			grouped[ lane.status ] = cards
				.filter( ( card ) => card.status === lane.status )
				.sort( ( a, b ) => a.order - b.order );

			return grouped;
		}, {
			todo: [],
			doing: [],
			done: [],
		} ),
		[ cards ]
	);
	const totalCards = cards.length;
	const selectedCard = selectedCardId
		? cards.find( ( card ) => card.id === selectedCardId )
		: undefined;

	useEffect( () => {
		const refreshCards = () => setCards( loadActionCards() );

		window.addEventListener( ACTIONS_UPDATED_EVENT, refreshCards );
		window.addEventListener( 'storage', refreshCards );

		return () => {
			window.removeEventListener( ACTIONS_UPDATED_EVENT, refreshCards );
			window.removeEventListener( 'storage', refreshCards );
		};
	}, [] );

	const updateCards = ( nextCards: HeyWooActionCard[] ) => {
		saveActionCards( nextCards );
		setCards( nextCards );
	};

	const moveCard = ( cardId: string, status: ActionStatus ) => {
		saveCardsWithMove( cards, cardId, status );
		setCards( loadActionCards() );
	};

	const deleteCard = ( cardId: string ) => {
		updateCards( cards.filter( ( card ) => card.id !== cardId ) );
	};

	const updateCard = ( cardId: string, updates: Partial< HeyWooActionCard > ) => {
		const now = Date.now();
		updateCards( cards.map( ( card ) => (
			card.id === cardId
				? {
						...card,
						...updates,
						updatedAt: now,
						order: updates.status && updates.status !== card.status ? now : card.order,
				  }
				: card
		) ) );
	};

	const closeAddModal = () => {
		setIsAddModalOpen( false );
		setForm( EMPTY_FORM );
	};

	const addManualCard = () => {
		const title = form.title.trim();

		if ( ! title ) {
			return;
		}

		addActionCard( {
			title,
			description: form.description.trim(),
			status: 'todo',
			priority: form.priority,
			source: __( 'Manual action', 'hey-woo' ),
		} );
		setCards( loadActionCards() );
		setForm( EMPTY_FORM );
		setIsAddModalOpen( false );
	};

	const dropCard = ( status: ActionStatus ) => {
		if ( draggingCardId ) {
			moveCard( draggingCardId, status );
			setDraggingCardId( null );
		}
	};

	return (
		<div className="hey-woo-page hey-woo-page--actions">
			<header className="hey-woo-actions-header">
				<div>
					<h1 className="hey-woo-actions-header__title">{ __( 'Actions', 'hey-woo' ) }</h1>
					<p className="hey-woo-actions-header__count">
						{ sprintf(
							/* translators: %d: number of action cards */
							_n( '%d action card', '%d action cards', totalCards, 'hey-woo' ),
							totalCards
						) }
					</p>
				</div>
				<Button
					type="button"
					variant="primary"
					icon={ plus }
					__next40pxDefaultSize
					onClick={ () => setIsAddModalOpen( true ) }
				>
					{ __( 'Add card', 'hey-woo' ) }
				</Button>
			</header>

			{ isAddModalOpen && (
				<Modal
					title={ __( 'Add card', 'hey-woo' ) }
					size="medium"
					className="hey-woo-actions-modal"
					onRequestClose={ closeAddModal }
				>
					<form
						className="hey-woo-actions-modal__form"
						onSubmit={ ( event ) => {
							event.preventDefault();
							addManualCard();
						} }
					>
						<TextControl
							label={ __( 'Action', 'hey-woo' ) }
							value={ form.title }
							onChange={ ( title ) => setForm( { ...form, title } ) }
							placeholder={ __( 'Follow up with the fulfilment team', 'hey-woo' ) }
						/>
						<TextareaControl
							label={ __( 'Details', 'hey-woo' ) }
							value={ form.description }
							onChange={ ( description ) => setForm( { ...form, description } ) }
							placeholder={ __( 'Add the context needed to complete this action.', 'hey-woo' ) }
							rows={ 4 }
						/>
						<SelectControl
							label={ __( 'Priority', 'hey-woo' ) }
							value={ form.priority }
							options={ [
								{ label: PRIORITY_LABELS.high, value: 'high' },
								{ label: PRIORITY_LABELS.medium, value: 'medium' },
								{ label: PRIORITY_LABELS.low, value: 'low' },
							] }
							onChange={ ( priority ) => setForm( { ...form, priority: priority as ActionPriority } ) }
						/>
						<div className="hey-woo-actions-modal__actions">
							<Button
								type="button"
								variant="tertiary"
								__next40pxDefaultSize
								onClick={ closeAddModal }
							>
								{ __( 'Cancel', 'hey-woo' ) }
							</Button>
							<Button
								type="submit"
								variant="primary"
								__next40pxDefaultSize
								disabled={ ! form.title.trim() }
							>
								{ __( 'Create card', 'hey-woo' ) }
							</Button>
						</div>
					</form>
				</Modal>
			) }

			{ selectedCard && (
				<ActionDetailsModal
					card={ selectedCard }
					onClose={ () => setSelectedCardId( null ) }
					onDelete={ deleteCard }
					onUpdate={ updateCard }
				/>
			) }

			<section className="hey-woo-dashboard-lanes" aria-label={ __( 'Action board', 'hey-woo' ) }>
				{ LANES.map( ( lane ) => {
					const laneCards = groupedCards[ lane.status ];

					return (
						<div
							key={ lane.status }
							className="hey-woo-action-lane"
							onDragOver={ ( event ) => event.preventDefault() }
							onDrop={ () => dropCard( lane.status ) }
						>
							<header className="hey-woo-action-lane__header">
								<div>
									<h2>{ lane.label }</h2>
									<p>{ lane.description }</p>
								</div>
								<span aria-label={ sprintf(
									/* translators: %d: number of cards in the lane */
									_n( '%d card', '%d cards', laneCards.length, 'hey-woo' ),
									laneCards.length
								) }>
									{ laneCards.length }
								</span>
							</header>

							<div className="hey-woo-action-lane__cards">
								{ laneCards.length === 0 && (
									<div className="hey-woo-action-lane__empty">
										{ __( 'Drop an action here.', 'hey-woo' ) }
									</div>
								) }

								{ laneCards.map( ( card ) => (
									<ActionCard
										key={ card.id }
										card={ card }
										onMove={ moveCard }
										onDelete={ deleteCard }
										onOpen={ setSelectedCardId }
										onDragStart={ setDraggingCardId }
									/>
								) ) }
							</div>
						</div>
					);
				} ) }
			</section>
		</div>
	);
}
