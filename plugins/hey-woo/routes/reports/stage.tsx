/**
 * Hey Woo Workflows - workflow launcher route.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { Button } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { Icon, chartBar } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@wordpress/route';
import { useAdaptiveDataViewsPageSize } from '../ai-insights/hooks/useAdaptiveDataViewsPageSize';
import { WORKFLOWS } from '../ai-insights/workflows';
import type { WorkflowAction } from '../ai-insights/workflows';
import { getReportMetadata } from './report-data';
import type { Action, Field, View } from '@wordpress/dataviews/wp';

interface ReportWorkflow extends WorkflowAction {
	id: string;
	category: string;
	priority: string;
}

const REPORT_VISIBLE_FIELDS = [ 'category', 'priority' ];
const REPORT_PAGE_SIZE_OPTIONS = [ 10, 18, 30, 50 ];
const REPORT_LAYOUTS = {
	table: {
		fields: REPORT_VISIBLE_FIELDS,
		titleField: 'label',
		descriptionField: 'description',
		showMedia: false,
		layout: {
			density: 'balanced',
			styles: {
				category: { width: '180px' },
				priority: { width: '140px' },
			},
		},
	},
	list: {
		fields: REPORT_VISIBLE_FIELDS,
		titleField: 'label',
		descriptionField: 'description',
		showMedia: false,
		layout: {
			density: 'balanced',
		},
	},
	grid: {
		fields: REPORT_VISIBLE_FIELDS,
		titleField: 'label',
		mediaField: 'media',
		descriptionField: 'description',
		showMedia: true,
		layout: {
			badgeFields: REPORT_VISIBLE_FIELDS,
			density: 'comfortable',
		},
	},
};

const DEFAULT_REPORTS_VIEW: View = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 10,
	filters: [],
	sort: {
		field: 'label',
		direction: 'asc',
	},
	...REPORT_LAYOUTS.table,
};

export function stage() {
	const search = useSearch( { strict: false } ) as { workflow?: string };
	const navigate = useNavigate();
	const selectedWorkflowSlug = typeof search.workflow === 'string' ? search.workflow : '';
	const [ view, setView ] = useState< View >( DEFAULT_REPORTS_VIEW );
	const reportWorkflows = useMemo< ReportWorkflow[] >(
		() => WORKFLOWS.map( ( workflow ) => {
			const metadata = getReportMetadata( workflow );

			return {
				...workflow,
				id: workflow.slug,
				category: metadata.category,
				priority: metadata.priority,
			};
		} ),
		[]
	);
	const { perPageSizes, rootRef } = useAdaptiveDataViewsPageSize( {
		itemCount: reportWorkflows.length,
		pageSizeOptions: REPORT_PAGE_SIZE_OPTIONS,
		setView,
		view,
	} );
	const categoryOptions = useMemo(
		() => Array.from( new Set( reportWorkflows.map( ( workflow ) => workflow.category ) ) )
			.map( ( category ) => ( {
				value: category,
				label: category,
			} ) ),
		[ reportWorkflows ]
	);
	const priorityOptions = useMemo(
		() => Array.from( new Set( reportWorkflows.map( ( workflow ) => workflow.priority ) ) )
			.map( ( priority ) => ( {
				value: priority,
				label: priority,
			} ) ),
		[ reportWorkflows ]
	);

	const selectWorkflow = ( workflowSlug: string ) => {
		void navigate( {
			to: '/reports',
			search: {
				workflow: workflowSlug,
			},
		} );
	};

	const fields = useMemo< Field< ReportWorkflow >[] >(
		() => [
			{
				id: 'media',
				label: __( 'Preview', 'hey-woo' ),
				enableHiding: false,
				enableSorting: false,
				render: () => (
					<div className="hey-woo-report-media">
						<Icon icon={ chartBar } size={ 32 } />
					</div>
				),
			},
			{
				id: 'label',
				label: __( 'Workflow', 'hey-woo' ),
				enableHiding: false,
				enableGlobalSearch: true,
				getValue: ( { item } ) => item.label,
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
				id: 'category',
				label: __( 'Category', 'hey-woo' ),
				elements: categoryOptions,
				enableGlobalSearch: true,
				enableSorting: true,
				filterBy: {
					operators: [ 'isAny' ],
					isPrimary: true,
				},
				getValue: ( { item } ) => item.category,
			},
			{
				id: 'priority',
				label: __( 'Cadence', 'hey-woo' ),
				elements: priorityOptions,
				enableGlobalSearch: true,
				enableSorting: true,
				filterBy: {
					operators: [ 'isAny' ],
					isPrimary: true,
				},
				getValue: ( { item } ) => item.priority,
			},
		],
		[ categoryOptions, priorityOptions ]
	);
	const { data: shownReports, paginationInfo } = useMemo(
		() => filterSortAndPaginate( reportWorkflows, view, fields ),
		[ fields, reportWorkflows, view ]
	);
	const actions = useMemo< Action< ReportWorkflow >[] >(
		() => [
			{
				id: 'set-up-report',
				label: ( items ) => items[ 0 ]?.slug === selectedWorkflowSlug
					? __( 'Selected', 'hey-woo' )
					: __( 'Set up', 'hey-woo' ),
				isPrimary: true,
				context: 'single',
				callback: ( items ) => {
					const workflow = items[ 0 ];

					if ( workflow ) {
						selectWorkflow( workflow.slug );
					}
				},
			},
		],
		[ selectedWorkflowSlug ]
	);
	const resetView = () => setView( DEFAULT_REPORTS_VIEW );

	return (
		<div ref={ rootRef } className="hey-woo-page hey-woo-page--reports">
			<header className="hey-woo-reports-header">
				<div>
					<h1 className="hey-woo-reports-header__title">{ __( 'Workflows', 'hey-woo' ) }</h1>
					<p className="hey-woo-reports-header__count">
						{ sprintf(
							/* translators: %d: number of visible workflows */
							_n( '%d workflow', '%d workflows', paginationInfo.totalItems, 'hey-woo' ),
							paginationInfo.totalItems
						) }
					</p>
				</div>
			</header>

			<DataViews
				actions={ actions }
				config={ {
					perPageSizes,
				} }
				data={ shownReports }
				defaultLayouts={ REPORT_LAYOUTS }
				empty={
					<div className="hey-woo-reports-empty" role="status">
						<p>{ __( 'No workflows match those filters.', 'hey-woo' ) }</p>
						<Button type="button" variant="secondary" onClick={ resetView }>
							{ __( 'Clear filters', 'hey-woo' ) }
						</Button>
					</div>
				}
				fields={ fields }
				getItemId={ ( item ) => item.id }
				isItemClickable={ () => true }
				onChangeView={ setView }
				onClickItem={ ( item ) => selectWorkflow( item.slug ) }
				onReset={ resetView }
				paginationInfo={ paginationInfo }
				searchLabel={ __( 'Search workflows', 'hey-woo' ) }
				view={ view }
			/>
		</div>
	);
}
