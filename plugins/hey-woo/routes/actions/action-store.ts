/**
 * Shared local action-card store for the Actions board and Reports flow.
 */
import { __ } from '@wordpress/i18n';

export type ActionStatus = 'todo' | 'doing' | 'done';
export type ActionPriority = 'high' | 'medium' | 'low';

export interface HeyWooActionCard {
	id: string;
	title: string;
	description: string;
	summary?: string;
	keyMetric?: string;
	impact?: string;
	evidence?: string;
	nextSteps?: string[];
	expectedOutcome?: string;
	status: ActionStatus;
	priority: ActionPriority;
	source: string;
	reportSlug?: string;
	reportLabel?: string;
	period?: string;
	createdAt: number;
	updatedAt: number;
	order: number;
}

const STORAGE_KEY = 'heyWooActionCards';
export const ACTIONS_UPDATED_EVENT = 'hey-woo-actions-updated';

function normaliseText( value: unknown ): string | undefined {
	return typeof value === 'string' && value.trim() ? value.trim() : undefined;
}

function normaliseTextList( value: unknown, limit = 8 ): string[] {
	if ( ! Array.isArray( value ) ) {
		return [];
	}

	return value
		.map( ( item ) => normaliseText( item ) )
		.filter( ( item ): item is string => Boolean( item ) )
		.slice( 0, limit );
}

function splitLegacyDescription( description: string ): { evidence: string; nextSteps: string[] } {
	const match = description.match( /\bNext steps:\s*/i );

	if ( ! match || undefined === match.index ) {
		return {
			evidence: description.trim(),
			nextSteps: [],
		};
	}

	const evidence = description.slice( 0, match.index ).trim();
	const stepsText = description.slice( match.index + match[0].length ).trim();
	const numberedSteps = stepsText
		.split( /\s+(?=\d+\.\s)/ )
		.map( ( step ) => step.replace( /^\d+\.\s*/, '' ).trim() )
		.filter( Boolean );

	return {
		evidence,
		nextSteps: numberedSteps.length
			? numberedSteps.slice( 0, 8 )
			: stepsText.split( /;\s*/ ).map( ( step ) => step.trim() ).filter( Boolean ).slice( 0, 8 ),
	};
}

function headlineFromText( text: string ): string | undefined {
	const sentences = text.match( /[^.!?]+[.!?]?/g ) || [];
	const metricSentence = sentences.find( ( sentence ) => /(?:\d|£|\$|€|%)/.test( sentence ) );
	const headline = ( metricSentence || sentences[0] || '' ).trim();

	if ( ! headline ) {
		return undefined;
	}

	return headline.length > 120 ? `${ headline.slice( 0, 117 ) }...` : headline;
}

function createId(): string {
	if ( window.crypto?.randomUUID ) {
		return window.crypto.randomUUID();
	}

	return `action-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }`;
}

function normaliseCard( card: Partial< HeyWooActionCard > ): HeyWooActionCard | null {
	if ( ! card.id || ! card.title ) {
		return null;
	}

	const now = Date.now();
	const status: ActionStatus = [ 'todo', 'doing', 'done' ].includes( card.status ?? '' )
		? card.status as ActionStatus
		: 'todo';
	const priority: ActionPriority = [ 'high', 'medium', 'low' ].includes( card.priority ?? '' )
		? card.priority as ActionPriority
		: 'medium';
	const description = String( card.description ?? '' );
	const legacyDetails = splitLegacyDescription( description );
	const evidence = normaliseText( card.evidence ) || legacyDetails.evidence;
	const nextSteps = normaliseTextList( card.nextSteps );

	return {
		id: String( card.id ),
		title: String( card.title ),
		description,
		summary: normaliseText( card.summary ) || headlineFromText( evidence || description ),
		keyMetric: normaliseText( card.keyMetric ) || headlineFromText( evidence ),
		impact: normaliseText( card.impact ),
		evidence,
		nextSteps: nextSteps.length ? nextSteps : legacyDetails.nextSteps,
		expectedOutcome: normaliseText( card.expectedOutcome ),
		status,
		priority,
		source: String( card.source ?? __( 'Manual action', 'hey-woo' ) ),
		reportSlug: card.reportSlug ? String( card.reportSlug ) : undefined,
		reportLabel: card.reportLabel ? String( card.reportLabel ) : undefined,
		period: card.period ? String( card.period ) : undefined,
		createdAt: Number.isFinite( card.createdAt ) ? Number( card.createdAt ) : now,
		updatedAt: Number.isFinite( card.updatedAt ) ? Number( card.updatedAt ) : now,
		order: Number.isFinite( card.order ) ? Number( card.order ) : now,
	};
}

function notifyUpdated(): void {
	window.dispatchEvent( new CustomEvent( ACTIONS_UPDATED_EVENT ) );
}

export function loadActionCards(): HeyWooActionCard[] {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );
		const parsed = raw ? JSON.parse( raw ) : [];

		if ( ! Array.isArray( parsed ) ) {
			return [];
		}

		return parsed
			.map( normaliseCard )
			.filter( ( card ): card is HeyWooActionCard => card !== null )
			.sort( ( a, b ) => a.order - b.order );
	} catch {
		return [];
	}
}

export function saveActionCards( cards: HeyWooActionCard[] ): void {
	window.localStorage.setItem( STORAGE_KEY, JSON.stringify( cards ) );
	notifyUpdated();
}

export function addActionCard( card: Omit< HeyWooActionCard, 'id' | 'createdAt' | 'updatedAt' | 'order' > ): HeyWooActionCard {
	const now = Date.now();
	const nextCard: HeyWooActionCard = {
		...card,
		id: createId(),
		createdAt: now,
		updatedAt: now,
		order: now,
	};

	saveActionCards( [ nextCard, ...loadActionCards() ] );

	return nextCard;
}
