/**
 * Local store tracking which workflows are currently running.
 *
 * Persisted to localStorage so the running indicator survives navigating
 * between Hey Woo routes within the same admin session.
 */
import { useEffect, useState } from '@wordpress/element';

const STORAGE_KEY = 'heyWooRunningWorkflows';
const UPDATED_EVENT = 'hey-woo-running-workflows-updated';

export interface RunningWorkflow {
	slug: string;
	conversationId: string;
	startedAt: number;
}

function readStore(): RunningWorkflow[] {
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );
		if ( ! raw ) {
			return [];
		}
		const parsed = JSON.parse( raw );
		if ( ! Array.isArray( parsed ) ) {
			return [];
		}
		return parsed.filter(
			( item ): item is RunningWorkflow =>
				item &&
				typeof item.slug === 'string' &&
				typeof item.conversationId === 'string' &&
				typeof item.startedAt === 'number'
		);
	} catch ( _err ) {
		return [];
	}
}

function writeStore( running: RunningWorkflow[] ): void {
	try {
		window.localStorage.setItem( STORAGE_KEY, JSON.stringify( running ) );
	} catch ( _err ) {
		// Ignore quota or privacy-mode failures.
	}
	window.dispatchEvent(
		new CustomEvent< RunningWorkflow[] >( UPDATED_EVENT, { detail: running } )
	);
}

export function markWorkflowRunning( slug: string, conversationId: string ): void {
	const current = readStore().filter( ( item ) => item.slug !== slug );
	current.push( { slug, conversationId, startedAt: Date.now() } );
	writeStore( current );
}

export function clearWorkflowRunning( slug: string ): void {
	const next = readStore().filter( ( item ) => item.slug !== slug );
	writeStore( next );
}

export function useRunningWorkflows(): RunningWorkflow[] {
	const [ running, setRunning ] = useState< RunningWorkflow[] >( () => readStore() );

	useEffect( () => {
		const handleUpdate = ( event: Event ) => {
			const customEvent = event as CustomEvent< RunningWorkflow[] >;
			if ( Array.isArray( customEvent.detail ) ) {
				setRunning( customEvent.detail );
			}
		};

		const handleStorage = ( event: StorageEvent ) => {
			if ( event.key === STORAGE_KEY ) {
				setRunning( readStore() );
			}
		};

		window.addEventListener( UPDATED_EVENT, handleUpdate );
		window.addEventListener( 'storage', handleStorage );

		return () => {
			window.removeEventListener( UPDATED_EVENT, handleUpdate );
			window.removeEventListener( 'storage', handleStorage );
		};
	}, [] );

	return running;
}
