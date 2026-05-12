/**
 * Shared TypeScript interfaces for the WooCommerce for Claude conversational assistant.
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
 * Top-level response shape from POST /woocommerce-claude/v1/difm/chat.
 */
export type ChatResponse =
	| { status: 'ok'; reply: string }
	| { status: 'no_key' }
	| { status: 'error'; message: string };

/**
 * Page-load data passed from PHP via an inline script
 * and read from window.woocommerceClaudeTodayData (see data.ts).
 */
export interface ModuleData {
	nonce: string;
	restBase: string;
	settingsUrl: string;
	userName: string;
	currency: string;
	hasKey: boolean;
}
