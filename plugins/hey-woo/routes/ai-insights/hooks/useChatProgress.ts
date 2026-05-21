/**
 * useChatProgress — poll the chat-progress endpoint while a chat turn runs.
 *
 * The chat REST call is a single synchronous PHP request, so during a turn
 * the client cannot observe what tool the controller is executing. The
 * controller writes a per-request transient at each tool boundary; this
 * hook reads it on a short cadence and exposes the latest payload, so the
 * progress component can swap "Hey Woo is working" for "Looking up store
 * totals" / "Reading product details" / etc.
 *
 * Polling is opt-in via the `enabled` flag — the workspace flips it on
 * when sendMessage starts and off when the chat fetch resolves or errors.
 * When `enabled` is false (or `progressId` is missing) the hook clears
 * any prior payload so a stale tool name does not flash on the next turn.
 */
import { useEffect, useState } from '@wordpress/element';
import moduleData from '../data';

/** Cadence at which we re-read the progress transient, in milliseconds. */
const PROGRESS_POLL_INTERVAL_MS = 600;

export type ChatProgressPhase = 'execute' | 'captured' | 'complete';

export interface ChatProgressPayload {
	tool: string | null;
	phase: ChatProgressPhase | null;
	timestamp: number | null;
}

const EMPTY_PAYLOAD: ChatProgressPayload = {
	tool: null,
	phase: null,
	timestamp: null,
};

interface ProgressResponse {
	status?: unknown;
	tool?: unknown;
	phase?: unknown;
	timestamp?: unknown;
}

function isChatProgressPhase( value: unknown ): value is ChatProgressPhase {
	return 'execute' === value || 'captured' === value || 'complete' === value;
}

function parseProgress( raw: unknown ): ChatProgressPayload {
	if ( ! raw || typeof raw !== 'object' ) {
		return EMPTY_PAYLOAD;
	}

	const candidate = raw as ProgressResponse;
	if ( candidate.status !== 'ok' ) {
		return EMPTY_PAYLOAD;
	}

	return {
		tool: typeof candidate.tool === 'string' && candidate.tool !== '' ? candidate.tool : null,
		phase: isChatProgressPhase( candidate.phase ) ? candidate.phase : null,
		timestamp: typeof candidate.timestamp === 'number' ? candidate.timestamp : null,
	};
}

/**
 * Subscribe to chat progress events for a single chat turn.
 *
 * @param progressId Per-turn identifier the chat POST also supplied to the
 *                   server. Undefined while no chat is active.
 * @param enabled    Whether to actively poll. Flip off when the chat call
 *                   resolves so we don't keep hitting the endpoint after
 *                   the assistant message lands.
 */
export function useChatProgress(
	progressId: string | undefined,
	enabled: boolean
): ChatProgressPayload {
	const [ payload, setPayload ] = useState< ChatProgressPayload >( EMPTY_PAYLOAD );

	useEffect( () => {
		if ( ! progressId || ! enabled ) {
			setPayload( EMPTY_PAYLOAD );
			return;
		}

		let cancelled = false;
		let timer: ReturnType< typeof setTimeout > | undefined;

		const tick = async () => {
			if ( cancelled ) {
				return;
			}

			try {
				const response = await fetch(
					moduleData.restBase +
						'/chat-progress?progress_id=' +
						encodeURIComponent( progressId ),
					{
						headers: {
							'X-WP-Nonce': moduleData.nonce,
						},
					}
				);

				if ( cancelled ) {
					return;
				}

				if ( response.ok ) {
					const json = ( await response.json() ) as unknown;
					if ( cancelled ) {
						return;
					}
					const next = parseProgress( json );
					setPayload( ( prev ) => {
						if (
							prev.tool === next.tool &&
							prev.phase === next.phase &&
							prev.timestamp === next.timestamp
						) {
							return prev;
						}
						return next;
					} );
				}
			} catch ( _err ) {
				// Polling is best-effort; swallow transient errors so the
				// generic working copy stays visible without flicker.
			}

			if ( cancelled ) {
				return;
			}

			timer = setTimeout( tick, PROGRESS_POLL_INTERVAL_MS );
		};

		void tick();

		return () => {
			cancelled = true;
			if ( timer ) {
				clearTimeout( timer );
			}
		};
	}, [ progressId, enabled ] );

	return payload;
}
