/**
 * Page-load data passed from PHP via wp_localize_script().
 *
 * PHP sets `window.heyWooTodayData` before the bundle executes.
 * This module exports a typed singleton so all components import from one place.
 */

import type { ModuleData } from './types';

declare global {
	interface Window {
		heyWooTodayData?: ModuleData;
	}
}

const DEFAULTS: ModuleData = {
	nonce: '',
	restBase: '',
	settingsUrl: '',
	userName: '',
	currency: '',
	hasKey: false,
};

/** Singleton module data, populated by PHP via wp_localize_script(). */
const moduleData: ModuleData = { ...DEFAULTS, ...( window.heyWooTodayData ?? {} ) };
export default moduleData;
