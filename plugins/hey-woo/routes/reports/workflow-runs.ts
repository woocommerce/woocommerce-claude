/**
 * Client-side workflow run launcher.
 */
import { __, sprintf } from '@wordpress/i18n';
import moduleData from '../ai-insights/data';
import { saveConversationRecord } from '../ai-insights/hooks/useConversations';
import type {
	ChatMessage,
	ChatResponse,
	StoredConversation,
	WorkflowRunMeta,
} from '../ai-insights/types';
import type { WorkflowAction } from '../ai-insights/workflows';
import {
	buildWorkflowPrompt,
	getReportMetadata,
	getWorkflowBySlug,
	PERIOD_LABELS,
	type PeriodOption,
	type RunMode,
} from './report-data';

const WORKFLOW_TIMEOUT_MS = 180_000;

export interface WorkflowRunOptions {
	actionCards: boolean;
	adminNotification: boolean;
	compare: boolean;
	day: string;
	period: PeriodOption;
	runMode: RunMode;
	time: string;
	workflow: WorkflowAction;
}

export function workflowFromSlashCommand( message: string ): WorkflowAction | undefined {
	const match = message.trim().match( /^\/(?:hey-woo:)?([a-z0-9-]+)\b/i );
	return match ? getWorkflowBySlug( match[1] ) : undefined;
}

function periodFromMessage( message: string, fallback: PeriodOption ): PeriodOption {
	const normalised = message.toLowerCase();

	if ( normalised.includes( 'last 7 days' ) || normalised.includes( 'last_7_days' ) ) {
		return 'last_7_days';
	}

	if ( normalised.includes( 'last 30 days' ) || normalised.includes( 'last_30_days' ) ) {
		return 'last_30_days';
	}

	if ( normalised.includes( 'month to date' ) || normalised.includes( 'month_to_date' ) ) {
		return 'month_to_date';
	}

	if ( normalised.includes( 'quarter to date' ) || normalised.includes( 'quarter_to_date' ) ) {
		return 'quarter_to_date';
	}

	return fallback;
}

function runModeFromMessage( message: string ): RunMode {
	const normalised = message.toLowerCase();
	return normalised.includes( 'weekly' ) || normalised.includes( 'schedule preference:' )
		? 'weekly'
		: 'now';
}

function compareFromMessage( message: string ): boolean {
	const normalised = message.toLowerCase();
	return ! normalised.includes( 'do not include a comparison period' ) &&
		! normalised.includes( 'no comparison' );
}

function actionCardsFromMessage( message: string ): boolean {
	const normalised = message.toLowerCase();
	return ! normalised.includes( 'keep actions empty' ) &&
		! normalised.includes( 'do not suggest separate action cards' );
}

export function workflowRunOptionsFromMessage(
	workflow: WorkflowAction,
	message: string
): WorkflowRunOptions {
	const metadata = getReportMetadata( workflow );

	return {
		workflow,
		runMode: runModeFromMessage( message ),
		period: periodFromMessage( message, metadata.defaultPeriod ),
		compare: compareFromMessage( message ),
		day: metadata.defaultDay,
		time: '09:00',
		actionCards: actionCardsFromMessage( message ),
		adminNotification: ! message.toLowerCase().includes( 'no admin notification' ),
	};
}

function uuid(): string {
	if ( typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ) {
		return crypto.randomUUID();
	}

	return `workflow-${ Date.now() }-${ Math.random().toString( 36 ).slice( 2 ) }`;
}

function workflowRunTitle( workflow: WorkflowAction ): string {
	return sprintf(
		/* translators: %s: workflow label */
		__( '%s report', 'hey-woo' ),
		workflow.label
	);
}

function workflowDisplayText( options: WorkflowRunOptions ): string {
	return sprintf(
		/* translators: 1: workflow label, 2: period label */
		__( 'Run %1$s workflow for %2$s', 'hey-woo' ),
		options.workflow.label,
		PERIOD_LABELS[ options.period ]
	);
}

function workflowScheduleLabel( options: WorkflowRunOptions ): string | undefined {
	if ( options.runMode !== 'weekly' ) {
		return undefined;
	}

	return sprintf(
		/* translators: 1: weekday, 2: time */
		__( 'Weekly on %1$s at %2$s', 'hey-woo' ),
		options.day,
		options.time
	);
}

