/**
 * Hey Woo Workflows - right-hand setup panel.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { Button, CheckboxControl, SelectControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@wordpress/route';
import {
	PERIOD_LABELS,
	WEEKDAYS,
	getReportMetadata,
	getWorkflowBySlug,
} from './report-data';
import { startWorkflowRun } from './workflow-runs';
import type { PeriodOption, RunMode } from './report-data';
import type { WorkflowAction } from '../ai-insights/workflows';

export function inspector() {
	const search = useSearch( { strict: false } ) as { workflow?: string; run?: string };
	const workflow = typeof search.workflow === 'string' ? getWorkflowBySlug( search.workflow ) : undefined;
	const initialRunMode: RunMode = search.run === 'weekly' ? 'weekly' : 'now';

	if ( ! workflow ) {
		return null;
	}

	return <ReportSetupPanel key={ `${ workflow.slug }-${ initialRunMode }` } workflow={ workflow } initialRunMode={ initialRunMode } />;
}

interface ReportSetupPanelProps {
	initialRunMode: RunMode;
	workflow: WorkflowAction;
}

function ReportSetupPanel( { initialRunMode, workflow }: ReportSetupPanelProps ) {
	const metadata = getReportMetadata( workflow );
	const navigate = useNavigate();
	const [ runMode, setRunMode ] = useState< RunMode >( initialRunMode );
	const [ period, setPeriod ] = useState< PeriodOption >( metadata.defaultPeriod );
	const [ compare, setCompare ] = useState( true );
	const [ day, setDay ] = useState( metadata.defaultDay );
	const [ time, setTime ] = useState( '09:00' );
	const [ actionCards, setActionCards ] = useState( true );
	const [ adminNotification, setAdminNotification ] = useState( true );
	const [ isStarting, setIsStarting ] = useState( false );

	const closeInspector = () => {
		void navigate( {
			to: '/reports',
			search: {},
		} );
	};

	const handleStart = async () => {
		setIsStarting( true );
		const conversation = await startWorkflowRun( {
			workflow,
			runMode,
			period,
			compare,
			day,
			time,
			actionCards,
			adminNotification,
		} );
		setIsStarting( false );

		void navigate( {
			to: '/history',
			search: {
				conversation: conversation.id,
			},
		} );
	};

	return (
		<aside className="hey-woo-report-preview" aria-label={ __( 'Workflow setup', 'hey-woo' ) }>
			<header className="hey-woo-report-preview__header">
				<div className="hey-woo-report-preview__heading">
					<span className="hey-woo-report-preview__eyebrow">{ metadata.priority }</span>
					<h2>{ workflow.label }</h2>
				</div>
				<Button
					type="button"
					variant="tertiary"
					size="compact"
					onClick={ closeInspector }
				>
					{ __( 'Close', 'hey-woo' ) }
				</Button>
			</header>

			<div className="hey-woo-report-preview__content">
				<p className="hey-woo-report-preview__description">{ workflow.description }</p>

				<div className="hey-woo-report-preview__field">
					<span className="hey-woo-report-preview__label">{ __( 'Run', 'hey-woo' ) }</span>
					<div className="hey-woo-segmented-control" role="radiogroup">
						<Button
							type="button"
							variant="tertiary"
							className={ runMode === 'now' ? 'is-selected' : undefined }
							aria-pressed={ runMode === 'now' }
							__next40pxDefaultSize
							onClick={ () => setRunMode( 'now' ) }
						>
							{ __( 'Now', 'hey-woo' ) }
						</Button>
						<Button
							type="button"
							variant="tertiary"
							className={ runMode === 'weekly' ? 'is-selected' : undefined }
							aria-pressed={ runMode === 'weekly' }
							__next40pxDefaultSize
							onClick={ () => setRunMode( 'weekly' ) }
						>
							{ __( 'Weekly', 'hey-woo' ) }
						</Button>
					</div>
				</div>

				{ runMode === 'weekly' && (
					<div className="hey-woo-report-preview__row">
						<SelectControl
							label={ __( 'Day', 'hey-woo' ) }
							value={ day }
							options={ WEEKDAYS.map( ( weekday ) => ( {
								label: weekday,
								value: weekday,
							} ) ) }
							onChange={ setDay }
						/>

						<TextControl
							label={ __( 'Time', 'hey-woo' ) }
							type="time"
							value={ time }
							onChange={ setTime }
						/>
					</div>
				) }

				<SelectControl
					label={ __( 'Period', 'hey-woo' ) }
					value={ period }
					options={ Object.entries( PERIOD_LABELS ).map( ( [ value, label ] ) => ( {
						label,
						value,
					} ) ) }
					onChange={ ( value ) => setPeriod( value as PeriodOption ) }
				/>

				<div className="hey-woo-report-preview__checks">
					<CheckboxControl
						label={ __( 'Compare with previous period', 'hey-woo' ) }
						checked={ compare }
						onChange={ setCompare }
					/>
					<CheckboxControl
						label={ __( 'Include addable recommended actions', 'hey-woo' ) }
						checked={ actionCards }
						onChange={ setActionCards }
					/>
					<CheckboxControl
						label={ __( 'Show in WooCommerce admin', 'hey-woo' ) }
						checked={ adminNotification }
						onChange={ setAdminNotification }
					/>
				</div>
			</div>

			<div className="hey-woo-report-preview__actions">
				<Button
					type="button"
					variant="primary"
					__next40pxDefaultSize
					isBusy={ isStarting }
					disabled={ isStarting }
					onClick={ handleStart }
				>
					{ __( 'Start workflow', 'hey-woo' ) }
				</Button>
			</div>
		</aside>
	);
}
