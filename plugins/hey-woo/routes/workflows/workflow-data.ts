/**
 * Shared workflow metadata and launch helpers.
 */
import { __, sprintf } from '@wordpress/i18n';
import moduleData from '../ai-insights/data';
import type {
	ChatMessage,
	ChatResponse,
	StoredConversation,
} from '../ai-insights/types';
import { WORKFLOWS } from '../ai-insights/workflows';
import type { WorkflowAction } from '../ai-insights/workflows';
import { CONVERSATIONS_UPDATED_EVENT } from '../ai-insights/hooks/useConversations';
import {
	clearWorkflowRunning,
	markWorkflowRunning,
} from './running-store';

export type RunMode = 'now' | 'weekly';
export type PeriodOption = 'last_7_days' | 'last_30_days' | 'month_to_date' | 'quarter_to_date';

export interface ReportMetadata {
	category: string;
	defaultPeriod: PeriodOption;
	defaultDay: string;
	priority: string;
}

export const WEEKDAYS = [
	__( 'Monday', 'hey-woo' ),
	__( 'Tuesday', 'hey-woo' ),
	__( 'Wednesday', 'hey-woo' ),
	__( 'Thursday', 'hey-woo' ),
	__( 'Friday', 'hey-woo' ),
	__( 'Saturday', 'hey-woo' ),
	__( 'Sunday', 'hey-woo' ),
];

export const PERIOD_LABELS: Record< PeriodOption, string > = {
	last_7_days: __( 'Last 7 days', 'hey-woo' ),
	last_30_days: __( 'Last 30 days', 'hey-woo' ),
	month_to_date: __( 'Month to date', 'hey-woo' ),
	quarter_to_date: __( 'Quarter to date', 'hey-woo' ),
};

