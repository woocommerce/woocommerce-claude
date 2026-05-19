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

/**
 * A single chat message - either from the merchant or from the assistant.
 */
export interface ChatMessage {
	id: number;
	role: 'user' | 'assistant';
	content: string;
	charts?: ChartSpec[];
}

/**
 * A persisted conversation stored in WordPress user meta.
 */
export interface StoredConversation {
	id: string;
	title: string;
	messages: ChatMessage[];
	updatedAt: number;
}

export interface BriefingMetric {
	label: string;
	value: string;
	trend: string;
	tone: 'positive' | 'warning' | 'negative' | 'neutral';
}

export interface BriefingItem {
	category: string;
	status: string;
	title: string;
	summary: string;
	workflowSlug: string;
}

export interface BriefingMonitor {
	title: string;
	metric: string;
	cadence: string;
	workflowSlug: string;
}

export interface FirstRunBriefing {
	source: 'ai' | 'fallback';
	generatedAt: number;
	headline: string;
	summary: string;
	metrics: BriefingMetric[];
	items: BriefingItem[];
	monitors: BriefingMonitor[];
}

/**
 * Top-level response shape from POST /hey-woo/v1/difm/chat.
 */
export type ChatResponse =
	| { status: 'ok'; reply: string; charts?: ChartSpec[] }
	| { status: 'no_key' }
	| { status: 'error'; message: string };

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
	firstRunBriefing?: FirstRunBriefing;
}
