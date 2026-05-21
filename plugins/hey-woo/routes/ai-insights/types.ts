/**
 * Shared TypeScript interfaces for the Hey Woo conversational assistant.
 */

export interface ChartDataPoint {
	x: string;
	y: number;
}

export interface ChartSeries {
	name: string;
	data: ChartDataPoint[];
}

export interface ChartSpec {
	type: 'line' | 'bar' | 'pie';
	title: string;
	x_label?: string;
	y_label?: string;
	series: ChartSeries[];
}

export type FeedbackRating = 'up' | 'down';

/**
 * Persisted merchant feedback on a single assistant message.
 */
export interface MessageFeedback {
	rating: FeedbackRating;
	comment?: string;
	submittedAt: number;
}

/**
 * A single chat message - either from the merchant or from the assistant.
 */
export interface ChatMessage {
	id: number;
	role: 'user' | 'assistant';
	content: string;
	charts?: ChartSpec[];
	feedback?: MessageFeedback;
}

export type WorkflowRunStatus = 'running' | 'complete' | 'error';

export interface WorkflowRunMeta {
	slug: string;
	label: string;
	status: WorkflowRunStatus;
	runMode: string;
	period: string;
	periodLabel: string;
	compare: boolean;
	actionCards: boolean;
	adminNotification: boolean;
	startedAt: number;
	scheduleLabel?: string;
	completedAt?: number;
	errorMessage?: string;
}

/**
 * A persisted conversation stored in WordPress user meta.
 */
export interface StoredConversation {
	id: string;
	title: string;
	messages: ChatMessage[];
	updatedAt: number;
	type?: 'chat' | 'workflow';
	workflowRun?: WorkflowRunMeta;
}

/**
 * Categorised chat error kinds returned by the backend ChatErrorMapper.
 *
 * - bad_key       — the configured AI key was rejected (401/403, missing).
 * - rate_limited  — the provider returned HTTP 429.
 * - overloaded    — the provider is overloaded (HTTP 529 / 503).
 * - timeout       — the request did not complete in time.
 * - network       — transport-level failure reaching the provider.
 * - generic       — anything else; fallback used for unknown server errors.
 *
 * Server-emitted on `{ status: 'error', kind, message }`. The frontend also
 * mints these locally for client-side aborts (timeout) and fetch failures
 * (network) so the chat workspace can render the same UI regardless of where
 * the error originated.
 */
export type ChatErrorKind =
	| 'bad_key'
	| 'rate_limited'
	| 'overloaded'
	| 'timeout'
	| 'network'
	| 'generic';

/**
 * Kinds that the merchant can resolve by clicking Retry — the request itself
 * was the problem (transient or recoverable), not their input.
 */
export const RETRYABLE_CHAT_ERROR_KINDS: ReadonlyArray< ChatErrorKind > = [
	'rate_limited',
	'overloaded',
	'timeout',
	'network',
	'generic',
];

/**
 * Top-level response shape from POST /hey-woo/v1/difm/chat.
 */
export type ChatResponse =
	| { status: 'ok'; reply: string; charts?: ChartSpec[] }
	| { status: 'no_key' }
	| { status: 'error'; kind?: ChatErrorKind; message: string };

/**
 * Page-load data passed from PHP via an inline script
 * and read from window.heyWooData (see data.ts).
 */
export interface ModuleData {
	nonce: string;
	restBase: string;
	settingsUrl: string;
	storeName: string;
	userName: string;
	currency: string;
	hasKey: boolean;
	providerMode: 'connector' | 'legacy';
	provider: string;
	conversations: StoredConversation[];
}
