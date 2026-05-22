/**
 * Shared workflow metadata and launch helpers.
 */
import { __, sprintf } from '@wordpress/i18n';
import type { WorkflowAction } from '../ai-insights/workflows';
import { WORKFLOWS } from '../ai-insights/workflows';

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

/**
 * Colour slot used to tint a category badge. Same `category` string always
 * maps to the same slot, so two cards with category "Store performance" will
 * carry the same green badge.
 */
export type CategoryColorSlot = 'info' | 'success' | 'warning' | 'neutral';

/**
 * Category → WPDS colour slot. Keyed on the English source string because
 * REPORT_METADATA uses the same source key. Categories not in this map fall
 * back to neutral, which is intentionally indistinguishable from the
 * "last run" badge — so a site running in a non-English locale degrades to a
 * uniform grey palette rather than a misleading mix of greens and blues.
 */
const CATEGORY_COLOR_SLOTS: Record< string, CategoryColorSlot > = {
	'Store performance': 'success',
	'Customers': 'success',
	'Markets': 'success',
	'Products': 'success',
	'Trading': 'info',
	'Marketing': 'info',
	'Catalogue': 'info',
	'Orders': 'info',
	'Content': 'info',
	'Operations': 'warning',
	'Payments': 'warning',
	'Finance': 'warning',
	'Store health': 'warning',
	'Shipping': 'warning',
};

export function getCategoryColorSlot( category: string ): CategoryColorSlot {
	return CATEGORY_COLOR_SLOTS[ category ] ?? 'neutral';
}

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
					__( 'Schedule preference: weekly on %1$s at %2$s. Mention it briefly in the intro, but do not claim an automatic schedule has been saved.', 'hey-woo' ),
					day,
					time
			  )
			: __( 'Run mode: one-off report.', 'hey-woo' ),
		__( 'Format: write a merchant-friendly markdown report with clear section headings. Do not prefix section headings with numbers (write "## Snapshot", not "## 1. Snapshot"). Prefer markdown tables for any data with a repeating shape — for example a list of channels, products, customers, payment methods, shipping methods, or countries where each item carries the same metrics (revenue, orders, change, share). Always include a header row with short column titles. Reserve bullet lists for narrative observations, recommendations, and short prose points, not for repeating-shape data.', 'hey-woo' ),
		actionCards
			? __( 'End the report with a "## Next Actions" heading followed by three merchant-doable steps as a numbered or bulleted list. Each step should start with a short bold title, then a one-sentence explanation tied to specific evidence in the report. Do not add vague actions like "review the report".', 'hey-woo' )
			: __( 'Do not include a separate Next Actions section; any next steps should sit inside the report summary.', 'hey-woo' ),
		adminNotification
			? __( 'Notification preference: show the result in WooCommerce admin.', 'hey-woo' )
			: __( 'Notification preference: no admin notification.', 'hey-woo' ),
	];

	return lines.join( '\n' );
}
