import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { useIdeaBoard } from './useIdeaBoard';
import type { IdeaBoardArrow, IdeaBoardData, IdeaBoardNote } from './types';
import type { FormEvent } from 'react';

interface IdeaBoardProps {
	restBase: string;
	nonce: string;
	days?: number;
}

const NOTE_WIDTH = 18;
const NOTE_HEIGHT = 17;
const MAX_NOTES = 12;

type AddedNoteType = 'insight' | 'idea' | 'question';

export function IdeaBoard( { restBase, nonce, days = 90 }: IdeaBoardProps ) {
	const { board, status, errorMessage, refresh, reanalyse, saveBoard, updateBoard } = useIdeaBoard( { restBase, nonce, days } );
	const [ isAddingCard, setIsAddingCard ] = useState( false );
	const [ newCardType, setNewCardType ] = useState< AddedNoteType >( 'idea' );
	const [ newCardTitle, setNewCardTitle ] = useState( '' );
	const [ newCardBody, setNewCardBody ] = useState( '' );
	const isBusy = status === 'loading' || status === 'reanalysing';
	const canAddCard = Boolean( board && board.notes.length < MAX_NOTES );
	const canReanalyse = Boolean( board && board.notes.length > 0 && ! isBusy );

	const removeNote = ( noteId: string ) => {
		if ( ! board ) {
			return;
		}

		const updatedBoard = {
			...board,
			notes: board.notes.filter( ( note ) => note.id !== noteId ),
			arrows: board.arrows.filter( ( arrow ) => arrow.from !== noteId && arrow.to !== noteId ),
		};
		updateBoard( updatedBoard );
		void saveBoard( updatedBoard );
	};

	const addCard = ( event: FormEvent< HTMLFormElement > ) => {
		event.preventDefault();
		if ( ! board || ! canAddCard ) {
			return;
		}

		const title = newCardTitle.trim();
		const body = newCardBody.trim();
		if ( ! title || ! body ) {
			return;
		}

		const ids = new Set( board.notes.map( ( note ) => note.id ) );
		const id = createMerchantNoteId( title, ids );
		const position = addedNotePosition( board.notes.length );
		const note: IdeaBoardNote = {
			id,
			type: newCardType,
			title: truncateText( title, 70 ),
			body: truncateText( body, 190 ),
			colour: colourForAddedNote( newCardType ),
			x: position.x,
			y: position.y,
			rotation: defaultAddedNoteRotation( board.notes.length ),
			prompt: truncateText(
				sprintf(
					/* translators: 1: card type, 2: card title, 3: card body. */
					__( 'Explore this merchant-added %1$s: %2$s. %3$s', 'woocommerce-claude' ),
					newCardType,
					title,
					body
				),
				220
			),
			confidence: 'medium',
		};

		const updatedBoard = {
			...board,
			notes: [ ...board.notes, note ],
		};

		updateBoard( updatedBoard );
		void saveBoard( updatedBoard );
		setNewCardTitle( '' );
		setNewCardBody( '' );
		setIsAddingCard( false );
	};

	return (
		<div className="hey-woo-idea-page">
			<header className="hey-woo-idea-header">
				<div>
					<h1>{ __( 'Idea board', 'woocommerce-claude' ) }</h1>
					{ board && (
						<p>
							{ sprintf(
								/* translators: 1: period label, 2: start date, 3: end date. */
								__( '%1$s, %2$s to %3$s', 'woocommerce-claude' ),
								board.period.label,
								formatDate( board.period.start ),
								formatDate( board.period.end )
							) }
						</p>
					) }
				</div>
				<div className="hey-woo-idea-header__actions">
					{ board && (
						<>
							<button
								type="button"
								className="button button-secondary"
								onClick={ () => setIsAddingCard( ( value ) => ! value ) }
								disabled={ ! canAddCard || isBusy }
							>
								{ __( 'Add card', 'woocommerce-claude' ) }
							</button>
							<button
								type="button"
								className="button button-primary"
								onClick={ () => reanalyse( board ) }
								disabled={ ! canReanalyse }
							>
								{ status === 'reanalysing'
									? __( 'Re-analysing', 'woocommerce-claude' )
									: __( 'Re-analyse board', 'woocommerce-claude' ) }
							</button>
						</>
					) }
					<button
						type="button"
						className="button button-secondary"
						onClick={ () => refresh() }
						disabled={ isBusy }
					>
						{ status === 'loading'
							? __( 'Refreshing', 'woocommerce-claude' )
							: __( 'Refresh', 'woocommerce-claude' ) }
					</button>
				</div>
			</header>

			{ status === 'error' && (
				<div className="hey-woo-idea-error" role="alert">
					{ errorMessage }
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
					<button type="button" className="button button-secondary" onClick={ () => refresh() } disabled={ isBusy }>
						{ __( 'Refresh board', 'woocommerce-claude' ) }
					</button>
				</div>
			) }

			{ board && isAddingCard && (
				<form className="hey-woo-idea-add-card" onSubmit={ addCard }>
					<label>
						<span>{ __( 'Type', 'woocommerce-claude' ) }</span>
						<select
							value={ newCardType }
							onChange={ ( event ) => setNewCardType( event.currentTarget.value as AddedNoteType ) }
							disabled={ isBusy }
						>
							<option value="insight">{ __( 'Insight', 'woocommerce-claude' ) }</option>
							<option value="idea">{ __( 'Idea', 'woocommerce-claude' ) }</option>
							<option value="question">{ __( 'Question', 'woocommerce-claude' ) }</option>
						</select>
					</label>
					<label>
						<span>{ __( 'Title', 'woocommerce-claude' ) }</span>
						<input
							type="text"
							value={ newCardTitle }
							onChange={ ( event ) => setNewCardTitle( event.currentTarget.value ) }
							maxLength={ 70 }
							disabled={ isBusy }
						/>
					</label>
					<label className="hey-woo-idea-add-card__body">
						<span>{ __( 'Body', 'woocommerce-claude' ) }</span>
						<textarea
							value={ newCardBody }
							onChange={ ( event ) => setNewCardBody( event.currentTarget.value ) }
							maxLength={ 190 }
							rows={ 2 }
							disabled={ isBusy }
						/>
					</label>
					<div className="hey-woo-idea-add-card__actions">
						<button
							type="button"
							className="button button-secondary"
							onClick={ () => setIsAddingCard( false ) }
							disabled={ isBusy }
						>
							{ __( 'Cancel', 'woocommerce-claude' ) }
						</button>
						<button
							type="submit"
							className="button button-primary"
							disabled={ isBusy || ! newCardTitle.trim() || ! newCardBody.trim() }
						>
							{ __( 'Add to board', 'woocommerce-claude' ) }
						</button>
					</div>
				</form>
			) }

			{ status === 'loading' && ! board && (
				<div className="hey-woo-idea-loading">
					<span className="hey-woo-idea-loading__pin" />
					{ __( 'Building the board…', 'woocommerce-claude' ) }
				</div>
			) }

			{ board && <Board board={ board } onRemoveNote={ removeNote } isBusy={ isBusy } /> }
		</div>
	);
}

