import { __, sprintf } from '@wordpress/i18n';
import { useIdeaBoard } from './useIdeaBoard';
import type { IdeaBoardArrow, IdeaBoardData, IdeaBoardNote } from './types';

interface IdeaBoardProps {
	restBase: string;
	nonce: string;
	days?: number;
}

const NOTE_WIDTH = 18;
const NOTE_HEIGHT = 17;

export function IdeaBoard( { restBase, nonce, days = 90 }: IdeaBoardProps ) {
	const { board, status, errorMessage, refresh } = useIdeaBoard( { restBase, nonce, days } );

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
				<button
					type="button"
					className="button button-secondary hey-woo-idea-header__refresh"
					onClick={ refresh }
					disabled={ status === 'loading' }
				>
					{ status === 'loading'
						? __( 'Refreshing', 'woocommerce-claude' )
						: __( 'Refresh', 'woocommerce-claude' ) }
				</button>
			</header>

			{ status === 'error' && (
				<div className="hey-woo-idea-error" role="alert">
					{ errorMessage }
				</div>
			) }

			{ status === 'loading' && ! board && (
				<div className="hey-woo-idea-loading">
					<span className="hey-woo-idea-loading__pin" />
					{ __( 'Building the board…', 'woocommerce-claude' ) }
				</div>
			) }

			{ board && <Board board={ board } /> }
		</div>
	);
}

function Board( { board }: { board: IdeaBoardData } ) {
	return (
		<section className="hey-woo-idea-board" aria-label={ board.title }>
			<BoardArrowLayer notes={ board.notes } arrows={ board.arrows } />
			<div className="hey-woo-idea-board__rail" aria-hidden="true">
				<span />
				<span />
				<span />
			</div>
			{ board.notes.map( ( note ) => (
				<StickyNote key={ note.id } note={ note } />
			) ) }
		</section>
	);
}

function StickyNote( { note }: { note: IdeaBoardNote } ) {
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
			<span className="hey-woo-idea-note__type">{ note.type }</span>
			<h2>{ note.title }</h2>
			<p>{ note.body }</p>
		</article>
	);
}

function BoardArrowLayer( { notes, arrows }: { notes: IdeaBoardNote[]; arrows: IdeaBoardArrow[] } ) {
	const notesById = new Map( notes.map( ( note ) => [ note.id, note ] ) );

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

function formatDate( value: string ) {
	return new Intl.DateTimeFormat( undefined, { month: 'short', day: 'numeric' } ).format(
		new Date( `${ value }T00:00:00` )
	);
}
