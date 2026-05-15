/**
 * Page-load data injected from PHP as a window global.
 *
 * PHP sets `window.woocommerceClaudeTodayData` via wp_add_inline_script()
 * before the boot module resolves. This module exports a typed singleton
 * so all components import from one place.
 */

import type { ModuleData } from './types';

declare global {
	interface Window {
		woocommerceClaudeTodayData?: ModuleData;
	}
}

const DEFAULTS: ModuleData = {
	nonce: '',
	restBase: '',
	settingsUrl: '',
	userName: '',
	currency: '',
	hasKey: false,
	providerMode: 'legacy',
	provider: 'auto',
	conversations: [],
};

/** Singleton module data, populated by PHP via wp_add_inline_script(). */
const moduleData: ModuleData = { ...DEFAULTS, ...( window.woocommerceClaudeTodayData ?? {} ) };
export default moduleData;
