/**
 * Shared TypeScript interfaces for the Hey Woo conversational assistant.
 */

/**
 * A single chat message — either from the merchant or from Claude.
 */
export interface ChatMessage {
	id: number;
	role: 'user' | 'assistant';
	content: string;
}

/**
 * Top-level response shape from POST /hey-woo/v1/difm/chat.
 */
export type ChatResponse =
	| { status: 'ok'; reply: string }
	| { status: 'no_key' }
	| { status: 'error'; message: string };

/**
 * Page-load data passed from PHP via wp_localize_script()
 * and read from window.heyWooTodayData (see src/data.ts).
 */
export interface ModuleData {
	nonce: string;
	restBase: string;
	settingsUrl: string;
	userName: string;
	currency: string;
	hasKey: boolean;
}
