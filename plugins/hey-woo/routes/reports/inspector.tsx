/**
 * Hey Woo Workflows - right-hand setup panel.
 */
import '../ai-insights/style.scss';
import './style.scss';
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useNavigate, useSearch } from '@wordpress/route';
import {
	PERIOD_LABELS,
	WEEKDAYS,
	buildWorkflowPrompt,
	getReportMetadata,
	getWorkflowBySlug,
	launchChatWorkflow,
} from './report-data';
import type { PeriodOption, RunMode } from './report-data';
import type { WorkflowAction } from '../ai-insights/workflows';

export function inspector() {
	const search = useSearch( { strict: false } ) as { workflow?: string };
	const workflow = typeof search.workflow === 'string' ? getWorkflowBySlug( search.workflow ) : undefined;

	if ( ! workflow ) {
		return null;
	}

	return <ReportSetupPanel key={ workflow.slug } workflow={ workflow } />;
}

interface ReportSetupPanelProps {
	workflow: WorkflowAction;
}

function ReportSetupPanel( { workflow }: ReportSetupPanelProps ) {
	const metadata = getReportMetadata( workflow );
	const navigate = useNavigate();
	const [ runMode, setRunMode ] = useState< RunMode >( 'now' );
	const [ period, setPeriod ] = useState< PeriodOption >( metadata.defaultPeriod );
	const [ compare, setCompare ] = useState( true );
	const [ day, setDay ] = useState( metadata.defaultDay );
	const [ time, setTime ] = useState( '09:00' );
	const [ actionCards, setActionCards ] = useState( true );
	const [ adminNotification, setAdminNotification ] = useState( true );

	const closeInspector = () => {
		void navigate( {
			to: '/reports',
			search: {},
		} );
	};

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

		launchChatWorkflow(
			prompt,
			sprintf(
				/* translators: 1: workflow name, 2: period label */
				__( 'Run %1$s workflow for %2$s', 'hey-woo' ),
				workflow.label,
				PERIOD_LABELS[ period ]
			)
		);
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
						<button
							type="button"
							className={ runMode === 'now' ? 'is-selected' : undefined }
							aria-pressed={ runMode === 'now' }
							onClick={ () => setRunMode( 'now' ) }
						>
							{ __( 'Now', 'hey-woo' ) }
						</button>
						<button
							type="button"
							className={ runMode === 'weekly' ? 'is-selected' : undefined }
							aria-pressed={ runMode === 'weekly' }
							onClick={ () => setRunMode( 'weekly' ) }
						>
							{ __( 'Weekly', 'hey-woo' ) }
						</button>
					</div>
				</div>

				{ runMode === 'weekly' && (
					<div className="hey-woo-report-preview__row">
						<label className="hey-woo-report-preview__field">
							<span className="hey-woo-report-preview__label">{ __( 'Day', 'hey-woo' ) }</span>
							<select value={ day } onChange={ ( event ) => setDay( event.target.value ) }>
								{ WEEKDAYS.map( ( weekday ) => (
									<option key={ weekday } value={ weekday }>
										{ weekday }
									</option>
								) ) }
							</select>
						</label>

						<label className="hey-woo-report-preview__field">
							<span className="hey-woo-report-preview__label">{ __( 'Time', 'hey-woo' ) }</span>
							<input
								type="time"
								value={ time }
								onChange={ ( event ) => setTime( event.target.value ) }
							/>
						</label>
					</div>
				) }

				<label className="hey-woo-report-preview__field">
					<span className="hey-woo-report-preview__label">{ __( 'Period', 'hey-woo' ) }</span>
					<select
						value={ period }
						onChange={ ( event ) => setPeriod( event.target.value as PeriodOption ) }
					>
						{ Object.entries( PERIOD_LABELS ).map( ( [ value, label ] ) => (
							<option key={ value } value={ value }>
								{ label }
							</option>
						) ) }
					</select>
				</label>

				<div className="hey-woo-report-preview__checks">
					<label>
						<input
							type="checkbox"
							checked={ compare }
							onChange={ ( event ) => setCompare( event.target.checked ) }
						/>
						<span>{ __( 'Compare with previous period', 'hey-woo' ) }</span>
					</label>
					<label>
							<input
								type="checkbox"
								checked={ actionCards }
								onChange={ ( event ) => setActionCards( event.target.checked ) }
							/>
							<span>{ __( 'Include addable recommended actions', 'hey-woo' ) }</span>
						</label>
					<label>
						<input
							type="checkbox"
							checked={ adminNotification }
							onChange={ ( event ) => setAdminNotification( event.target.checked ) }
						/>
						<span>{ __( 'Show in WooCommerce admin', 'hey-woo' ) }</span>
					</label>
				</div>
			</div>

			<div className="hey-woo-report-preview__actions">
				<Button type="button" variant="primary" onClick={ handleStart }>
					{ __( 'Start workflow', 'hey-woo' ) }
				</Button>
			</div>
		</aside>
	);
}
