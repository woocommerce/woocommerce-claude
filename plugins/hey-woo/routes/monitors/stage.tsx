/**
 * Hey Woo Monitors - recurring signal templates.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { Button } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { useMemo, useState } from '@wordpress/element';
import { Icon, scheduled } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import { WORKFLOWS } from '../ai-insights/workflows';
import {
	buildWorkflowPrompt,
	getReportMetadata,
	launchChatWorkflow,
} from '../reports/report-data';
import type { WorkflowAction } from '../ai-insights/workflows';
import type { Action, Field, View } from '@wordpress/dataviews/wp';

interface MonitorTemplate {
	id: string;
	title: string;
	metric: string;
	status: string;
	cadence: string;
	workflowSlug: string;
	description: string;
}

const MONITOR_VISIBLE_FIELDS = [ 'metric', 'status', 'cadence' ];
const MONITOR_LAYOUTS = {
	table: {
		fields: MONITOR_VISIBLE_FIELDS,
		titleField: 'title',
		descriptionField: 'description',
		showMedia: false,
		layout: {
			density: 'balanced',
			styles: {
				metric: { width: '240px' },
				status: { width: '140px' },
				cadence: { width: '140px' },
			},
		},
	},
	list: {
		fields: MONITOR_VISIBLE_FIELDS,
		titleField: 'title',
		descriptionField: 'description',
		showMedia: false,
		layout: {
			density: 'balanced',
		},
	},
	grid: {
		fields: MONITOR_VISIBLE_FIELDS,
		titleField: 'title',
		mediaField: 'media',
		descriptionField: 'description',
		showMedia: true,
		layout: {
			badgeFields: [ 'status', 'cadence' ],
			density: 'comfortable',
		},
	},
};

const DEFAULT_MONITORS_VIEW: View = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 10,
	filters: [],
	sort: {
		field: 'title',
		direction: 'asc',
	},
	...MONITOR_LAYOUTS.table,
};

const MONITORS: MonitorTemplate[] = [
	{
		id: 'weekly-revenue',
		title: __( 'Weekly revenue', 'hey-woo' ),
		metric: __( 'Revenue versus previous period', 'hey-woo' ),
		status: __( 'Suggested', 'hey-woo' ),
		cadence: __( 'Weekly', 'hey-woo' ),
		workflowSlug: 'weekly-store-review',
		description: __( 'A store-wide check for revenue, orders, products, customers, and next steps.', 'hey-woo' ),
	},
	{
		id: 'refund-rate',
		title: __( 'Refund rate', 'hey-woo' ),
		metric: __( 'Refunded revenue and product drivers', 'hey-woo' ),
		status: __( 'Suggested', 'hey-woo' ),
		cadence: __( 'Weekly', 'hey-woo' ),
		workflowSlug: 'refund-triage',
		description: __( 'Looks for refund drivers that can become fulfilment, product, or policy actions.', 'hey-woo' ),
	},
	{
		id: 'failed-orders',
		title: __( 'Failed orders', 'hey-woo' ),
		metric: __( 'Failed, on-hold, and unpaid orders', 'hey-woo' ),
		status: __( 'Suggested', 'hey-woo' ),
		cadence: __( 'Weekly', 'hey-woo' ),
		workflowSlug: 'failed-order-triage',
		description: __( 'Keeps payment and order recovery issues visible before they go stale.', 'hey-woo' ),
	},
	{
		id: 'new-customers',
		title: __( 'New customer acquisition', 'hey-woo' ),
		metric: __( 'New customers, channels, and first-order quality', 'hey-woo' ),
		status: __( 'Suggested', 'hey-woo' ),
		cadence: __( 'Monthly', 'hey-woo' ),
		workflowSlug: 'customer-acquisition-review',
		description: __( 'Checks whether customer growth is healthy and where the best new customers came from.', 'hey-woo' ),
	},
	{
		id: 'inventory-risk',
		title: __( 'Inventory risk', 'hey-woo' ),
		metric: __( 'Low stock, out of stock, and fast movers', 'hey-woo' ),
		status: __( 'Suggested', 'hey-woo' ),
		cadence: __( 'Weekly', 'hey-woo' ),
		workflowSlug: 'inventory-risk-review',
		description: __( 'Surfaces products where stock, demand, or merchandising needs attention.', 'hey-woo' ),
	},
	{
		id: 'coupon-performance',
		title: __( 'Coupon performance', 'hey-woo' ),
		metric: __( 'Discount cost, usage, and order value', 'hey-woo' ),
		status: __( 'Suggested', 'hey-woo' ),
		cadence: __( 'Monthly', 'hey-woo' ),
		workflowSlug: 'coupon-performance-triage',
		description: __( 'Reviews whether promotions are producing useful orders or just margin leakage.', 'hey-woo' ),
	},
	{
		id: 'tax-reconciliation',
		title: __( 'Tax reconciliation', 'hey-woo' ),
		metric: __( 'Tax collected, refunded, pending, and shipping tax', 'hey-woo' ),
		status: __( 'Suggested', 'hey-woo' ),
		cadence: __( 'Monthly', 'hey-woo' ),
		workflowSlug: 'tax-reconciliation',
		description: __( 'A finance check for tax totals that need to reconcile with WooCommerce admin.', 'hey-woo' ),
	},
];

function workflowForMonitor( monitor: MonitorTemplate ): WorkflowAction | undefined {
	return WORKFLOWS.find( ( workflow ) => workflow.slug === monitor.workflowSlug );
}

function runMonitor( monitor: MonitorTemplate ): void {
	const workflow = workflowForMonitor( monitor );
	if ( ! workflow ) {
		return;
	}

	const metadata = getReportMetadata( workflow );
	const prompt = buildWorkflowPrompt(
		workflow,
		'now',
		metadata.defaultPeriod,
		true,
		metadata.defaultDay,
		'09:00',
		true,
		true
	);

	launchChatWorkflow( prompt, monitor.title );
}

export function stage() {
	const [ view, setView ] = useState< View >( DEFAULT_MONITORS_VIEW );
	const statusOptions = useMemo(
		() => Array.from( new Set( MONITORS.map( ( monitor ) => monitor.status ) ) )
			.map( ( status ) => ( {
				value: status,
				label: status,
			} ) ),
		[]
	);
	const cadenceOptions = useMemo(
		() => Array.from( new Set( MONITORS.map( ( monitor ) => monitor.cadence ) ) )
			.map( ( cadence ) => ( {
				value: cadence,
				label: cadence,
			} ) ),
		[]
	);
	const fields = useMemo< Field< MonitorTemplate >[] >(
		() => [
			{
				id: 'media',
				label: __( 'Preview', 'hey-woo' ),
				enableHiding: false,
				enableSorting: false,
				render: () => (
					<div className="hey-woo-monitor-media">
						<Icon icon={ scheduled } size={ 32 } />
					</div>
				),
			},
			{
				id: 'title',
				label: __( 'Monitor', 'hey-woo' ),
				enableHiding: false,
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.title,
			},
			{
				id: 'description',
				label: __( 'Description', 'hey-woo' ),
				enableHiding: false,
				enableGlobalSearch: true,
				enableSorting: false,
				getValue: ( { item } ) => item.description,
			},
			{
				id: 'metric',
				label: __( 'Signal', 'hey-woo' ),
				enableGlobalSearch: true,
				enableSorting: true,
				getValue: ( { item } ) => item.metric,
			},
			{
				id: 'status',
				label: __( 'Status', 'hey-woo' ),
				elements: statusOptions,
				enableGlobalSearch: true,
				enableSorting: true,
				filterBy: {
					operators: [ 'isAny' ],
					isPrimary: true,
				},
				getValue: ( { item } ) => item.status,
			},
			{
				id: 'cadence',
				label: __( 'Cadence', 'hey-woo' ),
				elements: cadenceOptions,
				enableGlobalSearch: true,
				enableSorting: true,
				filterBy: {
					operators: [ 'isAny' ],
					isPrimary: true,
				},
				getValue: ( { item } ) => item.cadence,
			},
		],
		[ cadenceOptions, statusOptions ]
	);
	const { data: shownMonitors, paginationInfo } = useMemo(
		() => filterSortAndPaginate( MONITORS, view, fields ),
		[ fields, view ]
	);
	const actions = useMemo< Action< MonitorTemplate >[] >(
		() => [
			{
				id: 'run-monitor',
				label: __( 'Run check', 'hey-woo' ),
				isPrimary: true,
				context: 'single',
				callback: ( items ) => {
					const monitor = items[ 0 ];
					if ( monitor ) {
						runMonitor( monitor );
					}
				},
			},
		],
		[]
	);
	const resetView = () => setView( DEFAULT_MONITORS_VIEW );

	return (
		<div className="hey-woo-page hey-woo-page--monitors">
			<header className="hey-woo-monitors-header">
				<div>
					<h1 className="hey-woo-monitors-header__title">{ __( 'Monitors', 'hey-woo' ) }</h1>
					<p className="hey-woo-monitors-header__count">
						{ sprintf(
							/* translators: %d: number of visible monitors */
							_n( '%d monitor template', '%d monitor templates', paginationInfo.totalItems, 'hey-woo' ),
							paginationInfo.totalItems
						) }
					</p>
				</div>
			</header>

			<DataViews
				actions={ actions }
				config={ {
					perPageSizes: [ 10, 20, 50 ],
				} }
				data={ shownMonitors }
				defaultLayouts={ MONITOR_LAYOUTS }
				empty={
					<div className="hey-woo-monitors-empty" role="status">
						<p>{ __( 'No monitors match those filters.', 'hey-woo' ) }</p>
						<Button type="button" variant="secondary" onClick={ resetView }>
							{ __( 'Clear filters', 'hey-woo' ) }
						</Button>
					</div>
				}
				fields={ fields }
				getItemId={ ( item ) => item.id }
				isItemClickable={ () => true }
				onChangeView={ setView }
				onClickItem={ runMonitor }
				onReset={ resetView }
				paginationInfo={ paginationInfo }
				searchLabel={ __( 'Search monitors', 'hey-woo' ) }
				view={ view }
			/>
		</div>
	);
}