function Board( {
	board,
	onRemoveNote,
	isBusy,
}: {
	board: IdeaBoardData;
	onRemoveNote: ( noteId: string ) => void;
	isBusy: boolean;
} ) {
	return (
		<section className="hey-woo-idea-board" aria-label={ board.title }>
			<BoardArrowLayer notes={ board.notes } arrows={ board.arrows } />
			{ board.notes.map( ( note ) => (
				<StickyNote key={ note.id } note={ note } onRemove={ onRemoveNote } isBusy={ isBusy } />
			) ) }
		</section>
	);
}

function StickyNote( {
	note,
	onRemove,
	isBusy,
}: {
	note: IdeaBoardNote;
	onRemove: ( noteId: string ) => void;
	isBusy: boolean;
} ) {
	return (
		<article
			className={ `hey-woo-idea-note hey-woo-idea-note--${ note.type } hey-woo-idea-note--${ note.colour }` }
			style={ {
				left: `${ note.x }%`,
				top: `${ note.y }%`,
				transform: `rotate(${ note.rotation }deg)`,
			} }
		>
			<span className="hey-woo-idea-note__pin" aria-hidden="true" />
			<button
				type="button"
				className="hey-woo-idea-note__remove"
				onClick={ () => onRemove( note.id ) }
				disabled={ isBusy }
				aria-label={ sprintf(
					/* translators: %s: note title. */
					__( 'Remove %s', 'woocommerce-claude' ),
					note.title
				) }
				title={ __( 'Remove card', 'woocommerce-claude' ) }
			>
				<span aria-hidden="true">×</span>
			</button>
			<span className="hey-woo-idea-note__type">{ note.type }</span>
			<h2>{ note.title }</h2>
			<p>{ note.body }</p>
		</article>
	);
}