const REPORT_METADATA: Record< string, ReportMetadata > = {
	'weekly-store-review': {
		category: __( 'Store performance', 'hey-woo' ),
		defaultPeriod: 'last_7_days',
		defaultDay: __( 'Monday', 'hey-woo' ),
		priority: __( 'Weekly', 'hey-woo' ),
	},
	'revenue-drop-triage': {
		category: __( 'Trading', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Monday', 'hey-woo' ),
		priority: __( 'On demand', 'hey-woo' ),
	},
	'refund-triage': {
		category: __( 'Operations', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Wednesday', 'hey-woo' ),
		priority: __( 'Weekly', 'hey-woo' ),
	},
	'coupon-performance-triage': {
		category: __( 'Marketing', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Monday', 'hey-woo' ),
		priority: __( 'Monthly', 'hey-woo' ),
	},
	'customer-value-review': {
		category: __( 'Customers', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Thursday', 'hey-woo' ),
		priority: __( 'Monthly', 'hey-woo' ),
	},
	'product-performance-review': {
		category: __( 'Products', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Tuesday', 'hey-woo' ),
		priority: __( 'Weekly', 'hey-woo' ),
	},
	'channel-performance-review': {
		category: __( 'Marketing', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Tuesday', 'hey-woo' ),
		priority: __( 'Weekly', 'hey-woo' ),
	},
	'payment-method-review': {
		category: __( 'Payments', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Wednesday', 'hey-woo' ),
		priority: __( 'Monthly', 'hey-woo' ),
	},
	'failed-order-triage': {
		category: __( 'Orders', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Monday', 'hey-woo' ),
		priority: __( 'Weekly', 'hey-woo' ),
	},
	'customer-acquisition-review': {
		category: __( 'Customers', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Thursday', 'hey-woo' ),
		priority: __( 'Monthly', 'hey-woo' ),
	},
	'inventory-risk-review': {
		category: __( 'Operations', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Tuesday', 'hey-woo' ),
		priority: __( 'Weekly', 'hey-woo' ),
	},
	'catalogue-merchandising-review': {
		category: __( 'Catalogue', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Tuesday', 'hey-woo' ),
		priority: __( 'Weekly', 'hey-woo' ),
	},
	'geography-performance-review': {
		category: __( 'Markets', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Friday', 'hey-woo' ),
		priority: __( 'Monthly', 'hey-woo' ),
	},
	'shipping-method-review': {
		category: __( 'Shipping', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Wednesday', 'hey-woo' ),
		priority: __( 'Monthly', 'hey-woo' ),
	},
	'tax-reconciliation': {
		category: __( 'Finance', 'hey-woo' ),
		defaultPeriod: 'month_to_date',
		defaultDay: __( 'Friday', 'hey-woo' ),
		priority: __( 'Monthly', 'hey-woo' ),
	},
	'catalog-audit': {
		category: __( 'Catalogue', 'hey-woo' ),
		defaultPeriod: 'quarter_to_date',
		defaultDay: __( 'Thursday', 'hey-woo' ),
		priority: __( 'Quarterly', 'hey-woo' ),
	},
	'store-health-monitor': {
		category: __( 'Store health', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Monday', 'hey-woo' ),
		priority: __( 'Weekly', 'hey-woo' ),
	},
	'product-content-generator': {
		category: __( 'Content', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Thursday', 'hey-woo' ),
		priority: __( 'On demand', 'hey-woo' ),
	},
};

export function getReportMetadata( workflow: WorkflowAction ): ReportMetadata {
	return REPORT_METADATA[ workflow.slug ] ?? {
		category: __( 'Workflow', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Monday', 'hey-woo' ),
		priority: __( 'On demand', 'hey-woo' ),
	};
}

export function getWorkflowBySlug( workflowSlug: string ): WorkflowAction | undefined {
	return WORKFLOWS.find( ( workflow ) => workflow.slug === workflowSlug );
}

const MAX_LIBRARY_CONVERSATIONS = 5;
const WORKFLOW_TIMEOUT_MS = 180_000;

function upsertConversation(
	conversations: StoredConversation[],
	incoming: StoredConversation
): StoredConversation[] {
	const without = conversations.filter( ( item ) => item.id !== incoming.id );
	without.unshift( incoming );
	without.sort( ( a, b ) => b.updatedAt - a.updatedAt );
	return without.slice( 0, MAX_LIBRARY_CONVERSATIONS );
}

function publishConversations( conversations: StoredConversation[] ): void {
	moduleData.conversations = conversations;
	window.dispatchEvent(
		new CustomEvent< StoredConversation[] >( CONVERSATIONS_UPDATED_EVENT, {
			detail: conversations,
		} )
	);
}

async function persistConversation( conversation: StoredConversation ): Promise< void > {
	const next = upsertConversation( moduleData.conversations, conversation );
	publishConversations( next );

	try {
		await fetch( moduleData.restBase + '/conversations', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': moduleData.nonce,
			},
			body: JSON.stringify( conversation ),
		} );
	} catch ( _err ) {
		// Silent failure — conversation remains in local state.
	}
}

/**
 * Run a workflow in the background.
 *
 * Does not navigate. Marks the workflow as running, persists a placeholder
 * conversation so it appears in the Library immediately, fetches the assistant
 * reply, and updates the conversation when the reply lands. The running flag
 * is cleared regardless of success or failure.
 */
export async function runWorkflowInBackground( {
	workflow,
	prompt,
	displayText,
}: {
	workflow: WorkflowAction;
	prompt: string;
	displayText: string;
} ): Promise< void > {
	const conversationId =
		typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
			? crypto.randomUUID()
			: `wf-${ workflow.slug }-${ Date.now() }`;

	markWorkflowRunning( workflow.slug, conversationId );

	const userMessage: ChatMessage = {
		id: 0,
		role: 'user',
		content: displayText,
	};

	await persistConversation( {
		id: conversationId,
		title: displayText,
		messages: [ userMessage ],
		updatedAt: Date.now(),
	} );

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
			throw new Error( 'workflow_request_failed' );
		}

		const json = ( await response.json() ) as ChatResponse;

		if ( json.status === 'ok' ) {
			const assistantMessage: ChatMessage = {
				id: 1,
				role: 'assistant',
				content: json.reply,
				...( json.charts?.length ? { charts: json.charts } : {} ),
			};

			await persistConversation( {
				id: conversationId,
				title: displayText,
				messages: [ userMessage, assistantMessage ],
				updatedAt: Date.now(),
			} );
		}
	} catch ( _err ) {
		// Leave the placeholder conversation in place so the merchant can retry from chat.
	} finally {
		clearTimeout( timeoutId );
		clearWorkflowRunning( workflow.slug );
	}
}

const STRUCTURED_REPORT_SCHEMA_WITH_ACTIONS = '{"title":"","subtitle":"","summary":"","metric_tiles":[{"label":"","value":"","trend":"","caption":"","tone":"neutral"}],"insights":[{"title":"","summary":"","category":"","status":"","metric":"","tone":"neutral"}],"charts":[{"type":"bar","title":"","x_label":"","y_label":"","series":[{"name":"","data":[{"x":"","y":0}]}]}],"tables":[{"title":"","columns":[],"rows":[],"note":""}],"caveats":[{"title":"","detail":"","tone":"warning"}],"sources":[{"label":"","detail":""}],"actions":[{"title":"","priority":"medium","summary":"","key_metric":"","impact":"","evidence":"","next_steps":[],"expected_outcome":""}]}';

const STRUCTURED_REPORT_SCHEMA_WITHOUT_ACTIONS = '{"title":"","subtitle":"","summary":"","metric_tiles":[{"label":"","value":"","trend":"","caption":"","tone":"neutral"}],"insights":[{"title":"","summary":"","category":"","status":"","metric":"","tone":"neutral"}],"charts":[{"type":"bar","title":"","x_label":"","y_label":"","series":[{"name":"","data":[{"x":"","y":0}]}]}],"tables":[{"title":"","columns":[],"rows":[],"note":""}],"caveats":[{"title":"","detail":"","tone":"warning"}],"sources":[{"label":"","detail":""}],"actions":[]}';

function buildStructuredReportInstruction( workflow: WorkflowAction, actionCards: boolean ): string {
	const schema = actionCards
		? STRUCTURED_REPORT_SCHEMA_WITH_ACTIONS
		: STRUCTURED_REPORT_SCHEMA_WITHOUT_ACTIONS;
	const limits = actionCards
		? __( 'Limits: metric_tiles <= 5, insights <= 5, charts <= 1, tables <= 2, caveats <= 2, sources <= 5, actions <= 3.', 'hey-woo' )
		: __( 'Limits: metric_tiles <= 5, insights <= 5, charts <= 1, tables <= 2, caveats <= 2, sources <= 5.', 'hey-woo' );
	const weeklyGuidance = workflow.slug === 'weekly-store-review'
		? __( 'For the weekly store review, compose a briefing: metric_tiles are the tape, summary is the editor note, and insights are the ranked "what to look at" leads. Do not return a top-products-only answer. Do not restate a headline metric as an insight. Use insights for patterns the data actually supports: trend shift, product movement, customer/cohort movement, checkout pipeline, refund pattern, channel concentration, or tracking coverage. Prefer a compact evidence table when long product or channel names would make a chart hard to read. Only include a chart when it explains a movement or mix shift better than the table.', 'hey-woo' )
		: '';

	return [
		sprintf(
			/* translators: %s: JSON schema example for a structured report block */
			__( 'Return a concise merchant briefing followed by one fenced code block whose language is exactly hey-woo-report. The block must contain one compact JSON object, no markdown, with this schema: %s.', 'hey-woo' ),
			schema
		),
		limits,
		__( 'Shape the JSON like Hey Woo: summary is a short editorial note, metric_tiles are the metrics tape, insights are the ranked leads, charts and tables are supporting evidence, caveats are small notes, and sources name the aggregate surfaces used.', 'hey-woo' ),
		__( 'Only include values returned by tools or already present in the conversation; do not invent metrics. Read precomputed deltas, percentages, coverage, rates, and comparisons directly instead of recalculating them. If the data does not support a chart or table, leave that array empty and explain why in caveats.', 'hey-woo' ),
		__( 'Lead discipline: pick 3-5 observations that earn attention. Good leads name the driver or useful non-driver; weak leads merely say revenue/orders/AOV changed. Small samples must be caveated in the insight body, not overstated in the headline.', 'hey-woo' ),
		weeklyGuidance,
		actionCards
			? __( 'Put recommended action cards in actions inside hey-woo-report; do not output a separate hey-woo-actions block. Each action must come from a specific insight, be evidence-backed, and be doable by a merchant. Do not add vague actions like "review the report".', 'hey-woo' )
			: __( 'Keep actions empty in the JSON and keep any next steps inside the report summary.', 'hey-woo' ),
		__( 'Do not call the separate chart renderer for this report; any useful visual belongs in the hey-woo-report charts array.', 'hey-woo' ),
	].filter( Boolean ).join( ' ' );
}

export function buildWorkflowPrompt(
	workflow: WorkflowAction,
	runMode: RunMode,
	period: PeriodOption,
	compare: boolean,
	day: string,
	time: string,
	actionCards: boolean,
	adminNotification: boolean
): string {
	const lines = [
		`/${ workflow.slug }`,
		sprintf(
			/* translators: %s: report name */
			__( 'Run the %s workflow as a merchant report.', 'hey-woo' ),
			workflow.label
		),
		sprintf(
			/* translators: %s: period label */
			__( 'Period: %s.', 'hey-woo' ),
			PERIOD_LABELS[ period ]
		),
		compare
			? __( 'Compare it with the previous matching period.', 'hey-woo' )
			: __( 'Do not include a comparison period.', 'hey-woo' ),
		runMode === 'weekly'
			? sprintf(
					/* translators: 1: weekday, 2: time */
					__( 'Schedule preference: weekly on %1$s at %2$s. Include this in the setup summary, but do not claim an automatic schedule has been saved.', 'hey-woo' ),
					day,
					time
			  )
			: __( 'Run mode: one-off report.', 'hey-woo' ),
		buildStructuredReportInstruction( workflow, actionCards ),
		actionCards
			? __( 'Keep recommended actions short, merchant-doable, and present in the hey-woo-report actions array only. Do not say the actions have been created automatically.', 'hey-woo' )
			: __( 'Keep the output to the report summary and next actions; do not suggest separate action cards.', 'hey-woo' ),
		adminNotification
			? __( 'Notification preference: show the result in WooCommerce admin.', 'hey-woo' )
			: __( 'Notification preference: no admin notification.', 'hey-woo' ),
	];

	return lines.join( '\n' );
}
