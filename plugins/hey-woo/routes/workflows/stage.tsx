/**
 * Hey Woo Workflows — catalogue.
 *
 * A merchant-facing catalogue of the slash-command skills. Clicking "Run"
 * navigates to /chat with the matching `/<workflow-slug>` prompt prefilled;
 * the chat workspace turns that into a workflow run on first send. No
 * per-workflow setup form lives here — period, cadence, and other knobs
 * (when needed) are configured inside the chat.
 */
import '../ai-insights/style.scss';
import './style.scss';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { Icon, chartBar } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import { useNavigate } from '@wordpress/route';
import { WORKFLOWS } from '../ai-insights/workflows';
import type { WorkflowAction } from '../ai-insights/workflows';
import { getReportMetadata } from './workflow-data';
import type { ReportMetadata } from './workflow-data';

interface WorkflowCardData extends WorkflowAction {
	id: string;
	metadata: ReportMetadata;
}

const ALL_FILTER = '__all__';

export function stage() {
	const navigate = useNavigate();
	const [ search, setSearch ] = useState( '' );
	const [ category, setCategory ] = useState< string >( ALL_FILTER );
	const [ cadence, setCadence ] = useState< string >( ALL_FILTER );

	const workflows = useMemo< WorkflowCardData[] >(
		() => WORKFLOWS.map( ( workflow ) => ( {
			...workflow,
			id: workflow.slug,
			metadata: getReportMetadata( workflow ),
		} ) ),
		[]
	);

	const categoryOptions = useMemo(
		() => [
			{ value: ALL_FILTER, label: __( 'All categories', 'hey-woo' ) },
			...Array.from( new Set( workflows.map( ( item ) => item.metadata.category ) ) )
				.sort()
				.map( ( value ) => ( { value, label: value } ) ),
		],
		[ workflows ]
	);

	const cadenceOptions = useMemo(
		() => [
			{ value: ALL_FILTER, label: __( 'All cadences', 'hey-woo' ) },
			...Array.from( new Set( workflows.map( ( item ) => item.metadata.priority ) ) )
				.sort()
				.map( ( value ) => ( { value, label: value } ) ),
		],
		[ workflows ]
	);

	const filtered = useMemo( () => {
		const needle = search.trim().toLowerCase();
		return workflows.filter( ( workflow ) => {
			if ( category !== ALL_FILTER && workflow.metadata.category !== category ) {
				return false;
			}
			if ( cadence !== ALL_FILTER && workflow.metadata.priority !== cadence ) {
				return false;
			}
			if ( ! needle ) {
				return true;
			}
			return (
				workflow.label.toLowerCase().includes( needle ) ||
				workflow.description.toLowerCase().includes( needle ) ||
				workflow.slug.toLowerCase().includes( needle )
			);
		} );
	}, [ workflows, search, category, cadence ] );

	const clearFilters = () => {
		setSearch( '' );
		setCategory( ALL_FILTER );
		setCadence( ALL_FILTER );
	};

	const runWorkflow = ( workflow: WorkflowCardData ) => {
		void navigate( {
			to: '/chat',
			search: {
				workflowPrompt: `/${ workflow.slug }`,
				workflowDisplay: workflow.label,
			},
		} );
	};

	return (
		<div className="hey-woo-page hey-woo-page--workflows">
			<header className="hey-woo-workflows-header">
				<div>
					<h1 className="hey-woo-workflows-header__title">{ __( 'Workflows', 'hey-woo' ) }</h1>
					<p className="hey-woo-workflows-header__count">
						{ sprintf(
							/* translators: %d: number of visible workflows */
							_n( '%d workflow', '%d workflows', filtered.length, 'hey-woo' ),
							filtered.length
						) }
					</p>
				</div>
			</header>

			<div className="hey-woo-workflows-toolbar">
				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					className="hey-woo-workflows-toolbar__search"
					label={ __( 'Search workflows', 'hey-woo' ) }
					hideLabelFromVision
					placeholder={ __( 'Search workflows', 'hey-woo' ) }
					value={ search }
					onChange={ setSearch }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Category', 'hey-woo' ) }
					hideLabelFromVision
					value={ category }
					options={ categoryOptions }
					onChange={ setCategory }
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __( 'Cadence', 'hey-woo' ) }
					hideLabelFromVision
					value={ cadence }
					options={ cadenceOptions }
					onChange={ setCadence }
				/>
			</div>

			{ filtered.length === 0 ? (
				<div className="hey-woo-workflows-empty" role="status">
					<p>{ __( 'No workflows match those filters.', 'hey-woo' ) }</p>
					<Button variant="secondary" onClick={ clearFilters }>
						{ __( 'Clear filters', 'hey-woo' ) }
					</Button>
				</div>
			) : (
				<div className="hey-woo-workflows-grid">
					{ filtered.map( ( workflow ) => (
						<Card key={ workflow.id } className="hey-woo-workflow-card" size="small">
							<CardHeader className="hey-woo-workflow-card__header">
								<div className="hey-woo-workflow-card__media" aria-hidden="true">
									<Icon icon={ chartBar } size={ 24 } />
								</div>
								<div className="hey-woo-workflow-card__heading">
									<h2 className="hey-woo-workflow-card__title">{ workflow.label }</h2>
									<div className="hey-woo-workflow-card__badges">
										<span className="hey-woo-workflow-card__badge">
											{ workflow.metadata.category }
										</span>
										<span className="hey-woo-workflow-card__badge hey-woo-workflow-card__badge--muted">
											{ workflow.metadata.priority }
										</span>
									</div>
								</div>
							</CardHeader>
							<CardBody className="hey-woo-workflow-card__body">
								<p className="hey-woo-workflow-card__description">{ workflow.description }</p>
							</CardBody>
							<CardFooter className="hey-woo-workflow-card__footer">
								<Button
									variant="primary"
									onClick={ () => runWorkflow( workflow ) }
								>
									{ __( 'Run', 'hey-woo' ) }
								</Button>
							</CardFooter>
						</Card>
					) ) }
				</div>
			) }
		</div>
	);
}