function buildWorkflowMeta(
	options: WorkflowRunOptions,
	status: WorkflowRunMeta['status'],
	startedAt: number,
	overrides: Partial< WorkflowRunMeta > = {}
): WorkflowRunMeta {
	return {
		slug: options.workflow.slug,
		label: options.workflow.label,
		status,
		runMode: options.runMode,
		period: options.period,
		periodLabel: PERIOD_LABELS[ options.period ],
		compare: options.compare,
		actionCards: options.actionCards,
		adminNotification: options.adminNotification,
		startedAt,
		scheduleLabel: workflowScheduleLabel( options ),
		...overrides,
	};
}

function reportTitleFromReply( reply: string, fallback: string ): string {
	const headingMatch = reply.match( /^#{1,6}\s+(.+)$/m );
	if ( ! headingMatch ) {
		return fallback;
	}

	const title = headingMatch[ 1 ]
		.replace( /\*\*(.+?)\*\*/g, '$1' )
		.replace( /[*_`]/g, '' )
		.trim();

	return title || fallback;
}

async function runWorkflowRequest( prompt: string ): Promise< ChatResponse > {
	const controller = new AbortController();
	const timeoutId = setTimeout( () => controller.abort(), WORKFLOW_TIMEOUT_MS );

	try {
		const response = await fetch( moduleData.restBase + '/chat', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': moduleData.nonce,
			},
			body: JSON.stringify( {
				message: prompt,
				history: [],
			} ),
			signal: controller.signal,
		} );

		if ( ! response.ok ) {
			let message = __( 'Something went wrong. Please check your connection and try again.', 'hey-woo' );
			try {
				const json = ( await response.json() ) as { message?: unknown };
				if ( typeof json.message === 'string' && json.message.trim() ) {
					message = json.message;
				}
			} catch {
				// Keep the generic message when the server does not return JSON.
			}

			return { status: 'error', message };
		}

		return await response.json() as ChatResponse;
	} finally {
		clearTimeout( timeoutId );
	}
}

export async function startWorkflowRun( options: WorkflowRunOptions ): Promise< StoredConversation > {
	const startedAt = Date.now();
	const conversationId = uuid();
	const title = workflowRunTitle( options.workflow );
	const displayText = workflowDisplayText( options );
	const prompt = buildWorkflowPrompt(
		options.workflow,
		options.runMode,
		options.period,
		options.compare,
		options.day,
		options.time,
		options.actionCards,
		options.adminNotification
	);
	const userMessage: ChatMessage = {
		id: 0,
		role: 'user',
		content: displayText,
	};
	const pendingConversation: StoredConversation = {
		id: conversationId,
		title,
		type: 'workflow',
		workflowRun: buildWorkflowMeta( options, 'running', startedAt ),
		messages: [ userMessage ],
		updatedAt: startedAt,
	};

	await saveConversationRecord( pendingConversation );

	void ( async () => {
		try {
			const response = await runWorkflowRequest( prompt );
			const completedAt = Date.now();

			if ( response.status === 'ok' ) {
				const reply = response.reply;
				const assistantMessage: ChatMessage = {
					id: 1,
					role: 'assistant',
					content: reply,
					...( response.charts?.length ? { charts: response.charts } : {} ),
				};

				await saveConversationRecord( {
					...pendingConversation,
					title: reportTitleFromReply( reply, title ),
					workflowRun: buildWorkflowMeta( options, 'complete', startedAt, { completedAt } ),
					messages: [ userMessage, assistantMessage ],
					updatedAt: completedAt,
				} );
				return;
			}

			const errorMessage = response.status === 'no_key'
				? __( 'No AI provider is configured.', 'hey-woo' )
				: response.message;

			await saveConversationRecord( {
				...pendingConversation,
				workflowRun: buildWorkflowMeta( options, 'error', startedAt, {
					completedAt,
					errorMessage,
				} ),
				messages: [
					userMessage,
					{
						id: 1,
						role: 'assistant',
						content: errorMessage,
					},
				],
				updatedAt: completedAt,
			} );
		} catch ( error ) {
			const completedAt = Date.now();
			const isAbort = error instanceof Error && error.name === 'AbortError';
			const errorMessage = isAbort
				? __( 'The request timed out — please try again.', 'hey-woo' )
				: __( 'Something went wrong. Please check your connection and try again.', 'hey-woo' );

			await saveConversationRecord( {
				...pendingConversation,
				workflowRun: buildWorkflowMeta( options, 'error', startedAt, {
					completedAt,
					errorMessage,
				} ),
				messages: [
					userMessage,
					{
						id: 1,
						role: 'assistant',
						content: errorMessage,
					},
				],
				updatedAt: completedAt,
			} );
		}
	} )();

	return pendingConversation;
}