function BoardArrowLayer( { notes, arrows }: { notes: IdeaBoardNote[]; arrows: IdeaBoardArrow[] } ) {
	const notesById = useMemo( () => new Map( notes.map( ( note ) => [ note.id, note ] ) ), [ notes ] );

	return (
		<svg className="hey-woo-idea-arrows" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
			<defs>
				<marker
					id="hey-woo-idea-arrowhead"
					markerWidth="4"
					markerHeight="4"
					refX="3.5"
					refY="2"
					orient="auto"
				>
					<path className="hey-woo-idea-arrows__head" d="M0,0 L4,2 L0,4 Z" />
				</marker>
			</defs>
			{ arrows.map( ( arrow, index ) => {
				const from = notesById.get( arrow.from );
				const to = notesById.get( arrow.to );
				if ( ! from || ! to ) {
					return null;
				}

				const fromCenter = noteCenter( from );
				const toCenter = noteCenter( to );
				const dx = toCenter.x - fromCenter.x;
				const dy = toCenter.y - fromCenter.y;
				const start = noteEdgePoint( from, dx, dy, 0.8 );
				const end = noteEdgePoint( to, -dx, -dy, 0.8 );
				const curve = arrowCurve( start, end, index );

				return (
					<path
						className="hey-woo-idea-arrows__line"
						key={ `${ arrow.from }-${ arrow.to }` }
						d={ `M ${ start.x } ${ start.y } C ${ curve.controlStart.x } ${ curve.controlStart.y }, ${ curve.controlEnd.x } ${ curve.controlEnd.y }, ${ end.x } ${ end.y }` }
					/>
				);
			} ) }
		</svg>
	);
}

function noteCenter( note: IdeaBoardNote ) {
	return {
		x: note.x + NOTE_WIDTH / 2,
		y: note.y + NOTE_HEIGHT / 2,
	};
}

function arrowCurve( start: { x: number; y: number }, end: { x: number; y: number }, index: number ) {
	const dx = end.x - start.x;
	const dy = end.y - start.y;
	const length = Math.sqrt( dx * dx + dy * dy ) || 1;
	const perpX = -dy / length;
	const perpY = dx / length;
	const bend = length > 28 ? ( index % 2 === 0 ? -1 : 1 ) * Math.min( length * 0.04, 2.4 ) : 0;

	return {
		controlStart: {
			x: clamp( start.x + dx * 0.34 + perpX * bend, 3, 97 ),
			y: clamp( start.y + dy * 0.34 + perpY * bend, 3, 97 ),
		},
		controlEnd: {
			x: clamp( start.x + dx * 0.68 + perpX * bend, 3, 97 ),
			y: clamp( start.y + dy * 0.68 + perpY * bend, 3, 97 ),
		},
	};
}

function noteEdgePoint( note: IdeaBoardNote, dx: number, dy: number, margin: number ) {
	const center = noteCenter( note );
	const absX = Math.abs( dx );
	const absY = Math.abs( dy );
	const xScale = absX > 0 ? NOTE_WIDTH / 2 / absX : Number.POSITIVE_INFINITY;
	const yScale = absY > 0 ? NOTE_HEIGHT / 2 / absY : Number.POSITIVE_INFINITY;
	const scale = Math.min( xScale, yScale );
	const length = Math.sqrt( dx * dx + dy * dy ) || 1;

	return {
		x: clamp( center.x + dx * scale + ( dx / length ) * margin, 3, 97 ),
		y: clamp( center.y + dy * scale + ( dy / length ) * margin, 3, 97 ),
	};
}

function clamp( value: number, min: number, max: number ) {
	return Math.min( Math.max( value, min ), max );
}

function addedNotePosition( index: number ) {
	const slot = index % MAX_NOTES;
	return {
		x: 7 + ( slot % 3 ) * 25,
		y: 10 + Math.floor( slot / 3 ) * 17,
	};
}

function defaultAddedNoteRotation( index: number ) {
	const rotations = [ -1, 2, -2, 1, 0, -1 ];
	return rotations[ index % rotations.length ];
}

function colourForAddedNote( type: AddedNoteType ): IdeaBoardNote['colour'] {
	if ( type === 'insight' ) {
		return 'yellow';
	}

	return type === 'idea' ? 'orange' : 'white';
}

function createMerchantNoteId( title: string, existingIds: Set< string > ) {
	const base = slugify( title ) || 'card';
	let id = `merchant-${ base }`;
	let suffix = 2;

	while ( existingIds.has( id ) ) {
		id = `merchant-${ base }-${ suffix }`;
		suffix++;
	}

	return id;
}

function slugify( value: string ) {
	return value
		.toLowerCase()
		.normalize( 'NFKD' )
		.replace( /[\u0300-\u036f]/g, '' )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' )
		.slice( 0, 42 );
}

function truncateText( value: string, limit: number ) {
	return value.length <= limit ? value : `${ value.slice( 0, limit - 3 ).trim() }...`;
}

function formatDate( value: string ) {
	return new Intl.DateTimeFormat( undefined, { month: 'short', day: 'numeric' } ).format(
		new Date( `${ value }T00:00:00` )
	);
}
