/**
 * Hey Woo Workflows — card library and setup modal.
 */
import '../ai-insights/style.scss';
import './style.scss';
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	CheckboxControl,
	Modal,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { Icon, chartBar } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import { WORKFLOWS } from '../ai-insights/workflows';
import type { WorkflowAction } from '../ai-insights/workflows';
import {
	PERIOD_LABELS,
	WEEKDAYS,
	buildWorkflowPrompt,
	getReportMetadata,
	runWorkflowInBackground,
} from './workflow-data';
import type { PeriodOption, ReportMetadata, RunMode } from './workflow-data';
import { useRunningWorkflows } from './running-store';

interface WorkflowCardData extends WorkflowAction {
	id: string;
	metadata: ReportMetadata;
}

const ALL_FILTER = '__all__';

export function stage() {
	const [ search, setSearch ] = useState( '' );
	const [ category, setCategory ] = useState< string >( ALL_FILTER );
	const [ cadence, setCadence ] = useState< string >( ALL_FILTER );
	const [ activeWorkflow, setActiveWorkflow ] = useState< WorkflowCardData | null >( null );

	const running = useRunningWorkflows();
	const runningSlugs = useMemo(
		() => new Set( running.map( ( item ) => item.slug ) ),
		[ running ]
	);

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
					{ filtered.map( ( workflow ) => {
						const isRunning = runningSlugs.has( workflow.slug );

						return (
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
											{ isRunning && (
												<span
													className="hey-woo-workflow-card__badge hey-woo-workflow-card__badge--running"
													aria-live="polite"
												>
													<span className="hey-woo-workflow-card__pulse" aria-hidden="true" />
													{ __( 'Running', 'hey-woo' ) }
												</span>
											) }
										</div>
									</div>
								</CardHeader>
								<CardBody className="hey-woo-workflow-card__body">
									<p className="hey-woo-workflow-card__description">{ workflow.description }</p>
								</CardBody>
								<CardFooter className="hey-woo-workflow-card__footer">
									<Button
										variant={ isRunning ? 'secondary' : 'primary' }
										disabled={ isRunning }
										aria-disabled={ isRunning }
										onClick={ () => setActiveWorkflow( workflow ) }
									>
										{ isRunning
											? __( 'Running…', 'hey-woo' )
											: __( 'Set up', 'hey-woo' ) }
									</Button>
								</CardFooter>
							</Card>
						);
					} ) }
				</div>
			) }

			{ activeWorkflow && (
				<WorkflowSetupModal
					workflow={ activeWorkflow }
					onClose={ () => setActiveWorkflow( null ) }
				/>
			) }
		</div>
	);
}

interface WorkflowSetupModalProps {
	workflow: WorkflowCardData;
	onClose: () => void;
}

function WorkflowSetupModal( { workflow, onClose }: WorkflowSetupModalProps ) {
	const { metadata } = workflow;
	const [ runMode, setRunMode ] = useState< RunMode >( 'now' );
	const [ period, setPeriod ] = useState< PeriodOption >( metadata.defaultPeriod );
	const [ compare, setCompare ] = useState( true );
	const [ day, setDay ] = useState( metadata.defaultDay );
	const [ time, setTime ] = useState( '09:00' );
	const [ actionCards, setActionCards ] = useState( true );
	const [ adminNotification, setAdminNotification ] = useState( true );

	const periodOptions = useMemo(
		() => Object.entries( PERIOD_LABELS ).map( ( [ value, label ] ) => ( {
			value,
			label,
		} ) ),
		[]
	);

	const dayOptions = useMemo(
		() => WEEKDAYS.map( ( weekday ) => ( { value: weekday, label: weekday } ) ),
		[]
	);

	const handleStart = () => {
		const prompt = buildWorkflowPrompt(
			workflow,
			runMode,
			period,
			compare,
			day,
			time,
			actionCards,
			adminNotification
		);
		const displayText = sprintf(
			/* translators: 1: workflow name, 2: period label */
			__( 'Run %1$s workflow for %2$s', 'hey-woo' ),
			workflow.label,
			PERIOD_LABELS[ period ]
		);

		void runWorkflowInBackground( {
			workflow,
			prompt,
			displayText,
		} );

		onClose();
	};

	return (
		<Modal
			title={ workflow.label }
			onRequestClose={ onClose }
			className="hey-woo-workflow-modal"
			size="medium"
		>
			<p className="hey-woo-workflow-modal__description">{ workflow.description }</p>

			<div className="hey-woo-workflow-modal__field">
				<span className="hey-woo-workflow-modal__label">{ __( 'Run', 'hey-woo' ) }</span>
				<div
					className="hey-woo-workflow-modal__segmented"
					role="radiogroup"
					aria-label={ __( 'Run mode', 'hey-woo' ) }
				>
					<Button
						variant={ runMode === 'now' ? 'primary' : 'tertiary' }
						aria-pressed={ runMode === 'now' }
						onClick={ () => setRunMode( 'now' ) }
					>
						{ __( 'Now', 'hey-woo' ) }
					</Button>
					<Button
						variant={ runMode === 'weekly' ? 'primary' : 'tertiary' }
						aria-pressed={ runMode === 'weekly' }
						onClick={ () => setRunMode( 'weekly' ) }
					>
						{ __( 'Weekly', 'hey-woo' ) }
					</Button>
				</div>
			</div>

			{ runMode === 'weekly' && (
				<div className="hey-woo-workflow-modal__row">
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Day', 'hey-woo' ) }
						value={ day }
						options={ dayOptions }
						onChange={ setDay }
					/>
					<div className="hey-woo-workflow-modal__time-field">
						<label htmlFor="hey-woo-workflow-time" className="hey-woo-workflow-modal__label">
							{ __( 'Time', 'hey-woo' ) }
						</label>
						<input
							id="hey-woo-workflow-time"
							type="time"
							value={ time }
							onChange={ ( event ) => setTime( event.target.value ) }
						/>
					</div>
				</div>
			) }

			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Period', 'hey-woo' ) }
				value={ period }
				options={ periodOptions }
				onChange={ ( next ) => setPeriod( next as PeriodOption ) }
			/>

			<div className="hey-woo-workflow-modal__checks">
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Compare with previous period', 'hey-woo' ) }
					checked={ compare }
					onChange={ setCompare }
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Include addable recommended actions', 'hey-woo' ) }
					checked={ actionCards }
					onChange={ setActionCards }
				/>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __( 'Show in WooCommerce admin', 'hey-woo' ) }
					checked={ adminNotification }
					onChange={ setAdminNotification }
				/>
			</div>

			<div className="hey-woo-workflow-modal__actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'hey-woo' ) }
				</Button>
				<Button variant="primary" onClick={ handleStart }>
					{ __( 'Start workflow', 'hey-woo' ) }
				</Button>
			</div>
		</Modal>
	);
}
