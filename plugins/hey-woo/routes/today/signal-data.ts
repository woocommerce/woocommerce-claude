/**
 * Types + REST client for the Today home surface.
 */
import { __ } from '@wordpress/i18n';
import moduleData from '../ai-insights/data';

export type SignalSeverity = 'high' | 'medium' | 'low';
export type SignalTone = 'positive' | 'negative';
export type KpiTone = 'positive' | 'negative' | 'neutral';
export type ChangeDirection = 'up' | 'down' | 'flat';

export interface SignalEvidence {
	label: string;
	value: string;
	change: string;
}

export interface SignalAction {
	title: string;
	detail: string;
	workflow_slug: string;
}

export interface Signal {
	slug: string;
	severity: SignalSeverity;
	tone: SignalTone;
	workflow_slug: string;
	title: string;
	summary: string;
	evidence: SignalEvidence;
	action: SignalAction;
	first_detected_at: number;
	last_detected_at: number;
	dismissed_at: number | null;
	snoozed_until: number | null;
}

export interface KpiCell {
	id: string;
	label: string;
	value: string;
	change_percent: number;
	change_direction: ChangeDirection;
	tone: KpiTone;
}

export interface KpiPeriod {
	start: string;
	end: string;
	label: string;
}

export interface RunResult {
	signals: Signal[];
	detected: string[];
	skipped: string[];
	errors: Array< { detector: string; error: string; message: string } >;
}

export interface SignalsResponse {
	signals: Signal[];
	kpis: KpiCell[];
	kpiPeriod: KpiPeriod | null;
	monitoringEnabled: boolean;
	nextRefreshAt: number | null;
}

const SIGNALS_ENDPOINT = '/this-week/signals';
const RUN_ENDPOINT = '/this-week/run';
const DISMISS_ENDPOINT = '/this-week/signals/dismiss';
const SNOOZE_ENDPOINT = '/this-week/signals/snooze';

function buildUrl( path: string ): string {
	const base = ( moduleData.restBase || '' ).replace( /\/difm\/?$/, '' ).replace( /\/$/, '' );
	return `${ base }${ path }`;
}

export class RunnerBusyError extends Error {
	constructor( message: string ) {
		super( message );
		this.name = 'RunnerBusyError';
	}
}

async function jsonRequest< T >(
	url: string,
	options: { method: 'GET' | 'POST'; body?: Record< string, unknown > }
): Promise< T > {
	const response = await fetch( url, {
		method: options.method,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': moduleData.nonce,
		},
		body: options.body ? JSON.stringify( options.body ) : undefined,
	} );

	if ( ! response.ok ) {
		let message = __( 'Something went wrong. Please try again.', 'hey-woo' );
		try {
			const json = ( await response.json() ) as { message?: unknown };
			if ( typeof json.message === 'string' && json.message.trim() ) {
				message = json.message;
			}
		} catch {
			// ignore body parse failures
		}

		if ( response.status === 409 ) {
			throw new RunnerBusyError( message );
		}

		throw new Error( message );
	}

	return ( await response.json() ) as T;
}

export async function fetchSignals(): Promise< SignalsResponse > {
	const result = await jsonRequest< {
		signals?: unknown;
		kpis?: unknown;
		kpi_period?: unknown;
		monitoring_enabled?: unknown;
		next_refresh_at?: unknown;
	} >( buildUrl( SIGNALS_ENDPOINT ), {
		method: 'GET',
	} );

	const kpiPeriodRaw = result.kpi_period as Partial< KpiPeriod > | undefined;

	return {
		signals: Array.isArray( result.signals ) ? ( result.signals as Signal[] ) : [],
		kpis: Array.isArray( result.kpis ) ? ( result.kpis as KpiCell[] ) : [],
		kpiPeriod: kpiPeriodRaw && typeof kpiPeriodRaw === 'object'
			? {
					start: typeof kpiPeriodRaw.start === 'string' ? kpiPeriodRaw.start : '',
					end: typeof kpiPeriodRaw.end === 'string' ? kpiPeriodRaw.end : '',
					label: typeof kpiPeriodRaw.label === 'string' ? kpiPeriodRaw.label : '',
			  }
			: null,
		monitoringEnabled: result.monitoring_enabled !== false,
		nextRefreshAt: typeof result.next_refresh_at === 'number' && result.next_refresh_at > 0
			? result.next_refresh_at
			: null,
	};
}

export async function runSignals( options: { skipAi?: boolean } = {} ): Promise< RunResult > {
	const result = await jsonRequest< RunResult >( buildUrl( RUN_ENDPOINT ), {
		method: 'POST',
		body: { skip_ai: Boolean( options.skipAi ) },
	} );

	return {
		signals: Array.isArray( result.signals ) ? result.signals : [],
		detected: Array.isArray( result.detected ) ? result.detected : [],
		skipped: Array.isArray( result.skipped ) ? result.skipped : [],
		errors: Array.isArray( result.errors ) ? result.errors : [],
	};
}

export async function dismissSignal( slug: string ): Promise< Signal[] > {
	const result = await jsonRequest< { signals: Signal[] } >( buildUrl( DISMISS_ENDPOINT ), {
		method: 'POST',
		body: { slug },
	} );
	return Array.isArray( result.signals ) ? result.signals : [];
}

export async function snoozeSignal( slug: string, snoozedUntil?: number ): Promise< Signal[] > {
	const result = await jsonRequest< { signals: Signal[] } >( buildUrl( SNOOZE_ENDPOINT ), {
		method: 'POST',
		body: snoozedUntil ? { slug, snoozed_until: snoozedUntil } : { slug },
	} );
	return Array.isArray( result.signals ) ? result.signals : [];
}

export function workflowChatPrompt( signal: Signal ): string {
	if ( ! signal.workflow_slug ) {
		return '';
	}

	const summary = signal.summary ? ` ${ signal.summary }` : '';
	return `/${ signal.workflow_slug }\nFollow up on this week's "${ signal.title }" signal.${ summary }`;
}
