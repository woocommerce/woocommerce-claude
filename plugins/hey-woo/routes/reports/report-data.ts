/**
 * Shared report workflow metadata and launch helpers.
 */
import { __, sprintf } from '@wordpress/i18n';
import { WORKFLOWS } from '../ai-insights/workflows';
import type { WorkflowAction } from '../ai-insights/workflows';

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
		category: __( 'Report', 'hey-woo' ),
		defaultPeriod: 'last_30_days',
		defaultDay: __( 'Monday', 'hey-woo' ),
		priority: __( 'On demand', 'hey-woo' ),
	};
}

export function getWorkflowBySlug( workflowSlug: string ): WorkflowAction | undefined {
	return WORKFLOWS.find( ( workflow ) => workflow.slug === workflowSlug );
}

export function launchChatWorkflow( prompt: string, displayText = __( 'Run report', 'hey-woo' ) ): void {
	const url = new URL( window.location.href );
	const routeSearch = new URLSearchParams();
	routeSearch.set( 'workflowPrompt', prompt );
	routeSearch.set( 'workflowDisplay', displayText );

	url.searchParams.set( 'p', `/?${ routeSearch.toString() }` );
	window.location.assign( url.toString() );
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
		actionCards
			? __( 'After the report, include recommended actions in a fenced code block whose language is exactly hey-woo-actions. The block must contain only a JSON array of objects with title, priority, summary, key_metric, impact, evidence, next_steps, and expected_outcome fields. priority must be high, medium, or low. Keep title short and action-oriented, make key_metric the one scannable number or fact for the board card, make impact the business consequence, put detailed proof in evidence, and put merchant-doable steps in next_steps. Keep the same recommendations visible in the report prose too, but do not say the actions have been created automatically.', 'hey-woo' )
			: __( 'Keep the output to the report summary and next actions; do not suggest separate action cards.', 'hey-woo' ),
		adminNotification
			? __( 'Notification preference: show the result in WooCommerce admin.', 'hey-woo' )
			: __( 'Notification preference: no admin notification.', 'hey-woo' ),
	];

	return lines.join( '\n' );
}
