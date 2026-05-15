/**
 * Page-load data injected from PHP as a window global.
 *
 * PHP sets `window.heyWooData` via wp_add_inline_script()
 * before the boot module resolves. This module exports a typed singleton
 * so all components import from one place.
 */

import type { ModuleData } from './types';

declare global {
	interface Window {
		heyWooData?: ModuleData;
	}
}

const DEFAULTS: ModuleData = {
	nonce: '',
	restBase: '',
	settingsUrl: '',
	userName: '',
	currency: '',
	hasKey: false,
	conversations: [],
};

/** Singleton module data, populated by PHP via wp_add_inline_script(). */
const moduleData: ModuleData = { ...DEFAULTS, ...( window.heyWooData ?? {} ) };
export default moduleData;
