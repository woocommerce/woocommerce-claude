/**
 * Parse machine-readable recommended actions from generated reports.
 */
import type { ActionPriority } from '../actions/action-store';
import type { RecommendedReportAction } from './components/ReportActionCards';

export interface RawReportAction {
	title?: unknown;
	priority?: unknown;
	summary?: unknown;
	key_metric?: unknown;
	keyMetric?: unknown;
	impact?: unknown;
	evidence?: unknown;
	next_steps?: unknown;
	nextSteps?: unknown;
	expected_outcome?: unknown;
	expectedOutcome?: unknown;
}

interface ParsedReportActions {
	content: string;
	actions: RecommendedReportAction[];
}

const ACTION_BLOCK_PATTERN = /```hey-woo-actions\s*([\s\S]*?)```/gi;

function priorityFromValue( value: unknown ): ActionPriority {
	const priority = typeof value === 'string' ? value.toLowerCase() : '';

	if ( priority === 'high' || priority === 'medium' || priority === 'low' ) {
		return priority;
	}

	return 'medium';
}

function normaliseSteps( value: unknown ): string[] {
	if ( Array.isArray( value ) ) {
		return value
			.map( ( item ) => typeof item === 'string' ? item.trim() : '' )
			.filter( Boolean )
			.slice( 0, 5 );
	}

	if ( typeof value === 'string' && value.trim() ) {
		return [ value.trim() ];
	}

	return [];
}

function normaliseText( value: unknown ): string | undefined {
	return typeof value === 'string' && value.trim() ? value.trim() : undefined;
}

export function normaliseReportAction( rawAction: RawReportAction ): RecommendedReportAction | null {
	if ( ! rawAction || typeof rawAction !== 'object' ) {
		return null;
	}

	const title = typeof rawAction.title === 'string' ? rawAction.title.trim() : '';

	if ( ! title ) {
		return null;
	}

	return {
		title,
		priority: priorityFromValue( rawAction.priority ),
		summary: normaliseText( rawAction.summary ),
		keyMetric: normaliseText( rawAction.key_metric ?? rawAction.keyMetric ),
		impact: normaliseText( rawAction.impact ),
		evidence: normaliseText( rawAction.evidence ) || '',
		nextSteps: normaliseSteps( rawAction.next_steps ?? rawAction.nextSteps ),
		expectedOutcome: normaliseText( rawAction.expected_outcome ?? rawAction.expectedOutcome ),
	};
}

export function parseActionsJson( value: string ): RecommendedReportAction[] {
	try {
		const parsed = JSON.parse( value );

		if ( ! Array.isArray( parsed ) ) {
			return [];
		}

		return parsed
			.map( normaliseReportAction )
			.filter( ( action ): action is RecommendedReportAction => action !== null )
			.slice( 0, 6 );
	} catch {
		return [];
	}
}

export function parseReportActions( content: string ): ParsedReportActions {
	const actions: RecommendedReportAction[] = [];
	const cleanedContent = content.replace( ACTION_BLOCK_PATTERN, ( _match, json ) => {
		actions.push( ...parseActionsJson( json ) );
		return '';
	} ).trim();

	return {
		content: cleanedContent,
		actions,
	};
}
