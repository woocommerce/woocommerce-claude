/**
 * Types + REST client for the This Week home surface.
 */
import { __ } from '@wordpress/i18n';
import moduleData from '../ai-insights/data';

export type SignalSeverity = 'high' | 'medium' | 'low';

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

export interface RunResult {
	signals: Signal[];
	detected: string[];
	skipped: string[];
	errors: Array< { detector: string; error: string; message: string } >;
}

const SIGNALS_ENDPOINT = '/this-week/signals';
const RUN_ENDPOINT = '/this-week/run';
const DISMISS_ENDPOINT = '/this-week/signals/dismiss';
const SNOOZE_ENDPOINT = '/this-week/signals/snooze';

function buildUrl( path: string ): string {
	const base = ( moduleData.restBase || '' ).replace( /\/difm\/?$/, '' ).replace( /\/$/, '' );
	return `${ base }${ path }`;
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

		throw new Error( message );
	}

	return ( await response.json() ) as T;
}

export async function fetchSignals(): Promise< Signal[] > {
	const result = await jsonRequest< { signals: Signal[] } >( buildUrl( SIGNALS_ENDPOINT ), {
		method: 'GET',
	} );
	return Array.isArray( result.signals ) ? result.signals : [];
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

export function severityLabel( severity: SignalSeverity ): string {
	switch ( severity ) {
		case 'high':
			return __( 'High', 'hey-woo' );
		case 'low':
			return __( 'Low', 'hey-woo' );
		default:
			return __( 'Medium', 'hey-woo' );
	}
}

export function workflowChatPrompt( signal: Signal ): string {
	if ( ! signal.workflow_slug ) {
		return '';
	}

	const summary = signal.summary ? ` ${ signal.summary }` : '';
	return `/${ signal.workflow_slug }\nFollow up on this week's "${ signal.title }" signal.${ summary }`;
}
